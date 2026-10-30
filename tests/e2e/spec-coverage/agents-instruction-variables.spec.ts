/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spec-coverage e2e: placeholders and start fields (agents-instruction-variables).
 *
 * Drives the agent form's preview of the filled-in instructions and the chat
 * page's start fields: the send button waits for the required choice, and once
 * the answer is stored the session header shows it. The answer is stored before
 * the message goes, so the header check does not need a model to reply. Every
 * seeded object carries the e2espec- family prefix and is removed in afterAll.
 *
 * Run against a running Nextcloud with Hermiq + OpenRegister installed:
 *
 *     NEXTCLOUD_URL=http://localhost:8080 NC_USER=admin NC_PASS=admin \
 *       npx playwright test --project=chromium agents-instruction-variables
 *
 * Covers openspec/specs/agent-management-ui/spec.md (REQ-AGVAR-001, REQ-AGVAR-002).
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

const API = '/index.php/apps/hermiq/api'

test.describe('agents-instruction-variables: preview and start fields', () => {
	let token = ''
	let agentId = ''

	test.beforeAll(async ({ browser }) => {
		const page = await browser.newPage()
		token = await harvestToken(page)
		const seeded = await seedAgent(page.request, token, {
			name: `${TEST_PREFIX}-vars`,
			prompt: 'Address {{user.displayName}}. Today is {{today}}. Department: {{field.department}}.',
			startFields: [
				{
					key: 'department',
					label: 'Department',
					type: 'select',
					options: ['Permits', 'Taxes'],
					required: true,
				},
			],
		})
		agentId = seeded.id
		await page.close()
	})

	test.afterAll(async ({ browser }) => {
		const page = await browser.newPage()
		await cleanupFamily(page.request, token)
		await page.close()
	})

	// @e2e agent-management-ui::the-owner-previews-the-filled-in-instructions
	test('the owner previews the instructions with her own name and today', async ({
		page,
	}) => {
		const response = await page.request.post(
			`${API}/agents/${agentId}/prompt-preview`,
			{
				headers: { 'OCS-APIRequest': 'true', requesttoken: token },
				data: { sampleValues: { department: 'Permits' } },
			},
		)
		expect(response.status()).toBe(200)
		const body = await response.json()
		const today = new Date().toISOString().slice(0, 10)
		expect(body.text).not.toContain('{{user.displayName}}')
		expect(body.text).toContain('Department: Permits.')
		expect(body.text).toContain(today.slice(0, 7))

		await page.goto(`${await appRoot(page)}/agents/${agentId}`)
		await dismissTour(page)
		await page.getByRole('button', { name: 'Edit agent' }).click()
		await page.getByTestId('agent-form-preview-prompt').click()
		await expect(page.getByTestId('agent-form-prompt-preview')).toContainText(
			'Department:',
		)
	})

	// @e2e agent-management-ui::a-person-picks-a-department-before-asking
	test('the chat page asks for the department first and the header shows it', async ({
		page,
	}) => {
		const created = await page.request.post(`${API}/sessions`, {
			headers: { 'OCS-APIRequest': 'true', requesttoken: token },
			data: { agentUuid: agentId, title: `${TEST_PREFIX}-vars-session` },
		})
		expect(created.status()).toBe(201)
		const session = await created.json()

		expect(session.uuid).toBeTruthy()

		await page.goto(`${await appRoot(page)}/chat`)
		await dismissTour(page)
		await page.getByText(`${TEST_PREFIX}-vars-session`).first().click()
		await expect(page.getByTestId('chat-start-fields')).toBeVisible()

		await page
			.getByRole('textbox', { name: 'Message' })
			.fill('Kan ik een dakkapel bouwen?')
		await expect(
			page.getByRole('button', { name: 'Send message' }),
		).toBeDisabled()

		await page
			.getByTestId('chat-start-field-department')
			.getByRole('combobox')
			.click()
		await page.getByRole('option', { name: 'Permits' }).click()
		await expect(
			page.getByRole('button', { name: 'Send message' }),
		).toBeEnabled()
		await page.getByRole('button', { name: 'Send message' }).click()

		await expect(page.getByTestId('chat-start-values')).toContainText(
			'Department: Permits',
		)
	})
})
