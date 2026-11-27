/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The domain tools in a live agent turn (hermiq-domain-mcp-tools, task 5.1), against
 * the instance's real chat model.
 *
 * WHAT THE UNIT TESTS CANNOT SHOW. HermiqToolProviderTest pins the descriptors and
 * every invokeTool branch with its guard. It cannot show that a model, given the
 * tools through the engine's grant resolution, actually calls them and answers with
 * Hermiq's own objects instead of "I can list registers". That needs the model and
 * the registry, so it is here.
 *
 * An agent granted `hermiq.listAgents` and `hermiq.listPendingApprovals` is asked the
 * task's two questions through `POST /api/chat/send`. A second agent with a name no
 * model can guess is seeded first; the first answer must name it. Both agents carry
 * this run's prefix and are removed afterwards.
 *
 * Skipped when the instance has no chat model configured (chat health not `ok`).
 *
 * Not run when written: it needs a live instance with a chat model. Runs in the nightly.
 *
 * @e2e hermiq-domain-mcp-tools::a-user-asks-the-companion-what-agents-they-have
 *
 * @spec openspec/changes/archive/2026-11-24-hermiq-domain-mcp-tools/specs/hermiq-domain-mcp-tools/spec.md
 */

import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { cleanupFamily, harvestToken, jsonHeaders, resolveRegisterSchema, seedAgent, TEST_PREFIX } from './_fixtures.ts'

const API = '/index.php/apps/hermiq/api'
const TARGET_NAME = `${TEST_PREFIX}-Kestrel permit sorter`

test.describe.configure({ mode: 'serial' })

test.describe('Domain tools in a live agent turn', () => {
	test.setTimeout(240_000)

	let token = ''
	let companionId = ''

	test.beforeAll(async ({ browser }) => {
		const page = await browser.newPage()
		token = await harvestToken(page)
		const health = await (await page.request.get(`${API}/chat/health`, { headers: jsonHeaders(token) })).json()
		if (health.status === 'ok') {
			await resolveRegisterSchema(page.request, token, 'agent')
			await seedAgent(page.request, token, { name: TARGET_NAME })
			companionId = (await seedAgent(page.request, token, {
				name: `${TEST_PREFIX}-companion`,
				tools: ['hermiq.listAgents', 'hermiq.listPendingApprovals'],
			})).id
		}
		await page.close()
	})

	test.afterAll(async ({ browser }) => {
		const page = await browser.newPage()
		const cleanupToken = await harvestToken(page)
		await cleanupFamily(page.request, cleanupToken, 'agent').catch(() => undefined)
		await page.close()
	})

	/**
	 * One turn with the granted agent.
	 *
	 * @param page    The page whose request context sends it.
	 * @param message The user's question.
	 * @return The answer text.
	 */
	async function ask(page: Page, message: string): Promise<string> {
		const sent = await page.request.post(`${API}/chat/send`, {
			headers: jsonHeaders(await harvestToken(page)),
			data: { message, agentUuid: companionId },
			timeout: 200_000,
		})
		expect(sent.status(), await sent.text()).toBe(200)
		return String((await sent.json()).message ?? '')
	}

	test('asked what agents the user has, the answer names their agents', async ({ page }) => {
		test.skip(companionId === '', 'no chat model is configured on this instance, so no turn can run')

		const answer = await ask(page, 'What agents do I have? List them by name.')
		expect(answer, 'the answer comes from hermiq.listAgents, so it names the seeded agent').toContain('Kestrel permit sorter')
	})

	test('asked about pending approvals, the answer is about approvals, not registers', async ({ page }) => {
		test.skip(companionId === '', 'no chat model is configured on this instance, so no turn can run')

		const answer = await ask(page, 'Do I have any pending approvals?')
		expect(answer.toLowerCase()).toMatch(/approval/)
		expect(answer).not.toMatch(/I can list registers/i)
	})
})
