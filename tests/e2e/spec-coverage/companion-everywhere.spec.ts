/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The companion on every page (companion-everywhere): exactly one on Hermiq's own
 * pages, exactly one on a page Hermiq does not own, in its own container.
 *
 * WHAT THE OTHER TESTS CANNOT SHOW. The build only proves `js/hermiq-companion.js` is
 * produced. Whether the init script is registered, declines on Hermiq's pages (where
 * CnAppRoot already renders one) and mounts on another app's page can only be seen in
 * a running Nextcloud, so it is here.
 *
 * The Files app stands in for "a page Hermiq does not own": it is always installed and
 * shows no file on its landing page. The office-editor scenario needs a document open
 * in an office suite and is not covered here.
 *
 * Not run when written: it needs a live instance. Runs in the nightly.
 *
 * @e2e companion-everywhere::hermiqs-own-pages-get-exactly-one-companion
 * @e2e companion-everywhere::a-page-with-no-file-passes-none-without-error
 * @e2e companion-everywhere::the-host-pages-dom-is-untouched
 *
 * @spec openspec/changes/companion-everywhere/specs/companion-everywhere/spec.md
 */

import { expect, test } from '@playwright/test'
import { appRoot, collectHermiqConsoleErrors, dismissTour, harvestToken } from './_fixtures.ts'

const COMPANION = '[data-testid="cn-ai-companion"]'
const GLOBAL_ROOT = '#hermiq-companion-root'

test.describe('The companion on every page', () => {
	test.beforeEach(async ({ page }) => {
		await harvestToken(page)
	})

	test("Hermiq's own pages get exactly one companion, from CnAppRoot", async ({ page }) => {
		await page.goto(`${await appRoot(page)}/`, { waitUntil: 'domcontentloaded' })
		await dismissTour(page)
		await expect(page.locator(COMPANION).first()).toBeAttached({ timeout: 30_000 })
		// Give the global bundle time to have mounted, if it wrongly would.
		await page.waitForTimeout(3_000)
		await expect(page.locator(COMPANION)).toHaveCount(1)
		await expect(page.locator(GLOBAL_ROOT), 'the global bundle declines on Hermiq pages').toHaveCount(0)
	})

	test('a page Hermiq does not own gets exactly one, in its own container, without an error', async ({ page }) => {
		const errors = collectHermiqConsoleErrors(page)
		await page.goto('/index.php/apps/files/', { waitUntil: 'domcontentloaded' })

		await expect(page.locator(GLOBAL_ROOT)).toHaveCount(1, { timeout: 30_000 })
		await expect(page.locator(COMPANION)).toHaveCount(1, { timeout: 30_000 })

		const placement = await page.evaluate((selector) => {
			const root = document.querySelector(selector)
			return {
				parentIsBody: root?.parentElement === document.body,
				companionInside: root?.querySelector('[data-testid="cn-ai-companion"]') !== null,
			}
		}, GLOBAL_ROOT)
		expect(placement.parentIsBody, 'the container is appended to body, not to a host element').toBe(true)
		expect(placement.companionInside, 'the companion renders inside its own container').toBe(true)

		await page.waitForTimeout(2_000)
		await expect(page.locator(COMPANION), 'a later evaluation does not stack a second one').toHaveCount(1)
		expect(errors, 'no file on the page is normal and silent').toEqual([])
	})
})
