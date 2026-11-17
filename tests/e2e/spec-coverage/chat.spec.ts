/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Chat surface e2e — hermiq's flagship page (src/views/Chat.vue, agent-engine-port
 * task 5.1), at ZERO Playwright coverage before this spec (audit 2026-07-26).
 *
 * The router runs in HISTORY mode (createWebHistory, src/main.js) so routes
 * are PATH-form: /apps/hermiq/chat — never a #/ hash fragment.
 *
 * UI MECHANICS ONLY — no live LLM backend is required or assumed:
 *   - the session-list column renders coherently (rows XOR empty note)
 *   - with no active session, the composer is intentionally absent and
 *     the agent-selector empty state shows instead
 *   - with a seeded agent, starting a session reveals the composer, and
 *     the Send control's disabled state is coherent with the input state
 *   - sending a message surfaces the optimistic user bubble immediately; the
 *     turn then settles into EITHER an assistant reply (backend configured)
 *     OR the error note card (no LLM configured) — both are honest outcomes,
 *     a silent hang is the only failure.
 *
 * NOTE (@e2e mapping): openspec/specs/ has no chat-UI capability spec —
 * Chat.vue is annotated against agent-engine-port task 5.1, whose spec.md
 * scenarios cover credential handling, not the chat surface. Nothing is
 * tagged here rather than tagging scenarios this spec does not exercise.
 *
 * Auth: shared storageState session (tests/e2e/global-setup.ts).
 */

import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import {
	appRoot,
	cleanupFamily,
	dismissTour,
	harvestToken,
	jsonHeaders,
	resolveRegisterSchema,
	seedAgent,
	seedObject,
	TEST_PREFIX,
} from './_fixtures.ts'

/**
 * Collect app-level console errors, filtering known benign noise.
 *
 * @param page The Playwright page.
 * @return A live array accumulating error message strings.
 */
function collectConsoleErrors(page: Page): string[] {
	const errors: string[] = []
	page.on('console', (msg) => {
		if (msg.type() !== 'error') {
			return
		}
		const text = msg.text()
		if (
			/favicon|manifest\.json|the server responded with a status of 404|user_status|Failed to load resource/i.test(
				text,
			)
		) {
			return
		}
		// Only hermiq's own failures may fail a hermiq test — Nextcloud hosts
		// every installed app's widgets, so a shared instance logs errors from
		// apps this suite knows nothing about. See the fuller note in
		// dashboard-and-agents.spec.ts. Errors with no attributable script are
		// kept, so raw console.error from application code still counts.
		const source = `${msg.location()?.url || ''} ${text}`
		const foreignApp =
			source.match(/\/custom_apps\/([^/]+)\//)?.[1]
			|| source.match(/\/apps\/([^/]+)\/js\//)?.[1]
		if (foreignApp !== undefined && foreignApp !== 'hermiq') {
			return
		}
		errors.push(text)
	})
	return errors
}

/*
 * 🔴 Navigation goes through `appRoot(page)`, never a literal `/apps/hermiq/chat`.
 * On CI's `php -S` the router base is `/index.php/apps/hermiq`, so the pretty
 * deep link is outside it and the SPA catch-all redirects to the app root —
 * `.chat-page` is then genuinely absent because the Chat page was never
 * mounted. Both tests below failed that way on run 30865280923. The earlier
 * "PARKED / nc-vue selector hooks" hypothesis recorded here was never
 * confirmed and is not the cause.
 */
test.describe('hermiq chat surface (UI mechanics, no LLM required)', () => {
	test('chat page renders: session list column + thread empty state, composer absent without a session', async ({
		page,
	}) => {
		const errors = collectConsoleErrors(page)

		const root = await appRoot(page)
		await page.goto(`${root}/chat`, { waitUntil: 'domcontentloaded' })
		await dismissTour(page)

		// The chat shell renders both columns.
		await expect(page.locator('.chat-page')).toBeVisible({ timeout: 15_000 })
		// Scoped to the list column and matched exactly: sessions are titled
		// from their first message, so a real instance carries rows literally
		// named "New session 7/27/2026". An unscoped substring match on
		// "New session" therefore resolves to the action button AND every
		// such row (15 on the dev instance) and dies on strict mode: a failure
		// about seed data, not about the surface under test.
		await expect(
			page
				.locator('.chat-page__list')
				.getByRole('button', { name: 'New session', exact: true }),
		).toBeVisible()
		// Active/Archive list tabs.
		await expect(page.getByText('Active', { exact: true }).first()).toBeVisible()
		await expect(
			page.getByText('Archive', { exact: true }).first(),
		).toBeVisible()

		// The list column settles into a coherent state: loading spinner gone,
		// then EITHER session rows OR the empty-state note, never neither.
		await expect(page.locator('.chat-page__list-state')).toBeHidden({
			timeout: 20_000,
		})
		const rows = page.locator('.chat-page__row')
		const emptyNote = page.getByText(
			'No sessions yet. Start one to chat with an agent.',
		)
		await expect(rows.first().or(emptyNote)).toBeVisible({ timeout: 15_000 })

		// No active session on entry: the thread column shows the
		// start-a-session empty state, and the composer (message input + Send)
		// is intentionally NOT rendered, coherent with "nothing to send to".
		await expect(
			page.getByRole('heading', { name: 'Start a session' }),
		).toBeVisible()
		await expect(page.locator('.chat-page__composer')).toHaveCount(0)
		await expect(page.getByRole('button', { name: 'Send message' })).toHaveCount(
			0,
		)

		expect(
			errors,
			`Unexpected console errors: ${errors.join(' | ')}`,
		).toHaveLength(0)
	})

	test('with a seeded agent: start session, Send disabled/enabled coherent with input, optimistic bubble + honest turn outcome', async ({
		page,
	}) => {
		// 🔴 The turn-settles assertion below waits up to 90s, and the config's own
		// per-test budget is ALSO 90s. The inner wait could therefore never reach its
		// limit: setup spent the first seconds, the test budget expired, and Playwright
		// reported a bare "Test timeout of 90000ms exceeded" with no failing assertion
		// and no clue which step was outstanding. The test looked flaky and was not.
		//
		// The wait is right and the budget was wrong. Where an LLM IS reachable — a dev
		// instance with Ollama on host.docker.internal, which is the normal case here —
		// the turn does a real generation on a local model, and a cold 4b model on CPU
		// passes 90s without anything being broken. Where none is reachable the turn
		// settles fast on sendError, which is the other branch the assertion accepts.
		//
		// 180s is setup plus the full inner 90s with room to spare, so a failure now
		// means the turn genuinely never settled, which is the bug this test is for.
		test.setTimeout(180_000)

		// Seed a minimal agent through the OpenRegister objects API (register
		// 'hermiq', schema 'agent' — name is the only required property).
		const token = await harvestToken(page)
		await resolveRegisterSchema(page.request, token, 'agent')
		const agent = await seedAgent(page.request, token, {
			name: `${TEST_PREFIX}-chat-agent`,
		})

		const root = await appRoot(page)

		let sessionUuid = ''
		try {
			await page.goto(`${root}/chat`, { waitUntil: 'domcontentloaded' })
			await dismissTour(page)
			await expect(page.locator('.chat-page')).toBeVisible({ timeout: 15_000 })

			// The seeded agent appears in the selector; start a session.
			const card = page
				.locator('.agent-selector__card')
				.filter({ hasText: agent.name })
			await expect(card).toBeVisible({ timeout: 20_000 })
			// Capture the created session uuid from the POST response so
			// the test can clean it up afterwards. The URL is asserted as
			// `/api/sessions`, which also pins the rename: a frontend that
			// silently fell back to the deprecated alias would never match.
			const createResponse = page.waitForResponse(
				(res) =>
					res.url().includes('/apps/hermiq/api/sessions')
					&& res.request().method() === 'POST',
			)
			await card.getByRole('button', { name: 'Start session' }).click()
			const created = await (await createResponse).json().catch(() => ({}))
			sessionUuid = String(created.uuid ?? created.id ?? '')

			// The composer replaces the empty state.
			const composer = page.locator('.chat-page__composer')
			await expect(composer).toBeVisible({ timeout: 20_000 })
			const input = composer.locator('textarea.chat-page__input')
			const send = composer.getByRole('button', { name: 'Send message' })
			await expect(input).toBeVisible()

			// Send ↔ input coherence: empty → disabled, text → enabled,
			// whitespace-only → disabled again (the guard is on trim()).
			await expect(send).toBeDisabled()
			await input.fill('Hello from the e2e suite')
			await expect(send).toBeEnabled()
			await input.fill('   ')
			await expect(send).toBeDisabled()
			await input.fill('Hello from the e2e suite')
			await expect(send).toBeEnabled()

			// Send. The optimistic user bubble MUST render immediately —
			// that is pure frontend state, independent of any LLM backend.
			await send.click()
			const userBubble = page
				.locator('.chat-page__message--user')
				.filter({ hasText: 'Hello from the e2e suite' })
			await expect(userBubble.first()).toBeVisible({ timeout: 10_000 })

			// The turn must SETTLE honestly: either an assistant message
			// (working backend) or the composer's error note card (no LLM
			// configured, i.e. sendError). A silent hang is the only failure.
			const assistantBubble = page.locator('.chat-page__message--assistant')
			const errorNote = composer
				.locator('.notecard, [class*="note-card"], .notecard--error')
				.or(composer.getByRole('alert'))
			await expect(assistantBubble.first().or(errorNote.first())).toBeVisible({
				timeout: 90_000,
			})

			// Whatever the outcome, the composer must be usable again
			// (sending=false re-enables the input), with no stuck spinner.
			await expect(input).toBeEnabled({ timeout: 30_000 })
		} finally {
			// Cleanup: archive + permanently delete the session, then the
			// seeded agent family (best-effort; never masks the test result).
			if (sessionUuid) {
				await page.request
					.delete(`/index.php/apps/hermiq/api/sessions/${sessionUuid}`, {
						headers: jsonHeaders(token),
					})
					.catch(() => null)
				await page.request
					.delete(
						`/index.php/apps/hermiq/api/sessions/${sessionUuid}/permanent`,
						{
							headers: jsonHeaders(token),
						},
					)
					.catch(() => null)
			}
			await cleanupFamily(page.request, token, 'agent').catch(() => {})
		}
	})
	test('the session list separates human sessions from automated ones', async ({
		page,
	}) => {
		// 🔴 Both halves are asserted on purpose. Every session an installation
		// migrates carries `human`, so the automated group is empty by default
		// and the list renders identically whether the split works or matches
		// nothing at all. Only a session that actually carries a non-`human`
		// origin can tell the two apart, so this test seeds one.
		const token = await harvestToken(page)
		const root = await appRoot(page)
		await resolveRegisterSchema(page.request, token, 'agentsession')

		const agent = await seedAgent(page.request, token, {
			name: `${TEST_PREFIX} split agent`,
		})
		const humanTitle = `${TEST_PREFIX} human session`
		const cronTitle = `${TEST_PREFIX} cron session`

		await seedObject(page.request, token, 'agentsession', {
			name: humanTitle,
			title: humanTitle,
			agentId: agent.id,
			userId: 'admin',
			triggerOrigin: 'human',
		})
		await seedObject(page.request, token, 'agentsession', {
			name: cronTitle,
			title: cronTitle,
			agentId: agent.id,
			userId: 'admin',
			triggerOrigin: 'cron',
		})

		try {
			await page.goto(`${root}/chat`, { waitUntil: 'domcontentloaded' })
			await dismissTour(page)
			await expect(page.locator('.chat-page')).toBeVisible({ timeout: 15_000 })
			await expect(page.locator('.chat-page__list-state')).toBeHidden({
				timeout: 20_000,
			})

			const humanRows = page.locator('[data-testid="chat-session-row-human"]')
			const automatedRows = page.locator(
				'[data-testid="chat-session-row-automated"]',
			)

			// Present in its own group ...
			await expect(humanRows.filter({ hasText: humanTitle })).toHaveCount(1, {
				timeout: 20_000,
			})
			await expect(automatedRows.filter({ hasText: cronTitle })).toHaveCount(
				1,
				{ timeout: 20_000 },
			)

			// ... and ABSENT from the other. A filter that let everything through
			// would satisfy the two assertions above and fail these two.
			await expect(automatedRows.filter({ hasText: humanTitle })).toHaveCount(
				0,
			)
			await expect(humanRows.filter({ hasText: cronTitle })).toHaveCount(0)

			// With both groups populated, both headings are shown.
			await expect(
				page.locator('[data-testid="chat-session-group-human"]'),
			).toBeVisible()
			await expect(
				page.locator('[data-testid="chat-session-group-automated"]'),
			).toBeVisible()
		} finally {
			await cleanupFamily(page.request, token, 'agentsession').catch(() => {})
			await cleanupFamily(page.request, token, 'agent').catch(() => {})
		}
	})

	test('the deprecated /api/conversations aliases still answer', async ({
		page,
	}) => {
		// The aliases are this rename's rollback path: if they stopped answering,
		// reverting the frontend alone would not restore a working app. Asserted
		// as a real request rather than read off the route table, because a
		// duplicate route NAME displaces its twin silently and the table still
		// looks right.
		const token = await harvestToken(page)

		const canonical = await page.request.get(
			'/index.php/apps/hermiq/api/sessions',
			{ headers: jsonHeaders(token) },
		)
		const alias = await page.request.get(
			'/index.php/apps/hermiq/api/conversations',
			{ headers: jsonHeaders(token) },
		)

		expect(canonical.status(), 'the canonical route must answer').toBe(200)
		expect(alias.status(), 'the deprecated alias must still answer').toBe(200)

		const canonicalBody = await canonical.json()
		const aliasBody = await alias.json()
		expect(
			aliasBody.total,
			'the alias must reach the same controller, not a different list',
		).toBe(canonicalBody.total)
	})
})
