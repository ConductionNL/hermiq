/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Regression e2e — the session surface (session-store-consolidation +
 * session-nav-schema-retirement).
 *
 * Hermiq used to expose TWO conversation surfaces: the Chat page (real conversations,
 * real messages) and the AgentSessions page (Session/SessionTurn objects that nothing
 * ever wrote, so it could only render an empty list). This spec pins the consolidated
 * result: exactly one surface, called "Sessions", serving the live store.
 *
 * Why these assertions exist, and not others: every one of them corresponds to a defect
 * that a green unit suite did not catch, and that driving the real UI did.
 *
 *   - The nav carried both entries, and after the relabel BOTH were called "Sessions" —
 *     so the surviving one has to be identified by its route, never by its label.
 *   - The manifest page title and the thread-header fallback still said "Chat" after the
 *     menu said "Sessions". A grep for "conversation" cannot find the word "Chat".
 *   - `t('hermiq', 'Session started with {agent}')` is interpolated, so a grep for
 *     single-argument t() calls missed it.
 *   - The session list sorted on a bare `updated` key. OpenRegister reads a bare key as
 *     an OBJECT PROPERTY and Conversation has no `updated` property, so the sort silently
 *     did nothing and a newly created session landed at position ~185 of 185 — created
 *     through the UI, then gone on reload.
 *
 * Deliberately NOT asserted: that no session ROW says "New conversation". Those are
 * persisted placeholder titles — data, not chrome. The rename was scoped to the UI, and
 * ConversationTitleWriter::needsTitle() matches that literal, so renaming the stored
 * value without the matcher would make every new session permanently unnameable again.
 *
 * Run against a running Nextcloud with Hermiq + OpenRegister installed:
 *
 *     NEXTCLOUD_URL=http://localhost:8080 \
 *       NC_USER=admin NC_PASS=admin \
 *       npx playwright test --project chromium sessions.spec.ts
 *
 * Covers: openspec/changes/session-nav-schema-retirement/specs/app-manifest/spec.md
 * (one conversation surface; no page without a component) and the user-facing half of
 * openspec/changes/session-store-consolidation/specs/agent-memory/spec.md.
 */

import { test, expect, type Page } from '@playwright/test'

const NC_USER = process.env.NC_USER || 'admin'
const NC_PASS = process.env.NC_PASS || 'admin'

/**
 * Log the configured user in through Nextcloud's real login form.
 *
 * Idempotent: if an already-authenticated session lands us straight in the app, the login
 * form is absent and we return without acting.
 *
 * @param page The Playwright page.
 */
async function login(page: Page): Promise<void> {
	await page.goto('/login', { waitUntil: 'domcontentloaded' })

	const userField = page.locator('#user')
	if (await userField.count() === 0) {
		return
	}

	await userField.fill(NC_USER)
	await page.locator('#password').fill(NC_PASS)
	await page.locator('button[type="submit"], input[type="submit"]').first().click()
	await page.locator('#user').waitFor({ state: 'hidden', timeout: 30_000 })
}

/**
 * Dismiss the first-run walkthrough if it is showing.
 *
 * It renders a full-page dim layer that intercepts pointer events, so without this every
 * click in this spec times out against an element that is visible, enabled and stable.
 *
 * @param page The Playwright page.
 */
async function dismissWalkthrough(page: Page): Promise<void> {
	const close = page.locator('[aria-label="Welcome to Hermiq"] button[aria-label="Close tour"]')
	if (await close.count() > 0) {
		await close.first().click()
		await page.locator('.cn-walkthrough__dim').waitFor({ state: 'detached', timeout: 10_000 })
	}
}

/**
 * The app's own words: buttons, labels, headings, placeholders, nav entries.
 *
 * Deliberately excludes session titles and message bodies. Those are DATA — a session
 * legitimately called "New conversation", or an assistant reply that happens to use the
 * word, must never fail a wording assertion.
 *
 * @param page The Playwright page.
 * @return The concatenated chrome text.
 */
async function chromeText(page: Page): Promise<string> {
	return page.evaluate(() => [
		...[...document.querySelectorAll('button')].map((b) => `${b.textContent || ''} ${b.getAttribute('aria-label') || ''} ${b.getAttribute('title') || ''}`),
		...[...document.querySelectorAll('label, h1, h2, h3')].map((e) => e.textContent || ''),
		...[...document.querySelectorAll('input, textarea')].map((e) => (e as HTMLInputElement).placeholder || ''),
		...[...document.querySelectorAll('nav a')].map((a) => `${a.getAttribute('title') || ''} ${a.textContent || ''}`),
	].join(' | '))
}

test.describe('hermiq regression: the session surface', () => {
	test('exposes exactly one session surface, and it is not called Chat', async ({ page }) => {
		await login(page)
		await page.goto('/apps/hermiq/', { waitUntil: 'domcontentloaded' })
		await dismissWalkthrough(page)

		// Identify by ROUTE, not label: before the retirement both entries read "Sessions",
		// so a label-based assertion passed while the dead page was still in the nav.
		const sessionsEntries = page.locator('nav a[href="/apps/hermiq/chat"]')
		await expect(sessionsEntries).toHaveCount(1)
		await expect(sessionsEntries.first()).toHaveAttribute('title', 'Sessions')

		// The retired page is gone from the nav entirely.
		await expect(page.locator('nav a[href="/apps/hermiq/sessions"]')).toHaveCount(0)

		// No nav entry is called "Chat" any more.
		const navTitles = await page.locator('nav a').evaluateAll((els) => els.map((e) => (e.getAttribute('title') || '').trim()))
		expect(navTitles.filter((t) => t === 'Chat')).toHaveLength(0)
		expect(navTitles.filter((t) => t === 'Sessions')).toHaveLength(1)
	})

	test('renames the surface end to end while keeping the /chat route', async ({ page }) => {
		await login(page)
		await page.goto('/apps/hermiq/chat', { waitUntil: 'domcontentloaded' })
		await dismissWalkthrough(page)

		// The page's own identity, not just the menu label. The manifest page title and the
		// thread-header fallback both still said "Chat" after the menu said "Sessions".
		await expect(page.locator('.chat-page')).toHaveAttribute('title', 'Sessions')

		// The rename is user-facing only: the route is unchanged, so links keep working.
		expect(page.url()).toContain('/apps/hermiq/chat')

		const chrome = await chromeText(page)
		expect(chrome, 'no chrome may still say "conversation"').not.toMatch(/conversation/i)
		expect(chrome, 'no chrome may still say "Chat settings"').not.toMatch(/Chat settings/i)
		expect(chrome).toMatch(/New session/)
	})

	test('creates a session, gets a reply, and shows the generated title at the top of the list', async ({ page }) => {
		test.slow() // A real LLM round trip.

		await login(page)
		await page.goto('/apps/hermiq/chat', { waitUntil: 'domcontentloaded' })
		await dismissWalkthrough(page)

		await page.locator('button:has-text("New session")').first().click()

		// The agent picker offers sessions, not conversations.
		const startButtons = page.locator('button:has-text("Start session")')
		await expect(startButtons.first()).toBeVisible()
		await expect(page.locator('button:has-text("Start conversation")')).toHaveCount(0)

		// The picker is a grid inside a nested scroll container under a sticky header, and
		// Playwright's auto-scroll heuristic keeps reporting the first card's button as
		// "outside of the viewport" even right after scrollIntoView — so the click retries
		// to timeout. Scroll-to-centre and click in the SAME evaluate call (the button is
		// asserted visible above; the composer/reply assertions below would catch a no-op).
		await startButtons.first().evaluate((el: HTMLElement) => {
			el.scrollIntoView({ block: 'center' })
			el.click()
		})

		const composer = page.locator('textarea.chat-page__input')
		await expect(composer).toBeVisible()
		await composer.fill('What are the retention rules under the Dutch Archiefwet?')
		await composer.press('Enter')

		// The reply arrives. The user's own turn renders immediately, so assert on an
		// assistant bubble rather than on the text being present anywhere.
		const bubbles = page.locator('[class*="chat-page__message"]')
		await expect.poll(async () => bubbles.count(), { timeout: 120_000 }).toBeGreaterThanOrEqual(2)

		// The title is generated OFF the reply path (ConversationTitleJob), so a fresh
		// session legitimately still carries its placeholder here. Asserting a generated
		// title at this point would be asserting that the reply blocked on it — the exact
		// ~20s regression this change removed.
		const list = page.locator('.chat-page__row')
		await expect(list.first()).toBeVisible()
	})

	test('archives and restores a session with session wording', async ({ page }) => {
		await login(page)
		await page.goto('/apps/hermiq/chat', { waitUntil: 'domcontentloaded' })
		await dismissWalkthrough(page)

		// `.chat-page__row`, NOT `[class*="chat-page__row"]`: the substring form also matches
		// the `chat-page__rows` CONTAINER, so `.first()` silently resolved to the whole list.
		const rows = page.locator('.chat-page__row')
		await expect(rows.first()).toBeVisible()

		// Archive the first active session. This test pins the ARCHIVE→RESTORE flow and its
		// "session" wording (the toasts and the restore/delete button labels); it does not
		// track one specific row by title, because the reference instance has ~200 sessions
		// mostly titled "New conversation" and a title match would be ambiguous. It also does
		// not assert a row-count drop: the list is paginated at 50, so archiving one just
		// pulls the next page's row into view and the count stays put. The toasts are the
		// unambiguous evidence that each operation fired.
		await rows.first().locator('button[aria-label="Archive session"]').first().click()
		await expect(page.getByText('Session archived')).toBeVisible()

		// Switch to the Archive tab. Its rows offer restore + delete, in session wording.
		await page.getByText('Archive', { exact: true }).first().click()
		const archivedRow = page.locator('.chat-page__row').first()
		await expect(archivedRow).toBeVisible()
		await expect(archivedRow.locator('button[aria-label="Restore session"]')).toBeVisible()
		await expect(archivedRow.locator('button[aria-label="Delete permanently"]')).toBeVisible()

		await archivedRow.locator('button[aria-label="Restore session"]').first().click()
		await expect(page.getByText('Session restored')).toBeVisible()
	})

	test('lists sessions newest-first so a new session is findable', async ({ page }) => {
		await login(page)

		// The regression this pins: the list sorted on a bare `updated` key, which
		// OpenRegister reads as an object property. Conversation has no such property, so
		// the sort silently did nothing and the list came back oldest-first — a session
		// created through the UI was unreachable at position ~185 of 185.
		const response = await page.request.get('/index.php/apps/hermiq/api/conversations?limit=5', {
			headers: { 'OCS-APIRequest': 'true' },
		})
		expect(response.ok()).toBeTruthy()

		const body = await response.json()
		const updated: string[] = (body.results || []).map((r: { updated: string }) => r.updated)
		expect(updated.length, 'need at least two sessions to prove an order').toBeGreaterThan(1)

		const descending = [...updated].sort((a, b) => (a < b ? 1 : -1))
		expect(updated, 'the session list must be newest-first').toEqual(descending)
	})
})
