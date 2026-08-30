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

import { test, expect } from '@playwright/test'
import {
	TEST_PREFIX,
	appRoot,
	cleanupFamily,
	dismissTour,
	harvestToken,
	resolveRegisterSchema,
	seedAgent,
} from './_fixtures'

/*
 * 🔴 Every navigation below goes through `appRoot(page)` rather than a literal
 * `/apps/hermiq/...`.
 *
 * hermiq's router base is `generateUrl('/apps/hermiq')`, which is the pretty
 * form only when Nextcloud believes mod_rewrite works. On CI's `php -S` it is
 * `/index.php/apps/hermiq`, so a hard-coded pretty deep link is outside the
 * base, matches no route, and is redirected to the app root by the catch-all —
 * after which every selector here is missing and the failure reads as "the
 * page does not render". That is exactly what these four reported on run
 * 30865280923, and the earlier "PARKED / nc-vue selector hooks missing"
 * hypothesis this comment used to carry was never confirmed and is not the
 * cause. See `appRoot()` in _fixtures.ts.
 */
test.describe('hermiq agents + approvals', () => {
	test('Agents index renders: page identity, resolved route, Add button, rows or empty state', async ({
		page,
	}) => {
		const root = await appRoot(page)
		await page.goto(`${root}/agents`, { waitUntil: 'domcontentloaded' })
		await dismissTour(page)

		// Page identity: CnPageRenderer's stable testid + we are still ON the
		// requested route (no silent redirect to the dashboard).
		const indexPage = page.locator('[data-testid-page-id="AgentCatalog"]')
		await expect(indexPage).toBeVisible({ timeout: 15_000 })
		expect(new URL(page.url()).pathname).toContain(`${root}/agents`)

		// CnIndexPage renders no heading — assert the Add button instead
		// (default label "Add {schema.title}" → matches /^Add\b/).
		await expect(
			page.getByRole('button', { name: /^Add\b/i }).first(),
		).toBeVisible({ timeout: 10_000 })

		// The list settles into rows or an explicit empty state — the page
		// must render real content either way.
		const rendered = await indexPage.innerHTML()
		expect(rendered.length, 'AgentCatalog rendered no content').toBeGreaterThan(
			100,
		)
	})

	test('Agent detail renders the seeded agent (API-seeded via OpenRegister)', async ({
		page,
	}) => {
		const token = await harvestToken(page)
		await resolveRegisterSchema(page.request, token, 'agent')
		const agent = await seedAgent(page.request, token, {
			name: `${TEST_PREFIX}-detail-agent`,
		})

		const root = await appRoot(page)

		try {
			await page.goto(`${root}/agents/${agent.id}`, {
				waitUntil: 'domcontentloaded',
			})
			await dismissTour(page)

			// The detail page mounts as the AgentDetail manifest page and stays
			// on the parameterised route.
			const detailPage = page.locator('[data-testid-page-id="AgentDetail"]')
			await expect(detailPage).toBeVisible({ timeout: 15_000 })
			expect(new URL(page.url()).pathname).toContain(
				`${root}/agents/${agent.id}`,
			)

			// The seeded agent's data actually hydrates the grid — its name
			// must appear somewhere on the page (data widget / header).
			await expect(detailPage.getByText(agent.name).first()).toBeVisible({
				timeout: 20_000,
			})
		} finally {
			await cleanupFamily(page.request, token, 'agent').catch(() => {})
		}
	})

	test('Agent detail for a nonexistent id shows a graceful state, not a crash', async ({
		page,
	}) => {
		const root = await appRoot(page)
		await page.goto(`${root}/agents/00000000-0000-0000-0000-000000000000`, {
			waitUntil: 'domcontentloaded',
		})
		await dismissTour(page)

		// The page must still mount (no unhandled error / blank screen) —
		// manifest-driven-pages: "A missing or inaccessible agent still shows
		// a graceful empty state".
		const detailPage = page.locator('[data-testid-page-id="AgentDetail"]')
		await expect(detailPage).toBeVisible({ timeout: 15_000 })
		const rendered = await detailPage.innerHTML()
		expect(
			rendered.length,
			'AgentDetail rendered nothing for a missing agent',
		).toBeGreaterThan(100)
	})

	test('Approvals inbox renders: heading + table or empty state, no error card', async ({
		page,
	}) => {
		const root = await appRoot(page)
		await page.goto(`${root}/approvals`, { waitUntil: 'domcontentloaded' })
		await dismissTour(page)

		// ApprovalInbox is a type:"custom" page with its own h2 heading.
		await expect(page.locator('.approval-inbox')).toBeVisible({
			timeout: 15_000,
		})
		await expect(page.getByRole('heading', { name: 'Approvals' })).toBeVisible()

		// The inbox settles into a coherent state: loading gone, then EITHER
		// the approvals table OR the explicit empty state — an error note card
		// ("Could not load approvals") is a real failure.
		await expect(page.locator('.approval-inbox__loading')).toBeHidden({
			timeout: 20_000,
		})
		const table = page.locator('.approval-inbox__table')
		const empty = page.getByText('No approvals waiting')
		await expect(table.or(empty).first()).toBeVisible({ timeout: 15_000 })
		await expect(page.getByText('Could not load approvals')).toHaveCount(0)
	})
})
