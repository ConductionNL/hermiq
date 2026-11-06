/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * models-bind-a-provider-per-feature: the administrator changes the provider
 * of one AI feature from the AI-feature register in the admin settings.
 *
 * The feature list, the residency overview, the effective model policy and
 * the binding write are answered by page.route, so each scenario controls the
 * policy it needs. The server-side policy check behind the binding write is
 * covered by PHPUnit (AiFeatureControllerTest, AiFeatureBindingServiceTest);
 * this spec proves the register and the dialog.
 */

import { expect, test } from '@playwright/test'
import { harvestToken } from './_fixtures.ts'

const FEATURE = {
	uuid: '11111111-2222-4333-8444-555555555555',
	slug: 'summarise-case',
	name: 'Summarise a case',
	riskCategory: 'limited',
	lifecycle: 'enabled',
}

/**
 * Answer the register's reads and the binding write.
 *
 * @param page    The page.
 * @param state   The binding the residency overview reports; mutated by a save.
 * @param refuse  When set, the binding write answers 422 with this message.
 */
async function stub(page, state, refuse = '') {
	await page.route('**/apps/hermiq/api/ai-features', (route) =>
		route.fulfill({ json: { results: [FEATURE] } }),
	)
	await page.route('**/apps/hermiq/api/ai-features/residency', (route) =>
		route.fulfill({
			json: {
				results: [
					{
						id: FEATURE.uuid,
						slug: FEATURE.slug,
						name: FEATURE.name,
						...state,
					},
				],
			},
		}),
	)
	await page.route('**/apps/hermiq/api/model-policy/effective', (route) =>
		route.fulfill({
			json: {
				source: 'organisation',
				allowed: [
					{ provider: 'ollama', models: ['llama3'] },
					{ provider: 'openai', models: ['gpt-4o'] },
				],
				defaultModel: null,
			},
		}),
	)
	await page.route(
		`**/apps/hermiq/api/ai-features/${FEATURE.uuid}/binding`,
		async (route) => {
			if (refuse) {
				return route.fulfill({ status: 422, json: { error: refuse } })
			}
			const body = route.request().postDataJSON()
			if (body.provider === '') {
				Object.assign(state, {
					provider: null,
					model: null,
					source: 'instance',
					residency: 'undeclared',
					location: '',
				})
			} else {
				Object.assign(state, {
					provider: body.provider,
					model: body.model,
					source: 'feature',
					residency: 'on-premise',
					location: 'this instance',
				})
			}
			return route.fulfill({ json: { uuid: FEATURE.uuid, ...body } })
		},
	)
}

/**
 * Pick an option in the NcSelect with the given label.
 *
 * @param page   The page.
 * @param label  The select's input label.
 * @param option The option text.
 */
async function pick(page, label, option) {
	const dialog = page.getByRole('dialog')
	await dialog.getByRole('combobox', { name: label }).click()
	await page.getByRole('option', { name: option, exact: true }).click()
}

test.describe('hermiq AI-feature register: change provider', () => {
	test.beforeEach(async ({ page }) => {
		await harvestToken(page)
	})

	// @e2e ai-feature-governance::an-administrator-puts-summaries-on-a-local-model
	test('an administrator puts summaries on a local model', async ({ page }) => {
		const state = {
			provider: 'openai',
			model: 'gpt-4o',
			source: 'feature',
			residency: 'outside-eu',
			location: '',
		}
		await stub(page, state)
		await page.goto('/index.php/settings/admin/hermiq', {
			waitUntil: 'domcontentloaded',
		})

		await page
			.getByRole('button', { name: 'Change the provider of this AI feature' })
			.first()
			.click()
		await pick(page, 'Provider', 'ollama')
		await pick(page, 'Model', 'llama3')
		await page.getByRole('dialog').getByRole('button', { name: 'Save' }).click()

		await expect(page.getByRole('dialog')).toHaveCount(0)
		await expect(page.getByText('ollama · llama3')).toBeVisible()
		await expect(page.getByText('On premise').first()).toBeVisible()
	})

	// @e2e ai-feature-governance::a-binding-the-policy-does-not-allow
	test('a refused binding is shown in the dialog and the row is unchanged', async ({
		page,
	}) => {
		const refusal =
			"Refused by the model-policy check: the organisation model policy for 'acme' does not permit provider 'openai' model 'gpt-4o'."
		const state = {
			provider: 'ollama',
			model: 'llama3',
			source: 'feature',
			residency: 'on-premise',
			location: '',
		}
		await stub(page, state, refusal)
		await page.goto('/index.php/settings/admin/hermiq', {
			waitUntil: 'domcontentloaded',
		})

		await page
			.getByRole('button', { name: 'Change the provider of this AI feature' })
			.first()
			.click()
		await pick(page, 'Provider', 'openai')
		await pick(page, 'Model', 'gpt-4o')
		await page.getByRole('dialog').getByRole('button', { name: 'Save' }).click()

		await expect(page.getByRole('dialog').getByText(refusal)).toBeVisible()
		await page
			.getByRole('dialog')
			.getByRole('button', { name: 'Cancel' })
			.click()
		await expect(page.getByText('ollama · llama3')).toBeVisible()
	})

	// @e2e ai-feature-governance::back-to-the-default
	test('an administrator returns a feature to the organisation default', async ({
		page,
	}) => {
		const state = {
			provider: 'ollama',
			model: 'llama3',
			source: 'feature',
			residency: 'on-premise',
			location: '',
		}
		await stub(page, state)
		await page.goto('/index.php/settings/admin/hermiq', {
			waitUntil: 'domcontentloaded',
		})

		await page
			.getByRole('button', { name: 'Change the provider of this AI feature' })
			.first()
			.click()
		await page
			.getByRole('dialog')
			.getByText('Use the organisation default')
			.click()
		await page.getByRole('dialog').getByRole('button', { name: 'Save' }).click()

		await expect(page.getByRole('dialog')).toHaveCount(0)
		await expect(page.getByText('The instance default')).toBeVisible()
	})
})
