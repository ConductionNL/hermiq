/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * models-provider-connections tasks 2 and 4: the provider modal in Admin
 * settings (an OpenAI-compatible endpoint by name, its key field while
 * OpenRegister has no credential type for it, the Fireworks address fields,
 * no subscription sign-in for the organisation), and the subscription
 * sign-in block in hermiq's own settings dialog without the runner.
 *
 * What is real and what is answered by page.route, and why:
 * - The provider save is answered by page.route: a real save switches the
 *   LLM provider of the whole instance under every other suite. What is
 *   asserted is the patch the modal sends. ProviderFactoryOpenAiCompatibleTest
 *   pins the transport the server picks for it.
 * - The sign-in offer is answered with "no runner", so the test reads the
 *   same on an instance that has the llm-runner ExApp. SubscriptionLoginServiceTest
 *   pins the offer itself.
 *
 * Written from the change's scenarios; not run here, it needs a running Nextcloud.
 *
 * @spec openspec/changes/models-provider-connections/specs/llm-provider-connections/spec.md
 * @spec openspec/changes/models-provider-connections/specs/agent-credentials/spec.md
 */

import type { Page, Route } from '@playwright/test'

import { expect, test } from '@playwright/test'

const LLM_SETTINGS = /\/apps\/hermiq\/api\/settings\/llm(\?.*)?$/
const SIGN_IN_OFFER = /\/apps\/hermiq\/api\/subscription-login(\?.*)?$/
const KEY_NEEDS_TYPE =
	"A key needs OpenRegister's OpenAI-compatible credential type."

/**
 * Open Admin settings and the provider modal on one provider.
 *
 * @param page     The Playwright page.
 * @param provider The provider option's label.
 * @return The modal.
 */
async function openProviderModal(page: Page, provider: string) {
	await page.goto('/index.php/settings/admin/hermiq', {
		waitUntil: 'domcontentloaded',
	})
	await page.getByRole('button', { name: 'Use another provider' }).click()
	const modal = page.getByRole('dialog')
	await expect(modal).toBeVisible({ timeout: 20_000 })
	await modal.getByLabel('Provider').click()
	await page.getByRole('option', { name: provider, exact: true }).click()
	return modal
}

/**
 * Record every provider save and answer it as saved, without changing the instance.
 *
 * @param page The Playwright page.
 * @return The patches the modal sent.
 */
async function recordSaves(page: Page): Promise<Array<Record<string, unknown>>> {
	const saves: Array<Record<string, unknown>> = []
	await page.route(LLM_SETTINGS, async (route: Route) => {
		if (route.request().method() !== 'PATCH') {
			await route.fallback()
			return
		}
		const body = route.request().postDataJSON() as Record<string, unknown>
		saves.push(body)
		const response = await route.fetch({ method: 'GET' })
		const current = (await response.json()) as Record<string, unknown>
		await route.fulfill({ json: { ...current, ...body } })
	})
	return saves
}

test.describe('models-provider-connections: the provider modal', () => {
	// @e2e llm-provider-connections::an-admin-connects-a-municipal-vllm-server
	test('an admin connects an OpenAI-compatible endpoint by name', async ({
		page,
	}) => {
		const saves = await recordSaves(page)
		const modal = await openProviderModal(page, 'OpenAI-compatible endpoint')

		await modal
			.getByTestId('llm-compatible-label')
			.locator('input')
			.fill('vLLM Rekencentrum')
		await modal
			.getByTestId('llm-compatible-base-url')
			.locator('input')
			.fill('https://llm.rekencentrum.example.nl/v1')
		await modal.getByLabel('Model', { exact: true }).fill('mistral-small-3.2')
		await modal.getByRole('button', { name: 'Save' }).click()

		await expect.poll(() => saves.length).toBe(1)
		expect(saves[0].chatProvider).toBe('openai-compatible')
		expect(saves[0].openaiCompatibleConfig).toMatchObject({
			label: 'vLLM Rekencentrum',
			baseUrl: 'https://llm.rekencentrum.example.nl/v1',
			chatModel: 'mistral-small-3.2',
			credentialId: '',
			apiMode: 'chat_completions',
		})

		// Current provider names the endpoint by its label (AdminRoot).
		await expect(
			page.getByText('vLLM Rekencentrum (OpenAI-compatible)').first(),
		).toBeVisible()
	})

	// @e2e llm-provider-connections::a-keyed-endpoint-waits-for-the-broker
	test('without the credential type the key field is disabled and the keyless option stays', async ({
		page,
	}) => {
		const modal = await openProviderModal(page, 'OpenAI-compatible endpoint')
		const hint = modal.getByTestId('llm-compatible-credential-hint')
		await expect(hint).toBeVisible()
		test.skip(
			(await hint.textContent())?.trim() !== KEY_NEEDS_TYPE,
			'this OpenRegister lists an OpenAI-compatible credential, so the key field is on',
		)

		await expect(
			modal.getByTestId('llm-compatible-credential').locator('input'),
		).toBeDisabled()
		await modal
			.getByTestId('llm-compatible-base-url')
			.locator('input')
			.fill('https://llm.example.nl/v1')
		await expect(
			modal.getByText('This is a server on our own network'),
		).toBeVisible()
	})

	// @e2e llm-provider-connections::the-fireworks-form-shows-where-calls-go
	test('the Fireworks form shows the host read-only and edits only the API path', async ({
		page,
	}) => {
		const modal = await openProviderModal(page, 'Fireworks AI')

		const host = modal.getByTestId('llm-fireworks-host').locator('input')
		await expect(host).toHaveAttribute('readonly', /.*/)
		await expect(
			modal.getByText('Calls go to the host locked to the credential.'),
		).toBeVisible()
		await expect(
			modal.getByTestId('llm-fireworks-path').getByLabel('API path'),
		).toBeEditable()
	})

	// @e2e llm-provider-connections::a-regional-endpoint
	test('an OpenAI provider saved with an empty base URL sends none', async ({
		page,
	}) => {
		const saves = await recordSaves(page)
		const modal = await openProviderModal(page, 'OpenAI')
		await expect(modal.getByLabel('Base URL (optional)')).toHaveValue('')

		const credential = modal.getByLabel('API credential')
		await credential.click()
		const options = page.getByRole('option')
		test.skip(
			(await options.count()) === 0,
			'no OpenAI credential on this instance, and the save needs one',
		)
		await options.first().click()
		await modal.getByRole('button', { name: 'Save' }).click()

		await expect.poll(() => saves.length).toBe(1)
		const config = saves[0].openaiConfig as Record<string, unknown>
		expect(
			config.baseUrl ?? '',
			'an empty base URL keeps https://api.openai.com/v1',
		).toBe('')
	})

	// @e2e agent-credentials::an-admin-cannot-sign-in-for-the-organisation
	test('the admin form offers no subscription sign-in for the organisation', async ({
		page,
	}) => {
		const modal = await openProviderModal(page, 'Anthropic (Claude)')

		await expect(
			modal.getByRole('button', { name: /Sign in with your/ }),
		).toHaveCount(0)
		await modal.getByLabel('Authentication').click()
		await page
			.getByRole('option', { name: 'Claude Max subscription (OAuth)' })
			.click()
		await expect(
			modal.getByText(/personal-only per the Anthropic Terms of Service/),
		).toBeVisible()
	})
})

test.describe('models-provider-connections: the subscription sign-in', () => {
	// @e2e agent-credentials::no-runner-no-button
	test('without the runner the sign-in buttons are replaced by a line saying so', async ({
		page,
	}) => {
		await page.route(SIGN_IN_OFFER, async (route: Route) => {
			if (route.request().method() !== 'GET') {
				await route.fallback()
				return
			}
			await route.fulfill({ json: { available: false, providers: [] } })
		})

		await page.goto('/index.php/apps/hermiq/', { waitUntil: 'domcontentloaded' })
		const nav = page.locator('[data-testid="cn-nav"]')
		await expect(nav).toBeVisible({ timeout: 30_000 })
		const wizard = page.locator('[data-testid="cn-modal"]')
		if ((await wizard.count()) > 0) {
			await wizard.first().getByRole('button', { name: 'Close' }).click()
		}
		await nav.locator('[data-testid="cn-nav-settings"]').click()
		await nav.locator('[data-testid="cn-nav-personal-settings"]').click()

		const dialog = page.getByRole('dialog')
		await expect(
			dialog.getByRole('heading', { name: 'Subscription sign-in' }),
		).toBeVisible()
		await expect(
			dialog.getByText(
				'Subscription sign-in needs the Hermiq LLM runner app.',
			),
		).toBeVisible()
		await expect(
			dialog.getByRole('button', { name: /Sign in with your/ }),
		).toHaveCount(0)
	})
})
