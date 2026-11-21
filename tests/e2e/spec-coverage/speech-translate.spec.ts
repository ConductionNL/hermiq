/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spec-coverage e2e for chat-translate-a-recording: "Translate a recording" on
 * the Chat page, with the speech sidecar stubbed (tasks.md Task 5).
 *
 * Covered openspec scenarios (the `// @e2e` line above each test is the hook):
 *   - speech-services::a-front-office-employee-translates-a-voicemail
 *     (REQ-STRN-001): the voicemail is a real file in the admin's Files, chosen
 *     through the shipped file picker and sent by its file id; the chat shows
 *     the Arabic transcript, the Dutch translation and a player.
 *   - speech-services::a-language-without-a-voice-gets-text (REQ-STRN-001):
 *     a target without a voice is offered as "text only", and the result shows
 *     the written translation, the no-voice notice and no player.
 *   - speech-services::a-reviewer-can-see-what-was-used (REQ-STRN-002): real.
 *     A completed translation is seeded as the two turns SpeechTranslationController
 *     stores, and reopening the session shows the languages-and-engines line.
 *
 * WHAT IS STUBBED, AND WHY. The chain (transcribe on the sidecar, translate
 * under the `speech-translation` feature, speak with the mapped voice) runs on
 * the server, so the browser-facing ends of it are answered here:
 *   - `/api/speech/capabilities` (the Chat page offers the action only while
 *     the on-instance speech service answers);
 *   - `/api/speech/translations/where` (where the translation step runs, and
 *     which languages have a voice);
 *   - `POST /api/speech/translations` and, after it, the session's message
 *     listing, in the shape the controller answers with its two stored turns.
 * The modal, the file picker, the request it sends and the result in the thread
 * are the shipped code.
 *
 * Left to the live check in tasks.md (Verification, "with the speech sidecar"),
 * because the stub writes no file: that the spoken WAV lands in Files under
 * `Hermiq/Translations` tagged "Agent authored", and that a voiceless language
 * writes no audio. PHPUnit covers the chain (Task 1) and the saved file (Task 3).
 *
 * Not written here, per their `@e2e exclude` lines in the spec:
 *   - an-on-premise-requirement-stops-a-hosted-translation (residency and
 *     binding per test; PHPUnit on the chain)
 *   - nextcloud-34-boots-without-the-provider (two Nextcloud versions; PHPUnit
 *     on the registration guard)
 *
 * Every seeded object and file carries the e2espec- family prefix and is
 * removed in afterAll.
 *
 * Run against a disposable Nextcloud with hermiq + OpenRegister installed:
 *
 *     PLAYWRIGHT_BASE_URL=<your rig> NC_USER=admin NC_PASS=admin \
 *       npx playwright test -c tests/e2e/spec-coverage/playwright.config.ts speech-translate
 *
 * Covers openspec/changes/chat-translate-a-recording/specs/speech-services/spec.md
 */

import type { Locator, Page, Route } from '@playwright/test'

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
const USER = process.env.NC_USER || 'admin'
const DAV = `/remote.php/dav/files/${USER}`
const TURN_SCHEMA = 'agentsessionturn'

/** The voicemail in the front office employee's Files. */
const VOICEMAIL = `${TEST_PREFIX}-terugbelverzoek.m4a`

const SESSION_TITLE = `${TEST_PREFIX} Terugbelverzoeken`
const REVIEW_TITLE = `${TEST_PREFIX} Vertaalde voicemail`

const TRANSCRIPT = 'مرحبا، أنا أتصل بخصوص طلب جواز السفر. أرجو أن تتصلوا بي.'
const TRANSLATION = 'Hallo, ik bel over de aanvraag van mijn paspoort. Wilt u mij terugbellen?'
const NO_VOICE = 'There is no voice for Nederlands on this server. The translation is shown as text.'
const SAVED_PATH = `Hermiq/Translations/${TEST_PREFIX}-terugbelverzoek-nl.wav`

/** The record the chain stores on the answer turn (SessionTurn.translation). */
const RECORD = {
	sourceLanguage: '',
	detectedLanguage: 'ar',
	targetLanguage: 'nl',
	sttEngine: 'whisper',
	translationProvider: 'ollama',
	translationModel: 'qwen3:8b',
	translationResidency: 'on-premise',
	ttsEngine: 'piper',
	ttsVoice: 'nl_NL-mls-medium',
}

/** The languages-and-engines line, the language names in the reader's language. */
const LINE = /^(Arabic|Arabisch) \(detected\) to (Dutch|Nederlands)\. Transcribed and spoken on this server, translated by Qwen3 on this server\.$/

/**
 * Answer the browser-facing ends of the speech chain.
 *
 * @param page   The page.
 * @param voices The languages the administrator mapped a voice for.
 * @return Nothing.
 */
async function stubSpeechService(page: Page, voices: string[]): Promise<void> {
	await page.route('**/apps/hermiq/api/speech/capabilities', async (route) => {
		await route.fulfill({
			status: 200,
			contentType: 'application/json',
			body: JSON.stringify({ available: true, reason: '' }),
		})
	})
	await page.route('**/apps/hermiq/api/speech/translations/where', async (route) => {
		await route.fulfill({
			status: 200,
			contentType: 'application/json',
			body: JSON.stringify({
				enabled: true,
				provider: 'ollama',
				model: 'qwen3:8b',
				residency: 'on-premise',
				location: '',
				refusal: '',
				voices,
			}),
		})
	})
}

/**
 * Answer the translation POST with the two stored turns, then serve those turns
 * from the session's message listing (the Chat page re-reads it afterwards).
 *
 * @param page        The page.
 * @param sessionUuid The session the result is added to.
 * @param spoken      Whether the translation was spoken.
 * @return The captured multipart body of the translation request.
 */
async function stubTranslation(
	page: Page,
	sessionUuid: string,
	spoken: boolean,
): Promise<{ body: string }> {
	const sent = { body: '' }
	const now = new Date().toISOString()
	const userTurn = {
		id: `${TEST_PREFIX}-translate-request`,
		uuid: `${TEST_PREFIX}-translate-request`,
		sessionId: sessionUuid,
		role: 'user',
		content: `Translate ${VOICEMAIL} into Nederlands`,
		authorId: USER,
		created: now,
	}
	const assistantTurn = {
		id: `${TEST_PREFIX}-translate-result`,
		uuid: `${TEST_PREFIX}-translate-result`,
		sessionId: sessionUuid,
		role: 'assistant',
		content: spoken
			? `${TRANSCRIPT}\n\n${TRANSLATION}`
			: `${TRANSCRIPT}\n\n${TRANSLATION}\n\n${NO_VOICE}`,
		attachments: [],
		translation: {
			...RECORD,
			ttsEngine: spoken ? RECORD.ttsEngine : '',
			ttsVoice: spoken ? RECORD.ttsVoice : '',
			transcript: TRANSCRIPT,
			translation: TRANSLATION,
			audioPath: spoken ? SAVED_PATH : '',
		},
		created: now,
	}
	let translated = false

	await page.route('**/apps/hermiq/api/speech/translations', async (route: Route) => {
		if (route.request().method() !== 'POST') {
			await route.continue()
			return
		}
		sent.body = route.request().postData() ?? ''
		translated = true
		await route.fulfill({
			status: 200,
			contentType: 'application/json',
			body: JSON.stringify({
				transcript: TRANSCRIPT,
				translation: TRANSLATION,
				spoken,
				notice: spoken ? '' : NO_VOICE,
				file: spoken ? { fileId: 99001, name: SAVED_PATH.split('/').pop(), path: SAVED_PATH, size: 48_000 } : null,
				record: assistantTurn.translation,
				userTurn,
				assistantTurn,
			}),
		})
	})
	await page.route('**/apps/hermiq/api/sessions/*/messages**', async (route) => {
		if (!translated) {
			await route.continue()
			return
		}
		await route.fulfill({
			status: 200,
			contentType: 'application/json',
			body: JSON.stringify({
				results: [userTurn, assistantTurn],
				total: 2,
				limit: 50,
				offset: 0,
				pendingApprovals: [],
			}),
		})
	})
	return sent
}

/**
 * Open the session on the Chat page and the "Translate a recording" form.
 *
 * @param page  The page.
 * @param title The session title.
 * @return The form.
 */
async function openTranslateForm(page: Page, title: string): Promise<Locator> {
	await page.goto(`${await appRoot(page)}/chat`)
	await dismissTour(page)
	await page
		.getByText(title, { exact: true })
		.first()
		.click({ timeout: 20_000 })
	await page.getByTestId('chat-attach').getByRole('button').first().click()
	const action = page.getByTestId('chat-translate-recording')
	await expect(action).toContainText('Translate a recording')
	await action.click()
	const form = page.getByTestId('translate-form')
	await expect(form).toBeVisible()
	return form
}

/**
 * Choose the voicemail through the Files picker.
 *
 * @param page The page, with the form open.
 * @param form The form.
 * @return Nothing.
 */
async function chooseVoicemail(page: Page, form: Locator): Promise<void> {
	await form.getByTestId('translate-choose-file').click()
	const picker = page.getByRole('dialog', { name: 'Choose a recording' })
	await picker.getByText(VOICEMAIL).click({ timeout: 20_000 })
	await picker.getByRole('button', { name: 'Choose' }).click()
	await expect(form.getByTestId('translate-chosen')).toHaveText(VOICEMAIL)
}

/**
 * Pick the target language in the "Translate into" select.
 *
 * @param page  The page.
 * @param form  The form.
 * @param label The option's label.
 * @return Nothing.
 */
async function chooseTarget(page: Page, form: Locator, label: RegExp): Promise<void> {
	await form.getByRole('combobox', { name: /Translate into/ }).click()
	// NcSelect appends its listbox to <body>, outside the dialog.
	await page.locator('li[role="option"]', { hasText: label }).first().click()
	await expect(form.getByTestId('translate-target')).toContainText(/Dutch|Nederlands/)
}

test.describe.configure({ mode: 'serial' })

test.describe('chat-translate-a-recording: translate a recording and hear the result', () => {
	let token = ''
	let voicemailId = 0
	const sessions: string[] = []
	const turnIds: string[] = []

	test.beforeAll(async ({ browser }) => {
		const page = await browser.newPage()
		token = await harvestToken(page)
		const headers = { ...jsonHeaders(token), 'OCS-APIRequest': 'true' }

		const put = await page.request.put(`${DAV}/${VOICEMAIL}`, {
			headers: { requesttoken: token, 'Content-Type': 'audio/mp4' },
			data: Buffer.from('0000001866747970', 'hex'),
		})
		expect([201, 204]).toContain(put.status())
		voicemailId = parseInt(put.headers()['oc-fileid'] ?? '', 10)
		expect(voicemailId, 'the PUT must answer with an OC-FileId').toBeGreaterThan(0)

		const agentId = (await seedAgent(page.request, token, {
			name: `${TEST_PREFIX}-frontoffice`,
		})).id
		for (const title of [SESSION_TITLE, REVIEW_TITLE]) {
			const created = await page.request.post(`${API}/sessions`, {
				headers,
				data: { agentId, title },
			})
			expect(created.status(), await created.text()).toBe(201)
			sessions.push(String((await created.json()).uuid))
		}

		// A completed translation, stored as SpeechTranslationController stores it.
		const review = sessions[1]
		turnIds.push((await seedObject(page.request, token, TURN_SCHEMA, {
			sessionId: review,
			agentId,
			role: 'user',
			content: `Translate ${VOICEMAIL} into Nederlands`,
			authorId: USER,
		})).id)
		turnIds.push((await seedObject(page.request, token, TURN_SCHEMA, {
			sessionId: review,
			agentId,
			role: 'assistant',
			content: `${TRANSCRIPT}\n\n${TRANSLATION}`,
			translation: {
				...RECORD,
				transcript: TRANSCRIPT,
				translation: TRANSLATION,
				audioPath: '',
			},
		})).id)
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
		await page.request
			.delete(`${DAV}/${VOICEMAIL}`, { headers: { requesttoken: cleanupToken } })
			.catch(() => undefined)
		await cleanupFamily(page.request, cleanupToken, 'agent')
		await page.close()
	})

	// @e2e speech-services::a-front-office-employee-translates-a-voicemail
	test('a voicemail from Files is translated into Dutch: transcript, translation and a player', async ({
		page,
	}) => {
		await stubSpeechService(page, ['nl', 'ar'])
		const sent = await stubTranslation(page, sessions[0], true)

		const form = await openTranslateForm(page, SESSION_TITLE)
		// Where the translation runs is said before the person starts.
		await expect(form.getByTestId('translate-where')).toContainText(
			'Translation runs on qwen3:8b on this server.',
		)
		await expect(form.getByTestId('translate-start')).toBeDisabled()

		await chooseVoicemail(page, form)
		await chooseTarget(page, form, /^\s*(Dutch|Nederlands)\s*$/)
		await form.getByTestId('translate-start').click()
		await expect(form).toBeHidden({ timeout: 20_000 })

		// Sent by file id, so no copy of the recording is made.
		expect(sent.body).toMatch(new RegExp(`name="fileId"\\r?\\n\\r?\\n${voicemailId}\\b`))
		expect(sent.body).toMatch(/name="targetLanguage"\r?\n\r?\nnl\b/)
		expect(sent.body).toMatch(/name="sessionId"\r?\n\r?\n[0-9a-f-]{36}/)

		await expect(
			page.locator('.chat-page__message--user').filter({ hasText: `Translate ${VOICEMAIL} into Nederlands` }),
		).toBeVisible({ timeout: 20_000 })
		const result = page
			.locator('.chat-page__message--assistant')
			.filter({ has: page.getByTestId('chat-message-translation') })
		await expect(result).toContainText(TRANSCRIPT)
		await expect(result).toContainText(TRANSLATION)

		const player = result.getByLabel('Spoken translation')
		await expect(player).toBeVisible()
		await expect(player).toHaveAttribute(
			'src',
			new RegExp(`/remote\\.php/dav/files/${USER}/Hermiq/Translations/`),
		)
		await expect(result.locator('.chat-page__translation-line')).toHaveText(LINE)
	})

	// @e2e speech-services::a-language-without-a-voice-gets-text
	test('a target without a voice is offered as text only, and the result is text without a player', async ({
		page,
	}) => {
		await stubSpeechService(page, [])
		await stubTranslation(page, sessions[0], false)

		const form = await openTranslateForm(page, SESSION_TITLE)
		await chooseVoicemail(page, form)
		await chooseTarget(page, form, /^\s*(Dutch|Nederlands) \(text only\)\s*$/)
		await form.getByTestId('translate-start').click()
		await expect(form).toBeHidden({ timeout: 20_000 })

		const result = page
			.locator('.chat-page__message--assistant')
			.filter({ has: page.getByTestId('chat-message-translation') })
		await expect(result).toContainText(TRANSLATION, { timeout: 20_000 })
		await expect(result).toContainText(NO_VOICE)
		await expect(result.locator('audio')).toHaveCount(0)
		await expect(result.locator('.chat-page__translation-line')).toContainText(
			'Transcribed on this server, translated by Qwen3 on this server.',
		)
	})

	// @e2e speech-services::a-reviewer-can-see-what-was-used
	test('reopening the session shows which languages and engines were used', async ({
		page,
	}) => {
		await page.goto(`${await appRoot(page)}/chat`)
		await dismissTour(page)
		await page
			.getByText(REVIEW_TITLE, { exact: true })
			.first()
			.click({ timeout: 20_000 })

		const translation = page.getByTestId('chat-message-translation')
		await expect(translation).toBeVisible({ timeout: 20_000 })
		await expect(translation.locator('.chat-page__translation-line')).toHaveText(LINE)

		// Still there for whoever opens it later: the record is on the stored turn.
		await page.reload()
		await dismissTour(page)
		await page
			.getByText(REVIEW_TITLE, { exact: true })
			.first()
			.click({ timeout: 20_000 })
		await expect(
			page.getByTestId('chat-message-translation').locator('.chat-page__translation-line'),
		).toHaveText(LINE, { timeout: 20_000 })
	})
})
