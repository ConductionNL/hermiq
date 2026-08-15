/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spec-coverage e2e — the NC-native write tools' governance surface
 * (nc-native-write-tools).
 *
 * Two `@e2e` scenarios live here; every other scenario in the change carries a
 * reason-bearing `@e2e exclude` because it is a guard inside a service with no
 * browser surface, and is covered by unit tests instead.
 *
 * 🔴 The classification test is the one a unit test cannot replace. A tool's
 * `destructiveHint` is declared in Hermiq, carried through OpenRegister's tool
 * registry, and rendered back in Hermiq's grant editor — three hops across an
 * app boundary that `tests/bootstrap.php` stubs out. The consequence of losing a
 * hint in transit is not an error: `createCalendarEvent` would simply render as
 * an ordinary tool, and an operator would grant an iMIP-sending capability
 * believing it only touched their own calendar. That is precisely the failure the
 * per-tool grant editor exists to prevent, and only a live instance with both
 * apps installed exercises the round trip.
 *
 * 🔴 The default-deny test asserts the resolved set, not the catalogue. A tool
 * appearing in the catalogue proves it was registered; it says nothing about
 * whether an agent with no explicit grant can reach it. Those are different
 * questions, and only the second one is a security property.
 *
 * Run against a running Nextcloud with Hermiq + OpenRegister installed:
 *
 *     NEXTCLOUD_URL=http://localhost:8080 NC_USER=admin NC_PASS=admin \
 *       npx playwright test --project=chromium nc-native-write-tools
 *
 * Covers openspec/changes/nc-native-write-tools/specs/nc-native-tools/spec.md
 */

import { expect, test } from '@playwright/test'
import {
	appRoot,
	cleanupFamily,
	dismissTour,
	harvestToken,
	seedAgent,
	TEST_PREFIX,
} from './_fixtures.ts'

/** The five tools this change adds. */
const WRITE_TOOLS = [
	'hermiq.createCalendarEvent',
	'hermiq.upsertContact',
	'hermiq.listNotes',
	'hermiq.createNote',
	'hermiq.updateNote',
]

/**
 * The four that must be default-denied. `listNotes` is deliberately absent — it
 * is read-only and SHOULD survive an empty grant, so including it here would
 * turn a correct behaviour into a failure.
 */
const MUST_BE_DENIED = [
	'hermiq.createCalendarEvent',
	'hermiq.upsertContact',
	'hermiq.createNote',
	'hermiq.updateNote',
]

test.describe('nc-native-write-tools: grant surface and default-deny', () => {
	let token = ''
	let agentId = ''

	test.beforeAll(async ({ browser }) => {
		const page = await browser.newPage()
		token = await harvestToken(page)
		const seeded = await seedAgent(page.request, token, {
			name: `${TEST_PREFIX}-writetools`,
		})
		agentId = seeded.id
		await page.close()
	})

	test.afterAll(async ({ browser }) => {
		const page = await browser.newPage()
		await cleanupFamily(page.request, token)
		await page.close()
	})

	test('every write tool is offered in Tool governance with its honest classification', async ({
		page,
	}) => {
		const root = await appRoot(page)
		await page.goto(`${root}/agents/${agentId}`)
		await dismissTour(page)

		// The section exists at all — if the heading moved, the assertions below
		// could pass against some other list and prove nothing.
		await expect(
			page.getByRole('heading', { name: /tool governance/i }),
		).toBeVisible()

		const body = page.locator('body')
		for (const tool of WRITE_TOOLS) {
			await expect(body).toContainText(tool)
		}

		// The classification is the point. A hint dropped in transit between
		// Hermiq → OpenRegister → Hermiq does not error; it silently renders a
		// destructive tool as an ordinary one.
		const text = (await body.innerText()).toLowerCase()
		const calendarIdx = text.indexOf('hermiq.createcalendarevent')
		expect(
			calendarIdx,
			'the calendar tool must appear in the grant surface',
		).toBeGreaterThan(-1)

		// The description has to lead with the outbound effect, because that is
		// the only place an operator learns a calendar grant can email strangers.
		expect(text).toContain('sends them invitation emails')

		// Write tools announce that they need an explicit grant.
		expect(text).toContain('requires explicit grant')
	})

	test('an agent with no explicit grant resolves none of the four write tools', async ({
		page,
	}) => {
		// The RESOLVED catalogue for this agent — not the instance-wide tool list.
		// Registration and reachability are different questions, and only the
		// second one is a security property.
		const response = await page.request.get(
			`/index.php/apps/hermiq/api/agents/${agentId}/tool-catalog`,
			{ headers: { 'OCS-APIRequest': 'true', requesttoken: token } },
		)
		expect(response.ok()).toBeTruthy()

		const payload = await response.json()
		const tools: Record<string, unknown>[] = payload.tools ?? []
		const byId = new Map(tools.map((t) => [String(t.id), t]))

		// The seeded agent has an empty grant list, so default-deny is what
		// decides this. Every write tool must be withheld.
		for (const denied of MUST_BE_DENIED) {
			const entry = byId.get(denied)
			expect(entry, `${denied} must appear in the catalogue`).toBeTruthy()
			expect(entry?.granted, `${denied} must not be granted by default`).toBe(
				false,
			)
			expect(
				entry?.requiresExplicitGrant,
				`${denied} must announce that it needs an explicit grant`,
			).toBe(true)
		}

		// 🔴 The control. If every tool were withheld the assertions above would
		// pass for the wrong reason — a broken catalogue looks identical to a
		// correctly-strict one. listNotes is read-only and MUST survive.
		const listNotes = byId.get('hermiq.listNotes')
		expect(
			listNotes,
			'hermiq.listNotes must appear in the catalogue',
		).toBeTruthy()
		expect(
			listNotes?.requiresExplicitGrant,
			'listNotes is read-only and must NOT require an explicit grant — without this the '
				+ 'default-deny assertions above could pass simply because nothing resolved at all',
		).toBe(false)

		// The classification survived the Hermiq → OpenRegister → Hermiq round trip.
		expect(byId.get('hermiq.createCalendarEvent')?.reach).toBe('external')
		expect(byId.get('hermiq.upsertContact')?.reach).toBe('user')
	})
})
