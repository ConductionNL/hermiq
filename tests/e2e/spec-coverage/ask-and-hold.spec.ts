/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spec-coverage e2e — an agent asks a person, and written output is held
 * until a reviewer releases it (oversight-ask-and-hold, Tasks 3, 4 and 6).
 *
 * Covered openspec scenarios (the `// @e2e` line above each test is the hook):
 *  - REQ-ASKHOLD-001 "An agent asks which municipality a request is for": the
 *    stream's `final` frame carries the question "Which municipality is this
 *    for?" with "Utrecht", "Nieuwegein" and "Houten" and an answer of the
 *    person's own allowed. Under that answer the Chat page shows the question
 *    (AgentQuestionCard), exactly three buttons and the "Your own answer" box;
 *    choosing "Houten" sends "Houten" as the next turn, and the card is gone
 *    once the agent has answered that turn.
 *  - REQ-ASKHOLD-002 "A nightly run asks the team lead and continues in the
 *    morning": a pending `question` approval shows in the inbox with "Yes,
 *    close them" and "No, keep them" and no free-text box; choosing "Yes,
 *    close them" posts `{answer: 'Yes, close them'}` to
 *    /api/approvals/{id}/answer and the row leaves the inbox.
 *  - REQ-ASKHOLD-003 "A weekly report is checked before it reaches the
 *    council": the inbox's All / Drafts filter counts and shows only the held
 *    drafts; "Review" opens DraftReviewModal with the recipients read-only;
 *    after one figure is corrected, "Release" is off and "Edit and release"
 *    posts only the changed field (`{edits: {output: <corrected text>}}`).
 *    For a held mail, "Release" as written posts `{edits: {}}`, a subject edit
 *    posts only `{edits: {subject}}`, and "Discard" goes through the deny
 *    dialog and posts the reason to /api/approvals/{id}/deny.
 *
 * Left out:
 *  - REQ-ASKHOLD-003 "Changed recipients are refused": `@e2e exclude` in the
 *    spec (request tampering; the 422 is PHPUnit, DraftReleaseTest and
 *    ApprovalControllerTest). The modal has no recipient field to change.
 *  - The server halves of the covered scenarios: that no mail is sent while a
 *    draft is held, that the answered run is dispatched again and its history
 *    links question, answer and new run, the 7-day expiry, and the stored
 *    `editedOutput {original, sent}` ("the approval shows both versions" has
 *    no view yet: this inbox lists pending approvals only). PHPUnit:
 *    ApprovalServiceAskAndHoldTest, ScheduleServiceTest, DraftReleaseTest.
 *  - Whether a model really calls `hermiq.askPerson` and stops the turn:
 *    FacadeToolInvokerAskPersonTest; the live check needs a model.
 *
 * 🔑 WHAT IS REAL AND WHAT IS NOT.
 *  - The chat session and its agent are real (POST /api/sessions on a seeded
 *    agent, removed in afterAll). The stream (POST /api/chat/stream) is
 *    answered by page.route with the `final` payload ChatStreamController::
 *    finalPayload() builds, `question` included; the session's message list is
 *    answered the same way, because a stubbed stream stores no turn and the
 *    card is shown under the turn whose id is the payload's `messageId`.
 *  - The approval inbox read (GET /api/approvals) is answered by page.route
 *    with the records ApprovalInbox::inboxRecord() returns (`question` and
 *    `draft` on those source types only), and the answer, release and deny
 *    POSTs are caught and fulfilled: a real pending question needs a
 *    scheduled run to answer, and a real release would send the mail. What is
 *    checked is the exact body the page sends to each real route.
 *
 * Runs as the suite's administrator (the default storage state):
 *
 *     PLAYWRIGHT_BASE_URL=<your rig> NC_USER=admin NC_PASS=admin \
 *       npx playwright test -c tests/e2e/spec-coverage/playwright.config.ts ask-and-hold
 *
 * Covers openspec/changes/oversight-ask-and-hold/specs/human-approval-gate/spec.md
 */

import type { Page, Request } from '@playwright/test'

import { expect, test } from '@playwright/test'
import {
	appRoot,
	cleanupFamily,
	dismissTour,
	harvestToken,
	jsonHeaders,
	seedAgent,
	TEST_PREFIX,
} from './_fixtures.ts'

const API = '/index.php/apps/hermiq/api'

const INTAKE_TITLE = `${TEST_PREFIX} Intake aanvraag`
const REQUEST_TEXT = 'Een inwoner vraagt een parkeervergunning aan voor de Dorpsstraat.'
const ASK_ANSWER = 'I need one detail before I can route this request.'
const ASK_TURN = `${TEST_PREFIX}-ask-turn`
const CONTINUE_ANSWER = 'Routed to the Houten parking desk.'
const CONTINUE_TURN = `${TEST_PREFIX}-continue-turn`
const MUNICIPALITIES = ['Utrecht', 'Nieuwegein', 'Houten']

const REPORT = [
	'Weekly report for the council, week 47',
	'Open requests: 128',
	'Closed this week: 96',
].join('\n')
const REPORT_CORRECTED = REPORT.replace('Open requests: 128', 'Open requests: 132')

const MAIL_SUBJECT = 'Supplier digest week 47'
const MAIL_BODY = 'Three suppliers changed their delivery dates this week.'
const MAIL_TO = 'inkoop@example.org'

const BASE = {
	scheduleId: 'sched-nightly',
	agentId: 'agent-nightly',
	prompt: '',
	requestedAt: '2026-11-21T02:00:00+00:00',
	reviewer: 'admin',
	reviewerType: 'user',
	status: 'pending',
	toolId: '',
	toolArguments: null,
	preview: null,
}

const QUESTION = {
	...BASE,
	id: 'appr-question',
	prompt: 'Tidy up the duplicate reports of today',
	sourceType: 'question',
	question: {
		text: 'Close the three duplicate reports?',
		options: ['Yes, close them', 'No, keep them'],
		allowOther: false,
	},
}

const WEEKLY_DRAFT = {
	...BASE,
	id: 'appr-weekly-report',
	scheduleId: 'sched-weekly-report',
	agentId: 'agent-weekly-report',
	sourceType: 'draft',
	draft: {
		kind: 'delivery',
		channel: 'email',
		output: REPORT,
		conversationUuid: null,
	},
}

const MAIL_DRAFT = {
	...BASE,
	id: 'appr-supplier-mail',
	scheduleId: '',
	agentId: 'agent-supplier-digest',
	sourceType: 'draft',
	toolId: 'hermiq.sendMail',
	draft: {
		kind: 'tool',
		toolId: 'hermiq.sendMail',
		arguments: { to: MAIL_TO, subject: MAIL_SUBJECT, body: MAIL_BODY },
		editable: ['subject', 'body'],
	},
}

const HELD_RUN = {
	...BASE,
	id: 'appr-held-run',
	prompt: 'Summarise this week of supplier mail',
	sourceType: 'schedule',
}

/** The POSTs the inbox sent, by route, in order. */
interface Sent {
	answer: Array<{ id: string, body: unknown }>
	release: Array<{ id: string, body: unknown }>
	deny: Array<{ id: string, body: unknown }>
}

/**
 * The approval id in a decision URL (`/api/approvals/{id}/{action}`).
 *
 * @param request The caught request.
 * @return The id.
 */
function approvalIdOf(request: Request): string {
	const parts = new URL(request.url()).pathname.split('/')
	return decodeURIComponent(parts[parts.length - 2] ?? '')
}

/**
 * Answer the inbox read with the given records and catch the answer, release
 * and deny POSTs. A decided approval leaves the list, as on the server.
 *
 * @param page    The page.
 * @param records The pending inbox records.
 * @return What the page sent.
 */
async function stubInbox(
	page: Page,
	records: Array<Record<string, unknown>>,
): Promise<Sent> {
	const sent: Sent = { answer: [], release: [], deny: [] }
	const decided = new Set<string>()

	await page.route('**/apps/hermiq/api/approvals', (route) =>
		route.request().method() === 'GET'
			? route.fulfill({
				json: { results: records.filter((r) => !decided.has(String(r.id))) },
			})
			: route.continue(),
	)

	for (const action of ['answer', 'release', 'deny'] as const) {
		await page.route(`**/apps/hermiq/api/approvals/*/${action}`, (route) => {
			const id = approvalIdOf(route.request())
			sent[action].push({ id, body: route.request().postDataJSON() })
			decided.add(id)
			return route.fulfill({
				json: {
					approvalId: id,
					status: action === 'deny' ? 'denied' : 'approved',
					ran: action !== 'deny',
				},
			})
		})
	}
	return sent
}

/**
 * Open the approval inbox.
 *
 * @param page The page.
 * @return Nothing.
 */
async function openInbox(page: Page): Promise<void> {
	await page.goto(`${await appRoot(page)}/approvals`, { waitUntil: 'domcontentloaded' })
	await dismissTour(page)
	await expect(page.locator('.approval-inbox__table')).toBeVisible({ timeout: 30_000 })
}

/**
 * The inbox row that shows the given text.
 *
 * @param page The page.
 * @param text Text in the row.
 * @return The row.
 */
function row(page: Page, text: string) {
	return page.locator('.approval-inbox__table tbody tr').filter({ hasText: text })
}

test.describe.configure({ mode: 'serial' })

test.describe('oversight-ask-and-hold: a question in chat', () => {
	let token = ''
	let sessionUuid = ''

	test.beforeAll(async ({ browser }) => {
		const page = await browser.newPage()
		token = await harvestToken(page)
		const agentId = (await seedAgent(page.request, token, {
			name: `${TEST_PREFIX}-intake`,
		})).id
		const created = await page.request.post(`${API}/sessions`, {
			headers: jsonHeaders(token),
			data: { agentId, title: INTAKE_TITLE },
		})
		expect(created.status(), await created.text()).toBe(201)
		sessionUuid = String((await created.json()).uuid)
		await page.close()
	})

	test.afterAll(async ({ browser }) => {
		const page = await browser.newPage()
		const cleanupToken = await harvestToken(page)
		const headers = jsonHeaders(cleanupToken)
		if (sessionUuid !== '') {
			await page.request
				.delete(`${API}/sessions/${sessionUuid}`, { headers })
				.catch(() => undefined)
			await page.request
				.delete(`${API}/sessions/${sessionUuid}/permanent`, { headers })
				.catch(() => undefined)
		}
		await cleanupFamily(page.request, cleanupToken, 'agent')
		await page.close()
	})

	// @e2e human-approval-gate::an-agent-asks-which-municipality-a-request-is-for
	test('the question shows under the answer with three buttons and a text box, and "Houten" is sent as the next turn', async ({
		page,
	}) => {
		const turns: Array<Record<string, unknown>> = []
		const sentMessages: string[] = []
		const turn = (uuid: string, role: string, content: string) => ({
			uuid,
			id: uuid,
			role,
			content,
			created: new Date().toISOString(),
		})

		await page.route(
			(url) => url.pathname.endsWith(`/api/sessions/${sessionUuid}/messages`),
			(route) =>
				route.request().method() === 'GET'
					? route.fulfill({ json: { results: turns, total: turns.length } })
					: route.continue(),
		)

		await page.route('**/apps/hermiq/api/chat/stream', async (route) => {
			const body = route.request().postDataJSON() as { message?: string, sessionUuid?: string }
			const message = String(body.message ?? '')
			sentMessages.push(message)
			const first = sentMessages.length === 1
			const final = first
				? {
					messageId: ASK_TURN,
					fullText: ASK_ANSWER,
					question: {
						text: 'Which municipality is this for?',
						options: MUNICIPALITIES,
						allowOther: true,
					},
				}
				: { messageId: CONTINUE_TURN, fullText: CONTINUE_ANSWER, question: null }
			turns.push(turn(`${TEST_PREFIX}-user-${sentMessages.length}`, 'user', message))
			turns.push(turn(final.messageId, 'assistant', final.fullText))
			await route.fulfill({
				status: 200,
				headers: { 'Content-Type': 'text/event-stream' },
				body: `event: final\ndata: ${JSON.stringify(final)}\n\n`,
			})
		})

		await page.goto(`${await appRoot(page)}/chat`)
		await dismissTour(page)
		await page.getByText(INTAKE_TITLE, { exact: true }).first().click({ timeout: 20_000 })

		await page.getByLabel('Message', { exact: true }).fill(REQUEST_TEXT)
		await page.getByRole('button', { name: 'Send message' }).click()

		const asking = page
			.locator('.chat-page__message--assistant')
			.filter({ hasText: ASK_ANSWER })
		const card = asking.getByTestId('chat-agent-question')
		await expect(card).toBeVisible({ timeout: 30_000 })
		await expect(card).toHaveAttribute('aria-label', 'Question from the agent')
		await expect(card.locator('.agent-question__text')).toHaveText('Which municipality is this for?')

		const options = card.getByRole('group', { name: 'Suggested answers' }).getByRole('button')
		await expect(options).toHaveCount(3)
		await expect(options).toHaveText(MUNICIPALITIES)
		await expect(card.getByLabel('Your own answer')).toBeVisible()
		await expect(card.getByRole('button', { name: 'Send answer' })).toBeDisabled()

		// One card in the whole thread, under the answer that asked.
		await expect(page.getByTestId('chat-agent-question')).toHaveCount(1)

		await options.filter({ hasText: 'Houten' }).click()

		await expect(
			page.locator('.chat-page__message--assistant').filter({ hasText: CONTINUE_ANSWER }),
		).toBeVisible({ timeout: 30_000 })
		expect(sentMessages).toEqual([REQUEST_TEXT, 'Houten'])
		await expect(page.getByTestId('chat-agent-question')).toHaveCount(0)
	})
})

test.describe('oversight-ask-and-hold: questions and drafts in the approval inbox', () => {
	test.beforeEach(async ({ page }) => {
		await harvestToken(page)
	})

	// @e2e human-approval-gate::a-nightly-run-asks-the-team-lead-and-continues-in-the-morning
	test('the team lead answers "Yes, close them" in the inbox and the question leaves it', async ({
		page,
	}) => {
		const sent = await stubInbox(page, [QUESTION, HELD_RUN])
		await openInbox(page)

		const questionRow = row(page, 'Close the three duplicate reports?')
		const card = questionRow.getByTestId('chat-agent-question')
		await expect(card).toBeVisible()
		const options = card.getByRole('group', { name: 'Suggested answers' }).getByRole('button')
		await expect(options).toHaveText(['Yes, close them', 'No, keep them'])
		await expect(card.getByTestId('chat-agent-question-other')).toHaveCount(0)
		// A question is answered, never approved.
		await expect(questionRow.getByRole('button', { name: 'Approve run' })).toHaveCount(0)

		await options.filter({ hasText: 'Yes, close them' }).click()

		await expect(questionRow).toHaveCount(0)
		expect(sent.answer).toEqual([
			{ id: 'appr-question', body: { answer: 'Yes, close them' } },
		])
		expect(sent.release).toEqual([])
		expect(sent.deny).toEqual([])
		await expect(row(page, 'Summarise this week of supplier mail')).toBeVisible()
	})

	// @e2e human-approval-gate::a-weekly-report-is-checked-before-it-reaches-the-council
	test('"Drafts" shows only the held drafts, and "Edit and release" sends only the corrected text', async ({
		page,
	}) => {
		const sent = await stubInbox(page, [QUESTION, WEEKLY_DRAFT, MAIL_DRAFT, HELD_RUN])
		await openInbox(page)

		const all = page.getByTestId('approval-filter-all')
		const drafts = page.getByTestId('approval-filter-drafts')
		await expect(all).toHaveText('All')
		await expect(all).toHaveAttribute('aria-pressed', 'true')
		await expect(drafts).toHaveText('Drafts (2)')
		await expect(page.locator('.approval-inbox__table tbody tr')).toHaveCount(4)

		await drafts.click()
		await expect(drafts).toHaveAttribute('aria-pressed', 'true')
		await expect(all).toHaveAttribute('aria-pressed', 'false')
		const rows = page.locator('.approval-inbox__table tbody tr')
		await expect(rows).toHaveCount(2)
		// A draft row shows its first line: the report's title, the mail's subject.
		await expect(rows.locator('.approval-inbox__draft')).toHaveText([
			'Weekly report for the council, week 47',
			MAIL_SUBJECT,
		])
		await expect(row(page, 'Close the three duplicate reports?')).toHaveCount(0)

		await row(page, 'Weekly report for the council, week 47')
			.getByTestId('approval-review-draft')
			.click()
		const review = page.getByTestId('draft-review')
		await expect(review).toBeVisible()
		await expect(review.getByRole('heading', { name: 'Review draft' })).toBeVisible()
		await expect(review.getByTestId('draft-review-recipients')).toHaveText(/Goes to\s*email/)

		const text = review.getByLabel('Text', { exact: true })
		await expect(text).toHaveValue(REPORT)
		const release = review.getByTestId('draft-review-release')
		const editRelease = review.getByTestId('draft-review-edit-release')
		await expect(release).toHaveText('Release')
		await expect(editRelease).toHaveText('Edit and release')
		await expect(release).toBeEnabled()
		await expect(editRelease).toBeDisabled()

		await text.fill(REPORT_CORRECTED)
		await expect(release).toBeDisabled()
		await expect(editRelease).toBeEnabled()
		await editRelease.click()

		await expect(review).toBeHidden()
		expect(sent.release).toEqual([
			{ id: 'appr-weekly-report', body: { edits: { output: REPORT_CORRECTED } } },
		])
		await expect(drafts).toHaveText('Drafts (1)')
		await expect(row(page, 'Weekly report for the council, week 47')).toHaveCount(0)
	})

	// @e2e human-approval-gate::a-weekly-report-is-checked-before-it-reaches-the-council
	test('a held mail is released as written with no edits, and its recipient cannot be edited', async ({
		page,
	}) => {
		const sent = await stubInbox(page, [MAIL_DRAFT])
		await openInbox(page)

		await row(page, MAIL_SUBJECT).getByTestId('approval-review-draft').click()
		const review = page.getByTestId('draft-review')
		await expect(review.getByTestId('draft-review-recipients')).toHaveText(
			new RegExp(`Goes to\\s*${MAIL_TO.replace('.', '\\.')}`),
		)
		await expect(review.getByLabel('Subject', { exact: true })).toHaveValue(MAIL_SUBJECT)
		await expect(review.getByLabel('Message', { exact: true })).toHaveValue(MAIL_BODY)
		// Only the subject and the message are fields; the recipient is text.
		await expect(review.locator('input, textarea')).toHaveCount(2)

		await review.getByTestId('draft-review-release').click()

		await expect(review).toBeHidden()
		expect(sent.release).toEqual([
			{ id: 'appr-supplier-mail', body: { edits: {} } },
		])
		await expect(page.locator('.approval-inbox__table')).toHaveCount(0)
	})

	// @e2e human-approval-gate::a-weekly-report-is-checked-before-it-reaches-the-council
	test('editing only the subject of a held mail sends only the subject', async ({
		page,
	}) => {
		const sent = await stubInbox(page, [MAIL_DRAFT])
		await openInbox(page)

		await row(page, MAIL_SUBJECT).getByTestId('approval-review-draft').click()
		const review = page.getByTestId('draft-review')
		await review.getByLabel('Subject', { exact: true }).fill('Supplier digest week 47 (corrected)')
		await review.getByTestId('draft-review-edit-release').click()

		await expect(review).toBeHidden()
		expect(sent.release).toEqual([
			{
				id: 'appr-supplier-mail',
				body: { edits: { subject: 'Supplier digest week 47 (corrected)' } },
			},
		])
	})

	// @e2e human-approval-gate::a-weekly-report-is-checked-before-it-reaches-the-council
	test('"Discard" asks for a reason and denies the draft with it, releasing nothing', async ({
		page,
	}) => {
		const sent = await stubInbox(page, [WEEKLY_DRAFT])
		await openInbox(page)

		await row(page, 'Weekly report for the council, week 47')
			.getByTestId('approval-review-draft')
			.click()
		const review = page.getByTestId('draft-review')
		await review.getByTestId('draft-review-discard').click()
		await expect(review).toBeHidden()

		const deny = page.locator('.approval-deny')
		await expect(deny).toBeVisible()
		await expect(deny.getByRole('heading', { name: 'Deny approval' })).toBeVisible()
		await deny.getByLabel('Reason (optional)').fill('The figures are from the wrong week.')
		await deny.getByRole('button', { name: 'Deny', exact: true }).click()

		await expect(row(page, 'Weekly report for the council, week 47')).toHaveCount(0)
		expect(sent.deny).toEqual([
			{
				id: 'appr-weekly-report',
				body: { reason: 'The figures are from the wrong week.' },
			},
		])
		expect(sent.release).toEqual([])
	})
})
