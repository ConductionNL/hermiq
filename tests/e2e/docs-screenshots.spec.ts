/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Documentation screenshot capture suite — hermiq.
 *
 * This spec is *not* a regression test — it drives the app's UI
 * through every flow documented under `docs/tutorials/{user,admin}/*.md`
 * and writes a fresh PNG into `docs/static/screenshots/tutorials/<track>/`
 * for each step the markdown references.
 *
 * Run manually whenever the UI changes and tutorial screenshots need
 * to be refreshed:
 *
 *     NEXTCLOUD_URL=http://localhost:8080 \
 *       npx playwright test --project docs-capture
 *
 * Excluded from the default regression run via the `docs-capture`
 * project flag in `playwright.config.ts` so PR pipelines don't
 * reshoot screenshots on every push.
 *
 * The tests below are SKELETONS — selectors are TODOs the team fills
 * in once the relevant Vue components have stable `data-testid`
 * attributes. Add a story by appending a new `test(...)` block — see
 * `/journeydoc-add-story`. Add testids with `/journeydoc-instrument`.
 *
 * Pattern reference: ADR-030 (hydra/openspec/architecture/).
 */

import { test, type Page } from '@playwright/test'
import * as path from 'path'
import * as fs from 'fs'

const SHOT_ROOT = path.resolve(
	__dirname,
	'..',
	'..',
	'docs',
	'static',
	'screenshots',
	'tutorials',
)

/**
 * Save a screenshot under
 * `docs/static/screenshots/tutorials/<track>/<file>`.
 * Lives under `static/` so Docusaurus copies the PNG into the build
 * root — markdown image refs use `/screenshots/...` (root-absolute).
 *
 * @param page The Playwright page to capture.
 * @param track The tutorial track subdirectory (user or admin).
 * @param file The PNG file name to write.
 */
async function shoot(
	page: Page,
	track: 'user' | 'admin',
	file: string,
): Promise<void> {
	const dir = path.join(SHOT_ROOT, track)
	if (!fs.existsSync(dir)) {
		fs.mkdirSync(dir, { recursive: true })
	}
	await page.screenshot({
		path: path.join(dir, file),
		fullPage: false,
		type: 'png',
	})
}

// Capture flows are independent — each test re-navigates from
// `/apps/hermiq/` so a selector miss on one doesn't cascade.
// Selector misses are the expected first-run failure mode (UI markup
// drifts faster than docs); failures land per-test in `test-results/`
// rather than killing the suite.
test.describe.configure({ mode: 'default' })

const NC_USER = process.env.NC_USER || 'admin'
const NC_PASS = process.env.NC_PASS || 'admin'

/**
 * Log the configured user in through Nextcloud's real login form.
 *
 * Idempotent — when a session already exists the form is absent and we
 * return without acting. Without this the whole capture suite silently
 * "passed" while every PNG was a screenshot of the LOGIN page.
 *
 * @param page The Playwright page.
 */
async function login(page: Page): Promise<void> {
	await page.goto('/login', { waitUntil: 'domcontentloaded' })
	const userField = page.locator('#user')
	if ((await userField.count()) === 0) {
		return
	}
	await userField.fill(NC_USER)
	await page.locator('#password').fill(NC_PASS)
	await page.locator('button[type="submit"], input[type="submit"]').first().click()
	// Nextcloud holds persistent long-poll connections, so 'networkidle'
	// never fires; the login field detaching is the "logged in" signal.
	await page.locator('#user').waitFor({ state: 'hidden', timeout: 30_000 })
}

test.beforeEach(async ({ page }) => {
	page.setViewportSize({ width: 1280, height: 800 })
	await login(page)
	await page.goto('/apps/hermiq/')
})

// ---------------------------------------------------------------------------
// USER TRACK — see docs/tutorials/user/
// ---------------------------------------------------------------------------

test.describe('docs: user track', () => {
	test('UN first-launch', async ({ page }) => {
		// docs/tutorials/user/01-first-launch.md
		/* TODO: see /journeydoc-add-story — capture each numbered step.
		   Add data-testids first via /journeydoc-instrument. */
		await shoot(page, 'user', '01-first-launch.png')
	})
})

// ---------------------------------------------------------------------------
// ADMIN TRACK — see docs/tutorials/admin/
// ---------------------------------------------------------------------------

test.describe('docs: admin track', () => {
	test.beforeEach(async ({ page }) => {
		await page.goto('/settings/admin/hermiq', { waitUntil: 'domcontentloaded' })
		// Nextcloud holds persistent long-poll connections, so 'networkidle'
		// NEVER fires and would burn the whole test timeout (same trap the
		// login helper above documents). Wait for a rendered settings
		// section instead.
		await page
			.locator('.settings-section, #app-content, #content-vue')
			.first()
			.waitFor({ state: 'visible', timeout: 30_000 })
	})

	test('AN admin-settings', async ({ page }) => {
		// docs/tutorials/admin/01-admin-settings.md
		/* TODO: see /journeydoc-add-story — capture each numbered step.
		   Add data-testids first via /journeydoc-instrument. */
		await shoot(page, 'admin', '01-admin-settings.png')
	})
})
