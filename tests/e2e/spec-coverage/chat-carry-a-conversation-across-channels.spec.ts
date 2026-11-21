/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spec-coverage e2e for chat-carry-a-conversation-across-channels: a session
 * begun in a Talk room is listed and continued on the Chat page, the Chat page
 * opens the room, and a turn typed in Talk says so.
 *
 * Covered openspec scenarios (the `// @e2e` line above each test is the hook):
 *   - session-channel-continuity::a-buyer-picks-up-on-the-desktop-what-she-began-in-talk
 *     (REQ-SCHAN-001): the session row shows the room's name with the Talk
 *     icon, opening it shows both Talk questions and both answers, and a third
 *     question typed there is sent as a turn of that same session.
 *   - session-channel-continuity::from-the-chat-page-to-the-room (REQ-SCHAN-003):
 *     "Open in Talk" in the session's menu goes to the room's `/call/<token>`.
 *   - session-channel-continuity::a-mixed-thread-shows-its-origins
 *     (REQ-SCHAN-004): two turns typed in Talk read "via Talk", the third does not.
 *
 * WHAT IS REAL AND WHAT IS STUBBED:
 *   - The bound session and its turns are seeded through the OpenRegister
 *     objects API in the shape TalkRoomBinding and TalkTurnService store them
 *     (`talkRoomToken` on the session, `channel: "talk"` on a person's turn), so
 *     no Talk room and no model are needed. The list, the thread, the icon, the
 *     menu action and the "via Talk" line are the shipped Chat page reading the
 *     shipped SessionController.
 *   - The third question's stream (`/api/chat/stream`) is stubbed: the test
 *     checks that the turn goes to the bound session, not what a model answers.
 *     Whether the answer used the first two turns as context is a server-side
 *     property and stays with the live check in tasks.md.
 *   - The Talk page itself (`/call/<token>`) is stubbed in the browser, since CI
 *     does not install Talk; the test checks where the Chat page sends the person.
 *
 * Left to the live check in tasks.md (Verification), because no browser stub
 * reaches it:
 *   - session-channel-continuity::the-room-sees-the-question-asked-on-the-desktop
 *     (REQ-SCHAN-002): the mirror is MirrorTurnToTalkJob, a background job that
 *     posts through the agent's Talk bot after a real model answer. It needs
 *     Talk, a bot, a model and the job runner; PHPUnit covers TalkTurnMirror and
 *     the job.
 *
 * Not written here, per their `@e2e exclude` lines in the spec:
 *   - a-room-member-who-is-not-on-the-roster-cannot-continue (PHPUnit, Newman)
 *   - talk-being-down-does-not-stop-the-chat (PHPUnit on the mirror job)
 *   - from-the-room-to-the-chat-page (PHPUnit on the answer formatter)
 *
 * Every seeded object carries the e2espec- family prefix and is removed in
 * afterAll.
 *
 * Run against a disposable Nextcloud with hermiq + OpenRegister installed:
 *
 *     PLAYWRIGHT_BASE_URL=<your rig> NC_USER=admin NC_PASS=admin \
 *       npx playwright test -c tests/e2e/spec-coverage/playwright.config.ts chat-carry-a-conversation-across-channels
 *
 * Covers openspec/changes/chat-carry-a-conversation-across-channels/specs/session-channel-continuity/spec.md
 */

import type { APIRequestContext, Locator, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import {
	appRoot,
	cleanupFamily,
	deleteObject,
	dismissTour,
	harvestToken,
	seedAgent,
	seedObject,
	TEST_PREFIX,
} from './_fixtures.ts'

const USER = process.env.NC_USER || 'admin'

const SESSION_SCHEMA = 'agentsession'
const TURN_SCHEMA = 'agentsessionturn'

/** The session bound to the room, titled after the room (TalkRoomBinding::createBound). */
const ROOM_TITLE = `${TEST_PREFIX} Inkoop overleg`

/** A session with turns from both channels. */
const MIXED_TITLE = `${TEST_PREFIX} Inkoop overleg gemengd`

/** A Talk room token: Talk mints lowercase letters and digits. */
const ROOM_TOKEN = `e2e${Date.now().toString(36)}`.toLowerCase()

/** The two exchanges Anne had in the Talk room. */
const TALK_TURNS = [
	{
		question: 'Welke leveranciers hebben op de raamovereenkomst ingeschreven?',
		answer: 'Drie leveranciers hebben ingeschreven: Van Dijk Kantoor, Bureau Noord en OfficeLine.',
	},
	{
		question: 'Wanneer sluit de inschrijving?',
		answer: 'De inschrijving sluit op 12 december om 12:00 uur.',
	},
]

/** The question typed on the Chat page. */
const CHAT_QUESTION = 'Welke offertes lopen nog?'

/**
 * Seed one session turn and remember its id for cleanup.
 *
 * @param req   The request context.
 * @param token The CSRF request-token.
 * @param ids   The list of seeded turn ids.
 * @param data  The turn.
 * @return Nothing.
 */
async function seedTurn(
	req: APIRequestContext,
	token: string,
	ids: string[],
	data: Record<string, unknown>,
): Promise<void> {
	ids.push((await seedObject(req, token, TURN_SCHEMA, data)).id)
}

/**
 * The session's row in the list on the Chat page.
 *
 * @param page  The page, on `/chat`.
 * @param title The session title.
 * @return The row.
 */
function sessionRow(page: Page, title: string): Locator {
	return page
		.locator('.chat-page__row')
		.filter({ has: page.getByText(title, { exact: true }) })
		.first()
}

test.describe.configure({ mode: 'serial' })

test.describe('chat-carry-a-conversation-across-channels: from Talk to the Chat page and back', () => {
	let token = ''
	let agentId = ''
	let roomSession = ''
	let mixedSession = ''
	const turnIds: string[] = []

	test.beforeAll(async ({ browser }) => {
		const page = await browser.newPage()
		token = await harvestToken(page)

		agentId = (await seedAgent(page.request, token, {
			name: `${TEST_PREFIX}-inkoopassistent`,
			prompt: 'Help the purchasing team with tenders.',
		})).id

		// The session Talk began: bound to the room, titled after it.
		roomSession = (await seedObject(page.request, token, SESSION_SCHEMA, {
			agentId,
			title: ROOM_TITLE,
			userId: USER,
			talkRoomToken: ROOM_TOKEN,
			talkRoomOrigin: 'bound',
			triggerOrigin: 'human',
		})).id
		for (const turn of TALK_TURNS) {
			await seedTurn(page.request, token, turnIds, {
				sessionId: roomSession,
				agentId,
				role: 'user',
				content: turn.question,
				authorId: USER,
				channel: 'talk',
			})
			await seedTurn(page.request, token, turnIds, {
				sessionId: roomSession,
				agentId,
				role: 'assistant',
				content: turn.answer,
			})
		}

		// Two turns typed in Talk, then one on the Chat page.
		mixedSession = (await seedObject(page.request, token, SESSION_SCHEMA, {
			agentId,
			title: MIXED_TITLE,
			userId: USER,
			talkRoomToken: `${ROOM_TOKEN}m`,
			talkRoomOrigin: 'bound',
			triggerOrigin: 'human',
		})).id
		const mixed = [
			{ question: TALK_TURNS[0].question, answer: TALK_TURNS[0].answer, channel: 'talk' },
			{ question: TALK_TURNS[1].question, answer: TALK_TURNS[1].answer, channel: 'talk' },
			{ question: CHAT_QUESTION, answer: 'Twee offertes lopen nog: schoonmaak en catering.', channel: 'hermiq' },
		]
		for (const turn of mixed) {
			await seedTurn(page.request, token, turnIds, {
				sessionId: mixedSession,
				agentId,
				role: 'user',
				content: turn.question,
				authorId: USER,
				channel: turn.channel,
			})
			await seedTurn(page.request, token, turnIds, {
				sessionId: mixedSession,
				agentId,
				role: 'assistant',
				content: turn.answer,
			})
		}
		await page.close()
	})

	test.afterAll(async ({ browser }) => {
		const page = await browser.newPage()
		const cleanupToken = await harvestToken(page)
		for (const id of turnIds) {
			await deleteObject(page.request, cleanupToken, TURN_SCHEMA, id)
		}
		for (const id of [roomSession, mixedSession].filter(Boolean)) {
			await deleteObject(page.request, cleanupToken, SESSION_SCHEMA, id)
		}
		await cleanupFamily(page.request, cleanupToken, 'agent')
		await page.close()
	})

	// @e2e session-channel-continuity::a-buyer-picks-up-on-the-desktop-what-she-began-in-talk
	test('the session begun in Talk is listed with the room name and Talk icon and continues here', async ({
		page,
	}) => {
		const sent: { body: Record<string, unknown> | null } = { body: null }
		await page.route('**/apps/hermiq/api/chat/stream', async (route) => {
			sent.body = route.request().postDataJSON() as Record<string, unknown>
			const final = {
				messageId: `${TEST_PREFIX}-answer-3`,
				fullText: 'Twee offertes lopen nog.',
			}
			await route.fulfill({
				status: 200,
				headers: { 'Content-Type': 'text/event-stream' },
				body: `event: final\ndata: ${JSON.stringify(final)}\n\n`,
			})
		})

		await page.goto(`${await appRoot(page)}/chat`)
		await dismissTour(page)

		const row = sessionRow(page, ROOM_TITLE)
		await expect(row).toBeVisible({ timeout: 20_000 })
		// A session with a room is drawn with the Talk (Forum) icon.
		await expect(row.locator('.chat-page__row-icon .forum-icon')).toBeVisible()

		await row.getByTestId('chat-session-row').click()
		for (const turn of TALK_TURNS) {
			await expect(
				page.locator('.chat-page__message--user').filter({ hasText: turn.question }),
			).toBeVisible({ timeout: 20_000 })
			await expect(
				page.locator('.chat-page__message--assistant').filter({ hasText: turn.answer }),
			).toBeVisible()
		}

		// A third question, typed on the desktop, is a turn of the same session.
		await page.getByLabel('Message', { exact: true }).fill(CHAT_QUESTION)
		await page.getByRole('button', { name: 'Send message' }).click()
		await expect.poll(() => sent.body, { timeout: 20_000 }).not.toBeNull()
		expect(sent.body?.conversationUuid).toBe(roomSession)
		expect(sent.body?.message).toBe(CHAT_QUESTION)
	})

	// @e2e session-channel-continuity::from-the-chat-page-to-the-room
	test('"Open in Talk" in the session menu opens the Talk room', async ({ page }) => {
		// CI installs no Talk: answer the room page here, so the test checks where
		// the Chat page sends the person rather than whether Talk renders.
		await page.route(`**/call/${ROOM_TOKEN}`, async (route) => {
			await route.fulfill({
				status: 200,
				contentType: 'text/html',
				body: '<!doctype html><title>Talk</title><h1>Inkoop overleg</h1>',
			})
		})

		await page.goto(`${await appRoot(page)}/chat`)
		await dismissTour(page)

		const row = sessionRow(page, ROOM_TITLE)
		await expect(row).toBeVisible({ timeout: 20_000 })
		await row.getByRole('button', { name: 'Session actions' }).click()
		const openInTalk = page.getByTestId('chat-session-open-in-talk')
		await expect(openInTalk).toContainText('Open in Talk')
		await openInTalk.click()

		await expect(page).toHaveURL(new RegExp(`/call/${ROOM_TOKEN}$`), { timeout: 20_000 })
	})

	// @e2e session-channel-continuity::a-mixed-thread-shows-its-origins
	test('the two turns typed in Talk read "via Talk" and the one typed here does not', async ({
		page,
	}) => {
		await page.goto(`${await appRoot(page)}/chat`)
		await dismissTour(page)

		const row = sessionRow(page, MIXED_TITLE)
		await expect(row).toBeVisible({ timeout: 20_000 })
		await row.getByTestId('chat-session-row').click()

		const userTurn = (text: string): Locator =>
			page.locator('.chat-page__message--user').filter({ hasText: text })

		await expect(userTurn(CHAT_QUESTION)).toBeVisible({ timeout: 20_000 })
		await expect(page.getByTestId('chat-message-via-talk')).toHaveCount(2)
		for (const turn of TALK_TURNS) {
			await expect(userTurn(turn.question).getByTestId('chat-message-via-talk')).toHaveText('via Talk')
		}
		await expect(userTurn(CHAT_QUESTION).getByTestId('chat-message-via-talk')).toHaveCount(0)

		// The agent's answers carry no channel line either.
		await expect(
			page.locator('.chat-page__message--assistant').getByTestId('chat-message-via-talk'),
		).toHaveCount(0)
	})
})
