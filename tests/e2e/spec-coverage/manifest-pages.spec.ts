/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Manifest-driven page smoke sweep (spec-coverage, gate-19).
 *
 * hermiq declares 19 pages in `src/manifest.json` (mix of index / detail /
 * dashboard / custom / roadmap), rendered by CnPageRenderer with
 * HISTORY-mode routing (createWebHistory, src/main.js) — so every route is
 * PATH-form (`/apps/hermiq/chat`); a hash-form deep-link would be silently
 * swallowed by the catch-all redirect and every page would greenwash
 * against the Dashboard (the redirect-away guard below catches exactly
 * that failure mode).
 *
 * This spec is generated FROM the manifest at spec load time — add a page
 * to the manifest and it is automatically smoke tested; no hand-maintained
 * route list to drift. For every NON-parameterised route it asserts:
 *   - the SPA shell mounts (#app-content visible)
 *   - the router is still ON the requested route (redirect-away guard)
 *   - real content rendered (innerHTML > 100 chars)
 *   - no app-origin console errors during initial mount (curated filter)
 *
 * `:id`-parameterised routes (AgentDetail, EvalDatasetDetail, GraphDetail)
 * are excluded — a detail page needs a real object; the seeded AgentDetail
 * journey lives in agents-approvals.spec.ts. NOTE: GraphDetail hosts the
 * GraphBuilder canvas, which carries a known Vue-major render risk — it is
 * excluded here because it is parameterised, not because of that risk.
 *
 * Covered openspec scenario (@e2e back-reference lives in the spec file):
 *   - openspec/specs/dashboard-page/spec.md
 *       #### Scenario: Deep link to an in-app route
 *
 * Pattern reference: hrmq/tests/e2e/spec-coverage/manifest-pages.spec.ts
 * Auth: shared storageState session (tests/e2e/global-setup.ts).
 */

import { test, expect, type Page, type ConsoleMessage } from '@playwright/test'
import * as fs from 'fs'
import * as path from 'path'

/* --------------------------------------------------------------------- *
 *  Manifest loading (+ optional manifest.d fragments)
 * --------------------------------------------------------------------- */

interface ManifestPage {
	id: string
	route: string
	type: string
	title?: string
}

interface Manifest {
	pages: ManifestPage[]
	menu?: unknown[]
}

/**
 * Read `src/manifest.json` and merge any `src/manifest.d/*.json` fragments
 * (the directory does not currently exist, but the merge keeps this spec
 * correct the day it does).
 */
function loadManifest(): Manifest {
	const srcDir = path.resolve(__dirname, '..', '..', '..', 'src')
	const manifest = JSON.parse(fs.readFileSync(path.join(srcDir, 'manifest.json'), 'utf-8')) as Manifest
	const fragmentsDir = path.join(srcDir, 'manifest.d')
	if (fs.existsSync(fragmentsDir)) {
		for (const file of fs.readdirSync(fragmentsDir).filter((f) => f.endsWith('.json')).sort()) {
			const fragment = JSON.parse(fs.readFileSync(path.join(fragmentsDir, file), 'utf-8')) as Partial<Manifest>
			if (Array.isArray(fragment.pages)) {
				manifest.pages.push(...fragment.pages)
			}
		}
	}
	return manifest
}

const MANIFEST = loadManifest()

/** All pages whose route needs no path parameter — smoke-testable as-is. */
const SMOKE_PAGES = MANIFEST.pages.filter((p) => !p.route.includes(':'))

/** Parameterised (detail) pages — excluded here, counted for the sanity test. */
const PARAM_PAGES = MANIFEST.pages.filter((p) => p.route.includes(':'))

/* --------------------------------------------------------------------- *
 *  App root resolution
 * --------------------------------------------------------------------- */

// In Nextcloud installs with `htaccess.RewriteBase => '/'` (the apache dev
// container default) `generateUrl` returns `/apps/hermiq` and any
// `/index.php/`-prefixed URL sits outside the router base. In a php -S
// install (no htaccess processing) the inverse is true. Resolve at runtime.
const ROOT_CANDIDATES = ['/apps/hermiq', '/index.php/apps/hermiq']
let _root: string | null = null
async function rootUrl(page: Page): Promise<string> {
	if (_root) return _root
	for (const candidate of ROOT_CANDIDATES) {
		const res = await page.request.get(`${candidate}/`, { failOnStatusCode: false })
		if (res.ok() && (await res.text()).includes('hermiq-main.js')) {
			_root = candidate
			return candidate
		}
	}
	throw new Error('Neither /apps nor /index.php form serves the hermiq SPA shell')
}

/* --------------------------------------------------------------------- *
 *  Console noise filter
 * --------------------------------------------------------------------- */

/**
 * Errors we ignore — these come from Nextcloud's own bootstrap or the
 * shared dev instance's known platform quirks, not from hermiq.
 */
const IGNORED_CONSOLE_PATTERNS: RegExp[] = [
	/Deprecation/i,
	/Slow network is detected/i,
	/favicon/i,
	/the resource at .* was preloaded using link preload but not used/i,
	// The user_status app 500s on dev instances with a PostgreSQL collation
	// version mismatch — pre-existing platform noise unrelated to hermiq.
	/Failed to load user status/i,
	/user_status/i,
	/the server responded with a status of 500/i,
	// Missing avatars / previews on a fresh instance log 404 resource errors.
	/Failed to load resource:.*Not Found/i,
	// NC theming: when the active theme's token CSS is briefly unavailable
	// mid-run it serves the 404 HTML page, tripping a MIME-type refusal.
	/Refused to apply style/i,
	/is not a supported stylesheet MIME type/i,
]

function attachConsoleSpy(page: Page): { errors: string[] } {
	const errors: string[] = []
	page.on('console', (msg: ConsoleMessage) => {
		const text = msg.text()
		if (IGNORED_CONSOLE_PATTERNS.some((rx) => rx.test(text))) {
			return
		}
		if (msg.type() === 'error') {
			errors.push(text.slice(0, 300))
		}
	})
	page.on('pageerror', (err) => {
		errors.push(`pageerror: ${err.message}`)
	})
	return { errors }
}

/* --------------------------------------------------------------------- *
 *  Parametrized smoke tests
 * --------------------------------------------------------------------- */

test.describe('manifest pages — schema-driven render', () => {

	test('manifest sanity: page partition covers every declared page', () => {
		// If this fails the manifest changed shape and the smoke loop below
		// is silently under-covering — fail loudly instead.
		expect(MANIFEST.pages.length, 'manifest declares pages').toBeGreaterThan(0)
		expect(SMOKE_PAGES.length + PARAM_PAGES.length).toBe(MANIFEST.pages.length)
		expect(SMOKE_PAGES.length, 'non-parameterised pages to smoke').toBeGreaterThan(0)
	})

	test('SPA shell is served with every webpack chunk the mount depends on', async ({ page }) => {
		// Deployment-level assertion that needs NO rendered app DOM, so it is
		// verifiable against the currently-deployed bundle. templates/index.php
		// registers three chunks in dependency order; the main entry's
		// `__webpack_require__.O` callback only fires once ALL of them have
		// registered, so a missing shared chunk silently prevents the Vue mount
		// (the zaakafhandelapp#206 failure mode the template docblock records).
		const root = await rootUrl(page)
		const res = await page.request.get(`${root}/`, { failOnStatusCode: false })
		expect(res.ok(), `SPA shell HTTP ${res.status()}`).toBeTruthy()
		const html = await res.text()
		for (const chunk of ['hermiq-shared-vendor', 'hermiq-shared-nc-vue', 'hermiq-main']) {
			expect(html, `SPA shell must load the ${chunk} chunk`).toContain(chunk)
		}
	})

	for (const pg of SMOKE_PAGES) {
		// PARKED — requires nc-vue selector hooks present only in builds after
		// 2026-07-25 — unpark after the next hermiq deploy.
		// STATIC evidence: the deployed nc-vue chunk (2026-07-25 22:13) was
		// built from node_modules/@conduction/nextcloud-vue/dist (the PUBLISHED
		// dist) rather than the LOCAL_LIB source — the configuration
		// webpack.config.js records as making "CnAppRoot render nothing at all
		// — silently, with zero console errors".
		// NOT yet confirmed live: a read-only probe on 2026-07-27 observed an
		// empty `.hermiq-root`, but the shared instance later reported
		// needsDbUpgrade:true, so that observation is unusable as proof.
		// Re-verify on a healthy instance before concluding an app defect.
		test(`[${pg.type}] ${pg.id} mounts at ${pg.route}`, async ({ page }) => {
			const { errors } = attachConsoleSpy(page)

			const root = await rootUrl(page)
			// HISTORY mode → PATH-form deep link. `domcontentloaded`, not
			// `networkidle` — NC's notification poll never goes idle.
			await page.goto(`${root}${pg.route}`, { waitUntil: 'domcontentloaded', timeout: 30_000 })

			// The Nextcloud SPA shell mounts inside #app-content.
			await expect(page.locator('#app-content, [data-cy=app-content], .app-content').first()).toBeVisible({ timeout: 15_000 })

			// Route identity: the router must still be ON the requested route.
			// A redirect back to the default page (greenwash mode) changes the
			// pathname and must fail the smoke test.
			expect(new URL(page.url()).pathname, `${pg.id} was redirected away from ${pg.route}`).toContain(pg.route)

			// CnPageRenderer should have resolved the route to *some* page
			// component. Verify anything rendered beyond the loading spinner.
			const renderedContent = await page.locator('#app-content, .app-content').first().innerHTML()
			expect(renderedContent.length, `${pg.id} (${pg.route}) rendered no content inside app-content`).toBeGreaterThan(100)

			// No fatal console errors during initial mount.
			expect(errors, `${pg.id} (${pg.route}) emitted console errors: ${errors.join(' | ')}`).toEqual([])
		})
	}

})
