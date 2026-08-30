/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Skill maturity e2e (skill-maturity-model): catalog maturity dots for the three seeded
 * example skills (L1/L2/L4), the Qualify row action showing the per-level scorecard with
 * the failing levels' reasons, and the SkillDetail page's durable scorecard (including
 * the seeded L4 attestation). Runs as admin, so the action-gated Attest surface is
 * reachable too (admin always passes ADR-023's matrix).
 *
 * Run against a running Nextcloud with Hermiq + OpenRegister installed and the repair
 * steps executed (the three seed skills present):
 *
 *     NEXTCLOUD_URL=http://localhost:8080 \
 *       NC_USER=admin NC_PASS=admin \
 *       npx playwright test tests/e2e/skill-maturity.spec.ts --project chromium
 *
 * Covers scenarios in openspec/changes/skill-maturity-model/specs/skill-maturity/spec.md
 * (catalog dots, Qualify row action, SkillDetail scorecard, fresh-install maturity spread).
 */

import { test, expect, type Page } from '@playwright/test'

const NC_USER = process.env.NC_USER || 'admin'
const NC_PASS = process.env.NC_PASS || 'admin'

/**
 * Log the configured user in through Nextcloud's real login form (idempotent — mirrors
 * dashboard-and-agents.spec.ts).
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
 * Open the Hermiq skills catalog and wait for the seed rows.
 *
 * @param page The Playwright page.
 */
async function openSkillsCatalog(page: Page): Promise<void> {
	await page.goto('/apps/hermiq/skills', { waitUntil: 'domcontentloaded' })
	await expect(page.getByText('woo-request-triage').first()).toBeVisible({
		timeout: 30_000,
	})
}

test.describe('skill maturity (skill-maturity-model)', () => {
	test.beforeEach(async ({ page }) => {
		await login(page)
	})

	// Scenario: The list shows maturity dots + Scenario: A fresh install shows the
	// maturity spread — the three seed skills render maturity badges L1/L2/L4. The L2
	// badge on woo-request-triage is the browser surface of the compact-well-triggering
	// L2 computation rule.
	// @e2e skill-maturity::the-list-shows-maturity-dots
	// @e2e skill-maturity::a-fresh-install-shows-the-maturity-spread
	// @e2e skill-maturity::a-compact-well-triggering-skill-without-reference-files-is-l2
	test('catalog shows maturity dots for the three seed skills', async ({
		page,
	}) => {
		await openSkillsCatalog(page)

		await expect(page.getByText('meeting-notes-cleanup').first()).toBeVisible()
		await expect(page.getByText('tender-summary').first()).toBeVisible()

		// The dots badge carries an accessible, non-color-only textual level.
		await expect(page.getByLabel('Maturity level 1 of 7').first()).toBeVisible()
		await expect(page.getByLabel('Maturity level 2 of 7').first()).toBeVisible()
		await expect(page.getByLabel('Maturity level 4 of 7').first()).toBeVisible()
	})

	// Scenario: Qualifying from the row shows the scorecard — the Qualify row action
	// calls the endpoint and the returned per-level scorecard shows the first failing
	// level's reasons (woo-request-triage fails L3 for missing references/examples).
	// @e2e skill-maturity::qualifying-from-the-row-shows-the-scorecard
	test('Qualify row action shows the scorecard with failing reasons', async ({
		page,
	}) => {
		await openSkillsCatalog(page)

		const row = page.locator('tr', { hasText: 'woo-request-triage' }).first()
		await row.getByRole('button', { name: 'Qualify skill maturity' }).click()

		await expect(page.getByText('Maturity scorecard').first()).toBeVisible({
			timeout: 15_000,
		})
		await expect(
			page.getByText('no references/ or examples/ entry in files').first(),
		).toBeVisible()
		await expect(page.getByText('Not passed').first()).toBeVisible()
	})

	// Scenario: The detail page shows the durable scorecard — /skills/:id renders the
	// stored maturity level, target level and per-level evidence, including the seeded
	// L4 attestation of tender-summary.
	// @e2e skill-maturity::the-detail-page-shows-the-durable-scorecard
	test('SkillDetail page shows the durable scorecard with the seeded attestation', async ({
		page,
	}) => {
		await openSkillsCatalog(page)

		await page
			.locator('tr', { hasText: 'tender-summary' })
			.first()
			.getByText('tender-summary')
			.first()
			.click()

		await expect(page.getByLabel('Maturity level 4 of 7').first()).toBeVisible({
			timeout: 30_000,
		})
		await expect(page.getByText('Target: L5').first()).toBeVisible()
		await expect(page.getByText('Personalization').first()).toBeVisible()
		// The seeded attestation's curator is shown as evidence.
		await expect(page.getByText(/Attested by admin/).first()).toBeVisible()
		// The action-gated attest surface is present (admin passes the matrix).
		await expect(
			page.getByRole('button', { name: 'Attest maturity level 4' }),
		).toBeVisible()
	})
})
