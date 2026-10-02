/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spec-coverage e2e: an agent described in chat becomes a draft, and the draft
 * opens in the full agent form after a check (agents-plain-language-builder).
 *
 * The assistant's answer is stubbed: the messages endpoint of a seeded session
 * is answered with one assistant message that carries a hermiq-agent-draft
 * block, so the run needs no model. The draft check, the form, the save and the
 * schedule form are real. Every seeded object carries the e2espec- family prefix
 * and is removed in afterAll.
 *
 * Run against a running Nextcloud with Hermiq + OpenRegister installed:
 *
 *     NEXTCLOUD_URL=http://localhost:8080 NC_USER=admin NC_PASS=admin \
 *       npx playwright test --project=chromium agents-plain-language-builder
 *
 * Covers openspec/specs/agent-management-ui/spec.md (REQ-AGBUILD-001, REQ-AGBUILD-002).
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

const draftName = `${TEST_PREFIX}-objections-digest`

const draftMessage = (model: string): string => [
	'This agent reads the objections filed last week and posts a digest for the legal team every Monday at eight.',
	'',
	'```hermiq-agent-draft',
	JSON.stringify({
		name: draftName,
		description: 'Summarises new objections every Monday for the legal team.',
		prompt: 'Summarise the objections filed last week, grouped by case.',
		provider: 'not-a-provider',
		model,
		tools: [],
		sharing: { mode: 'only-me', groups: [] },
		schedule: { kind: 'cron', cronExpr: '0 8 * * 1', prompt: 'Write this week\'s objections digest.' },
		startFields: [],
	}),
	'```',
	'Open the draft to check it and save it.',
].join('\n')

test.describe('agents-plain-language-builder: a draft from chat', () => {
	let token = ''
	let sessionUuid = ''
	let sessionTitle = ''

	test.beforeAll(async ({ browser }) => {
		const page = await browser.newPage()
		token = await harvestToken(page)
		const builder = await seedAgent(page.request, token, { name: `${TEST_PREFIX}-builder` })
		sessionTitle = `${TEST_PREFIX}-builder-session`
		const created = await page.request.post(`${API}/sessions`, {
			headers: { 'OCS-APIRequest': 'true', requesttoken: token },
			data: { agentUuid: builder.id, title: sessionTitle },
		})
		expect(created.status()).toBe(201)
		sessionUuid = (await created.json()).uuid
		await page.close()
	})

	test.afterAll(async ({ browser }) => {
		const page = await browser.newPage()
		await cleanupFamily(page.request, token)
		await page.close()
	})

	/**
	 * Answer the session's messages with a user question and a draft.
	 *
	 * @param page The page.
	 */
	const stubDraft = async (page) => {
		await page.route(`**/api/sessions/${sessionUuid}/messages**`, (route) => route.fulfill({
			json: {
				total: 2,
				results: [
					{ id: 1, role: 'user', content: 'An agent that summarises new objections every Monday at eight and posts them in the legal team\'s Talk room' },
					{ id: 2, role: 'assistant', content: draftMessage('not-a-model') },
				],
			},
		}))
	}

	// @e2e agent-management-ui::a-team-lead-describes-an-agent
	test('the answer carries a draft and no agent exists yet', async ({ page }) => {
		await stubDraft(page)
		await page.goto(`${await appRoot(page)}/chat`)
		await dismissTour(page)
		await page.getByText(sessionTitle).first().click()
		await expect(page.getByTestId('chat-open-as-agent')).toBeVisible()

		const agents = await page.request.get(`${API}/../../openregister/api/objects/hermiq/agent?name=${encodeURIComponent(draftName)}`, {
			headers: { 'OCS-APIRequest': 'true', requesttoken: token },
		})
		const body = await agents.json()
		expect((body.results ?? []).length).toBe(0)
	})

	// @e2e agent-management-ui::the-team-lead-opens-the-draft-and-fixes-the-model
	// @e2e agent-management-ui::the-proposed-schedule-is-offered-after-saving
	test('the form opens filled in, flags the model, saves, then offers the schedule', async ({ page }) => {
		await stubDraft(page)
		await page.goto(`${await appRoot(page)}/chat`)
		await dismissTour(page)
		await page.getByText(sessionTitle).first().click()
		await page.getByTestId('chat-open-as-agent').click()

		await expect(page.getByRole('textbox', { name: 'Name' })).toHaveValue(draftName)
		await expect(page.getByTestId('agent-form-finding-model')).toContainText('Not allowed by your organisation')

		// Fix the model: clear the provider and model pickers so the instance default applies.
		const clear = page.getByRole('dialog').getByRole('button', { name: /clear selected/i })
		while (await clear.count() > 0) {
			await clear.first().click()
		}
		await expect(page.getByTestId('agent-form-finding-model')).toBeVisible()
		await page.getByRole('button', { name: 'Save' }).click()

		await expect(page.getByRole('heading', { name: 'Attach schedule' })).toBeVisible()
		await expect(page.getByRole('textbox', { name: 'Cron expression' })).toHaveValue('0 8 * * 1')
	})
})
