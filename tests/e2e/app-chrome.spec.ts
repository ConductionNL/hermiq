/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The bottom-left app chrome, in a browser (ADR-114).
 *
 * gate-107 reads the manifest and can prove the entries are DECLARED. It
 * cannot prove they RENDER, and this programme has produced three defects of
 * exactly that shape: an unregistered icon name renders NO glyph (no fallback,
 * no console error — ChartBoxOutline had to be added to src/icons.js for this
 * very entry), an entry whose `route` names a page the app does not host
 * renders a row that goes nowhere, and `nav.includePersonalSettings: false`
 * silently removed the entry reaching the user's notification preferences in
 * two apps.
 *
 * ⚠️ SCOPE EVERY SELECTOR TO `[data-testid="cn-nav"]`. An unscoped selector
 * also matches Nextcloud's own user menu, which is attached-but-hidden:
 * `waitFor({state:'attached'})` passes on it and the click never becomes
 * actionable, so the spec fails with "Target page has been closed" — a timeout
 * wearing a crash's clothes.
 *
 * ⚠️ SETTINGS ENTRIES ARE ATTACHED, NOT VISIBLE, inside a collapsed foldout.
 *
 * The config supplies `use.storageState`, so specs start signed in.
 */

import { expect, test } from '@playwright/test'

const APP_BASE = '/index.php/apps/hermiq'

test.describe('app chrome (ADR-114)', () => {
	test.beforeEach(async ({ page }) => {
		await page.goto(`${APP_BASE}/`, { waitUntil: 'domcontentloaded' })
		await expect(page.locator('[data-testid="cn-nav"]')).toBeVisible({
			timeout: 30_000,
		})
	})

	test('the footer reads Documentation, Store, Reports, Features & roadmap, each with a glyph', async ({
		page,
	}) => {
		const footer = page.locator(
			'[data-testid="cn-nav"] .cn-app-nav__footer-list',
		)
		await expect(footer).toBeAttached({ timeout: 15_000 })

		const rows = footer.locator('li')
		const texts = (await rows.allInnerTexts())
			.map((t) => t.trim())
			.filter(Boolean)

		// Hermiq is one of only four apps that had a Store before this
		// programme, so its footer carries all four declared chrome items.
		// ORDER is the rule, not the numbers.
		const seen = texts.filter((t) =>
			/Documentation|Store|Reports|roadmap/i.test(t),
		)
		expect(seen.length).toBe(4)
		expect(seen[0]).toMatch(/Documentation/i)
		expect(seen[1]).toMatch(/Store/i)
		expect(seen[2]).toMatch(/Reports/i)
		expect(seen[3]).toMatch(/roadmap/i)

		for (const row of await rows.all()) {
			await expect(
				row.locator('svg, .material-design-icon').first(),
			).toBeAttached()
		}
	})

	test('AI oversight is a card on Reports, not a settings entry', async ({
		page,
	}) => {
		const nav = page.locator('[data-testid="cn-nav"]')

		// It sat in the SETTINGS foldout, which is where configuration belongs
		// (ADR-044 Decision 3). Oversight is not configuration — it is a reading
		// of what the agents did.
		await expect(
			nav.locator('[data-testid="cn-nav-entry-AiOversightMenu"]'),
		).toHaveCount(0)

		await nav.locator('[data-testid="cn-nav-entry-ReportsMenu"]').click()
		await expect(page).toHaveURL(/\/apps\/hermiq\/reports(\?|$)/, {
			timeout: 15_000,
		})
		await expect(
			page.getByText('AI oversight', { exact: false }).first(),
		).toBeVisible({ timeout: 15_000 })
	})

	test('the AI oversight page is still routable, because another app deep-links it', async ({
		page,
	}) => {
		// dossiq declares /apps/hermiq/ai-oversight as an ADR-110 integrations
		// entry. Retiring the menu entry must not take the ROUTE with it, or
		// that link 404s from another app (ADR-044 Decision 5).
		await page.goto(`${APP_BASE}/ai-oversight`)
		await expect(page).toHaveURL(/\/ai-oversight(\?|$)/, { timeout: 15_000 })
		await expect(page.locator('[data-testid="cn-nav"]')).toBeVisible()
	})

	test('guardrails, the algorithm register and compliance stay in the foldout', async ({
		page,
	}) => {
		// Deliberate asymmetry with AI oversight: these ARE configuration, so
		// ADR-044 Decision 3 keeps them where they are. A later sweep that cards
		// them fails here rather than passing review.
		const nav = page.locator('[data-testid="cn-nav"]')
		for (const id of ['GuardrailPolicy', 'AlgorithmRegister', 'Compliance']) {
			await expect(
				nav.locator(`[data-testid="cn-nav-entry-${id}"]`),
			).toBeAttached({ timeout: 15_000 })
		}
	})

	test('the settings foldout carries Personal settings, Admin settings and Flows', async ({
		page,
	}) => {
		const nav = page.locator('[data-testid="cn-nav"]')

		await expect(nav.locator('[data-testid="cn-nav-settings"]')).toBeAttached({
			timeout: 15_000,
		})
		await expect(
			nav.locator('[data-testid="cn-nav-personal-settings"]'),
		).toBeAttached()
		await expect(
			nav.locator('[data-testid="cn-nav-entry-FlowIndex"]'),
		).toBeAttached()

		const admin = nav.locator('[data-testid="cn-nav-admin-settings"]')
		await expect(admin).toBeAttached()
		await expect(admin).toHaveAttribute('href', /\/settings\/admin\/hermiq$/)
	})
})
