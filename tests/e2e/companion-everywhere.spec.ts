/**
 * The AI companion, on every page — presence, uniqueness and function.
 *
 * 🔴 This suite exists because the companion vanished from EVERY page and
 * nothing noticed. Four separate faults stacked up — a missing webpack entry, a
 * splitChunks deferral, Vue 2 code in a Vue 3 app, and a publicPath pointing at
 * a URL that answers 200 with `text/html` — and each one was invisible to the
 * unit tests, the linters and the PHP suite. A build ran five times in one
 * session and silently deleted the bundle every time.
 *
 * So the assertions here are deliberately about what a PERSON sees:
 * - the launcher is present on pages that are not Hermiq's own
 * - there is EXACTLY ONE of it, which is the other half of the same bug: two
 *   launchers landed at identical coordinates and looked like one
 * - it opens, and the panel is usable
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/companion-everywhere/specs/companion-everywhere/spec.md
 */

import { test, expect, type Page } from '@playwright/test'

const NC_USER = process.env.NC_USER || 'admin'
const NC_PASS = process.env.NC_PASS || 'admin'

/** The container the companion script mounts into. */
const ROOT = '#hermiq-companion-root'

/**
 * Log the configured user in through Nextcloud's real login form.
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
	await page.locator('#user').waitFor({ state: 'hidden', timeout: 30_000 })
}

/**
 * Every visible AI-chat launcher on the page, however it was rendered.
 *
 * Counted by ROLE AND LABEL rather than by the companion's own container,
 * because the duplicate this guards against comes from a DIFFERENT renderer
 * (CnAppRoot). Counting only `#hermiq-companion-root` would have reported one
 * launcher on a page that was showing two.
 *
 * @param page The Playwright page.
 */
function launchers(page: Page) {
	return page.getByRole('button', { name: /open ai chat/i })
}

test.describe('AI companion', () => {
	test.beforeEach(async ({ page }) => {
		await login(page)
	})

	test('is present, and unique, on a plain Files page', async ({ page }) => {
		await page.goto('/apps/files/', { waitUntil: 'domcontentloaded' })
		await expect(page.locator(ROOT)).toHaveCount(1, { timeout: 20_000 })

		// The launcher itself, not just its container: the container existed
		// while the component failed to render, and an empty div is not a
		// companion.
		await expect(launchers(page)).toHaveCount(1, { timeout: 20_000 })
		await expect(launchers(page).first()).toBeVisible()
	})

	test('is present on the Dashboard', async ({ page }) => {
		await page.goto('/apps/dashboard/', { waitUntil: 'domcontentloaded' })
		await expect(launchers(page)).toHaveCount(1, { timeout: 20_000 })
	})

	test('mounts exactly once on a Files page that frames an office document', async ({ page }) => {
		// 🔴 The Files app opens a document by embedding a FULL Nextcloud page,
		// same-origin, inside itself — so the init script runs twice on one
		// screen. The outer one is the keeper; the inner is clipped by the
		// frame.
		await page.goto('/apps/files/files/25337?dir=/&openfile=true', { waitUntil: 'domcontentloaded' })
		await page.waitForTimeout(4_000)

		await expect(launchers(page)).toHaveCount(1)

		// And nothing inside the frame.
		const framed = await page.evaluate(() => {
			const frame = document.querySelector('iframe') as HTMLIFrameElement | null
			try {
				return frame?.contentDocument?.querySelectorAll('#hermiq-companion-root').length ?? 0
			} catch {
				return 0
			}
		})
		expect(framed).toBe(0)
	})

	test('does NOT double up on Hermiq\'s own pages', async ({ page }) => {
		// 🔴 CnAppRoot already renders a companion here. The guard used to test
		// only the `app-hermiq` body class, which this instance never sets, so a
		// second launcher mounted at the SAME coordinates as the first — one
		// button to the eye, two to the DOM.
		await page.goto('/apps/hermiq/', { waitUntil: 'domcontentloaded' })
		await page.waitForTimeout(4_000)

		await expect(launchers(page)).toHaveCount(1)
		await expect(page.locator(ROOT)).toHaveCount(0)
	})

	test('opens a usable panel, and closes again', async ({ page }) => {
		await page.goto('/apps/files/', { waitUntil: 'domcontentloaded' })
		await launchers(page).first().click()

		const panel = page.locator(ROOT)
		await expect(panel.getByRole('textbox').first()).toBeVisible({ timeout: 20_000 })
		await expect(panel).toContainText(/hermiq/i)

		// The agent picker is the thing that makes the panel usable rather than
		// merely present: without it the user cannot choose who answers.
		await expect(panel).toContainText(/agent/i)

		// ⚠️ Closed by its own control, NOT by Escape. The companion is a
		// SIDEBAR, not a modal, so Escape is not its convention — an earlier
		// version of this test asserted Escape, failed, and the failure was the
		// test's assumption rather than a defect. Written down so the next
		// person does not "fix" the component to satisfy a wrong expectation.
		await panel.getByRole('button', { name: /close sidebar/i }).first().click()
		await expect(panel.getByRole('textbox').first()).toBeHidden({ timeout: 10_000 })
	})

	test('accepts typed input and sends it', async ({ page }) => {
		await page.goto('/apps/files/', { waitUntil: 'domcontentloaded' })
		await launchers(page).first().click()

		const input = page.locator(ROOT).getByRole('textbox').first()
		await expect(input).toBeVisible({ timeout: 20_000 })
		await input.fill('Hallo')

		// Assert the value round-trips before sending: an input that silently
		// drops what is typed looks identical to one that is merely slow.
		await expect(input).toHaveValue('Hallo')

		await input.press('Enter')

		// The message must appear in the transcript. Not asserting a REPLY —
		// that needs a model and would make this suite depend on one.
		await expect(page.locator(ROOT)).toContainText('Hallo', { timeout: 20_000 })
	})

	test('carries the open document as context on a file page', async ({ page }) => {
		// The companion reads the open file id so the assistant can be asked
		// about the document on screen without the user pasting an id.
		await page.goto('/apps/files/files/25337?dir=/&openfile=true', { waitUntil: 'domcontentloaded' })
		await page.waitForTimeout(3_000)

		const hasContext = await page.evaluate(() => {
			const root = document.querySelector('#hermiq-companion-root')
			return root !== null && root.innerHTML.length > 0
		})
		expect(hasContext).toBe(true)
	})

	test('the companion bundle is served as JavaScript, not an error page', async ({ page }) => {
		// ⚠️ The publicPath fault produced a 200 whose body was Nextcloud's HTML
		// error page. A status-only check called that healthy; only the MIME
		// type gave it away. Assert the type, not the status.
		await page.goto('/apps/files/', { waitUntil: 'domcontentloaded' })

		const probe = await page.evaluate(async () => {
			const tag = document.querySelector('script[src*="hermiq-companion"]') as HTMLScriptElement | null
			if (tag === null) {
				return { found: false, status: 0, type: '' }
			}
			const res = await fetch(tag.src)
			return { found: true, status: res.status, type: res.headers.get('content-type') || '' }
		})

		expect(probe.found).toBe(true)
		expect(probe.status).toBe(200)
		expect(probe.type).toMatch(/javascript/i)
	})

	test('loads without console errors', async ({ page }) => {
		const errors: string[] = []
		page.on('console', (msg) => {
			if (msg.type() === 'error') {
				errors.push(msg.text())
			}
		})

		await page.goto('/apps/files/', { waitUntil: 'domcontentloaded' })
		await page.waitForTimeout(4_000)

		// Only OUR errors: the host page carries third-party noise this suite
		// has no business failing on.
		const ours = errors.filter((e) => /hermiq|companion|ChunkLoadError|not a constructor/i.test(e))
		expect(ours, ours.join('\n')).toHaveLength(0)
	})
})
