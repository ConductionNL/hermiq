/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spec-coverage e2e: switch an agent off and on (agents-switch-off-and-stop).
 *
 * Drives the AgentDetail page's "Switch off or on" dialog, the chat refusal of
 * a switched-off agent, the tool call cap on the agent form, and the
 * AgentCatalog delete confirmation that names the agent's schedules. Every
 * seeded object carries the e2espec- family prefix and is removed in afterAll.
 *
 * Run against a running Nextcloud with Hermiq + OpenRegister installed:
 *
 *     NEXTCLOUD_URL=http://localhost:8080 NC_USER=admin NC_PASS=admin \
 *       npx playwright test --project=chromium agents-switch-off-and-stop
 *
 * Covers openspec/specs/agent-management-ui/spec.md and
 * openspec/specs/agent-tool-governance/spec.md (REQ-AGOFF-001 to -005).
 */

import { expect, test } from '@playwright/test'
import {
	appRoot,
	cleanupFamily,
	dismissTour,
	harvestToken,
	seedAgent,
	seedObject,
	TEST_PREFIX,
} from './_fixtures.ts'

const API = '/index.php/apps/hermiq/api'

test.describe('agents-switch-off-and-stop: the AgentDetail switch and the AgentCatalog delete', () => {
	let token = ''

	test.beforeAll(async ({ browser }) => {
		const page = await browser.newPage()
		token = await harvestToken(page)
		await page.close()
	})

	test.afterAll(async ({ browser }) => {
		const page = await browser.newPage()
		await cleanupFamily(page.request, token)
		await page.close()
	})

	// @e2e agent-management-ui::an-organisation-admin-switches-off-an-agent-that-sends-wrong-reminders
	// @e2e agent-management-ui::switching-on-again
	test('the agent page switches an agent off with a reason and on again', async ({
		page,
	}) => {
		const agent = await seedAgent(page.request, token, {
			name: `${TEST_PREFIX}-permit-reminder`,
		})
		const root = await appRoot(page)
		await page.goto(`${root}/agents/${agent.id}`)
		await dismissTour(page)

		await page.getByRole('button', { name: 'Switch off or on' }).click()
		const dialog = page.getByTestId('agent-availability-dialog')
		await expect(dialog).toContainText('This agent is switched on.')

		await page
			.getByTestId('agent-availability-reason')
			.locator('input')
			.fill('Sends reminders for closed permits')
		await page.getByTestId('agent-availability-switch-off').click()
		await expect(page.getByTestId('agent-availability-state')).toContainText(
			'Switched off',
		)
		await expect(page.getByTestId('agent-availability-state')).toContainText(
			'Reason: Sends reminders for closed permits',
		)

		await page.getByTestId('agent-availability-switch-on').click()
		await expect(page.getByTestId('agent-availability-state')).toContainText(
			'This agent is switched on.',
		)
	})

	// @e2e agent-management-ui::a-person-opens-chat-with-a-switched-off-agent
	test('a chat message to a switched-off agent is refused with the sentence', async ({
		page,
	}) => {
		const headers = { 'OCS-APIRequest': 'true', requesttoken: token }
		const agent = await seedAgent(page.request, token, {
			name: `${TEST_PREFIX}-off`,
		})
		const off = await page.request.post(
			`${API}/agents/${agent.id}/availability`,
			{
				headers,
				data: { active: false, reason: 'Paused for the test' },
			},
		)
		expect(off.ok()).toBeTruthy()

		const send = await page.request.post(`${API}/chat/send`, {
			headers,
			data: { message: 'Hallo', agentUuid: agent.id },
		})
		expect(send.status()).toBe(409)
		const body = await send.json()
		expect(body.errorCode).toBe('agent_switched_off')
		expect(body.message).toBe('This agent is switched off.')
	})

	// @e2e agent-tool-governance::an-owner-raises-the-cap-on-the-agent-form
	test('the owner sets the maximum tool calls on the agent form', async ({
		page,
	}) => {
		const agent = await seedAgent(page.request, token, {
			name: `${TEST_PREFIX}-cap`,
		})
		const root = await appRoot(page)
		await page.goto(`${root}/agents/${agent.id}`)
		await dismissTour(page)

		await page.getByRole('button', { name: 'Edit agent' }).click()
		await page
			.getByTestId('agent-form-max-tool-calls')
			.locator('input')
			.fill('25')
		await page.getByRole('button', { name: 'Save' }).click()

		const stored = await (
			await page.request.get(
				`/index.php/apps/openregister/api/objects/hermiq/agent/${agent.id}`,
				{
					headers: { 'OCS-APIRequest': 'true', requesttoken: token },
				},
			)
		).json()
		expect(stored.maxToolCalls).toBe(25)
	})

	// @e2e agent-management-ui::an-agent-with-two-schedules-is-deleted
	test('the AgentCatalog delete confirmation names the two schedules', async ({
		page,
	}) => {
		const headers = { 'OCS-APIRequest': 'true', requesttoken: token }
		const agent = await seedAgent(page.request, token, {
			name: `${TEST_PREFIX}-two-schedules`,
		})
		for (const n of [1, 2]) {
			await seedObject(page.request, token, 'schedule', {
				name: `${TEST_PREFIX}-schedule-${n}`,
				agentId: agent.id,
				kind: 'interval',
				intervalMinutes: 60,
				prompt: 'go',
				enabled: false,
			})
		}
		const state = await (
			await page.request.get(`${API}/agents/${agent.id}/availability`, {
				headers,
			})
		).json()
		expect(state.scheduleCount).toBe(2)

		const root = await appRoot(page)
		await page.goto(`${root}/agents`)
		await dismissTour(page)
		const row = page.getByRole('row', {
			name: new RegExp(`${TEST_PREFIX}-two-schedules`),
		})
		await row.getByRole('button', { name: 'Actions' }).click()
		await page.getByRole('menuitem', { name: 'Delete' }).click()
		await expect(
			page.getByTestId('agent-delete-dialog').or(page.getByRole('dialog')),
		).toContainText('2 schedules are deleted with this agent.')
	})
})
