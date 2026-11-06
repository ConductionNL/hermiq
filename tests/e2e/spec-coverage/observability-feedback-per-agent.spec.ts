/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * observability-feedback-per-agent: the agent page shows how answers were
 * rated and the latest low ratings; the dashboard breakdown shows the thumbs
 * per agent. The analytics reads are answered by page.route so the counts are
 * known; the aggregation and the access guard are covered by PHPUnit
 * (AnalyticsFeedbackTest, AnalyticsControllerTest).
 */

import { expect, test } from '@playwright/test'
import {
	appRoot,
	cleanupFamily,
	dismissTour,
	harvestToken,
	seedAgent,
	TEST_PREFIX,
} from './_fixtures.ts'

const METRICS = {
	scope: 'agent',
	totalRuns: 4,
	successRuns: 4,
	successRate: 100,
	statusBreakdown: { ok: 4 },
	latency: { avgSeconds: 1 },
	tokens: { available: false, total: 0 },
	feedback: { positive: 3, negative: 1, helpfulRate: 0.75 },
	perAgent: [
		{
			agentId: 'a',
			name: 'Permit helper',
			runs: 4,
			success: 4,
			positive: 3,
			negative: 1,
		},
		{
			agentId: 'b',
			name: 'Tax helper',
			runs: 2,
			success: 1,
			positive: 0,
			negative: 2,
		},
	],
}

test.describe('hermiq ratings per agent', () => {
	test.afterAll(async ({ browser }) => {
		const page = await browser.newPage()
		const token = await harvestToken(page)
		await cleanupFamily(page.request, token, 'agent')
		await page.close()
	})

	// @e2e run-analytics::an-owner-checks-how-an-agent-is-received
	// @e2e run-analytics::an-owner-reads-why-answers-were-rated-down
	test('the agent page shows the share rated helpful and the latest low ratings', async ({
		page,
	}) => {
		const token = await harvestToken(page)
		const agent = await seedAgent(page.request, token, {
			name: `${TEST_PREFIX}-permit-helper`,
		})
		await page.route('**/apps/hermiq/api/analytics?**', (route) =>
			route.fulfill({ json: METRICS }),
		)
		await page.route(
			'**/apps/hermiq/api/analytics/agents/*/low-ratings',
			(route) =>
				route.fulfill({
					json: {
						results: [
							{
								comment: 'Gave the old opening hours',
								date: '2026-09-20T10:00:00+00:00',
								conversationId: 'c',
							},
						],
					},
				}),
		)

		const root = await appRoot(page)
		await page.goto(`${root}/agents/${agent.id}`, {
			waitUntil: 'domcontentloaded',
		})
		await dismissTour(page)

		const tile = page.getByTestId('agent-ratings-tile')
		await expect(tile).toContainText('75%', { timeout: 15_000 })
		await expect(tile).toContainText('3 up, 1 down')
		await expect(page.getByText('Gave the old opening hours')).toBeVisible()
	})

	// @e2e run-analytics::the-dashboard-compares-agents
	test('the dashboard breakdown shows the thumbs per agent', async ({ page }) => {
		await harvestToken(page)
		await page.route('**/apps/hermiq/api/analytics**', (route) =>
			route.request().url().includes('/api/analytics/agents/')
				? route.continue()
				: route.fulfill({ json: { ...METRICS, scope: 'organisation' } }),
		)

		const root = await appRoot(page)
		await page.goto(`${root}/`, { waitUntil: 'domcontentloaded' })
		await dismissTour(page)

		const row = page.getByRole('row', { name: /Tax helper/ })
		await expect(
			page.getByRole('columnheader', { name: 'Thumbs down' }),
		).toBeVisible({ timeout: 15_000 })
		await expect(row.getByRole('cell')).toHaveText([
			'Tax helper',
			'2',
			'1',
			'0',
			'2',
		])
	})
})
