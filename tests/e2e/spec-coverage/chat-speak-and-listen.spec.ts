/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * chat-speak-and-listen: the microphone in the chat composer and the agent's
 * speech policy. The speech sidecar is not on CI, so its two routes are
 * answered by page.route with the shapes SpeechController returns, and the
 * browser's recorder is replaced by an init script (no microphone on CI). The
 * logic behind both buttons is asserted in tests/chat-speech.spec.js.
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

/**
 * Answer the speech routes and replace the recorder.
 *
 * @param page The Playwright page.
 * @param available What the capabilities route answers.
 * @param transcript What the transcription route answers, or null for a 502.
 */
async function fakeSpeech(
	page: Page,
	available: boolean,
	transcript: string | null,
) {
	await page.addInitScript(() => {
		class FakeRecorder {
			mimeType = 'audio/webm'
			ondataavailable: ((e: { data: Blob }) => void) | null = null
			onstop: (() => void) | null = null
			start() {}
			stop() {
				this.ondataavailable?.({
					data: new Blob(['a'], { type: 'audio/webm' }),
				})
				this.onstop?.()
			}
		}
		Object.defineProperty(window, 'MediaRecorder', { value: FakeRecorder })
		Object.defineProperty(navigator, 'mediaDevices', {
			value: { getUserMedia: async () => ({ getTracks: () => [] }) },
		})
	})
	await page.route('**/apps/hermiq/api/speech/capabilities', (route) =>
		route.fulfill({
			json: { available, reason: available ? '' : 'unreachable' },
		}),
	)
	await page.route('**/apps/hermiq/api/speech/transcriptions', (route) =>
		transcript === null
			? route.fulfill({
					status: 502,
					json: { error: 'The speech service is unavailable.' },
				})
			: route.fulfill({
					json: { text: transcript, language: 'en', engine: 'local' },
				}),
	)
}

/**
 * Seed an agent, open the chat and start a session with it.
 *
 * @param page The Playwright page.
 * @param overrides The agent's speech policy.
 * @return The composer locator.
 */
async function startChat(page: Page, overrides: Record<string, unknown>) {
	const token = await harvestToken(page)
	await resolveRegisterSchema(page.request, token, 'agent')
	const agent = await seedAgent(page.request, token, {
		name: `${TEST_PREFIX}-permit-helper-${Date.now()}`,
		...overrides,
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
	return { composer, token }
}

test.describe('chat-speak-and-listen', () => {
	test.afterEach(async ({ page }) => {
		const token = await harvestToken(page).catch(() => '')
		if (token) {
			await cleanupFamily(page.request, token, 'agent').catch(() => {})
		}
	})

	// @e2e speech-services::a-person-dictates-a-question
	test('a person dictates a question', async ({ page }) => {
		let sent = 0
		page.on('request', (req) => {
			if (/\/api\/chat\/(send|stream)/.test(req.url())) {
				sent++
			}
		})
		await fakeSpeech(page, true, 'Which permits expire this month')
		const { composer } = await startChat(page, { voiceInputEngine: 'local' })
		const mic = composer.getByRole('button', { name: 'Dictate a message' })
		await mic.click()
		await composer
			.getByRole('button', { name: 'Listening, press to stop' })
			.click()
		await expect(composer.locator('textarea.chat-page__input')).toHaveValue(
			'Which permits expire this month',
		)
		expect(sent).toBe(0)
	})

	// @e2e speech-services::a-recording-that-cannot-be-transcribed
	test('a recording that cannot be transcribed', async ({ page }) => {
		await fakeSpeech(page, true, null)
		const { composer } = await startChat(page, { voiceInputEngine: 'local' })
		const input = composer.locator('textarea.chat-page__input')
		await input.fill('Typed before')
		await composer.getByRole('button', { name: 'Dictate a message' }).click()
		await composer
			.getByRole('button', { name: 'Listening, press to stop' })
			.click()
		await expect(
			composer.getByText('The speech service is unavailable.'),
		).toBeVisible()
		await expect(input).toHaveValue('Typed before')
	})

	// @e2e speech-services::an-agent-with-dictation-switched-off
	test('an agent with dictation switched off', async ({ page }) => {
		await fakeSpeech(page, true, 'unused')
		const { composer } = await startChat(page, { voiceInputEngine: 'off' })
		await expect(
			composer.getByRole('button', { name: 'Send message' }),
		).toBeVisible()
		await expect(
			composer.getByRole('button', { name: 'Dictate a message' }),
		).toHaveCount(0)
	})

	// @e2e speech-services::the-speech-service-is-down
	test('the speech service is down', async ({ page }) => {
		await fakeSpeech(page, false, 'unused')
		const { composer } = await startChat(page, {
			voiceInputEngine: 'local',
			voiceOutputEngine: 'local',
		})
		await expect(
			composer.getByRole('button', { name: 'Send message' }),
		).toBeVisible()
		await expect(
			composer.getByRole('button', { name: 'Dictate a message' }),
		).toHaveCount(0)
		await expect(page.getByRole('button', { name: 'Read aloud' })).toHaveCount(0)
	})
})
