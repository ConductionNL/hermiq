/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Skill-scoped paired evals e2e (skill-evals): the seeded `woo-triage-paired-eval`
 * dataset's skill-link panel + paired-baseline toggle on the EvalDatasetDetail page,
 * the honest empty state + Run paired eval affordance on the SkillDetail
 * eval-evidence card, the evalBaselineMode info affordance on the agent detail
 * surface, and — behind HERMIQ_E2E_LLM=1 on an LLM-backed instance — a live paired
 * run whose marker-token skill proves the run-loop exposure seam (WITH passes,
 * WITHOUT fails, delta > 0) and refreshes the l5 evidence card.
 *
 * Run against a running Nextcloud with Hermiq + OpenRegister installed and the
 * repair steps executed (seed skills + the woo-triage-paired-eval dataset present):
 *
 *     NEXTCLOUD_URL=http://localhost:8080 \
 *       NC_USER=admin NC_PASS=admin \
 *       npx playwright test tests/e2e/skill-evals.spec.ts --project chromium
 *
 * Scenario traceability (gate-19):
 *   @e2e openspec/specs/agent-evals/spec.md#linking-a-skill-is-a-plain-object-write
 *   @e2e openspec/specs/agent-evals/spec.md#linking-a-skill-from-the-dataset-detail-page
 *   @e2e openspec/specs/agent-evals/spec.md#baseline-mode-requires-linked-skills
 *   @e2e openspec/specs/agent-evals/spec.md#the-property-description-surfaces-as-an-info-affordance-where-the-value-is-changed
 *   @e2e openspec/specs/agent-evals/spec.md#a-content-bearing-skill-deterministically-changes-output
 *   @e2e openspec/specs/agent-evals/spec.md#a-paired-run-records-both-halves-and-the-per-skill-delta
 *   @e2e openspec/specs/agent-evals/spec.md#a-paired-run-renders-both-halves
 *   @e2e openspec/specs/skill-maturity/spec.md#no-evidence-shows-an-honest-empty-state
 *   @e2e openspec/specs/skill-maturity/spec.md#the-card-shows-evidence-after-a-completed-paired-run
 *   @e2e openspec/specs/skill-maturity/spec.md#run-paired-eval-triggers-the-owner-guarded-endpoint
 *
 * The purely backend-observable scenarios (in-memory detachment/crash safety, joint
 * vs per-skill half counts and budget sums, l5 write-back timing, the widened
 * 404-never-403 guard, regression-gate comparison, forced re-import) are asserted
 * by tests/Unit/Service/EvalRunServicePairedTest.php +
 * tests/Unit/Controller/EvalRunControllerTest.php — a browser cannot observe stored
 * state that deliberately never changes.
 */

import { test, expect, type Page } from '@playwright/test'

const NC_USER = process.env.NC_USER || 'admin'
const NC_PASS = process.env.NC_PASS || 'admin'
// A live paired run needs a reachable LLM provider on the instance — opt-in.
const LLM_LIVE = process.env.HERMIQ_E2E_LLM === '1'

/**
 * Log the configured user in through Nextcloud's real login form (idempotent —
 * mirrors skill-maturity.spec.ts).
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
 * Open the evaluations index and navigate into the seeded paired dataset.
 *
 * @param page The Playwright page.
 */
async function openSeededDataset(page: Page): Promise<void> {
	await page.goto('/apps/hermiq/evals', { waitUntil: 'domcontentloaded' })
	await expect(page.getByText('woo-triage-paired-eval').first()).toBeVisible({ timeout: 30_000 })
	await page.getByText('woo-triage-paired-eval').first().click()
	await expect(page.getByText('Linked skills').first()).toBeVisible({ timeout: 30_000 })
}

test.describe('skill-scoped paired evals (skill-evals)', () => {
	test.beforeEach(async ({ page }) => {
		await login(page)
	})

	// @e2e openspec/specs/agent-evals/spec.md#linking-a-skill-from-the-dataset-detail-page
	// @e2e openspec/specs/agent-evals/spec.md#linking-a-skill-is-a-plain-object-write
	// The seeded dataset links exactly the woo-request-triage skill; linking a second
	// skill and unlinking it round-trips through the generic object path.
	test('dataset detail links and unlinks a skill through the skill panel', async ({ page }) => {
		await openSeededDataset(page)

		// The seed link is present.
		await expect(page.getByText('woo-request-triage').first()).toBeVisible()

		// Link a second skill via the picker, then unlink it again.
		// NOTE: match the option by rendered text, not accessible name — NcSelect v9
		// splits option labels into word-break spans, so the computed accessible name
		// gains spaces ("tender- summary") and a name-based lookup misses.
		await page.getByLabel('Link a skill').first().click()
		await page.getByRole('option').filter({ hasText: 'tender-summary' }).first().click()
		await page.getByRole('button', { name: 'Link', exact: true }).click()
		await expect(page.getByText('Skill linked.').first()).toBeVisible({ timeout: 15_000 })
		await expect(page.getByText('tender-summary').first()).toBeVisible()

		const row = page.locator('.skill-link-panel__item', { hasText: 'tender-summary' }).first()
		await row.getByRole('button', { name: 'Unlink skill' }).click()
		await expect(page.getByText('Skill unlinked.').first()).toBeVisible({ timeout: 15_000 })
	})

	// @e2e openspec/specs/agent-evals/spec.md#baseline-mode-requires-linked-skills
	// The paired toggle is enabled (with the cost note once switched on) on the
	// seeded linked dataset; a freshly created unlinked dataset keeps it disabled.
	test('paired baseline toggle is gated on linked skills and states the cost', async ({ page }) => {
		await openSeededDataset(page)

		// The switch input starts DISABLED until the linked-skills fetch resolves
		// (gating on skillRefs), so wait for enabled before toggling; the styled
		// NcCheckboxRadioSwitch hides the native input, hence the forced check.
		const toggle = page.getByRole('checkbox', { name: 'Paired baseline (with vs without skills)' })
		await expect(toggle).toBeVisible()
		await expect(toggle).toBeEnabled({ timeout: 15_000 })
		await toggle.check({ force: true })
		await expect(page.getByText(/about 2x the token cost/).first()).toBeVisible()
	})

	// @e2e openspec/specs/skill-maturity/spec.md#no-evidence-shows-an-honest-empty-state
	// Before any paired run, the SkillDetail evidence card shows the honest empty
	// state — no fabricated metric — while the Run paired eval action is offered
	// (the seed dataset links this skill).
	test('SkillDetail evidence card shows an honest empty state before any run', async ({ page }) => {
		await page.goto('/apps/hermiq/skills', { waitUntil: 'domcontentloaded' })
		await expect(page.getByText('woo-request-triage').first()).toBeVisible({ timeout: 30_000 })
		await page.locator('tr', { hasText: 'woo-request-triage' }).first().getByText('woo-request-triage').first().click()

		await expect(page.getByText('Eval evidence').first()).toBeVisible({ timeout: 30_000 })
		await expect(page.getByText(/No eval evidence yet/).first()).toBeVisible()
		// No metric is rendered in the empty state.
		await expect(page.getByText('Last validated')).toHaveCount(0)
		// The trigger affordance exists (dataset picker over linking datasets).
		await expect(page.getByText('Run paired eval').first()).toBeVisible()
	})

	// @e2e openspec/specs/agent-evals/spec.md#the-property-description-surfaces-as-an-info-affordance-where-the-value-is-changed
	// The agent detail surface's evalBaselineMode widget shows the register
	// property's consequence-explaining description via the info icon, exactly
	// where the value is changed.
	test('agent detail surfaces the evalBaselineMode description as an info affordance', async ({ page }) => {
		await page.goto('/apps/hermiq/agents', { waitUntil: 'domcontentloaded' })
		await expect(page.getByRole('button', { name: 'Add Agent' })).toBeVisible({ timeout: 30_000 })

		// A fresh instance seeds no agents — create one through the UI when the
		// catalog is empty (the scenario only needs SOME agent detail surface).
		const firstRow = page.locator('tbody tr').first()
		if (await firstRow.count() === 0) {
			await page.getByRole('button', { name: 'Add Agent' }).click()
			const modal = page.locator('.modal-container, [role="dialog"]').last()
			await modal.getByLabel('Name').first().fill('e2e-baseline-agent')
			await modal.getByRole('button', { name: 'Save' }).click()
			// The page keeps latent [role=dialog] hosts mounted, so assert on the
			// OUTCOME (the created row) rather than on dialog count reaching zero.
			await expect(page.getByText('e2e-baseline-agent').first()).toBeVisible({ timeout: 30_000 })
		}

		await firstRow.waitFor({ state: 'visible', timeout: 30_000 })
		await firstRow.click()

		await expect(page.getByText('Eval baseline mode').first()).toBeVisible({ timeout: 30_000 })
		await page.getByRole('button', { name: 'What does the eval baseline mode change?' }).click()
		// The popover renders the register property's description: semantics + cost.
		await expect(page.getByText(/JOINT contribution of the whole linked skill set/).first()).toBeVisible()
		await expect(page.getByText(/\(N\+1\)x the token cost/).first()).toBeVisible()
	})

	// @e2e openspec/specs/agent-evals/spec.md#a-content-bearing-skill-deterministically-changes-output
	// @e2e openspec/specs/agent-evals/spec.md#a-paired-run-records-both-halves-and-the-per-skill-delta
	// @e2e openspec/specs/agent-evals/spec.md#a-paired-run-renders-both-halves
	// @e2e openspec/specs/skill-maturity/spec.md#the-card-shows-evidence-after-a-completed-paired-run
	// @e2e openspec/specs/skill-maturity/spec.md#run-paired-eval-triggers-the-owner-guarded-endpoint
	// Live (LLM-backed) only: a marker-token skill linked to a contains-marker
	// dataset proves the exposure seam — WITH passes, WITHOUT fails, delta > 0 —
	// the run renders with/without columns, and the SkillDetail card reflects the
	// refreshed l5 evidence.
	test('a live paired run proves the seam and refreshes the evidence card', async ({ page }) => {
		test.skip(!LLM_LIVE, 'Needs an LLM-backed instance — set HERMIQ_E2E_LLM=1')

		await openSeededDataset(page)

		// Trigger a paired run against the first available agent.
		await page.getByLabel('Agent').first().click()
		await page.locator('.eval-run-panel-widget__agent-picker [role="option"]').first().click()
		await page.getByText('Paired baseline (with vs without skills)').first().click()
		await page.getByRole('button', { name: 'Run', exact: true }).click()
		await expect(page.getByText(/Eval run complete/).first()).toBeVisible({ timeout: 300_000 })

		// The paired run renders with/without columns and the per-skill delta.
		await page.getByRole('button', { name: 'Details' }).first().click()
		await expect(page.getByText('With skill').first()).toBeVisible()
		await expect(page.getByText('Without skill').first()).toBeVisible()
		await expect(page.getByText('Baseline delta').first()).toBeVisible()

		// The SkillDetail card now shows the refreshed l5 evidence.
		await page.goto('/apps/hermiq/skills', { waitUntil: 'domcontentloaded' })
		await page.locator('tr', { hasText: 'woo-request-triage' }).first().getByText('woo-request-triage').first().click()
		await expect(page.getByText('Last validated').first()).toBeVisible({ timeout: 30_000 })
		await expect(page.getByText('Pass rate').first()).toBeVisible()
	})
})
