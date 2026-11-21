/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spec-coverage e2e for chat-how-an-answer-came-about: the folded "How this
 * answer came about" account under an answer, the folded "Model reasoning"
 * section, and the context readout under the message box.
 *
 * Covered openspec scenarios (the `// @e2e` line above each test is the hook):
 *   - answer-transparency::a-legal-aid-worker-checks-what-an-answer-rests-on
 *     (REQ-ATRN-002): real. An answer turn with a provenance record (two
 *     documents, one tool called, one call refused) is seeded through the
 *     OpenRegister objects API; the sentences are the shipped
 *     AnswerProvenanceWriter's, served by SessionController, never a model's.
 *   - answer-transparency::a-researcher-reads-the-reasoning-next-to-the-answer
 *     (REQ-ATRN-003): real. The turn is seeded with `reasoning` as the engine
 *     stores it after the output filter and redaction, for an agent with
 *     "Show the model's reasoning" on.
 *   - answer-transparency::an-analyst-sees-the-session-filling-up (REQ-ATRN-004)
 *   - answer-transparency::a-provider-without-a-token-count-gets-an-honest-estimate
 *     (REQ-ATRN-004): the stream's `final` frame is stubbed with the
 *     `contextUsage` the server reports (a provider count with a declared
 *     window; an estimate without one), so the test checks what the Chat page
 *     shows for that record without a model. Which number the server reports
 *     per driver is PHPUnit (ContextUsageMeterTest).
 *
 * Not written here, per their `@e2e exclude` lines in the spec:
 *   - a-streamed-answer-gets-the-same-record-as-a-sent-one (compares stored
 *     records across two transports; PHPUnit and a Newman pair)
 *   - an-older-companion-does-not-print-reasoning-as-the-answer (raw SSE
 *     frames; PHPUnit on ChatStreamController)
 *
 * The live check in tasks.md (one streamed answer with a tool call shows its
 * account, and the meter moves after the next message) needs a model and stays
 * there.
 *
 * Every seeded object carries the e2espec- family prefix and is removed in
 * afterAll.
 *
 * Run against a disposable Nextcloud with hermiq + OpenRegister installed:
 *
 *     PLAYWRIGHT_BASE_URL=<your rig> NC_USER=admin NC_PASS=admin \
 *       npx playwright test -c tests/e2e/spec-coverage/playwright.config.ts answer-transparency
 *
 * Covers openspec/changes/chat-how-an-answer-came-about/specs/answer-transparency/spec.md
 */

import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import {
	appRoot,
	cleanupFamily,
	deleteObject,
	dismissTour,
	harvestToken,
	jsonHeaders,
	seedAgent,
	seedObject,
	TEST_PREFIX,
} from './_fixtures.ts'

const API = '/index.php/apps/hermiq/api'
const TURN_SCHEMA = 'agentsessionturn'

const LEGAL_TITLE = `${TEST_PREFIX} Huurgeschil`
const RESEARCH_TITLE = `${TEST_PREFIX} Subsidieregelingen`
const METER_TITLE = `${TEST_PREFIX} Contextmeter`

const LEGAL_ANSWER = 'De verhuurder mag de huur niet eenzijdig verhogen boven het wettelijke maximum.'
const RESEARCH_ANSWER = 'De SDE++ subsidieert de opbrengst, de ISDE de aanschaf.'
const REASONING = 'SDE++ pays per produced unit over years; ISDE is a one-off grant at purchase. Compare the payment moment first.'

const DOC_A = `${TEST_PREFIX}-huurprijswet-brochure.pdf`
const DOC_B = `${TEST_PREFIX}-uitspraak-kantonrechter.pdf`

/**
 * Open a session on the Chat page by its title.
 *
 * @param page  The page.
 * @param title The session title.
 * @return Nothing.
 */
async function openSession(page: Page, title: string): Promise<void> {
	await page.goto(`${await appRoot(page)}/chat`)
	await dismissTour(page)
	await page
		.getByText(title, { exact: true })
		.first()
		.click({ timeout: 20_000 })
}

/**
 * Answer the next streamed turn with a `final` frame carrying a context usage.
 *
 * @param page  The page.
 * @param usage The `contextUsage` the server would report.
 * @return Nothing.
 */
async function stubFinalWithUsage(
	page: Page,
	usage: Record<string, unknown>,
): Promise<void> {
	await page.route('**/apps/hermiq/api/chat/stream', async (route) => {
		const final = {
			messageId: `${TEST_PREFIX}-meter-answer`,
			fullText: 'Hier is het overzicht.',
			contextUsage: usage,
		}
		await route.fulfill({
			status: 200,
			headers: { 'Content-Type': 'text/event-stream' },
			body: `event: final\ndata: ${JSON.stringify(final)}\n\n`,
		})
	})
}

/**
 * Send one message from the Chat page composer.
 *
 * @param page The page, with a session open.
 * @param text The message.
 * @return Nothing.
 */
async function send(page: Page, text: string): Promise<void> {
	await page.getByLabel('Message', { exact: true }).fill(text)
	await page.getByRole('button', { name: 'Send message' }).click()
}

test.describe.configure({ mode: 'serial' })

test.describe('chat-how-an-answer-came-about: the account, the reasoning and the context readout', () => {
	let token = ''
	const sessions: string[] = []
	const turnIds: string[] = []

	test.beforeAll(async ({ browser }) => {
		const page = await browser.newPage()
		token = await harvestToken(page)
		const headers = { ...jsonHeaders(token), 'OCS-APIRequest': 'true' }

		const newSession = async (agentId: string, title: string): Promise<string> => {
			const created = await page.request.post(`${API}/sessions`, {
				headers,
				data: { agentId, title },
			})
			expect(created.status(), await created.text()).toBe(201)
			const uuid = String((await created.json()).uuid)
			sessions.push(uuid)
			return uuid
		}
		const turn = async (data: Record<string, unknown>): Promise<void> => {
			turnIds.push((await seedObject(page.request, token, TURN_SCHEMA, data)).id)
		}

		// The legal aid worker's answer: two documents, one tool done, one refused.
		const legalAgent = (await seedAgent(page.request, token, {
			name: `${TEST_PREFIX}-juridisch-loket`,
		})).id
		const legal = await newSession(legalAgent, LEGAL_TITLE)
		await turn({
			sessionId: legal,
			agentId: legalAgent,
			role: 'user',
			content: 'Mag mijn verhuurder de huur met 15% verhogen?',
		})
		await turn({
			sessionId: legal,
			agentId: legalAgent,
			role: 'assistant',
			content: LEGAL_ANSWER,
			provenance: {
				model: {
					feature: 'chat',
					provider: 'ollama',
					model: 'qwen3:8b',
					residency: 'on-premise',
					location: 'Utrecht',
				},
				sources: [
					{ id: '9101', type: 'file', name: DOC_A },
					{ id: '9102', type: 'file', name: DOC_B },
				],
				steps: [
					{ type: 'tool', name: 'hermiq.readFile', outcome: 'ok' },
					{ type: 'tool', name: 'hermiq.sendMail', outcome: 'denied' },
				],
			},
		})

		// The researcher's answer, with the reasoning the agent's owner asked for.
		const researchAgent = (await seedAgent(page.request, token, {
			name: `${TEST_PREFIX}-onderzoeker`,
			showReasoning: true,
		})).id
		const research = await newSession(researchAgent, RESEARCH_TITLE)
		await turn({
			sessionId: research,
			agentId: researchAgent,
			role: 'user',
			content: 'Vergelijk de SDE++ en de ISDE.',
		})
		await turn({
			sessionId: research,
			agentId: researchAgent,
			role: 'assistant',
			content: RESEARCH_ANSWER,
			reasoning: REASONING,
			reasoningWithheld: false,
		})

		// An empty session for the context readout.
		const meterAgent = (await seedAgent(page.request, token, {
			name: `${TEST_PREFIX}-analist`,
		})).id
		await newSession(meterAgent, METER_TITLE)

		await page.close()
	})

	test.afterAll(async ({ browser }) => {
		const page = await browser.newPage()
		const cleanupToken = await harvestToken(page)
		const headers = { ...jsonHeaders(cleanupToken), 'OCS-APIRequest': 'true' }
		for (const id of turnIds) {
			await deleteObject(page.request, cleanupToken, TURN_SCHEMA, id)
		}
		for (const uuid of sessions) {
			await page.request
				.delete(`${API}/sessions/${uuid}`, { headers })
				.catch(() => undefined)
			await page.request
				.delete(`${API}/sessions/${uuid}/permanent`, { headers })
				.catch(() => undefined)
		}
		await cleanupFamily(page.request, cleanupToken, 'agent')
		await page.close()
	})

	// @e2e answer-transparency::a-legal-aid-worker-checks-what-an-answer-rests-on
	test('"How this answer came about" names the model, both documents, the refused call and the final say', async ({
		page,
	}) => {
		await openSession(page, LEGAL_TITLE)

		const answer = page
			.locator('.chat-page__message--assistant')
			.filter({ hasText: LEGAL_ANSWER })
		await expect(answer).toBeVisible({ timeout: 20_000 })

		const account = answer.getByTestId('chat-message-provenance')
		await expect(account).toBeVisible()
		// Folded until the reader opens it.
		await expect(account).not.toHaveAttribute('open')
		const summary = account.locator('summary')
		await expect(summary).toHaveText('How this answer came about')
		await summary.click()
		await expect(account).toHaveAttribute('open', '')

		const sentences = account.locator('li')
		await expect(sentences.first()).toBeVisible()
		await expect(sentences.first()).toHaveText(
			'Answered by qwen3:8b (ollama), which runs on this server (Utrecht).',
		)
		await expect(account).toContainText(`Used 2 documents: ${DOC_A}, ${DOC_B}.`)
		await expect(account).toContainText('Called 1 tool: hermiq.readFile (done).')
		await expect(account).toContainText(
			'1 call was refused: hermiq.sendMail (this agent may not use it).',
		)
		await expect(sentences.last()).toHaveText('You decide what to do with this answer.')

		// Keyboard operable: the summary folds it again from the keyboard.
		await summary.focus()
		await page.keyboard.press('Enter')
		await expect(account).not.toHaveAttribute('open')
	})

	// @e2e answer-transparency::a-researcher-reads-the-reasoning-next-to-the-answer
	test('a folded "Model reasoning" section shows the model\'s text and says it can be wrong', async ({
		page,
	}) => {
		await openSession(page, RESEARCH_TITLE)

		const answer = page
			.locator('.chat-page__message--assistant')
			.filter({ hasText: RESEARCH_ANSWER })
		await expect(answer).toBeVisible({ timeout: 20_000 })

		const reasoning = answer.getByTestId('chat-message-reasoning')
		await expect(reasoning).toBeVisible()
		await expect(reasoning).not.toHaveAttribute('open')
		await expect(reasoning.locator('summary')).toHaveText('Model reasoning')
		await reasoning.locator('summary').click()

		await expect(reasoning.locator('.chat-page__reasoning-text')).toHaveText(REASONING)
		await expect(reasoning.locator('.chat-page__reasoning-note')).toHaveText(
			'This is the model\'s own text. It can be wrong.',
		)
		await expect(answer.getByTestId('chat-message-reasoning-withheld')).toHaveCount(0)

		// The reasoning is beside the answer, never in it.
		await expect(answer.locator('.chat-page__text')).not.toContainText(REASONING)
	})

	// @e2e answer-transparency::an-analyst-sees-the-session-filling-up
	test('the readout shows 106,300 of 128,000 tokens (83%) and warns the session is close to the limit', async ({
		page,
	}) => {
		await stubFinalWithUsage(page, {
			usedTokens: 106_300,
			windowTokens: 128_000,
			estimated: false,
		})
		await openSession(page, METER_TITLE)
		await send(page, 'Zet de kwartaalcijfers naast elkaar.')

		const readout = page.getByTestId('chat-context-usage')
		await expect(readout).toBeVisible({ timeout: 30_000 })
		await expect(readout).toHaveAttribute('role', 'status')
		await expect(readout).toContainText('Context: 106,300 of 128,000 tokens (83%)')
		await expect(page.getByTestId('chat-context-warning')).toHaveText(
			'The session is close to the model\'s limit. Start a new session or fork from an earlier answer.',
		)
	})

	// @e2e answer-transparency::a-provider-without-a-token-count-gets-an-honest-estimate
	test('without a provider count the readout starts with "Context: about"', async ({
		page,
	}) => {
		// Fireworks reports no token count: the server estimates and marks it.
		await stubFinalWithUsage(page, { usedTokens: 1_840, estimated: true })
		await openSession(page, METER_TITLE)
		await send(page, 'Hoeveel aanvragen kwamen er dit kwartaal binnen?')

		const readout = page.getByTestId('chat-context-usage')
		await expect(readout).toBeVisible({ timeout: 30_000 })
		await expect(readout).toHaveText(/^Context: about 1,840 tokens/)
		await expect(page.getByTestId('chat-context-warning')).toHaveCount(0)
	})
})
