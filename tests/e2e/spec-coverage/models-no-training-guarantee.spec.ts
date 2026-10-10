/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spec-coverage e2e: what a provider does with your data, and the policy switch
 * that refuses providers which may train (models-no-training-guarantee).
 *
 * The gate itself, the chat message and the run record are covered by PHPUnit
 * (ProviderDataUseTest, ProviderFactoryTest, FeatureProviderResolverTest,
 * ChatControllerTest, ScheduleServiceTest, AnalyticsServiceTest); the scenarios
 * here are the two screens.
 *
 * Run against a running Nextcloud with Hermiq + OpenRegister installed:
 *
 *     NEXTCLOUD_URL=http://localhost:8080 NC_USER=admin NC_PASS=admin \
 *       npx playwright test --project=chromium models-no-training-guarantee
 *
 * Covers openspec/specs/provider-data-use/spec.md
 */

import { expect, test } from '@playwright/test'
import { harvestToken } from './_fixtures.ts'

const API = '/index.php/apps/hermiq/api'

test.describe('models-no-training-guarantee: declarations and the policy switch', () => {
	let token = ''

	test.beforeAll(async ({ browser }) => {
		const page = await browser.newPage()
		token = await harvestToken(page)
		await page.close()
	})

	// @e2e provider-data-use::an-admin-records-the-term-for-anthropic
	test('a data-use statement is stored with who declared it and shown on the provider form', async ({
		page,
	}) => {
		const headers = { 'OCS-APIRequest': 'true', requesttoken: token }
		const put = await page.request.put(
			`${API}/settings/provider-data-use/anthropic`,
			{
				headers,
				data: {
					dataUse: 'no-training',
					termsReference: 'Anthropic commercial terms, checked 2026-09-01',
				},
			},
		)
		expect(put.ok()).toBeTruthy()
		const stored = await put.json()
		expect(stored.dataUse).toBe('no-training')
		expect(stored.declaredBy).not.toBe('')

		// An unknown value is refused, not stored as something that reads safe.
		const refused = await page.request.put(
			`${API}/settings/provider-data-use/anthropic`,
			{
				headers,
				data: { dataUse: 'sometimes' },
			},
		)
		expect(refused.status()).toBe(422)

		const read = await page.request.get(`${API}/settings/provider-data-use`, {
			headers,
		})
		const body = await read.json()
		expect(body.dataUse.anthropic.termsReference).toBe(
			'Anthropic commercial terms, checked 2026-09-01',
		)
	})

	// @e2e provider-data-use::the-residency-gets-a-screen-too
	test('the provider form shows where it runs and what it does with your data', async ({
		page,
	}) => {
		await page.goto('/index.php/settings/admin/hermiq')
		await page.getByRole('button', { name: /configure provider/i }).click()
		await expect(
			page.getByRole('heading', { name: 'Where it runs' }),
		).toBeVisible()
		await expect(
			page.getByRole('heading', { name: 'What it does with your data' }),
		).toBeVisible()
	})

	// @e2e provider-data-use::an-organisation-admin-switches-the-requirement-on
	test('switching the requirement on names the providers it would refuse, before saving', async ({
		page,
	}) => {
		const headers = { 'OCS-APIRequest': 'true', requesttoken: token }
		const list = await page.request.get(`${API}/model-policy`, { headers })
		const payload = await list.json()
		expect(
			payload.dataUse,
			"the list carries each provider's data-use label",
		).toBeTruthy()
		const policies = payload.policies ?? []
		test.skip(
			policies.length === 0,
			'No model policy exists on this instance, and creating an instance default here would restrict every agent on it.',
		)

		await page.goto('/index.php/apps/hermiq/tenant-ops')
		await page.getByRole('button', { name: 'Edit' }).first().click()
		await page.getByLabel('Allowed providers and models').fill('openai')
		await page
			.getByText('Only use providers that never train on our data')
			.click()
		const undeclared =
			payload.dataUse.openai !== 'no-training'
			&& payload.dataUse.openai !== 'zero-retention'
		if (undeclared) {
			await expect(
				page.getByText('Runs on this provider will be refused.'),
			).toBeVisible()
		}
		// Leave without saving: this test must not change the instance's policy.
		await page.getByRole('button', { name: 'Cancel' }).first().click()
	})
})
