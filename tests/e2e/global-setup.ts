/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright globalSetup — logs into Nextcloud once and persists the
 * resulting cookie jar / localStorage to `tests/e2e/.auth/admin.json`.
 * Every spec then reuses that storage state via the `use.storageState`
 * setting in playwright.config.ts, so individual tests start from an
 * authenticated session without each one paying the login cost.
 *
 * Ported from docudesk/tests/e2e/global-setup.ts (NC34-safe selectors,
 * status.php health poll). Pattern reference: ADR-030.
 */

import type { FullConfig } from '@playwright/test'

import { chromium, request } from '@playwright/test'
import { execSync } from 'child_process'
import * as fs from 'fs'
import * as path from 'path'

const AUTH_DIR = path.resolve(__dirname, '.auth')
const STORAGE_STATE = path.join(AUTH_DIR, 'admin.json')
const APP_ROOT = path.resolve(__dirname, '..', '..')
const BUNDLE_PATH = path.join(APP_ROOT, 'js', 'hermiq-main.js')

/**
 * Ensure the webpack bundle exists before specs hit `/apps/hermiq/`.
 *
 * The shared `ConductionNL/.github/quality.yml` Playwright job runs
 * `npm ci` + `npx playwright install` before the spec run, but never
 * `npm run build`. On a fresh CI VM the `js/hermiq-main.js` artefact
 * doesn't exist, so the rendered page loads a 404 script tag and the
 * Vue app never mounts — every selector wait then times out.
 *
 * Locally, the dev container typically serves its own mounted checkout,
 * so this step is a no-op when the bundle is already present.
 */
function ensureBundleBuilt(): void {
	if (fs.existsSync(BUNDLE_PATH)) {
		return
	}

	console.log(
		`[playwright globalSetup] bundle missing at ${BUNDLE_PATH}; running 'npm run build' once…`,
	)
	execSync('npm run build', { cwd: APP_ROOT, stdio: 'inherit' })
}

/**
 * Wait until Nextcloud is actually serving requests.
 *
 * A shared dev instance is routinely mid-flight: another deploy flips it into
 * maintenance mode, an app version bump sets needsDbUpgrade (which makes NC
 * answer 503 on every route), or Postgres is still finishing crash recovery.
 * All three are transient and clear within minutes, but a single-shot check
 * turns them into a hard suite failure.
 *
 * Poll until the instance reports installed, out of maintenance and not
 * awaiting a DB upgrade. Tune with E2E_HEALTH_TIMEOUT_MS (default 10 min).
 *
 * @param baseURL Instance base URL.
 * @return Resolves once healthy; rejects on timeout.
 */
async function ensureNextcloudReachable(baseURL: string): Promise<void> {
	const deadline =
		Date.now() + Number(process.env.E2E_HEALTH_TIMEOUT_MS || 600_000)
	const ctx = await request.newContext()
	let last = 'no response yet'
	try {
		while (Date.now() < deadline) {
			try {
				const res = await ctx.get(`${baseURL}/status.php`, {
					failOnStatusCode: false,
				})
				if (res.ok()) {
					const body = await res.json().catch(() => ({}))
					if (
						body
						&& body.installed === true
						&& body.maintenance === false
						&& body.needsDbUpgrade === false
					) {
						return
					}
					last = `status.php = ${JSON.stringify(body)}`
				} else {
					// 503 while an app upgrade is pending, 500 while the DB recovers.
					last = `status.php returned ${res.status()}`
				}
			} catch (err) {
				last = `request failed: ${(err as Error).message}`
			}

			await new Promise((resolve) => setTimeout(resolve, 5_000))
		}
		throw new Error(
			`Nextcloud at ${baseURL} did not become healthy in time — last seen: ${last}. `
				+ 'Check for a concurrent deploy (occ upgrade), maintenance mode, or a recovering database.',
		)
	} finally {
		await ctx.dispose()
	}
}

export default async function globalSetup(config: FullConfig): Promise<void> {
	const baseURL =
		(config.projects[0]?.use?.baseURL as string | undefined)
		?? process.env.NEXTCLOUD_URL
		?? process.env.NC_BASE_URL
		?? 'http://localhost:8080'
	const username = process.env.NC_ADMIN_USER ?? process.env.NC_USER ?? 'admin'
	const password = process.env.NC_ADMIN_PASS ?? process.env.NC_PASS ?? 'admin'

	ensureBundleBuilt()
	await ensureNextcloudReachable(baseURL)
	fs.mkdirSync(AUTH_DIR, { recursive: true })

	const browser = await chromium.launch()
	const context = await browser.newContext({ baseURL })
	const page = await context.newPage()

	// The instance can flip back into maintenance between the health check and
	// this navigation; re-check health and retry rather than failing the suite.
	for (let attempt = 1; ; attempt++) {
		try {
			await page.goto('/index.php/login')
			break
		} catch (err) {
			if (attempt >= 3) {
				throw err
			}
			await ensureNextcloudReachable(baseURL)
		}
	}
	// Nextcloud's login form is client-rendered and its markup has drifted
	// between releases: on NC 34 the fields carry `id="user"` / `id="password"`
	// but no `name` attribute, so a `input[name="user"]` selector never resolves
	// and globalSetup times out. Match either shape, and wait for the field to
	// be attached first.
	const userField = page.locator('input#user, input[name="user"]').first()
	const passwordField = page
		.locator('input#password, input[name="password"]')
		.first()
	await userField.waitFor({ state: 'visible', timeout: 30_000 })
	// The login form is a Vue app: the markup exists before its submit handler
	// is attached, so clicking too early silently does nothing and the page
	// simply stays on /login.
	//
	// This used to wait for 'networkidle', which never settles on Nextcloud
	// (ADR-074 rule 4) — the shell keeps long-polling, so the wait always ran to
	// its timeout and only "worked" because the .catch() swallowed it, i.e. it
	// was a disguised fixed delay, not a readiness signal. Wait for the actual
	// precondition instead: the submit control being present and enabled, which
	// is what the mounted Vue app produces.
	await page
		.locator('button[type="submit"]')
		.first()
		.waitFor({ state: 'visible', timeout: 30_000 })
	await page
		.waitForFunction(
			() => {
				const btn = document.querySelector('button[type="submit"]')
				return !!btn && !btn.hasAttribute('disabled')
			},
			undefined,
			{ timeout: 30_000 },
		)
		.catch(() => {})
	await userField.fill(username)
	await passwordField.fill(password)
	// Bind the navigation wait BEFORE clicking, so a fast redirect cannot be
	// missed between the click returning and the wait starting.
	await Promise.all([
		page
			.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 60_000 })
			.catch(() => {}),
		page.locator('button[type="submit"]').first().click(),
	])
	// Wait for the authenticated shell. NC 34 no longer guarantees the legacy
	// `#header` / `header.header` markup, so accept any banner-role header and
	// give the (slow, shared) instance room to finish the post-login redirect.
	await page.waitForURL((url) => /\/login(\?|$|\/)/.test(url.pathname) === false, {
		timeout: 60_000,
	})
	await page.waitForSelector('#header, header.header, header, [role="banner"]', {
		timeout: 60_000,
	})
	const currentUrl = page.url()
	if (/\/login(\?|$|\/)/.test(currentUrl)) {
		throw new Error(
			`Login appears to have failed — still on ${currentUrl}. `
				+ 'Check NC_ADMIN_USER / NC_ADMIN_PASS (defaults admin/admin).',
		)
	}

	/*
	 * Suppress the product walkthrough (ADR-043) for automated runs, the way
	 * dossiq's global-setup already does.
	 *
	 * This became load-bearing with @conduction/nextcloud-vue 2.22.x. A
	 * `placement: "center"` welcome step used to be parked in `_pendingAutoTour`
	 * and never opened; the library now correctly starts it on any route, so the
	 * tour actually appears — and its `cn-walkthrough__dim--full` layer is a
	 * `role="dialog" aria-modal="true"` overlay that intercepts every click
	 * behind it. Specs that had never had to account for a tour started timing
	 * out, and `getByRole('dialog').first()` began resolving to the dim layer
	 * instead of the modal under test.
	 *
	 * The marker is per USER, not per test, so without it the suite is also
	 * order-dependent: whichever spec runs first wears the tour and the rest
	 * inherit a dismissed one.
	 *
	 * The sentinel is higher than any real app version, so every step's
	 * `sinceVersion` sorts below it and the tour composes to an empty step set
	 * rather than merely starting dismissed. The page is already on the instance
	 * origin after login, which is the origin storageState persists.
	 */
	try {
		await page.evaluate(() => {
			try {
				window.localStorage.setItem('cn-walkthrough-seen:hermiq', '999.0.0')
			} catch {
				// localStorage unavailable — specs fall back to dismissing by hand.
			}
		})
	} catch {
		// Never fail setup over an optional convenience.
	}

	await context.storageState({ path: STORAGE_STATE })
	await browser.close()
}
