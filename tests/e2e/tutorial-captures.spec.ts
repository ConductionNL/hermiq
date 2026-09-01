/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * One-off capture spec for the conduction-website hermiq tutorial series
 * (academy/2026-07-27-hermiq-skills-tutorial-{1..4}). NOT a regression test —
 * writes five PNGs straight into the website worktree's academy post folders.
 *
 * Prereqs on the target instance (default http://localhost:8091):
 *   - hermiq + openregister installed, occ maintenance:repair executed
 *   - the demo fixtures seeded by scratchpad/seed-tutorial-fixtures.py
 *     (woo-intake-agent, the "Draft a meeting-notes skill" conversation,
 *     and the completed paired EvalRun on woo-triage-paired-eval)
 *
 * Run:
 *   NEXTCLOUD_URL=http://localhost:8091 NC_USER=admin NC_PASS=admin \
 *     npx playwright test tests/e2e/tutorial-captures.spec.ts --project chromium
 */

import type { Locator, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import * as fs from 'fs'
import * as path from 'path'

const NC_USER = process.env.NC_USER || 'admin'
const NC_PASS = process.env.NC_PASS || 'admin'

const SITE = '/home/rubenlinde/gate19-worktrees/website-hermiq-tutorial/academy'
const OUT = {
	catalog: path.join(
		SITE,
		'2026-07-27-hermiq-skills-tutorial-1-skills-for-your-agents/images/01-skills-catalog-maturity-dots.png',
	),
	saveAsSkill: path.join(
		SITE,
		'2026-07-27-hermiq-skills-tutorial-1-skills-for-your-agents/images/02-save-as-skill-modal.png',
	),
	scorecard: path.join(
		SITE,
		'2026-07-27-hermiq-skills-tutorial-2-qualifying-a-skill/images/01-qualify-scorecard.png',
	),
	pairedRun: path.join(
		SITE,
		'2026-07-27-hermiq-skills-tutorial-3-paired-evals/images/01-paired-run-detail.png',
	),
	learnings: path.join(
		SITE,
		'2026-07-27-hermiq-skills-tutorial-4-skills-that-learn/images/01-learnings-tab.png',
	),
}

test.use({ viewport: { width: 1280, height: 800 }, colorScheme: 'light' })

/** Log in through the real Nextcloud login form (idempotent). */
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

/** Element screenshot with the output directory created on demand. */
async function shoot(target: Locator | Page, file: string): Promise<void> {
	fs.mkdirSync(path.dirname(file), { recursive: true })
	await target.screenshot({ path: file, type: 'png' })
}

async function openSkillsCatalog(page: Page): Promise<void> {
	await page.goto('/apps/hermiq/skills', { waitUntil: 'domcontentloaded' })
	await expect(page.getByText('woo-request-triage').first()).toBeVisible({
		timeout: 30_000,
	})
}

test.describe('tutorial captures', () => {
	test.beforeEach(async ({ page }) => {
		await login(page)
	})

	// Part 1, TODO 1: skills catalog with the four seeded skills + maturity dots.
	// The catalog table is 1580px wide (its Description column is generous), so
	// this one capture runs at a wider viewport and crops to the table itself —
	// at 1280 the Maturity/State columns sit beyond the scroll container's edge.
	test('capture 1 — skills catalog with maturity dots', async ({ page }) => {
		await page.setViewportSize({ width: 2000, height: 1300 })
		await openSkillsCatalog(page)
		await expect(page.getByText('meeting-notes-cleanup').first()).toBeVisible()
		await expect(page.getByText('tender-summary').first()).toBeVisible()
		await expect(page.getByText('skill-creator').first()).toBeVisible()
		await expect(page.getByLabel('Maturity level 4 of 7').first()).toBeVisible()
		await page.waitForTimeout(750)
		await shoot(page.locator('table').first(), OUT.catalog)
	})

	// Part 1, TODO 2: Save as skill on an assistant chat message + pre-filled modal.
	test('capture 2 — save-as-skill modal over the chat', async ({ page }) => {
		await page.goto('/apps/hermiq/chat', { waitUntil: 'domcontentloaded' })
		// The curated demo conversation is HAND-MADE state (needs a configured
		// LLM provider to produce the assistant SKILL.md answer) — it is not
		// seeded by any repair step. Skip loudly when absent (fresh/disposable
		// instance) instead of timing out: same not-a-pass pattern as the
		// hydra-console spec's precondition skips.
		await page
			.getByText('Draft a meeting-notes skill')
			.first()
			.waitFor({ state: 'visible', timeout: 15_000 })
			.catch(() => {})
		test.skip(
			(await page.getByText('Draft a meeting-notes skill').count()) === 0,
			'PRECONDITION MISSING: the curated "Draft a meeting-notes skill" demo conversation does not exist on this instance (requires an LLM provider + manual authoring). Not a pass.',
		)
		await page.getByText('Draft a meeting-notes skill').first().click()
		await expect(
			page.getByText('meeting-notes-to-decisions').first(),
		).toBeVisible({ timeout: 30_000 })
		await page.getByRole('button', { name: 'Save as skill' }).first().click()
		// The modal opens pre-filled with the assistant message's SKILL.md body.
		// NOTE: latent hidden [role=dialog]/.modal-container hosts stay mounted —
		// always match the VISIBLE one.
		const dialog = page.locator('.modal-container:visible').first()
		await expect(dialog).toBeVisible({ timeout: 15_000 })
		await expect(
			dialog.getByText('meeting-notes-to-decisions').first(),
		).toBeVisible()
		await page.waitForTimeout(750)
		await shoot(page, OUT.saveAsSkill)
	})

	// Part 2, TODO 3: Qualify scorecard for meeting-notes-cleanup (L1 pass, L2 fail).
	test('capture 3 — qualify scorecard modal', async ({ page }) => {
		await openSkillsCatalog(page)
		const row = page.locator('tr', { hasText: 'meeting-notes-cleanup' }).first()
		await row.getByRole('button', { name: 'Qualify skill maturity' }).click()
		await expect(page.getByText('Maturity scorecard').first()).toBeVisible({
			timeout: 15_000,
		})
		await page.waitForTimeout(750)
		await shoot(page.locator('.modal-container:visible').first(), OUT.scorecard)
	})

	// Part 3, TODO 4: completed paired run, both halves + per-skill baseline delta.
	test('capture 4 — paired run detail with both halves', async ({ page }) => {
		// The two per-case halves need column room — at 1280 the case lines collide.
		await page.setViewportSize({ width: 1920, height: 1080 })
		await page.goto('/apps/hermiq/evals', { waitUntil: 'domcontentloaded' })
		await page.getByText('woo-triage-paired-eval').first().click()
		await expect(page.getByText('Linked skills').first()).toBeVisible({
			timeout: 30_000,
		})
		// A COMPLETED paired run is curated state — executing one needs a
		// configured LLM provider, which a fresh/disposable instance lacks.
		// Skip loudly instead of timing out (not-a-pass precondition pattern).
		await page
			.getByRole('button', { name: 'Details' })
			.first()
			.waitFor({ state: 'visible', timeout: 15_000 })
			.catch(() => {})
		test.skip(
			(await page.getByRole('button', { name: 'Details' }).count()) === 0,
			'PRECONDITION MISSING: no completed paired eval run exists on this instance (requires an LLM provider to execute one). Not a pass.',
		)
		await page.getByRole('button', { name: 'Details' }).first().click()
		await expect(page.getByText('Baseline delta').first()).toBeVisible()
		await expect(page.getByText('With skill').first()).toBeVisible()
		await expect(
			page.getByText('Without skills — per case').first(),
		).toBeVisible()
		await page.waitForTimeout(750)
		await shoot(page.locator('.eval-run-panel-widget').first(), OUT.pairedRun)
	})

	// Part 4, TODO 5: Learnings tab on tender-summary (five sections + activity strip).
	test('capture 5 — learnings tab on tender-summary', async ({ page }) => {
		// Tall viewport so the whole learnings card (five sections + strip) is in view.
		await page.setViewportSize({ width: 1280, height: 1700 })
		await openSkillsCatalog(page)
		await page
			.locator('tr', { hasText: 'tender-summary' })
			.first()
			.getByText('tender-summary')
			.first()
			.click()
		await expect(page.getByText('Promoted learnings').first()).toBeVisible({
			timeout: 30_000,
		})
		await expect(
			page.getByRole('heading', { name: 'Patterns That Work' }),
		).toBeVisible()
		// The card scrolls internally inside a fixed-height gridstack item (456px
		// visible vs ~568px content). Grow just that item for the capture so all
		// five sections + the activity strip land in one image — the equivalent of
		// scrolling through, no content is altered. The item is absolutely
		// positioned, so raise it above the neighbour below and give it an opaque
		// background; the element screenshot is clipped to the card anyway.
		await page.evaluate(() => {
			const card = document.querySelector('.skill-learnings') as HTMLElement
			const item = card.closest('.grid-stack-item') as HTMLElement
			const content = card.closest('.grid-stack-item-content') as HTMLElement
			const needed = card.scrollHeight + 40
			item.style.height = `${needed + 24}px`
			item.style.zIndex = '50'
			content.style.height = `${needed}px`
			content.style.background = 'var(--color-main-background)'
		})
		const card = page.locator('.skill-learnings').first()
		await card.scrollIntoViewIfNeeded()
		await page.waitForTimeout(750)
		await shoot(card, OUT.learnings)
	})
})
