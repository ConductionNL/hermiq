/**
 * End-to-end regression for the per-user default-agent picker.
 *
 * Drives the real personal-settings panel of the Hermiq app and pins the scenarios the
 * unit tests and a build cannot see: that the picker renders in the app settings ABOVE
 * Talk delivery (where the request put it), lists the agents the user can access, saves
 * a choice through the existing preferences API, persists it across a reload, and clears
 * it again.
 *
 * The picker's storage is the generic PreferencesController (`pref_default-agent`), which
 * ships in development already, so these scenarios need only the built frontend bundle —
 * not the resolver change. The resolver (per-user tier) is covered by
 * ChatStreamControllerTest.
 *
 * Run against a running Nextcloud with Hermiq + OpenRegister installed:
 *
 *     NEXTCLOUD_URL=http://localhost:8080 \
 *       NC_USER=admin NC_PASS=admin \
 *       npx playwright test --project chromium default-agent.spec.ts
 *
 * Covers: openspec/changes/default-companion-agent/specs/default-companion-agent/spec.md
 * (a user can set and clear a default companion agent).
 */

import { test, expect, type Page } from '@playwright/test'

const NC_USER = process.env.NC_USER || 'admin'
const NC_PASS = process.env.NC_PASS || 'admin'

/**
 * Log the configured user in through Nextcloud's real login form. Idempotent.
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
 * Dismiss the first-run walkthrough if it is showing (its dim layer eats clicks).
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
 * Open the app's personal-settings dialog and wait for the Default agent section.
 *
 * Two steps, verified live: the app-nav `.settings-button` opens a small menu whose
 * "Personal settings" item (an `href="#"` JS action, distinct from the routed
 * Guardrail/Compliance entries below it) opens the NcAppSettingsDialog that CnAppRoot
 * hosts for the App.vue `#user-settings` slot. Clicking the button alone does nothing
 * visible, and Nextcloud's own top-right "Settings" menu is a different control — so the
 * selectors are deliberately specific.
 *
 * @param page The Playwright page.
 */
async function openSettings(page: Page): Promise<void> {
	await page.locator('button.settings-button').click()
	await page.locator('a[href="#"]', { hasText: 'Personal settings' }).first().click()
	await page.locator('.default-agent__select').waitFor({ state: 'visible', timeout: 15_000 })
}

/**
 * Open the Default agent select, pick the first REAL agent, and return its label.
 *
 * Scopes to `.vs__dropdown-option` so the empty-state row ("No results", shown for the
 * instant before the agent collection resolves) can never be chosen, and closes the
 * dropdown afterwards so a lingering overlay cannot intercept the next click.
 *
 * @param page The Playwright page.
 * @return The chosen agent's label.
 */
async function chooseFirstAgent(page: Page): Promise<string> {
	const select = page.locator('.default-agent__select')
	await select.locator('.vs__search').click()
	const option = page.locator('.vs__dropdown-menu li.vs__dropdown-option').first()
	await expect(option).toBeVisible()
	const label = (await option.textContent() || '').trim()
	await option.click()
	await expect(select.locator('.vs__selected')).toContainText(label)
	return label
}

test.describe('hermiq regression: the default-agent picker', () => {
	test('the picker sits in personal settings, above Talk delivery', async ({ page }) => {
		await login(page)
		await page.goto('/apps/hermiq/', { waitUntil: 'domcontentloaded' })
		await dismissWalkthrough(page)
		await openSettings(page)

		// The dialog lists its sections as left-nav links; assert both are present and that
		// Default agent is ordered above Talk delivery (where the request put it).
		const sections = page.locator('.navigation-list__link-text')
		await expect(sections.filter({ hasText: 'Default agent' })).toBeVisible()
		await expect(sections.filter({ hasText: 'Talk delivery' })).toBeVisible()

		const order = await page.evaluate(() => {
			const names = [...document.querySelectorAll('.navigation-list__link-text')].map((n) => (n.textContent || '').trim())
			return { da: names.indexOf('Default agent'), td: names.indexOf('Talk delivery') }
		})
		expect(order.da, 'both sections must be present in the settings nav').toBeGreaterThanOrEqual(0)
		expect(order.td).toBeGreaterThanOrEqual(0)
		expect(order.da, 'Default agent must render above Talk delivery').toBeLessThan(order.td)
	})

	test('selecting an agent saves it, and it persists across a reload', async ({ page }) => {
		await login(page)
		await page.goto('/apps/hermiq/', { waitUntil: 'domcontentloaded' })
		await dismissWalkthrough(page)
		await openSettings(page)

		const select = page.locator('.default-agent__select')
		await expect(select).toBeVisible()

		// Pick a real agent. The user-visible outcome (the select now shows that agent) is
		// the assertion, not the transient save toast.
		const chosen = await chooseFirstAgent(page)

		// Reload and reopen: the chosen agent must STILL be selected — the choice was
		// persisted to the per-user preference, not just held in component state.
		await page.reload({ waitUntil: 'domcontentloaded' })
		await dismissWalkthrough(page)
		await openSettings(page)
		await expect(page.locator('.default-agent__select .vs__selected')).toContainText(chosen)
	})

	test('clearing the default removes it', async ({ page }) => {
		await login(page)
		await page.goto('/apps/hermiq/', { waitUntil: 'domcontentloaded' })
		await dismissWalkthrough(page)
		await openSettings(page)

		const select = page.locator('.default-agent__select')
		await expect(select).toBeVisible()

		// The clear affordance only exists when something is selected; ensure a selection
		// first (idempotent — if one is already set, this just reasserts it).
		if (await select.locator('.vs__clear').count() === 0) {
			await chooseFirstAgent(page)
		}

		await expect(select.locator('.vs__clear')).toBeVisible()
		await select.locator('.vs__clear').click()

		// The user-visible outcome of clearing: the select shows its placeholder again, no
		// chosen value. (Asserted directly rather than via the transient save toast.)
		await expect(select.locator('.vs__selected')).toHaveCount(0)

		// And the cleared state survives a reload — the preference was actually removed.
		await page.reload({ waitUntil: 'domcontentloaded' })
		await dismissWalkthrough(page)
		await openSettings(page)
		await expect(page.locator('.default-agent__select .vs__selected')).toHaveCount(0)
	})
})
