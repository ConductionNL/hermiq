/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Run comparison (observability-compare-two-runs).
 *
 * Drives the RunCompare page (src/views/RunCompare.vue, /runs/compare) from the Runs
 * list, and the FlowRunCompare page (src/views/FlowRunCompare.vue, /flow-runs/compare).
 *
 * A run is an audit entry the run path writes and no API creates, so the run list,
 * the comparison and the flow runs are answered by `page.route` here: each scenario
 * controls its runs. The visibility boundary is asserted against the real endpoint
 * (an unknown run is 404 on its side) and in RunCompareTest, which can construct entries.
 *
 * @spec openspec/specs/run-replay-and-dry-run/spec.md#requirement-a-person-can-compare-any-two-runs-they-may-see-req-rcmp-001
 * @spec openspec/specs/run-replay-and-dry-run/spec.md#requirement-a-person-can-compare-two-runs-of-a-flow-node-by-node-req-rcmp-003
 */
import { expect, test } from '@playwright/test'
import { appRoot, dismissTour, harvestToken, jsonHeaders } from './_fixtures.ts'

function run(id: string, created: string) {
	return {
		id,
		agentId: 'agent-night',
		agentName: 'Nachtelijke zaakcontrole',
		status: 'ok',
		trigger: 'schedule',
		created,
		durationMs: 1000,
		summary: 'Checked the cases',
	}
}

test.describe('run comparison', () => {
	test("An operator compares last night's run with the night before", async ({
		page,
	}) => {
		await page.route('**/apps/hermiq/api/runs?**', (route) =>
			route.fulfill({
				json: {
					results: [
						run('run-b', '2026-09-28T02:00:00+00:00'),
						run('run-a', '2026-09-27T02:00:00+00:00'),
						run('run-c', '2026-09-26T02:00:00+00:00'),
					],
					total: 3,
					limit: 50,
					offset: 0,
				},
			}),
		)
		await page.route('**/apps/hermiq/api/runs/compare**', (route) =>
			route.fulfill({
				json: {
					left: {
						...run('run-a', '2026-09-27T02:00:00+00:00'),
						steps: [],
						agentVersion: null,
						provider: null,
						model: null,
					},
					right: {
						...run('run-b', '2026-09-28T02:00:00+00:00'),
						durationMs: 5200,
						steps: [],
						agentVersion: null,
						provider: null,
						model: null,
					},
					sameAgent: true,
					comparison: {
						differences: 1,
						summaryChanged: false,
						steps: [
							{
								mark: 'same',
								left: { name: 'Read file', outcome: 'ok' },
								right: { name: 'Read file', outcome: 'ok' },
							},
							{
								mark: 'only-right',
								left: null,
								right: { name: 'Read file', outcome: 'ok' },
							},
						],
					},
				},
			}),
		)

		const root = await appRoot(page)
		await page.goto(`${root}/runs`, { waitUntil: 'domcontentloaded' })
		await dismissTour(page)

		const boxes = page.getByRole('checkbox', { name: 'Compare this run' })
		await expect(boxes).toHaveCount(3, { timeout: 20_000 })
		await boxes.nth(1).check()
		await boxes.nth(0).check()

		// A third tick is refused, and the two chosen stay chosen.
		await boxes.nth(2).click()
		await expect(page.getByText('Choose two runs to compare.')).toBeVisible()
		await expect(boxes.nth(2)).not.toBeChecked()

		await page.getByRole('button', { name: 'Compare', exact: true }).click()
		await expect(page.locator('.hermiq-run-compare__summary')).toHaveText(
			'Same outcome. Run B called Read file once more. Run B took 4.2 s longer.',
		)
		await expect(page.getByText('Only in run B')).toBeVisible()
		await expect(
			page.getByText('Not recorded for this run').first(),
		).toBeVisible()
	})

	test('A run the caller may not see is answered like a missing run', async ({
		page,
		request,
	}) => {
		const token = await harvestToken(page)
		const res = await request.get(
			'/index.php/apps/hermiq/api/runs/compare?left=no-such-run&right=no-such-run-either',
			{ headers: jsonHeaders(token) },
		)
		expect(res.status()).toBe(404)
		expect((await res.json()).missing).toEqual(['left', 'right'])
	})

	test('A process owner sees where a flow run went different', async ({
		page,
	}) => {
		const flowRun = (
			uuid: string,
			flowVersion: number,
			status: string,
			log: object[],
		) => ({
			uuid,
			flowId: 'flow-intake',
			flowVersion,
			status,
			created: '2026-09-28T09:00:00+00:00',
			log,
		})
		const done = flowRun('fr-1', 4, 'completed', [
			{ transition: 'intake', status: 'completed', durationMs: 20 },
			{ transition: 'check', status: 'completed', durationMs: 40 },
		])
		const failed = flowRun('fr-2', 5, 'failed', [
			{ transition: 'intake', status: 'completed', durationMs: 25 },
			{ transition: 'check', status: 'failed', durationMs: 90 },
		])
		await page.route('**/apps/openregister/api/flows', (route) =>
			route.fulfill({
				json: {
					results: [
						{ uuid: 'flow-intake', name: 'Vergunningaanvraag intake' },
					],
				},
			}),
		)
		await page.route('**/apps/openregister/api/flow-runs?**', (route) =>
			route.fulfill({
				json: { results: [done, failed], limit: 50, offset: 0 },
			}),
		)
		await page.route('**/apps/openregister/api/flow-runs/fr-*', (route) =>
			route.fulfill({
				json: route.request().url().endsWith('fr-1') ? done : failed,
			}),
		)

		const root = await appRoot(page)
		await page.goto(`${root}/flow-runs/compare?flow=flow-intake`, {
			waitUntil: 'domcontentloaded',
		})
		await dismissTour(page)

		const boxes = page.getByRole('checkbox', { name: 'Compare this run' })
		await expect(boxes).toHaveCount(2, { timeout: 20_000 })
		await boxes.nth(0).check()
		await boxes.nth(1).check()
		await page.getByRole('button', { name: 'Compare', exact: true }).click()

		await expect(
			page.getByText(
				'These runs used different versions of the flow (4 and 5).',
			),
		).toBeVisible()
		await expect(page.locator('.hermiq-flow-compare__row--differs')).toHaveCount(
			1,
		)
	})
})
