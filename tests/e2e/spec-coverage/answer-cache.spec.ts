/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * models-prompt-and-answer-cache tasks 3, 4 and 7: the answer cache setting
 * on the agent form, the "Answered from cache" note in the chat with its
 * "Ask the model again" button and its thumbs down, and the provider cache
 * line on the Runs page.
 *
 * What is real and what is answered by page.route, and why:
 * - The setting is saved for real through OpenRegister and read back.
 * - A cached answer needs an earlier model turn, so the session's messages are
 *   answered with one served from the cache (`fromCache: true`, as
 *   SessionController returns it). The turn "Ask the model again" sends and
 *   the thumbs down are answered too; what is asserted is what the page sends.
 *   The server half is pinned by ResponseAnswerCacheTest and
 *   ChatFeedbackAnswerCacheTest.
 * - A run row is an audit entry only the run path writes, so the Runs list is
 *   answered with one run that read from the provider's cache.
 *
 * Written from the change's scenarios; not run here, it needs a running Nextcloud.
 *
 * @spec openspec/changes/models-prompt-and-answer-cache/specs/answer-cache/spec.md
 * @spec openspec/changes/models-prompt-and-answer-cache/specs/provider-prompt-caching/spec.md#requirement-the-run-reports-cache-tokens-req-pcache-003
 */

import type { Page, Route } from '@playwright/test'

import { expect, test } from '@playwright/test'
import {
	appRoot,
	cleanupFamily,
	dismissTour,
	harvestToken,
	jsonHeaders,
	openAgentForm,
	OR_API,
	seedAgent,
	TEST_PREFIX,
} from './_fixtures.ts'

const API = '/index.php/apps/hermiq/api'
const QUESTION = 'Wanneer wordt het oud papier opgehaald?'
const ANSWER = 'Oud papier wordt elke tweede dinsdag van de maand opgehaald.'
const NO_VECTOR_SEARCH =
	'Similar questions are not matched: OpenRegister has no vector search here.'

/** The run list endpoint. */
const RUN_LIST = /\/apps\/hermiq\/api\/runs(\?.*)?$/

/** The two turn endpoints of the chat page. */
const TURN_ROUTES = /\/apps\/hermiq\/api\/chat\/(stream|send)(\?.*)?$/

test.describe('models-prompt-and-answer-cache: the agent setting', () => {
	let token = ''

	test.beforeAll(async ({ browser }) => {
		const page = await browser.newPage()
		token = await harvestToken(page)
		await page.close()
	})

	test.afterAll(async ({ browser }) => {
		const page = await browser.newPage()
		await cleanupFamily(page.request, token, 'agent')
		await page.close()
	})

	/**
	 * Open the agent form of a freshly seeded agent without tools.
	 *
	 * @param page The Playwright page.
	 * @param name The agent's name suffix.
	 * @return The agent id.
	 */
	async function editSeededAgent(page: Page, name: string): Promise<string> {
		const agent = await seedAgent(page.request, token, {
			name: `${TEST_PREFIX}-${name}`,
			tools: [],
		})
		await page.goto(`${await appRoot(page)}/agents/${agent.id}`, {
			waitUntil: 'domcontentloaded',
		})
		await dismissTour(page)
		await openAgentForm(page)
		return agent.id
	}

	// @e2e answer-cache::an-agent-owner-switches-the-cache-on
	test('an agent owner switches the answer cache on with a lifetime of 7 days', async ({
		page,
	}) => {
		const id = await editSeededAgent(page, 'answer-cache')
		const form = page.locator('.agent-form')

		await form.getByText('Reuse answers to repeated questions').click()
		const days = form.getByLabel('Keep answers for (days)')
		await expect(days).toBeVisible()
		await days.fill('7')

		await page.getByRole('button', { name: 'Save' }).last().click()
		await expect(page.locator('.agent-form')).toHaveCount(0, { timeout: 20_000 })

		const res = await page.request.get(`${OR_API}/objects/hermiq/agent/${id}`, {
			headers: jsonHeaders(token),
		})
		expect(res.ok(), `read agent: HTTP ${res.status()}`).toBeTruthy()
		const stored = await res.json()
		expect(stored.answerCache).toMatchObject({
			enabled: true,
			ttlMinutes: 7 * 24 * 60,
		})

		// The agent's Configuration card says so (formatter agentAnswerCache).
		await expect(
			page.getByText('Answer cache: on, 7 days').first(),
		).toBeVisible()
	})

	// @e2e answer-cache::no-vector-search-no-similarity-layer
	test('without vector search the similarity choice is disabled and says why', async ({
		page,
	}) => {
		await editSeededAgent(page, 'answer-cache-similarity')

		// Nextcloud renders the page state as a hidden input per key.
		const state = await page
			.locator('#initial-state-hermiq-vector_search_available')
			.getAttribute('value')
			.catch(() => null)
		const available = state !== null && JSON.parse(atob(state)) === true
		test.skip(
			available,
			'this instance has a vector facade, so the similarity choice is on',
		)

		const form = page.locator('.agent-form')
		await form.getByText('Reuse answers to repeated questions').click()
		await expect(form.getByText(NO_VECTOR_SEARCH)).toBeVisible()
		await expect(
			form.getByRole('combobox', { name: 'Similar questions' }),
		).toBeDisabled()
	})
})

test.describe('models-prompt-and-answer-cache: a served answer in the chat', () => {
	let token = ''
	let sessionUuid = ''
	let sessionTitle = ''

	test.beforeAll(async ({ browser }) => {
		const page = await browser.newPage()
		token = await harvestToken(page)
		const agent = await seedAgent(page.request, token, {
			name: `${TEST_PREFIX}-answer-cache-chat`,
			tools: [],
			answerCache: { enabled: true, ttlMinutes: 10080, similarity: 'off' },
		})
		sessionTitle = `${TEST_PREFIX}-answer-cache-session`
		const created = await page.request.post(`${API}/sessions`, {
			headers: { 'OCS-APIRequest': 'true', requesttoken: token },
			data: { agentUuid: agent.id, title: sessionTitle },
		})
		expect(created.status()).toBe(201)
		sessionUuid = (await created.json()).uuid
		await page.close()
	})

	test.afterAll(async ({ browser }) => {
		const page = await browser.newPage()
		await page.request.delete(`${API}/sessions/${sessionUuid}`, {
			headers: { 'OCS-APIRequest': 'true', requesttoken: token },
		})
		await cleanupFamily(page.request, token, 'agent')
		await page.close()
	})

	/**
	 * Answer the session's messages with a question and the answer the cache served.
	 *
	 * @param page The Playwright page.
	 */
	async function servedFromCache(page: Page): Promise<void> {
		await page.route(
			new RegExp(`/api/sessions/${sessionUuid}/messages(\\?.*)?$`),
			async (route: Route) => {
				if (route.request().method() !== 'GET') {
					await route.fallback()
					return
				}
				await route.fulfill({
					json: {
						total: 2,
						results: [
							{
								id: 11,
								uuid: 'e2e-question',
								role: 'user',
								content: QUESTION,
							},
							{
								id: 12,
								uuid: 'e2e-answer',
								role: 'assistant',
								content: ANSWER,
								fromCache: true,
							},
						],
					},
				})
			},
		)
	}

	/**
	 * Open the chat on the seeded session.
	 *
	 * @param page The Playwright page.
	 */
	async function openSession(page: Page): Promise<void> {
		await page.goto(`${await appRoot(page)}/chat`, {
			waitUntil: 'domcontentloaded',
		})
		await dismissTour(page)
		await page.getByText(sessionTitle).first().click()
		await expect(page.getByText(ANSWER)).toBeVisible({ timeout: 20_000 })
	}

	// @e2e answer-cache::a-residents-question-is-answered-from-the-cache
	test('an answer the cache served says so, and "Ask the model again" bypasses the cache', async ({
		page,
	}) => {
		await servedFromCache(page)
		const turns: Array<Record<string, unknown>> = []
		await page.route(TURN_ROUTES, async (route: Route) => {
			turns.push(route.request().postDataJSON() as Record<string, unknown>)
			await route.fulfill({
				status: 503,
				json: { error: 'Answered by the test, not by a model.' },
			})
		})

		await openSession(page)
		const note = page.locator('.chat-page__from-cache')
		await expect(note).toHaveCount(1)
		await expect(note.getByText('Answered from cache')).toBeVisible()

		await note.getByRole('button', { name: 'Ask the model again' }).click()
		await expect.poll(() => turns.length).toBeGreaterThan(0)
		expect(
			turns[0].message,
			'the question before the cached answer is asked again',
		).toBe(QUESTION)
		expect(
			turns[0].answerCacheBypass,
			'the cache is neither read nor written for that turn',
		).toBe(true)
	})

	// @e2e answer-cache::a-thumbs-down-removes-a-wrong-answer
	test('a thumbs down on a served answer is sent as negative feedback on that message', async ({
		page,
	}) => {
		await servedFromCache(page)
		const feedback: Array<{ url: string; body: Record<string, unknown> }> = []
		await page.route(
			`**/api/sessions/${sessionUuid}/messages/*/feedback`,
			async (route: Route) => {
				feedback.push({
					url: route.request().url(),
					body: route.request().postDataJSON() as Record<string, unknown>,
				})
				await route.fulfill({ json: { status: 'recorded' } })
			},
		)

		await openSession(page)
		await page.getByRole('button', { name: 'Not helpful' }).last().click()

		await expect.poll(() => feedback.length).toBe(1)
		expect(feedback[0].body.type).toBe('negative')
		expect(feedback[0].url).toContain('/messages/e2e-answer/feedback')
	})
})

test.describe('models-prompt-and-answer-cache: the provider cache on the run', () => {
	// @e2e provider-prompt-caching::an-organisation-admin-sees-the-saving
	test('a run that read from the provider cache shows how many input tokens came from it', async ({
		page,
	}) => {
		const run = {
			id: `${TEST_PREFIX}-cached-run`,
			created: new Date().toISOString(),
			agentId: '',
			agentName: `${TEST_PREFIX}-agent`,
			status: 'success',
			trigger: 'schedule',
			durationMs: 2100,
			summary: 'Weekly overview sent.',
			usage: {
				promptTokens: 2300,
				completionTokens: 310,
				cacheReadTokens: 1840,
				cacheWriteTokens: 0,
			},
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
		await expect(page.locator('.hermiq-runs__cache')).toHaveText(
			/1,840 of 2,300 input tokens from the provider[’']s cache/,
		)
	})
})
