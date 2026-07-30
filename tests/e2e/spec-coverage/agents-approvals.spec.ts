/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Agents + Approvals e2e (spec-coverage).
 *
 * The router runs in HISTORY mode (createWebHistory, src/main.js) so routes
 * are PATH-form: /apps/hermiq/agents, /apps/hermiq/agents/:id,
 * /apps/hermiq/approvals — never #/ hash fragments.
 *
 * Covered openspec scenarios (@e2e back-references live in the spec files):
 *   - openspec/specs/agent-management-ui/spec.md
 *       #### Scenario: Open the agent catalog
 *   - openspec/specs/manifest-driven-pages/spec.md
 *       #### Scenario: Visiting an agent's detail route renders the grid
 *       #### Scenario: A missing or inaccessible agent still shows a graceful empty state
 *
 * The Approvals inbox render test is NOT tagged against
 * openspec/specs/human-approval-gate/spec.md — that spec's scenarios cover
 * engine gating/notification behaviour, which a pure render assertion does
 * not exercise.
 *
 * NOTE: CnIndexPage (the type:"index" page renderer) deliberately renders NO
 * title heading — the page identity assertion is CnPageRenderer's stable
 * `data-testid-page-id` plus the resolved route path, and the Add button
 * (label defaults to "Add {schema.title}").
 *
 * Auth: shared storageState session (tests/e2e/global-setup.ts).
 * Seeding: OpenRegister objects API via _fixtures (register 'hermiq').
 */

import { test, expect, type Page } from '@playwright/test'
import { cleanupFamily, harvestToken, resolveRegisterSchema, seedAgent, TEST_PREFIX } from './_fixtures'

/**
 * Close the first-run onboarding tour dialog if it is showing.
 *
 * @param page The Playwright page.
 */
async function dismissTour(page: Page): Promise<void> {
	const close = page.getByRole('button', { name: 'Close tour' })
	if (await close.count() > 0) {
		await close.first().click()
	}
}

/*
 * PARKED (all four tests below) — requires nc-vue selector hooks present only
 * in builds after 2026-07-25 — unpark after the next hermiq deploy.
 *
 * STATIC evidence (independent of any instance, reproducible by grepping the
 * deployed chunks in js/): `data-testid-page-id` occurs 0 times in BOTH
 * hermiq-main.js and hermiq-shared-nc-vue.js (bundles dated 2026-07-25
 * 22:13), so the page-identity selector these tests use cannot resolve on the
 * deployed build no matter how healthy the instance is. `approval-inbox` DOES
 * occur in hermiq-main.js, but a string in a bundle is not proof it renders.
 * The deployed sourcemap also shows the nc-vue chunk was built from
 * node_modules/@conduction/nextcloud-vue/dist (the PUBLISHED dist), not from
 * the LOCAL_LIB source — the configuration webpack.config.js records as
 * making "CnAppRoot render nothing at all — silently, with zero console
 * errors".
 *
 * NOT yet confirmed live: a read-only probe on 2026-07-27 did observe an
 * empty `.hermiq-root`, but the shared instance later reported
 * needsDbUpgrade:true, so that observation is unusable as proof. Re-verify on
 * a healthy instance before drawing any app-defect conclusion.
 */
test.describe('hermiq agents + approvals', () => {

	test('Agents index renders: page identity, resolved route, Add button, rows or empty state', async ({ page }) => {
		await page.goto('/apps/hermiq/agents', { waitUntil: 'domcontentloaded' })
		await dismissTour(page)

		// Page identity: CnPageRenderer's stable testid + we are still ON the
		// requested route (no silent redirect to the dashboard).
		const indexPage = page.locator('[data-testid-page-id="AgentCatalog"]')
		await expect(indexPage).toBeVisible({ timeout: 15_000 })
		expect(new URL(page.url()).pathname).toContain('/apps/hermiq/agents')

		// CnIndexPage renders no heading — assert the Add button instead
		// (default label "Add {schema.title}" → matches /^Add\b/).
		await expect(page.getByRole('button', { name: /^Add\b/i }).first()).toBeVisible({ timeout: 10_000 })

		// The list settles into rows or an explicit empty state — the page
		// must render real content either way.
		const rendered = await indexPage.innerHTML()
		expect(rendered.length, 'AgentCatalog rendered no content').toBeGreaterThan(100)
	})

	test('Agent detail renders the seeded agent (API-seeded via OpenRegister)', async ({ page }) => {
		const token = await harvestToken(page)
		await resolveRegisterSchema(page.request, token, 'agent')
		const agent = await seedAgent(page.request, token, { name: `${TEST_PREFIX}-detail-agent` })

		try {
			await page.goto(`/apps/hermiq/agents/${agent.id}`, { waitUntil: 'domcontentloaded' })
			await dismissTour(page)

			// The detail page mounts as the AgentDetail manifest page and stays
			// on the parameterised route.
			const detailPage = page.locator('[data-testid-page-id="AgentDetail"]')
			await expect(detailPage).toBeVisible({ timeout: 15_000 })
			expect(new URL(page.url()).pathname).toContain(`/apps/hermiq/agents/${agent.id}`)

			// The seeded agent's data actually hydrates the grid — its name
			// must appear somewhere on the page (data widget / header).
			await expect(detailPage.getByText(agent.name).first()).toBeVisible({ timeout: 20_000 })
		} finally {
			await cleanupFamily(page.request, token, 'agent').catch(() => {})
		}
	})

	test('Agent detail for a nonexistent id shows a graceful state, not a crash', async ({ page }) => {
		await page.goto('/apps/hermiq/agents/00000000-0000-0000-0000-000000000000', { waitUntil: 'domcontentloaded' })
		await dismissTour(page)

		// The page must still mount (no unhandled error / blank screen) —
		// manifest-driven-pages: "A missing or inaccessible agent still shows
		// a graceful empty state".
		const detailPage = page.locator('[data-testid-page-id="AgentDetail"]')
		await expect(detailPage).toBeVisible({ timeout: 15_000 })
		const rendered = await detailPage.innerHTML()
		expect(rendered.length, 'AgentDetail rendered nothing for a missing agent').toBeGreaterThan(100)
	})

	test('Approvals inbox renders: heading + table or empty state, no error card', async ({ page }) => {
		await page.goto('/apps/hermiq/approvals', { waitUntil: 'domcontentloaded' })
		await dismissTour(page)

		// ApprovalInbox is a type:"custom" page with its own h2 heading.
		await expect(page.locator('.approval-inbox')).toBeVisible({ timeout: 15_000 })
		await expect(page.getByRole('heading', { name: 'Approvals' })).toBeVisible()

		// The inbox settles into a coherent state: loading gone, then EITHER
		// the approvals table OR the explicit empty state — an error note card
		// ("Could not load approvals") is a real failure.
		await expect(page.locator('.approval-inbox__loading')).toBeHidden({ timeout: 20_000 })
		const table = page.locator('.approval-inbox__table')
		const empty = page.getByText('No approvals waiting')
		await expect(table.or(empty).first()).toBeVisible({ timeout: 15_000 })
		await expect(page.getByText('Could not load approvals')).toHaveCount(0)
	})
})
