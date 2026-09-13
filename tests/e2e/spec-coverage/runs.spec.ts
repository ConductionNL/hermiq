/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Runs — the cross-agent run list.
 *
 * ⚠️ WHAT THIS SUITE CAN AND CANNOT SEED, stated rather than worked around. A run row
 * is an OpenRegister AuditTrail entry written by the run path itself; no API creates
 * one, and a fresh CI instance has none. So these tests assert the surface contract and
 * the invariant that holds at ANY row count, rather than seeding rows and asserting
 * they render. A test that could only run on an instance with history would skip on CI
 * forever, which is the same as not existing.
 *
 * The invariant is the valuable half anyway: the list and the dashboard KPIs above it
 * must always describe the same set of runs. They share one tenant boundary in
 * AnalyticsService precisely so they cannot drift, and this asserts that from outside.
 * Row shaping, ordering, paging, the status filter, the flow/schedule channel and the
 * dry-run exclusion are covered by AnalyticsServiceTest, which CAN construct entries.
 *
 * @spec openspec/specs/run-analytics/spec.md#requirement-a-cross-agent-run-list-on-the-same-tenant-boundary-as-the-metrics
 */
import { expect, test } from '@playwright/test'
import { appRoot, dismissTour, harvestToken, jsonHeaders } from './_fixtures.ts'

test.describe('runs — the cross-agent run list', () => {
	test('the Runs page is reachable from the nav and renders its own surface', async ({
		page,
	}) => {
		const root = await appRoot(page)
		await page.goto(`${root}/runs`, { waitUntil: 'domcontentloaded' })
		await dismissTour(page)

		// 🔑 The route must still be /runs. The SPA catch-all answers 200 for a path
		// nothing serves and the router then lands on the dashboard, so asserting
		// "the shell rendered" would pass on exactly the bug this page was built to
		// stop happening to delivery links.
		await expect(page.locator('.hermiq-runs')).toBeVisible({ timeout: 20_000 })
		expect(new URL(page.url()).pathname).toContain('/runs')

		await expect(page.locator('.hermiq-runs__heading')).toHaveText('Runs')

		// Both filters are present and labelled. NcSelect renders `inputLabel` as an
		// associated <label>, so role+name is the honest way to reach them.
		await expect(
			page.getByRole('combobox', { name: /agent/i }).first(),
		).toBeVisible()
		await expect(
			page.getByRole('combobox', { name: /status/i }).first(),
		).toBeVisible()

		// The nav entry exists, so the page is discoverable rather than only
		// reachable by URL.
		await expect(
			page.locator('.app-navigation').getByText('Runs', { exact: true }),
		).toBeVisible()
	})

	test('the list and the dashboard KPIs never disagree about what the caller may see', async ({
		page,
		request,
	}) => {
		const token = await harvestToken(page)

		const runsRes = await request.get('/index.php/apps/hermiq/api/runs', {
			headers: jsonHeaders(token),
		})
		expect(runsRes.status(), 'the run list must answer').toBe(200)
		const runs = await runsRes.json()

		const metricsRes = await request.get(
			'/index.php/apps/hermiq/api/analytics',
			{
				headers: jsonHeaders(token),
			},
		)
		expect(metricsRes.status(), 'the metrics must answer').toBe(200)
		const metrics = await metricsRes.json()

		// The whole reason listRuns() reuses loadVisibleAgents() instead of writing a
		// second boundary. A list that shows fewer runs than the KPI counts, or more,
		// means one of the two is scoped wrong — and the KPI is the one nobody can
		// check by eye.
		expect(
			runs.total,
			`the run list total (${runs.total}) must equal the analytics totalRuns (${metrics.totalRuns})`,
		).toBe(metrics.totalRuns)

		// Paging reports the UNPAGED total, so a pager can say "51 to 100 of 340".
		expect(runs.results.length).toBeLessThanOrEqual(runs.total)
	})

	test('a delivered link narrows the list to one schedule instead of landing on the dashboard', async ({
		page,
	}) => {
		const root = await appRoot(page)

		// 🔴 THE REGRESSION GUARD. Run-history rows and every Talk delivery used to
		// carry `/apps/hermiq/schedules/<uuid>`, which the manifest never declared.
		// That is not a 404: the SPA catch-all serves the shell, the router matches
		// nothing, and the reader silently arrives at the dashboard. Verified in a
		// browser before the fix. Both builders now point here.
		await page.goto(`${root}/runs?schedule=some-schedule-uuid`, {
			waitUntil: 'domcontentloaded',
		})
		await dismissTour(page)

		await expect(page.locator('.hermiq-runs')).toBeVisible({ timeout: 20_000 })
		expect(
			new URL(page.url()).pathname,
			'a schedule-scoped link must stay on /runs, not fall through to the dashboard',
		).toContain('/runs')

		// The page says it is showing a subset, rather than silently presenting a
		// filtered list as the whole history.
		await expect(page.getByText('Showing one schedule')).toBeVisible()
		await expect(
			page.getByRole('button', { name: /show every run/i }),
		).toBeVisible()
	})
})
