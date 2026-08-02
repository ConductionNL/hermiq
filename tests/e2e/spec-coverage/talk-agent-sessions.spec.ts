/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * e2e — talk-agent-sessions.
 *
 * Covers the half of this change a browser can actually see: a session created
 * through hermiq's own UI/API comes back owning a Talk room, that room is named
 * after the session, renaming the session renames the room, and a session for a
 * Talk-disabled agent gets no room at all.
 *
 * 🔴 What is deliberately NOT here, and why it is not a gap:
 *
 * The per-agent bot identity, the reaction-driven approval decisions and the
 * mention gate all live inside spreed's own event dispatch. Playwright can
 * drive Talk's UI, but asserting through it would prove that spreed renders a
 * name — not that hermiq installed the right bot, and not that a NON-reviewer's
 * 👍 was refused on authorization rather than never delivered. Those carry
 * `@e2e exclude` in the spec and are verified live in the change's Task 7,
 * where the guard's own log line is the evidence that it ran. A test that
 * cannot tell "refused" from "never arrived" is worse than no test, because it
 * reports green for both.
 *
 * Run against a running Nextcloud with hermiq + OpenRegister installed:
 *
 *     NEXTCLOUD_URL=http://localhost:8080 npx playwright test tests/e2e/spec-coverage/talk-agent-sessions.spec.ts
 */

import { test, expect } from '@playwright/test'
import {
	OR_API,
	TEST_PREFIX,
	cleanupFamily,
	dismissTour,
	harvestToken,
	jsonHeaders,
	resolveRegisterSchema,
	seedAgent,
} from './_fixtures'

const HERMIQ_API = '/index.php/apps/hermiq/api'

test.describe('talk-agent-sessions — a session owns its Talk room', () => {

	test.afterAll(async ({ browser }) => {
		const page = await browser.newPage()
		try {
			const token = await harvestToken(page)
			await cleanupFamily(page.request, token, 'conversation')
			await cleanupFamily(page.request, token, 'agent')
		} finally {
			await page.close()
		}
	})

	test('a session for a Talk-enabled agent is created owning a room named after it', async ({ page }) => {
		const token = await harvestToken(page)
		await resolveRegisterSchema(page.request, token, 'agent')

		const agent = await seedAgent(page.request, token, {
			name: `${TEST_PREFIX}-room-owner`,
			talkEnabled: true,
		})

		const title = `${TEST_PREFIX} owned session`
		const created = await page.request.post(`${HERMIQ_API}/conversations`, {
			headers: jsonHeaders(token),
			data: { title, agentId: agent.id },
		})
		expect(created.ok(), `create session HTTP ${created.status()}`).toBeTruthy()

		const body = await created.json()
		const uuid = String(body.conversation?.uuid ?? body.uuid ?? body.id ?? '')
		expect(uuid, 'the created session must carry a uuid').not.toEqual('')

		// 🔴 Read the STORED session, not the create response. The room is
		// attached by a second write, and an object-event listener from an
		// unrelated app can throw between the two — which leaves the response
		// stale while the stored session is correct. Storage is what every
		// other reader uses, so storage is what this asserts.
		const stored = await page.request.get(`${OR_API}/objects/hermiq/conversation/${uuid}`, {
			headers: jsonHeaders(token),
		})
		expect(stored.ok()).toBeTruthy()
		const session = await stored.json()

		expect(session.talkRoomToken, 'a Talk-enabled agent must get a room').toBeTruthy()
		expect(session.talkRoomOrigin, 'the room must be recorded as hermiq-created').toBe('created')

		// The room carries the session's name — this is what stops a sidebar
		// full of sessions reading as "you and a bot" over and over.
		const room = await page.request.get(
			`/ocs/v2.php/apps/spreed/api/v4/room/${session.talkRoomToken}?format=json`,
			{ headers: { ...jsonHeaders(token), 'OCS-APIRequest': 'true' } },
		)
		expect(room.ok(), `room lookup HTTP ${room.status()}`).toBeTruthy()
		expect((await room.json()).ocs.data.displayName).toBe(title)
	})

	test('renaming the session renames the room it owns', async ({ page }) => {
		const token = await harvestToken(page)
		await resolveRegisterSchema(page.request, token, 'agent')

		const agent = await seedAgent(page.request, token, {
			name: `${TEST_PREFIX}-rename-owner`,
			talkEnabled: true,
		})

		const created = await page.request.post(`${HERMIQ_API}/conversations`, {
			headers: jsonHeaders(token),
			data: { title: `${TEST_PREFIX} before`, agentId: agent.id },
		})
		const uuid = String((await created.json()).conversation?.uuid ?? (await created.json()).uuid ?? '')

		const stored = await (await page.request.get(`${OR_API}/objects/hermiq/conversation/${uuid}`, {
			headers: jsonHeaders(token),
		})).json()
		const roomToken = String(stored.talkRoomToken ?? '')
		test.skip(roomToken === '', 'no room was created — Talk is not available on this instance')

		// PATCH, not PUT — the route declares PATCH and a PUT answers 405.
		const renamed = `${TEST_PREFIX} after`
		const update = await page.request.patch(`${HERMIQ_API}/conversations/${uuid}`, {
			headers: jsonHeaders(token),
			data: { title: renamed },
		})
		expect(update.ok(), `rename HTTP ${update.status()}`).toBeTruthy()

		await expect.poll(async () => {
			const room = await page.request.get(
				`/ocs/v2.php/apps/spreed/api/v4/room/${roomToken}?format=json`,
				{ headers: { ...jsonHeaders(token), 'OCS-APIRequest': 'true' } },
			)
			return (await room.json()).ocs.data.displayName
		}, { timeout: 15_000 }).toBe(renamed)
	})

	test('a session for a Talk-DISABLED agent gets no room, and is still usable', async ({ page }) => {
		const token = await harvestToken(page)
		await resolveRegisterSchema(page.request, token, 'agent')

		// The opt-in is what gates this. An agent that never opted into Talk
		// must not have rooms created on its behalf.
		const agent = await seedAgent(page.request, token, {
			name: `${TEST_PREFIX}-no-talk`,
			talkEnabled: false,
		})

		const created = await page.request.post(`${HERMIQ_API}/conversations`, {
			headers: jsonHeaders(token),
			data: { title: `${TEST_PREFIX} roomless session`, agentId: agent.id },
		})
		expect(created.ok(), 'a session must be created with or without Talk').toBeTruthy()

		const uuid = String((await created.json()).conversation?.uuid ?? (await created.json()).uuid ?? '')
		const session = await (await page.request.get(`${OR_API}/objects/hermiq/conversation/${uuid}`, {
			headers: jsonHeaders(token),
		})).json()

		expect(session.talkRoomToken ?? '', 'a Talk-disabled agent must get no room').toEqual('')
		// Absent origin reads as `bound`, which keeps the mention gate on — the
		// safe default, and the reason no backfill was needed.
		expect(session.talkRoomOrigin ?? '', 'origin must stay unset').not.toBe('created')
	})

	test('the hermiq chat surface still renders with the session-room wiring in place', async ({ page }) => {
		await page.goto('/apps/hermiq/chat', { waitUntil: 'domcontentloaded' })
		await dismissTour(page)

		await expect(page.locator('.chat-page')).toBeVisible({ timeout: 15_000 })
		await expect(
			page.locator('.chat-page__list').getByRole('button', { name: 'New conversation', exact: true }),
		).toBeVisible()
	})
})
