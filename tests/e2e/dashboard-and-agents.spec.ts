/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Regression e2e — hermiq dashboard + agents (playwright-regression-coverage).
 *
 * The FIRST real assertion-bearing regression spec for Hermiq: it logs a user in through
 * Nextcloud's real login form, opens the Hermiq app, asserts the app shell renders, then
 * navigates to the Agents view and asserts its heading renders — all while collecting
 * page console errors and failing if any surfaced. Unlike docs-screenshots.spec.ts (a
 * screenshot skeleton excluded from the default project), this spec runs in the default
 * `chromium` project so PR pipelines actually drive the browser against these flows.
 *
 * Run against a running Nextcloud with Hermiq + OpenRegister installed:
 *
 *     NEXTCLOUD_URL=http://localhost:8080 \
 *       NC_USER=admin NC_PASS=admin \
 *       npx playwright test --project chromium
 *
 * Covers: openspec/specs/dashboard-page/spec.md (Dashboard renders) and part of
 * openspec/specs/agent-management-ui/spec.md (Agents list renders).
 */

import { test, expect, type Page } from '@playwright/test'

const NC_USER = process.env.NC_USER || 'admin'
const NC_PASS = process.env.NC_PASS || 'admin'

/**
 * Log the configured user in through Nextcloud's real login form.
 *
 * Idempotent: if a storage-state / already-authenticated session lands us straight in the
 * app, the login form is absent and we return without acting.
 *
 * @param page The Playwright page.
 */
async function login(page: Page): Promise<void> {
	await page.goto('/login', { waitUntil: 'domcontentloaded' })

	const userField = page.locator('#user')
	if (await userField.count() === 0) {
		// Already authenticated (redirected past the login form).
		return
	}

	await userField.fill(NC_USER)
	await page.locator('#password').fill(NC_PASS)
	await page.locator('button[type="submit"], input[type="submit"]').first().click()
	// Nextcloud holds persistent long-poll connections, so 'networkidle' never fires; the
	// login field detaching once we land past the form is the unambiguous "logged in" signal.
	await page.locator('#user').waitFor({ state: 'hidden', timeout: 30_000 })
}

/**
 * Collect console errors on a page so a flow can assert none surfaced.
 *
 * Nextcloud emits benign warnings; we only capture `error`-level messages and filter the
 * well-known noisy favicon/manifest 404s that are unrelated to the app under test.
 *
 * @param page The Playwright page.
 * @return A live array that accumulates error message strings.
 */
function collectConsoleErrors(page: Page): string[] {
	const errors: string[] = []
	page.on('console', (msg) => {
		if (msg.type() !== 'error') {
			return
		}
		const text = msg.text()
		if (/favicon|manifest\.json|the server responded with a status of 404/i.test(text)) {
			return
		}
		errors.push(text)
	})
	return errors
}

test.describe('hermiq regression: dashboard + agents', () => {
	test('logs in, opens the app, and renders the Agents view without console errors', async ({ page }) => {
		const errors = collectConsoleErrors(page)

		await login(page)

		// The Hermiq app shell renders (its nav lists the Agents entry).
		await page.goto('/apps/hermiq/', { waitUntil: 'domcontentloaded' })
		await expect(page.locator('#app-content, .app-hermiq, [data-testid="agent-catalog"]').first()).toBeVisible()

		// Navigate to the Agents view and assert its heading renders. The app uses Vue
		// history mode (ADR-004), so the route is a real path, not a `#/` hash fragment.
		await page.goto('/apps/hermiq/agents', { waitUntil: 'domcontentloaded' })
		const heading = page.locator('[data-testid="agent-catalog-heading"]')
		await expect(heading).toBeVisible({ timeout: 10_000 })
		await expect(heading).toContainText('Agent')

		// No app-level console errors surfaced across the flow.
		expect(errors, `Unexpected console errors: ${errors.join(' | ')}`).toHaveLength(0)
	})

	test('renders the Dashboard quota-usage widget matching a direct API call (dashboard-org-widgets)', async ({ page }) => {
		await login(page)

		// The admin login used across this spec is an instance admin, so
		// `can_manage_killswitch` is true and QuotaUsageWidget renders
		// (dashboard-org-widgets: relocated from TenantOps.vue onto the Dashboard).
		await page.goto('/apps/hermiq/', { waitUntil: 'domcontentloaded' })

		const widget = page.locator('[data-testid="quota-usage-widget"]')
		await expect(widget).toBeVisible({ timeout: 10_000 })

		const schedulesCard = widget.locator('[data-testid="quota-schedules-card"]')
		const agentsCard = widget.locator('[data-testid="quota-agents-card"]')
		await expect(schedulesCard).toBeVisible()
		await expect(agentsCard).toBeVisible()

		// Cross-check the rendered values against a direct call to the same
		// endpoint the widget fetches (GET /api/tenant-ops/quota), sharing the
		// browser context's authenticated session.
		const response = await page.request.get('/apps/hermiq/api/tenant-ops/quota')
		expect(response.ok()).toBeTruthy()
		const quota = await response.json()

		await expect(schedulesCard).toContainText(`${quota.schedules.count} / ${quota.schedules.limit}`)
		await expect(agentsCard).toContainText(`${quota.agents.count} / ${quota.agents.limit}`)
	})
})
