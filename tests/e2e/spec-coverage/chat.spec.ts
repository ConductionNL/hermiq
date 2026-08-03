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
 *   - the conversation-list column renders coherently (rows XOR empty note)
 *   - with no active conversation, the composer is intentionally absent and
 *     the agent-selector empty state shows instead
 *   - with a seeded agent, starting a conversation reveals the composer, and
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

import { test, expect, type Page } from '@playwright/test'
import { TEST_PREFIX, cleanupFamily, dismissTour, harvestToken, jsonHeaders, resolveRegisterSchema, seedAgent } from './_fixtures'


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
		if (/favicon|manifest\.json|the server responded with a status of 404|user_status|Failed to load resource/i.test(text)) {
			return
		}
		// Only hermiq's own failures may fail a hermiq test — Nextcloud hosts
		// every installed app's widgets, so a shared instance logs errors from
		// apps this suite knows nothing about. See the fuller note in
		// dashboard-and-agents.spec.ts. Errors with no attributable script are
		// kept, so raw console.error from application code still counts.
		const source = `${msg.location()?.url || ''} ${text}`
		const foreignApp = source.match(/\/custom_apps\/([^/]+)\//)?.[1]
			|| source.match(/\/apps\/([^/]+)\/js\//)?.[1]
		if (foreignApp !== undefined && foreignApp !== 'hermiq') {
			return
		}
		errors.push(text)
	})
	return errors
}

/*
 * PARKED (both tests below) — requires nc-vue selector hooks present only in
 * builds after 2026-07-25 — unpark after the next hermiq deploy.
 *
 * STATIC evidence: the deployed nc-vue chunk (js/hermiq-shared-nc-vue.js,
 * 2026-07-25 22:13) was built from node_modules/@conduction/nextcloud-vue/dist
 * (the PUBLISHED dist) rather than the LOCAL_LIB source — the configuration
 * webpack.config.js records as making "CnAppRoot render nothing at all —
 * silently, with zero console errors". These tests need real Chat DOM
 * (.chat-page, the composer), so they cannot be re-pointed at some other
 * selector on this build.
 *
 * NOT yet confirmed live: a read-only probe on 2026-07-27 did observe an
 * empty `.hermiq-root`, but the shared instance later reported
 * needsDbUpgrade:true, so that observation is unusable as proof. Re-verify on
 * a healthy instance before drawing any app-defect conclusion.
 */
test.describe('hermiq chat surface (UI mechanics, no LLM required)', () => {

	test('chat page renders: conversation list column + thread empty state, composer absent without a conversation', async ({ page }) => {
		const errors = collectConsoleErrors(page)

		await page.goto('/apps/hermiq/chat', { waitUntil: 'domcontentloaded' })
		await dismissTour(page)

		// The chat shell renders both columns.
		await expect(page.locator('.chat-page')).toBeVisible({ timeout: 15_000 })
		// Scoped to the list column and matched exactly: conversations are titled
		// from their first message, so a real instance carries rows literally
		// named "New Conversation 7/27/2026". An unscoped substring match on
		// "New conversation" therefore resolves to the action button AND every
		// such row (15 on the dev instance) and dies on strict mode — a failure
		// about seed data, not about the surface under test.
		await expect(
			page.locator('.chat-page__list').getByRole('button', { name: 'New conversation', exact: true }),
		).toBeVisible()
		// Active/Archive list tabs.
		await expect(page.getByText('Active', { exact: true }).first()).toBeVisible()
		await expect(page.getByText('Archive', { exact: true }).first()).toBeVisible()

		// The list column settles into a coherent state: loading spinner gone,
		// then EITHER conversation rows OR the empty-state note — never neither.
		await expect(page.locator('.chat-page__list-state')).toBeHidden({ timeout: 20_000 })
		const rows = page.locator('.chat-page__row')
		const emptyNote = page.getByText('No conversations yet. Start one to chat with an agent.')
		await expect(rows.first().or(emptyNote)).toBeVisible({ timeout: 15_000 })

		// No active conversation on entry → the thread column shows the
		// agent-selector empty state, and the composer (message input + Send)
		// is intentionally NOT rendered — coherent with "nothing to send to".
		await expect(page.getByRole('heading', { name: 'Start a conversation' })).toBeVisible()
		await expect(page.locator('.chat-page__composer')).toHaveCount(0)
		await expect(page.getByRole('button', { name: 'Send message' })).toHaveCount(0)

		expect(errors, `Unexpected console errors: ${errors.join(' | ')}`).toHaveLength(0)
	})

	test('with a seeded agent: start conversation, Send disabled/enabled coherent with input, optimistic bubble + honest turn outcome', async ({ page }) => {
		// Seed a minimal agent through the OpenRegister objects API (register
		// 'hermiq', schema 'agent' — name is the only required property).
		const token = await harvestToken(page)
		await resolveRegisterSchema(page.request, token, 'agent')
		const agent = await seedAgent(page.request, token, { name: `${TEST_PREFIX}-chat-agent` })

		let conversationUuid = ''
		try {
			await page.goto('/apps/hermiq/chat', { waitUntil: 'domcontentloaded' })
			await dismissTour(page)
			await expect(page.locator('.chat-page')).toBeVisible({ timeout: 15_000 })

			// The seeded agent appears in the selector; start a conversation.
			const card = page.locator('.agent-selector__card').filter({ hasText: agent.name })
			await expect(card).toBeVisible({ timeout: 20_000 })
			// Capture the created conversation uuid from the POST response so
			// the test can clean it up afterwards.
			const createResponse = page.waitForResponse((res) =>
				res.url().includes('/apps/hermiq/api/conversations') && res.request().method() === 'POST')
			await card.getByRole('button', { name: 'Start conversation' }).click()
			const created = await (await createResponse).json().catch(() => ({}))
			conversationUuid = String(created.uuid ?? created.id ?? '')

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
			const userBubble = page.locator('.chat-page__message--user').filter({ hasText: 'Hello from the e2e suite' })
			await expect(userBubble.first()).toBeVisible({ timeout: 10_000 })

			// The turn must SETTLE honestly: either an assistant message
			// (working backend) or the composer's error note card (no LLM
			// configured — sendError). A silent hang is the only failure.
			const assistantBubble = page.locator('.chat-page__message--assistant')
			const errorNote = composer.locator('.notecard, [class*="note-card"], .notecard--error')
				.or(composer.getByRole('alert'))
			await expect(assistantBubble.first().or(errorNote.first())).toBeVisible({ timeout: 90_000 })

			// Whatever the outcome, the composer must be usable again
			// (sending=false re-enables the input) — no stuck spinner.
			await expect(input).toBeEnabled({ timeout: 30_000 })
		} finally {
			// Cleanup: archive + permanently delete the conversation, then the
			// seeded agent family (best-effort; never masks the test result).
			if (conversationUuid) {
				await page.request.delete(`/index.php/apps/hermiq/api/conversations/${conversationUuid}`, {
					headers: jsonHeaders(token),
				}).catch(() => null)
				await page.request.delete(`/index.php/apps/hermiq/api/conversations/${conversationUuid}/permanent`, {
					headers: jsonHeaders(token),
				}).catch(() => null)
			}
			await cleanupFamily(page.request, token, 'agent').catch(() => {})
		}
	})
})
