/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Regression e2e — hermiq wave-2 surfaces (playwright-regression-coverage).
 *
 * Assertion-bearing browser regression for the three richest wave-2 nav pages, each of
 * which is validated ONLY by PHPUnit-against-controllers otherwise:
 *
 *   - Evaluations (agent-evals): the page renders, and the create-dataset modal round-trips
 *     an EvalDataset through OpenRegister — the dataset persists and re-renders as a card.
 *   - Compliance (compliance-control-packs): the dashboard renders per-framework control
 *     tables whose statuses are COMPUTED from live governance data (never hand-ticked), so
 *     the page must show real control rows, not an empty shell.
 *   - Agent templates (agent-template-gallery): the SeedAgentTemplates repair step's five
 *     starter templates render.
 *
 * All three run in the default `chromium` project (PR pipelines drive the browser), and
 * every flow fails if any app-level console error surfaces.
 *
 * Run against a running Nextcloud with Hermiq + OpenRegister installed:
 *
 *     NEXTCLOUD_URL=http://localhost:8080 \
 *       NC_USER=admin NC_PASS=admin \
 *       npx playwright test --project chromium wave2-surfaces
 *
 * Covers scenarios in openspec/specs/{run-analytics,multi-tenant-ops,skills-catalog}/spec.md's
 * wave-2 siblings (agent-evals / compliance-control-packs / agent-template-gallery, archived).
 */

import { test, expect, type Page } from '@playwright/test'

const NC_USER = process.env.NC_USER || 'admin'
const NC_PASS = process.env.NC_PASS || 'admin'

/**
 * Log the configured user in through Nextcloud's real login form. Idempotent: a
 * pre-authenticated session (no login form) is a no-op.
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
	// Nextcloud holds persistent long-poll connections, so 'networkidle' never fires; the
	// login field detaching once we land past the form is the unambiguous "logged in" signal.
	await page.locator('#user').waitFor({ state: 'hidden', timeout: 30_000 })
}

/**
 * Collect app-level console errors, filtering the well-known benign favicon/manifest 404s.
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
		if (/favicon|manifest\.json|the server responded with a status of 404/i.test(text)) {
			return
		}
		errors.push(text)
	})
	return errors
}

/**
 * Close the first-run onboarding tour dialog if it is showing, so it does not overlay the
 * page under test.
 *
 * @param page The Playwright page.
 */
async function dismissTour(page: Page): Promise<void> {
	const close = page.getByRole('button', { name: 'Close tour' })
	if (await close.count() > 0) {
		await close.first().click()
	}
}

test.describe('hermiq regression: wave-2 surfaces', () => {
	test('Evaluations page round-trips a dataset through OpenRegister', async ({ page }) => {
		const errors = collectConsoleErrors(page)
		await login(page)

		await page.goto('/apps/hermiq/evals', { waitUntil: 'domcontentloaded' })
		await dismissTour(page)
		await expect(page.locator('[data-testid="evals-heading"]')).toBeVisible({ timeout: 10_000 })

		// Create a dataset with one substring case and assert it persists + re-renders.
		// Scope to the dialog and use exact labels — the modal has several textboxes
		// (Name / Prompt / Expected substring) whose accessible names would otherwise
		// collide under a loose getByRole match.
		const uniqueName = `E2E dataset ${Date.now()}`
		await page.getByRole('button', { name: 'New dataset' }).click()
		const dialog = page.getByRole('dialog', { name: 'Create dataset' })
		await dialog.getByLabel('Name', { exact: true }).fill(uniqueName)
		await dialog.getByLabel('Prompt', { exact: true }).fill('Say hello politely')
		await dialog.getByLabel('Expected substring', { exact: true }).fill('hello')
		await dialog.getByRole('button', { name: 'Save' }).click()

		// On success the modal emits close; wait for it to detach (the OR write can take a
		// while on a loaded instance) before asserting the saved dataset re-renders as a
		// card — proving the createObjectStore write path round-tripped through OpenRegister.
		await expect(dialog).toBeHidden({ timeout: 30_000 })
		await expect(page.getByText(uniqueName)).toBeVisible({ timeout: 15_000 })

		// Clean up the object we created so the test is idempotent across runs.
		await page.evaluate(async (name) => {
			const token = (window as unknown as { OC?: { requestToken?: string } }).OC?.requestToken
				|| document.head.querySelector<HTMLMetaElement>('meta[name=requesttoken]')?.content || ''
			const base = '/apps/openregister/api/objects/hermiq/evaldataset'
			const listRes = await fetch(`${base}?_limit=200`, { headers: { requesttoken: token, 'OCS-APIRequest': 'true' } })
			const body = await listRes.json()
			const list = Array.isArray(body) ? body : (body.results || body.data || [])
			for (const obj of list) {
				if ((obj.name || obj['@self']?.name) === name) {
					const id = obj.id || obj['@self']?.id
					await fetch(`${base}/${id}`, { method: 'DELETE', headers: { requesttoken: token, 'OCS-APIRequest': 'true' } })
				}
			}
		}, uniqueName)

		expect(errors, `Unexpected console errors: ${errors.join(' | ')}`).toHaveLength(0)
	})

	test('Compliance dashboard renders live-computed control rows', async ({ page }) => {
		const errors = collectConsoleErrors(page)
		await login(page)

		await page.goto('/apps/hermiq/compliance', { waitUntil: 'domcontentloaded' })
		await dismissTour(page)
		await expect(page.locator('[data-testid="compliance-heading"]')).toBeVisible({ timeout: 10_000 })

		// The three seeded frameworks render as sections with control tables — not an empty
		// shell. A control's Status cell must show one of the computed states.
		await expect(page.getByRole('heading', { name: 'EU AI Act' })).toBeVisible()
		await expect(page.getByRole('heading', { name: 'ISO/IEC 42001' })).toBeVisible()
		await expect(page.getByRole('heading', { name: 'NIST AI RMF' })).toBeVisible()
		await expect(page.getByRole('cell', { name: 'Record-keeping' })).toBeVisible()
		await expect(page.getByText(/Satisfied|Unevidenced|Partial/).first()).toBeVisible()

		expect(errors, `Unexpected console errors: ${errors.join(' | ')}`).toHaveLength(0)
	})

	test('Agent templates gallery renders the seeded starter templates', async ({ page }) => {
		const errors = collectConsoleErrors(page)
		await login(page)

		await page.goto('/apps/hermiq/agent-templates', { waitUntil: 'domcontentloaded' })
		await dismissTour(page)
		await expect(page.locator('[data-testid="agent-template-gallery-heading"]')).toBeVisible({ timeout: 10_000 })

		// The SeedAgentTemplates repair step seeds five starters; assert a representative one.
		await expect(page.getByRole('cell', { name: 'Morning briefing' })).toBeVisible()
		await expect(page.getByRole('button', { name: 'Use this template' }).first()).toBeVisible()

		expect(errors, `Unexpected console errors: ${errors.join(' | ')}`).toHaveLength(0)
	})
})
