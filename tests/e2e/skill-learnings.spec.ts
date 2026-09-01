/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Skill learnings e2e (skill-learnings): the SkillDetail page's read-only Learnings
 * card — the seeded tender-summary learnings render (five-section markdown + the l6
 * activity strip) with NO edit affordance; a skill without learnings files shows the
 * honest empty state; and the maturity scorecard still reports L6 not passed
 * (capture + promotion alone never grant L6 — consolidation is skill-self-improvement).
 *
 * Run against a running Nextcloud with Hermiq + OpenRegister installed and the repair
 * steps executed (the seed skills present, tender-summary carrying the demo learnings):
 *
 *     NEXTCLOUD_URL=http://localhost:8080 \
 *       NC_USER=admin NC_PASS=admin \
 *       npx playwright test tests/e2e/skill-learnings.spec.ts --project chromium
 *
 * Scenario traceability (gate-19):
 * @e2e skill-learnings::the-learnings-tab-renders-promoted-learnings
 * @e2e skill-learnings::a-skill-without-learnings-shows-an-empty-state
 * @e2e skill-learnings::a-fresh-install-demonstrates-learnings-end-to-end
 * @e2e skill-learnings::promotion-alone-does-not-grant-l6
 */

import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'

const NC_USER = process.env.NC_USER || 'admin'
const NC_PASS = process.env.NC_PASS || 'admin'

/**
 * Log the configured user in through Nextcloud's real login form (idempotent — mirrors
 * skill-maturity.spec.ts).
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
	await expect(page.getByText('tender-summary').first()).toBeVisible({
		timeout: 30_000,
	})
}

/**
 * Open one seed skill's detail page from the catalog row.
 *
 * @param page The Playwright page.
 * @param name The seed skill name.
 */
async function openSkillDetail(page: Page, name: string): Promise<void> {
	await openSkillsCatalog(page)
	await page
		.locator('tr', { hasText: name })
		.first()
		.getByText(name)
		.first()
		.click()
}

test.describe('skill learnings (skill-learnings)', () => {
	test.beforeEach(async ({ page }) => {
		await login(page)
	})

	// @e2e skill-learnings::the-learnings-tab-renders-promoted-learnings
	// @e2e skill-learnings::a-fresh-install-demonstrates-learnings-end-to-end
	test('tender-summary renders the seeded learnings read-only with activity counts', async ({
		page,
	}) => {
		await openSkillDetail(page, 'tender-summary')

		// The Learnings card's activity strip (l6): candidate count + last activity.
		await expect(page.getByText('Promoted learnings').first()).toBeVisible({
			timeout: 30_000,
		})
		await expect(page.getByText('Open candidates').first()).toBeVisible()
		await expect(page.getByText('Last capture').first()).toBeVisible()
		await expect(page.getByText('Last promotion').first()).toBeVisible()

		// The rendered five-section markdown (seeded consultancy entries).
		await expect(
			page.getByRole('heading', { name: 'Patterns That Work' }),
		).toBeVisible()
		await expect(
			page.getByRole('heading', { name: 'Mistakes to Avoid' }),
		).toBeVisible()
		await expect(
			page.getByRole('heading', { name: 'Domain Knowledge' }),
		).toBeVisible()
		await expect(
			page
				.getByText(
					"TED deadlines are CET, not the contracting authority's local time.",
				)
				.first(),
		).toBeVisible()

		// Read-only by contract: the card offers NO edit/add/delete affordance.
		const learningsCard = page.locator('.skill-learnings')
		await expect(learningsCard.getByRole('button')).toHaveCount(0)
		await expect(
			learningsCard.locator('textarea, input[type="text"]'),
		).toHaveCount(0)
	})

	// @e2e skill-learnings::a-skill-without-learnings-shows-an-empty-state
	test('a skill without learnings files shows the empty state without error', async ({
		page,
	}) => {
		await openSkillDetail(page, 'woo-request-triage')

		await expect(
			page
				.getByText(
					'No learnings yet. Once agents run with this skill, observations are captured automatically and confirmed ones are promoted here.',
				)
				.first(),
		).toBeVisible({ timeout: 30_000 })
		await expect(page.getByText('Learnings error')).toHaveCount(0)
	})

	// @e2e skill-learnings::promotion-alone-does-not-grant-l6
	test('the maturity scorecard still reports L6 not passed despite seeded learnings', async ({
		page,
	}) => {
		await openSkillDetail(page, 'tender-summary')

		// tender-summary stays L4: promoted learnings exist but no consolidation has
		// run (no lastConsolidatedAt), so Self-Improvement (L6) reads Not passed.
		await expect(page.getByLabel('Maturity level 4 of 7').first()).toBeVisible({
			timeout: 30_000,
		})
		const l6Row = page
			.locator('tr, li, .skill-maturity-scorecard__row', {
				hasText: 'Self-Improvement',
			})
			.first()
		await expect(l6Row).toBeVisible()
		await expect(l6Row.getByText(/Not passed/).first()).toBeVisible()
	})
})
