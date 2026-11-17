/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spec-coverage e2e: an administrator declares which inputs a model reads
 * natively, in the LLM provider settings (chat-attachments-and-images task 4).
 *
 * The registry itself is covered by PHPUnit (ModelCapabilityRegistryTest,
 * LlmSettingsModelCapabilitiesTest). Here: the declaration round-trips through the
 * settings endpoint, and the modal shows the ticks for the model typed in it. The
 * model id carries the e2espec- family prefix so no real model's declaration is
 * touched; the instance's provider choice is never saved (the modal is cancelled).
 * A declaration cannot be deleted, only set to "neither", so afterAll leaves one
 * empty `ollama/<prefix>-llava` entry behind.
 *
 * Run against a running Nextcloud with Hermiq + OpenRegister installed:
 *
 *     NEXTCLOUD_URL=http://localhost:8080 NC_USER=admin NC_PASS=admin \
 *       npx playwright test --project=chromium chat-attachments-model-capabilities
 *
 * Covers openspec/changes/chat-attachments-and-images/specs/chat-attachments/spec.md (REQ-CATT-004).
 */

import { expect, test } from '@playwright/test'
import { harvestToken, TEST_PREFIX } from './_fixtures.ts'

const API = '/index.php/apps/hermiq/api'
const MODEL = `${TEST_PREFIX}-llava`
const KEY = `ollama/${MODEL}`

test.describe('chat-attachments: declared model capabilities', () => {
	let token = ''

	test.beforeAll(async ({ browser }) => {
		const page = await browser.newPage()
		token = await harvestToken(page)
		await page.close()
	})

	test.afterAll(async ({ browser }) => {
		const page = await browser.newPage()
		await page.request.patch(`${API}/settings/llm`, {
			headers: { 'OCS-APIRequest': 'true', requesttoken: token },
			data: { llm: { modelCapabilities: { [KEY]: [] } } },
		})
		await page.close()
	})

	test('ticking "Reads images" is stored for that model only, and the modal shows it', async ({
		page,
	}) => {
		const headers = { 'OCS-APIRequest': 'true', requesttoken: token }

		const saved = await page.request.patch(`${API}/settings/llm`, {
			headers,
			data: { llm: { modelCapabilities: { [KEY]: ['image'] } } },
		})
		expect(saved.ok()).toBeTruthy()

		// An unknown capability is refused, not stored.
		const refused = await page.request.patch(`${API}/settings/llm`, {
			headers,
			data: { llm: { modelCapabilities: { [KEY]: ['audio'] } } },
		})
		expect(refused.status()).toBe(422)

		const read = await page.request.get(`${API}/settings/llm`, { headers })
		const body = await read.json()
		expect(body.modelCapabilities[KEY]).toEqual(['image'])
		expect(body.modelCapabilities[`ollama/${TEST_PREFIX}-other`]).toBeUndefined()

		await page.goto('/index.php/settings/admin/hermiq')
		await page.getByRole('button', { name: /configure provider/i }).click()
		await page.getByLabel('Provider').click()
		await page.getByRole('option', { name: 'Ollama (local)' }).click()
		await page.getByLabel('Model').fill(MODEL)

		const box = page.getByTestId('llm-model-capabilities')
		await expect(
			box.getByRole('checkbox', { name: 'Reads images' }),
		).toBeChecked()
		await expect(
			box.getByRole('checkbox', { name: 'Reads PDFs' }),
		).not.toBeChecked()

		// An undeclared model shows nothing ticked.
		await page.getByLabel('Model').fill(`${TEST_PREFIX}-other`)
		await expect(
			box.getByRole('checkbox', { name: 'Reads images' }),
		).not.toBeChecked()

		// Leave without saving: the instance's provider choice must not change.
		await page.getByRole('button', { name: 'Cancel' }).click()
	})
})
