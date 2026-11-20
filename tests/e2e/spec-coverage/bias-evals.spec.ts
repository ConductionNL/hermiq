/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The bias test set template and the factsheet's bias check
 * (reuse-scheduled-bias-evals, REQ-BIASEVAL-002 and REQ-BIASEVAL-004).
 *
 * WHAT THE UNIT TESTS CANNOT SHOW. SeedBiasEvalTemplateTest, EvalTemplateCopierTest
 * and EvalTemplateControllerTest pin the seed, the copy and the owner guard;
 * BiasCheckEvidenceTest pins how the latest check is chosen. They cannot show that
 * the seeded template reaches the evals page, that "Use for this agent" opens an
 * editable copy with twenty pairs, or that the factsheet dialog prints the check
 * and links to its dataset. Those sit between the manifest, nextcloud-vue and
 * OpenRegister, so they are here.
 *
 * The template is the one the repair step seeds; the agent, the copy and the
 * seeded run carry this run's prefix and are removed afterwards. The administrator
 * runs it (the project's admin storage state); base URL from the environment.
 *
 * Not run when written: it needs a live instance with the repair step applied.
 *
 * @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md
 */

import { expect, test } from '@playwright/test'
import { appRoot, deleteObject, dismissTour, harvestToken, OR_API, seedAgent, seedObject, TEST_PREFIX } from './_fixtures.ts'

const TEMPLATE_NAME = 'Bias check: public services (template)'
const AGENT_NAME = `${TEST_PREFIX}-Permit intake helper`

test.describe('Bias test set template and factsheet bias check', () => {
	const seeded: Array<{ schema: string, id: string }> = []
	let token = ''
	let agentId = ''

	test.beforeAll(async ({ browser, request }) => {
		const page = await browser.newPage()
		token = await harvestToken(page)
		await page.close()

		const agent = await seedAgent(request, token, { name: AGENT_NAME })
		agentId = agent.id
		seeded.push({ schema: 'agent', id: agent.id })
	})

	test.afterAll(async ({ request }) => {
		for (const { schema, id } of seeded.reverse()) {
			await deleteObject(request, token, schema, id)
		}
	})

	/**
	 * @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-ready-made-bias-test-set-can-be-used-for-an-agent-req-biaseval-002
	 */
	test('an owner adds the bias check to an agent @spec agent-evals', async ({ page, request }) => {
		await page.goto(`${await appRoot(page)}/evals`, { waitUntil: 'domcontentloaded' })
		await dismissTour(page)

		await page.getByText(TEMPLATE_NAME, { exact: true }).first().click()
		await expect(page.getByText('This test set is a template'), 'the template says it is read-only').toBeVisible({ timeout: 30_000 })
		await expect(page.getByRole('button', { name: 'Run', exact: true }), 'a template is not run directly').toHaveCount(0)

		await page.getByLabel('Agent').click()
		await page.getByRole('option', { name: AGENT_NAME }).click()
		await page.getByRole('button', { name: 'Use for this agent' }).click()

		// The copy opens: an ordinary dataset with Run, named after the agent.
		await expect(page.getByRole('button', { name: 'Run', exact: true }), 'the copy can be run').toBeVisible({ timeout: 30_000 })
		await expect(page.getByText('This test set is a template'), 'the copy is not a template').toHaveCount(0)

		const copyId = page.url().split('/').pop() ?? ''
		expect(copyId, 'the copy has its own page').not.toBe('')
		seeded.push({ schema: 'evaldataset', id: copyId })

		const copy = await request.get(`${OR_API}/objects/hermiq/evaldataset/${copyId}`, { headers: { requesttoken: token } })
		expect(copy.ok()).toBe(true)
		const body = await copy.json()
		expect(body.agentId, 'the copy is linked to the agent').toBe(agentId)
		expect(body.cases, 'all twenty pairs are copied').toHaveLength(20)
		expect(body.template ?? false, 'the copy can be edited').toBe(false)
	})

	/**
	 * @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-the-latest-bias-check-is-visible-as-evidence-req-biaseval-004
	 */
	test('a compliance officer reads the bias check on the factsheet @spec agent-evals', async ({ page, request }) => {
		const dataset = await seedObject(request, token, 'evaldataset', {
			name: `${TEST_PREFIX}-bias copy`,
			templateKey: 'bias-public-services',
			agentId,
			cases: [],
		})
		seeded.push({ schema: 'evaldataset', id: dataset.id })
		const run = await seedObject(request, token, 'evalrun', {
			datasetId: dataset.id,
			agentId,
			status: 'completed',
			startedAt: '2026-09-01T06:00:00Z',
			passRate: 0.95,
			results: [],
		})
		seeded.push({ schema: 'evalrun', id: run.id })

		await page.goto(`${await appRoot(page)}/agents/${agentId}`, { waitUntil: 'domcontentloaded' })
		await dismissTour(page)
		await page.getByRole('button', { name: /factsheet/i }).first().click()

		await expect(page.getByText('Bias check', { exact: true }), 'the factsheet names the bias check').toBeVisible({ timeout: 30_000 })
		const link = page.getByRole('link', { name: '95% on 2026-09-01' })
		await expect(link, 'the check reads as pass rate on date').toBeVisible()
		await link.click()
		await expect(page).toHaveURL(new RegExp(`/evals/${dataset.id}$`))
	})
})
