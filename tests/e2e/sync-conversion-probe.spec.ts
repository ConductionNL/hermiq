/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Live probe for the Vue-3 two-way-binding sweep (dead `.sync` conversion).
 *
 * Under Vue 3 the Vue-2 `:value.sync` / `:checked` two-way patterns render but
 * NEVER propagate updates (the AgentFormModal bug class fixed in a209755e): typing
 * never reaches the form model, so gates like `:disabled="!form.name"` stay
 * disabled forever and saves persist stale values. This probe exercises the two
 * most user-critical converted surfaces end-to-end on a live instance:
 *
 *   1. ScheduleFormModal (agent run-operations widget): typing the schedule name
 *      enables Save (v-model on NcTextField), toggling "Requires approval"
 *      reveals the reviewer section (v-model on NcCheckboxRadioSwitch), and the
 *      save round-trips.
 *   2. LlmProviderModal (admin settings): the Ollama URL + model NcTextFields
 *      round-trip through save and reopen with the typed values.
 *
 * Run: NEXTCLOUD_URL=http://localhost:8091 npx playwright test tests/e2e/sync-conversion-probe.spec.ts
 */

import { test, expect, type Page } from '@playwright/test'

const NC_USER = process.env.NC_USER || 'admin'
const NC_PASS = process.env.NC_PASS || 'admin'

/**
 * Log in through Nextcloud's real login form (idempotent — mirrors
 * dashboard-and-agents.spec.ts).
 *
 * @param page The Playwright page.
 */
async function login(page: Page): Promise<void> {
	await page.goto('/login', { waitUntil: 'domcontentloaded' })

	const userField = page.locator('#user')
	if ((await userField.count()) === 0) {
		return
	}

	await userField.fill(NC_USER)
	await page.locator('#password').fill(NC_PASS)
	await page.locator('button[type="submit"], input[type="submit"]').first().click()
	await page.locator('#user').waitFor({ state: 'hidden', timeout: 30_000 })
}

/**
 * Resolve one existing agent uuid through the session-authenticated objects API.
 *
 * @param page The logged-in Playwright page.
 */
async function firstAgentUuid(page: Page): Promise<string | null> {
	const response = await page.request.get(
		'/index.php/apps/openregister/api/objects/hermiq/agent?_limit=1',
	)
	if (!response.ok()) {
		return null
	}
	const body = await response.json()
	const first = (body.results || [])[0]
	return (first && (first.id || (first['@self'] || {}).id)) || null
}

test.describe('dead .sync conversion probe (Vue 3 two-way bindings)', () => {
	test.beforeEach(async ({ page }) => {
		await login(page)
	})

	test('ScheduleFormModal: name typing enables Save, approval switch reveals reviewer, save round-trips', async ({
		page,
	}) => {
		const agentUuid = await firstAgentUuid(page)
		test.skip(
			agentUuid === null,
			'No agent exists on this instance to attach a schedule to.',
		)

		await page.goto(`/apps/hermiq/agents/${agentUuid}`, {
			waitUntil: 'domcontentloaded',
		})

		// The run-operations widget offers Attach (no schedule yet) or Edit (existing).
		const attach = page.getByRole('button', { name: 'Attach schedule' })
		const edit = page.getByRole('button', { name: 'Edit schedule' })
		await expect(attach.or(edit).first()).toBeVisible({ timeout: 30_000 })
		await attach.or(edit).first().click()

		const modal = page.locator('.schedule-form')
		await expect(modal).toBeVisible({ timeout: 10_000 })

		const nameField = modal.getByLabel('Name', { exact: true })
		const saveButton = modal.getByRole('button', { name: 'Save', exact: true })

		// The exact dead-binding symptom: with :value.sync the model never updates,
		// so Save (disabled on !form.name) would stay disabled after typing.
		await nameField.fill('')
		await expect(saveButton).toBeDisabled()

		const probeName = `probe-schedule-${Date.now()}`
		await nameField.fill(probeName)
		await expect(saveButton).toBeEnabled()

		// NcCheckboxRadioSwitch v-model: toggling reveals the v-if reviewer section.
		const approvalSwitch = modal.getByText('Requires approval', { exact: true })
		await expect(modal.getByText('Reviewer type')).toHaveCount(0)
		await approvalSwitch.click()
		await expect(modal.getByText('Reviewer type').first()).toBeVisible()
		await approvalSwitch.click()
		await expect(modal.getByText('Reviewer type')).toHaveCount(0)

		// NcTextArea v-model: the prompt reaches the form model and persists.
		const promptProbe = `probe prompt ${Date.now()}`
		await modal.getByLabel('Prompt', { exact: true }).fill(promptProbe)

		await saveButton.click()
		await expect(modal).toBeHidden({ timeout: 15_000 })

		// Round-trip: reopen — the persisted schedule carries the typed values.
		await expect(
			page.getByRole('button', { name: 'Edit schedule' }).first(),
		).toBeVisible({ timeout: 15_000 })
		await page.getByRole('button', { name: 'Edit schedule' }).first().click()
		await expect(modal).toBeVisible({ timeout: 10_000 })
		await expect(modal.getByLabel('Name', { exact: true })).toHaveValue(
			probeName,
		)
		await expect(modal.getByLabel('Prompt', { exact: true })).toHaveValue(
			promptProbe,
		)
	})

	test('LlmProviderModal: Ollama URL + model NcTextFields round-trip through save', async ({
		page,
	}) => {
		await page.goto('/settings/admin/hermiq', { waitUntil: 'domcontentloaded' })

		await page.getByRole('button', { name: 'Configure provider' }).click()

		const modal = page.locator('.llm-provider')
		await expect(modal).toBeVisible({ timeout: 15_000 })

		// Pick the credential-less Ollama provider through the (already-working) NcSelect.
		const providerSelect = modal.getByLabel('Provider', { exact: true })
		await providerSelect.click()
		await page.getByRole('option', { name: /Ollama/ }).click()

		const urlProbe = `http://probe-ollama:11434`
		const modelProbe = `probe-model-${Date.now()}`
		await modal.getByLabel('Ollama URL', { exact: true }).fill(urlProbe)
		await modal.getByLabel('Model', { exact: true }).fill(modelProbe)

		await modal.getByRole('button', { name: 'Save', exact: true }).click()
		await expect(modal).toBeHidden({ timeout: 15_000 })

		// Round-trip: the saved config repopulates the reopened modal.
		await page.getByRole('button', { name: 'Configure provider' }).click()
		await expect(modal).toBeVisible({ timeout: 15_000 })
		await expect(modal.getByLabel('Ollama URL', { exact: true })).toHaveValue(
			urlProbe,
			{ timeout: 15_000 },
		)
		await expect(modal.getByLabel('Model', { exact: true })).toHaveValue(
			modelProbe,
		)
	})
})
