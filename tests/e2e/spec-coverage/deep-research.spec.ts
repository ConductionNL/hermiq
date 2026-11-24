/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * tools-deep-research-report task 6: the "Deep research" switch in the chat
 * composer, the progress of a running run and its "Stop research" button. A
 * real run needs a search backend and a model, so the research routes and the
 * session's handoff listing are answered by page.route with the shapes
 * ResearchController and OpenRegister return. The logic behind the controls is
 * asserted in tests/vitest/chat-deep-research.spec.js.
 */

import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import {
	appRoot,
	cleanupFamily,
	dismissTour,
	harvestToken,
	resolveRegisterSchema,
	seedAgent,
	TEST_PREFIX,
} from './_fixtures.ts'

const QUESTION = 'Welke gemeenten gebruiken een algoritmeregister en wat publiceren ze?'

/**
 * Answer the research routes, and list one waiting research handoff once a run
 * has started.
 *
 * @param page The Playwright page.
 * @return What the page sent: the started question and the stops.
 */
async function fakeResearch(page: Page) {
	const sent = { question: '', stops: 0, started: false }
	await page.route('**/apps/hermiq/api/research', async (route) => {
		const body = route.request().postDataJSON() as { question: string }
		sent.question = body.question
		sent.started = true
		await route.fulfill({
			status: 201,
			json: { id: 'h-e2e', status: 'waiting', question: body.question, plan: [], progress: { phase: 'plan' } },
		})
	})
	await page.route('**/apps/hermiq/api/research/h-e2e/stop', async (route) => {
		sent.stops++
		await route.fulfill({ json: { id: 'h-e2e', stopRequested: true } })
	})
	await page.route('**/apps/openregister/api/objects/hermiq/agenthandoff**', async (route) => {
		const url = new URL(route.request().url())
		const conversationId = url.searchParams.get('conversationId') || ''
		const results = sent.started
			? [{
					id: 'h-e2e',
					kind: 'research',
					status: 'waiting',
					label: QUESTION,
					conversationId,
					requestedAt: new Date().toISOString(),
					progress: { phase: 'gather', sources: 3, toolCalls: 4 },
					stopRequested: sent.stops > 0,
				}]
			: []
		await route.fulfill({ json: { results } })
	})
	return sent
}

/**
 * Seed an agent with the given tools, open the chat and start a session with it.
 *
 * @param page The Playwright page.
 * @param tools The agent's granted tools.
 * @return The composer locator.
 */
async function startChat(page: Page, tools: string[]) {
	const token = await harvestToken(page)
	await resolveRegisterSchema(page.request, token, 'agent')
	const agent = await seedAgent(page.request, token, {
		name: `${TEST_PREFIX}-researcher-${Date.now()}`,
		tools,
	})
	const root = await appRoot(page)
	await page.goto(`${root}/chat`, { waitUntil: 'domcontentloaded' })
	await dismissTour(page)
	const card = page
		.locator('.agent-selector__card')
		.filter({ hasText: agent.name })
	await expect(card).toBeVisible({ timeout: 20_000 })
	await card.getByRole('button', { name: 'Start session' }).click()
	const composer = page.locator('.chat-page__composer')
	await expect(composer).toBeVisible({ timeout: 20_000 })
	return composer
}

test.describe('tools-deep-research-report', () => {
	test.afterEach(async ({ page }) => {
		const token = await harvestToken(page).catch(() => '')
		if (token) {
			await cleanupFamily(page.request, token, 'agent').catch(() => {})
		}
	})

	// @e2e deep-research::a-policy-officer-asks-for-research
	test('a policy officer asks for research', async ({ page }) => {
		const sent = await fakeResearch(page)
		const composer = await startChat(page, ['hermiq.webSearch', 'hermiq.webFetch'])

		await composer.getByTestId('chat-deep-research').click()
		await composer.locator('textarea.chat-page__input').fill(QUESTION)
		await composer.getByRole('button', { name: 'Send message' }).click()

		const progress = composer.getByTestId('chat-research-progress')
		await expect(progress).toContainText('Researching: 3 sources read, 4 tool calls', { timeout: 20_000 })
		expect(sent.question).toBe(QUESTION)

		await progress.getByRole('button', { name: 'Stop research' }).click()
		await expect(progress).toContainText('Stopping the research…', { timeout: 20_000 })
		expect(sent.stops).toBe(1)
	})

	// Task 6 acceptance: the switch is hidden unless the agent holds both web tools.
	test('an agent without both web tools shows no switch', async ({ page }) => {
		await fakeResearch(page)
		const composer = await startChat(page, ['hermiq.webSearch'])

		await expect(composer.getByRole('button', { name: 'Send message' })).toBeVisible()
		await expect(composer.getByTestId('chat-deep-research')).toHaveCount(0)
	})
})
