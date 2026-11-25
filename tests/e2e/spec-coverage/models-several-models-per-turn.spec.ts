/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * models-several-models-per-turn: the fallback chain on the Tenant operations
 * page, the attempts of a run that fell back on the Runs page, and the
 * ensemble setting on the agent form.
 *
 * What is real and what is answered by page.route, and why:
 * - The model policy list and its save are answered by page.route. A real
 *   write changes the instance-wide default policy for every other suite on
 *   the same instance, and the refusal of a fallback outside the policy is
 *   pinned by OrganisationModelPolicyWriteTest. The assertions here are on
 *   what the editor sends (the chain, in order) and on what it shows.
 * - A run row is an OpenRegister audit entry that only the run path writes,
 *   so the Runs list is answered with one fallen-back run in the shape
 *   AnalyticsService returns (AnalyticsServiceTest::testARunRowShowsItsProviderAttempts).
 * - The ensemble save goes through the real OpenRegister write and the real
 *   AgentEnsembleGuardListener. Only the effective policy the form reads its
 *   options from is answered, so the two reference providers are on offer on
 *   any instance. The refused save is answered by page.route with the
 *   listener's message, because a real refusal needs an organisation policy
 *   this suite cannot own; AgentEnsembleGuardListenerTest pins the server half.
 *
 * Written from the change's scenarios; not run here, it needs a running Nextcloud.
 *
 * @spec openspec/specs/tenant-model-policy/spec.md
 * @spec openspec/specs/model-ensemble/spec.md
 */

import type { Page, Route } from '@playwright/test'

import { expect, test } from '@playwright/test'
import {
	appRoot,
	cleanupFamily,
	deleteObject,
	dismissTour,
	harvestToken,
	jsonHeaders,
	openAgentForm,
	OR_API,
	seedAgent,
	TEST_PREFIX,
} from './_fixtures.ts'

const API = '/index.php/apps/hermiq/api'

const FALLBACK_REFUSED = 'This fallback is not allowed by the model policy.'
const MODEL_REFUSED =
	"This model is not allowed by your organisation's model policy."
const COST_LINE = 'Each answer costs up to four model calls.'

/** The model policy list endpoint, with or without a query string. */
const POLICY_LIST = /\/apps\/hermiq\/api\/model-policy(\?.*)?$/

/** One policy's save endpoint. */
const POLICY_SAVE = /\/apps\/hermiq\/api\/model-policy\/e2e-policy(\?.*)?$/

/** The effective policy the agent form reads. */
const POLICY_EFFECTIVE = /\/apps\/hermiq\/api\/model-policy\/effective(\?.*)?$/

/** The cross-agent run list. */
const RUN_LIST = /\/apps\/hermiq\/api\/runs(\?.*)?$/

/**
 * A stored model policy, as the list endpoint returns it.
 *
 * @param allowed       The allowed providers and their models.
 * @param fallbackChain The stored fallback chain.
 * @return The policy row.
 */
function policyRow(
	allowed: Array<{ provider: string; models: string[] }>,
	fallbackChain: Array<{ provider: string; model: string }> = [],
): Record<string, unknown> {
	return {
		id: 'e2e-policy',
		organisation: '',
		source: 'instance',
		allowed,
		defaultModel: null,
		requireNoTraining: false,
		fallbackChain,
	}
}

/**
 * Answer the policy list with `current()`, and record every save. A save is
 * answered by `onSave`, which returns the HTTP status and body.
 *
 * @param page    The Playwright page.
 * @param current The policy the list returns, read on every request.
 * @param onSave  How a save is answered.
 * @return The bodies of the saves the editor sent.
 */
async function fakePolicies(
	page: Page,
	current: () => Record<string, unknown>,
	onSave: (body: Record<string, unknown>) => { status: number; body: unknown },
): Promise<Array<Record<string, unknown>>> {
	const saves: Array<Record<string, unknown>> = []
	await page.route(POLICY_LIST, async (route: Route) => {
		if (route.request().method() !== 'GET') {
			await route.fallback()
			return
		}
		// The list answers `{ policies, dataUse }`, as GET /api/model-policy does.
		await route.fulfill({ json: { policies: [current()], dataUse: {} } })
	})
	await page.route(POLICY_SAVE, async (route: Route) => {
		const body = route.request().postDataJSON() as Record<string, unknown>
		saves.push(body)
		const answer = onSave(body)
		await route.fulfill({ status: answer.status, json: answer.body })
	})
	return saves
}

/**
 * Open the Tenant operations page and the editor of the one policy.
 *
 * @param page The Playwright page.
 */
async function openPolicyEditor(page: Page): Promise<void> {
	await page.goto(`${await appRoot(page)}/tenant-ops`, {
		waitUntil: 'domcontentloaded',
	})
	await dismissTour(page)
	const policy = page.locator('.tenant-ops__policy').first()
	await expect(policy).toBeVisible({ timeout: 20_000 })
	await policy.getByRole('button', { name: 'Edit' }).click()
	await expect(page.locator('.tenant-ops__fallbacks')).toBeVisible()
}

/**
 * Fill the last fallback row with a provider and a model.
 *
 * @param page     The Playwright page.
 * @param provider The provider.
 * @param model    The model.
 */
async function fillLastFallback(
	page: Page,
	provider: string,
	model: string,
): Promise<void> {
	const row = page.locator('.tenant-ops__fallback').last()
	await row.getByLabel('Provider').fill(provider)
	await row.getByLabel('Model').fill(model)
}

test.describe('models-several-models-per-turn: the fallback chain', () => {
	// @e2e tenant-model-policy::an-organisation-admin-adds-a-local-model-as-fallback
	test('an organisation admin adds a local model as fallback, and the chain is saved in order', async ({
		page,
	}) => {
		const allowed = [
			{ provider: 'anthropic', models: ['claude-opus-4-8'] },
			{ provider: 'ollama', models: ['qwen2.5', 'llama3.1'] },
		]
		let stored = policyRow(allowed)
		const saves = await fakePolicies(
			page,
			() => stored,
			(body) => {
				stored = policyRow(
					allowed,
					body.fallbackChain as Array<{ provider: string; model: string }>,
				)
				return { status: 200, body: stored }
			},
		)

		await openPolicyEditor(page)
		const editor = page.locator('.tenant-ops__fallbacks')

		await editor.getByRole('button', { name: 'Add fallback' }).click()
		await fillLastFallback(page, 'ollama', 'llama3.1')
		await editor.getByRole('button', { name: 'Add fallback' }).click()
		await fillLastFallback(page, 'ollama', 'qwen2.5')

		// Reorder: qwen2.5 goes first, then the first row is no longer movable up.
		await page
			.locator('.tenant-ops__fallback')
			.nth(1)
			.getByRole('button', { name: 'Move fallback up' })
			.click()
		await expect(
			page
				.locator('.tenant-ops__fallback')
				.first()
				.getByRole('button', { name: 'Move fallback up' }),
		).toBeDisabled()

		await page.getByRole('button', { name: 'Save policy' }).click()

		await expect.poll(() => saves.length).toBe(1)
		expect(
			saves[0].fallbackChain,
			'the chain is sent in the order shown',
		).toEqual([
			{ provider: 'ollama', model: 'qwen2.5' },
			{ provider: 'ollama', model: 'llama3.1' },
		])

		// The editor closes and the policy's summary names the chain.
		await expect(page.locator('.tenant-ops__fallbacks')).toHaveCount(0)
		await expect(page.locator('.tenant-ops__policy').first()).toContainText(
			'qwen2.5',
		)
	})

	test('a removed fallback is not sent, and at most four can be added', async ({
		page,
	}) => {
		const allowed = [{ provider: 'ollama', models: [] }]
		const saves = await fakePolicies(
			page,
			() => policyRow(allowed, [{ provider: 'ollama', model: 'qwen2.5' }]),
			() => ({ status: 200, body: policyRow(allowed) }),
		)

		await openPolicyEditor(page)
		const editor = page.locator('.tenant-ops__fallbacks')
		await expect(page.locator('.tenant-ops__fallback')).toHaveCount(1)

		for (let added = 1; added < 4; added++) {
			await editor.getByRole('button', { name: 'Add fallback' }).click()
		}
		await expect(page.locator('.tenant-ops__fallback')).toHaveCount(4)
		await expect(
			editor.getByRole('button', { name: 'Add fallback' }),
		).toBeDisabled()

		// Remove every row: the chain sent is empty.
		while ((await page.locator('.tenant-ops__fallback').count()) > 0) {
			await page
				.locator('.tenant-ops__fallback')
				.first()
				.getByRole('button', { name: 'Remove fallback' })
				.click()
		}
		await page.getByRole('button', { name: 'Save policy' }).click()
		await expect.poll(() => saves.length).toBe(1)
		expect(saves[0].fallbackChain).toEqual([])
	})

	// @e2e tenant-model-policy::a-fallback-outside-the-policy-is-refused
	test('a fallback outside the policy is refused, and the stored policy is unchanged', async ({
		page,
	}) => {
		const allowed = [{ provider: 'ollama', models: ['qwen2.5'] }]
		const saves = await fakePolicies(
			page,
			() => policyRow(allowed),
			() => ({ status: 400, body: { error: FALLBACK_REFUSED } }),
		)

		await openPolicyEditor(page)
		await page
			.locator('.tenant-ops__fallbacks')
			.getByRole('button', { name: 'Add fallback' })
			.click()
		await fillLastFallback(page, 'openai', 'gpt-4o-mini')
		await page.getByRole('button', { name: 'Save policy' }).click()

		await expect.poll(() => saves.length).toBe(1)
		expect(saves[0].fallbackChain).toEqual([
			{ provider: 'openai', model: 'gpt-4o-mini' },
		])

		// The refusal is shown under the editor, and the editor stays open with the row.
		await expect(
			page.locator('.tenant-ops__policy-edit').getByText(FALLBACK_REFUSED),
		).toBeVisible()
		await expect(page.locator('.tenant-ops__fallback')).toHaveCount(1)

		// Cancelling shows the stored policy, which names no fallback.
		await page
			.locator('.tenant-ops__policy')
			.first()
			.getByRole('button', { name: 'Cancel' })
			.click()
		await expect(page.locator('.tenant-ops__policy').first()).not.toContainText(
			'gpt-4o-mini',
		)
	})
})

test.describe('models-several-models-per-turn: the attempts on the run record', () => {
	// @e2e tenant-model-policy::a-privacy-officer-reads-where-a-prompt-went
	test('a run that fell back shows both attempts in order and who received the prompt', async ({
		page,
	}) => {
		const run = {
			id: `${TEST_PREFIX}-run`,
			created: new Date().toISOString(),
			agentId: '',
			agentName: `${TEST_PREFIX}-agent`,
			status: 'success',
			trigger: 'schedule',
			durationMs: 4200,
			summary: 'Weekly permit overview sent.',
			providerDisclosure: {
				provider: 'ollama',
				model: 'qwen2.5',
				dataUse: 'no-training',
				termsReference: '',
			},
			providerAttempts: [
				{
					provider: 'anthropic',
					model: 'claude-opus-4-8',
					outcome: 'failed',
					reason: 'rate_limit',
					durationMs: 300,
				},
				{
					provider: 'ollama',
					model: 'qwen2.5',
					outcome: 'answered',
					reason: '',
					durationMs: 3900,
				},
			],
			receivedBy: [
				{ provider: 'anthropic', model: 'claude-opus-4-8' },
				{ provider: 'ollama', model: 'qwen2.5' },
			],
		}
		await page.route(RUN_LIST, async (route: Route) => {
			await route.fulfill({
				json: { results: [run], total: 1, limit: 50, offset: 0 },
			})
		})

		await page.goto(`${await appRoot(page)}/runs`, {
			waitUntil: 'domcontentloaded',
		})
		await dismissTour(page)
		await expect(page.locator('.hermiq-runs')).toBeVisible({ timeout: 20_000 })

		const attempts = page.locator('.hermiq-runs__attempts li')
		await expect(attempts).toHaveCount(2)
		await expect(attempts.nth(0)).toContainText('anthropic claude-opus-4-8')
		await expect(attempts.nth(0)).toContainText('rate limited')
		await expect(attempts.nth(1)).toContainText('ollama qwen2.5')
		await expect(attempts.nth(1)).toContainText('answered')

		await expect(
			page.getByText(
				'Received the prompt: anthropic claude-opus-4-8, ollama qwen2.5',
			),
		).toBeVisible()
	})
})

test.describe('models-several-models-per-turn: the ensemble on the agent', () => {
	let token = ''
	let seededPolicy = ''

	test.beforeAll(async ({ browser }) => {
		const page = await browser.newPage()
		token = await harvestToken(page)
		// The save is checked on the server against the caller's effective policy,
		// so the policy the form is shown below must also exist there: a clean
		// instance allows none of these models (seen live, 25 Nov 2026).
		const headers = { ...jsonHeaders(token), 'OCS-APIRequest': 'true' }
		const active = await page.request.get('/index.php/apps/openregister/api/organisations/active', { headers })
		const organisation = String((await active.json())?.activeOrganisation?.uuid ?? '')
		const created = await page.request.post(`${API}/model-policy`, {
			headers,
			data: {
				organisation,
				allowed: [
					{ provider: 'anthropic', models: ['claude-opus-4-8'] },
					{ provider: 'ollama', models: ['qwen2.5'] },
					{ provider: 'openai', models: ['gpt-4o-mini'] },
				],
				defaultModel: { provider: 'ollama', model: 'qwen2.5' },
			},
		})
		expect(created.ok(), `seed the model policy: HTTP ${created.status()}`).toBeTruthy()
		seededPolicy = String((await created.json())?.id ?? '')
		await page.close()
	})

	test.afterAll(async ({ browser }) => {
		const page = await browser.newPage()
		await cleanupFamily(page.request, token, 'agent')
		if (seededPolicy !== '') {
			await deleteObject(page.request, token, 'modelpolicy', seededPolicy)
		}
		await page.close()
	})

	/**
	 * Answer the effective policy the agent form reads its options from.
	 *
	 * @param page The Playwright page.
	 */
	async function offerTwoProviders(page: Page): Promise<void> {
		await page.route(POLICY_EFFECTIVE, async (route: Route) => {
			await route.fulfill({
				json: {
					source: 'instance',
					allowed: [
						{ provider: 'anthropic', models: ['claude-opus-4-8'] },
						{ provider: 'ollama', models: ['qwen2.5'] },
						{ provider: 'openai', models: ['gpt-4o-mini'] },
					],
					defaultModel: null,
					requireNoTraining: false,
					fallbackChain: [],
				},
			})
		})
	}

	/**
	 * Pick a provider and a model for one reference row of the ensemble editor.
	 *
	 * @param page     The Playwright page.
	 * @param index    The row, from 0.
	 * @param provider The provider option.
	 * @param model    The model option.
	 */
	async function pickReference(
		page: Page,
		index: number,
		provider: string,
		model: string,
	): Promise<void> {
		const row = page.getByTestId(`agent-form-ensemble-reference-${index}`)
		await row.getByLabel(`Reference provider ${index + 1}`).click()
		await page.getByRole('option', { name: provider, exact: true }).click()
		// The model field is taggable: type the model and take it with Enter, as a
		// person does; its open list is not reachable by role inside the dialog.
		const modelField = row.getByLabel(`Reference model ${index + 1}`, { exact: true })
		await modelField.click()
		await modelField.fill(model)
		await modelField.press('Enter')
	}

	/**
	 * Open the agent form of a freshly seeded agent on its own model.
	 *
	 * @param page The Playwright page.
	 * @param name The agent's name suffix.
	 * @return The seeded agent's id.
	 */
	async function editSeededAgent(page: Page, name: string): Promise<string> {
		const agent = await seedAgent(page.request, token, {
			name: `${TEST_PREFIX}-${name}`,
			provider: 'ollama',
			model: 'qwen2.5',
		})
		await offerTwoProviders(page)
		await page.goto(`${await appRoot(page)}/agents/${agent.id}`, {
			waitUntil: 'domcontentloaded',
		})
		await dismissTour(page)
		await openAgentForm(page)
		return agent.id
	}

	/**
	 * Read the stored agent through OpenRegister.
	 *
	 * @param page The Playwright page.
	 * @param id   The agent id.
	 * @return The stored agent.
	 */
	async function storedAgent(
		page: Page,
		id: string,
	): Promise<Record<string, unknown>> {
		const res = await page.request.get(`${OR_API}/objects/hermiq/agent/${id}`, {
			headers: jsonHeaders(token),
		})
		expect(res.ok(), `read agent: HTTP ${res.status()}`).toBeTruthy()
		return res.json()
	}

	// @e2e model-ensemble::an-agent-owner-sets-up-an-ensemble
	test('an agent owner sets up an ensemble of two reference models', async ({
		page,
	}) => {
		const id = await editSeededAgent(page, 'ensemble')

		const editor = page.getByTestId('agent-form-ensemble')
		await editor
			// The test id lands on the switch's hidden input, whose label takes the click.
			.getByText('Answer with several models', { exact: true })
			.click()
		await expect(page.getByTestId('agent-form-ensemble-cost')).toHaveText(
			COST_LINE,
		)

		// Fewer than two is not an ensemble yet, and the form says so.
		while (
			(await editor
				.locator('[data-testid^="agent-form-ensemble-reference-"]')
				.count()) < 2
		) {
			await editor.getByRole('button', { name: 'Add reference model' }).click()
		}
		await pickReference(page, 0, 'anthropic', 'claude-opus-4-8')
		await pickReference(page, 1, 'ollama', 'qwen2.5')
		await expect(page.getByTestId('agent-form-ensemble-too-few')).toHaveCount(0)

		await page.getByRole('button', { name: 'Save' }).last().click()
		await expect(page.locator('.agent-form')).toHaveCount(0, { timeout: 20_000 })

		// The Configuration card shows its first fields; the rest fold behind "Show all".
		const showAll = page.getByRole('button', { name: /^Show all \d+ fields$/ })
		if ((await showAll.count()) > 0) {
			await showAll.first().click()
		}
		await expect(
			page.getByText('Ensemble: 2 reference models').first(),
		).toBeVisible()

		const stored = await storedAgent(page, id)
		expect(stored.ensemble).toEqual({
			enabled: true,
			referenceModels: [
				{ provider: 'anthropic', model: 'claude-opus-4-8' },
				{ provider: 'ollama', model: 'qwen2.5' },
			],
		})
	})

	// @e2e model-ensemble::a-reference-model-outside-the-policy-is-refused
	test('a reference model outside the policy is refused and the agent is unchanged', async ({
		page,
	}) => {
		const id = await editSeededAgent(page, 'ensemble-refused')

		// The listener's refusal, as OpenRegister's object save answers it.
		await page.route(
			new RegExp(`/apps/openregister/api/objects/hermiq/agent/${id}(\\?.*)?$`),
			async (route: Route) => {
				if (route.request().method() !== 'PUT') {
					await route.fallback()
					return
				}
				await route.fulfill({ status: 400, json: MODEL_REFUSED })
			},
		)

		const editor = page.getByTestId('agent-form-ensemble')
		await editor
			// The test id lands on the switch's hidden input, whose label takes the click.
			.getByText('Answer with several models', { exact: true })
			.click()
		while (
			(await editor
				.locator('[data-testid^="agent-form-ensemble-reference-"]')
				.count()) < 2
		) {
			await editor.getByRole('button', { name: 'Add reference model' }).click()
		}
		await pickReference(page, 0, 'openai', 'gpt-4o-mini')
		await pickReference(page, 1, 'ollama', 'qwen2.5')

		await page.getByRole('button', { name: 'Save' }).last().click()
		await expect(
			page.locator('.agent-form').getByText(MODEL_REFUSED),
		).toBeVisible()

		const stored = await storedAgent(page, id)
		expect(
			(stored.ensemble as { enabled?: boolean } | undefined)?.enabled ?? false,
			'the stored agent has no ensemble',
		).toBe(false)
	})
})
