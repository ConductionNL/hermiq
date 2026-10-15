/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spec-coverage e2e: a standing goal on a session (agents-standing-goal).
 *
 * Sets a goal through the chat page's "Set a goal" form and stops it from the
 * session header. The goal's turns run on the background job and are covered
 * by PHPUnit (GoalServiceTest, ScheduleTaskTest), so this spec needs no model.
 * Every seeded object carries the e2espec- family prefix and is removed in afterAll.
 *
 * Run against a running Nextcloud with Hermiq + OpenRegister installed:
 *
 *     NEXTCLOUD_URL=http://localhost:8080 NC_USER=admin NC_PASS=admin \
 *       npx playwright test --project=chromium agents-standing-goal
 *
 * Covers openspec/changes/agents-standing-goal/specs/agent-schedule/spec.md (REQ-AGGOAL-001, REQ-AGGOAL-003).
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

test.describe('agents-standing-goal: set and stop a goal', () => {
	let token = ''
	let agentId = ''

	test.beforeAll(async ({ browser }) => {
		const page = await browser.newPage()
		token = await harvestToken(page)
		const seeded = await seedAgent(page.request, token, {
			name: `${TEST_PREFIX}-goal`,
			prompt: 'Send a reminder for every overdue permit application.',
		})
		agentId = seeded.id
		await page.close()
	})

	test.afterAll(async ({ browser }) => {
		const page = await browser.newPage()
		await cleanupFamily(page.request, token)
		await page.close()
	})

	// @e2e agent-schedule::a-permit-officer-sets-a-reminder-goal
	// @e2e agent-schedule::the-officer-stops-the-goal
	test('a goal set in the session shows in the header and can be stopped', async ({
		page,
	}) => {
		const created = await page.request.post(`${API}/sessions`, {
			headers: { 'OCS-APIRequest': 'true', requesttoken: token },
			data: { agentUuid: agentId, title: `${TEST_PREFIX}-goal-session` },
		})
		expect(created.status()).toBe(201)

		await page.goto(`${await appRoot(page)}/chat`)
		await dismissTour(page)
		await page.getByText(`${TEST_PREFIX}-goal-session`).first().click()

		await page.getByTestId('chat-set-goal').click()
		const form = page.getByTestId('goal-form')
		await form
			.getByLabel('Goal', { exact: true })
			.fill('Every overdue permit application has had a reminder')
		await form.getByLabel('Register', { exact: true }).fill('hermiq')
		await form.getByLabel('Schema', { exact: true }).fill('agent')
		await form.getByLabel('Reached when the count is').fill('0')
		await form.getByLabel('Take a turn every (minutes, 15 to 1440)').fill('60')
		await form.getByLabel('Turn limit (1 to 50)').fill('10')
		await page.getByTestId('goal-save').click()

		await expect(page.getByTestId('chat-goal-text')).toContainText(
			'Every overdue permit application has had a reminder',
		)
		await expect(page.getByTestId('chat-goal-text')).toContainText(
			'turn 0 of 10',
		)
		await page.getByTestId('chat-stop-goal').click()
		await expect(page.getByTestId('chat-goal-text')).toContainText(
			'Goal stopped',
		)
		await expect(page.getByTestId('chat-stop-goal')).toHaveCount(0)
	})
})
