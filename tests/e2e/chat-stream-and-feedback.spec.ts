/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * chat-streaming-and-feedback, in a browser (gate 19).
 *
 * One person sends a message on the chat page, sees the answer bubble fill
 * before the stream's `final` event, rates the answer thumbs down with a
 * comment, reloads, reopens the conversation and sees the thumbs down still
 * marked.
 *
 * Needs an instance whose default agent model streams (a configured provider);
 * on an instance without one the turn ends in the composer's error note and
 * the test is skipped with that reason, never passed.
 *
 * The config supplies `use.storageState`, so the suite starts signed in. The
 * session and the seeded agent are removed afterwards.
 */

import { expect, test } from '@playwright/test'
import {
	appRoot,
	cleanupFamily,
	dismissTour,
	harvestToken,
	jsonHeaders,
	seedAgent,
	TEST_PREFIX,
} from './spec-coverage/_fixtures.ts'

const API = '/index.php/apps/hermiq/api'

test.describe('chat-streaming-and-feedback: a streamed answer and its rating', () => {
	// @e2e agent-engine-port::the-answer-grows-while-it-is-written
	// @e2e agent-engine-port::a-thumbs-down-with-a-comment-is-kept
	test('the answer grows while it is written, and a thumbs down survives a reload', async ({
		page,
	}) => {
		// A real generation on a local model can take most of a minute.
		test.setTimeout(240_000)

		const token = await harvestToken(page)
		const agent = await seedAgent(page.request, token, {
			name: `${TEST_PREFIX}-stream-agent`,
		})
		const phrase = `${TEST_PREFIX} write three short sentences about rivers`
		const root = await appRoot(page)
		let sessionUuid = ''

		try {
			await page.goto(`${root}/chat`, { waitUntil: 'domcontentloaded' })
			await dismissTour(page)
			await expect(page.locator('.chat-page')).toBeVisible({ timeout: 15_000 })

			const card = page
				.locator('.agent-selector__card')
				.filter({ hasText: agent.name })
			await expect(card).toBeVisible({ timeout: 20_000 })
			const createResponse = page.waitForResponse(
				(res) =>
					res.url().includes('/apps/hermiq/api/sessions')
					&& res.request().method() === 'POST',
			)
			await card.getByRole('button', { name: 'Start session' }).click()
			const created = await (await createResponse).json().catch(() => ({}))
			sessionUuid = String(created.uuid ?? created.id ?? '')

			const composer = page.locator('.chat-page__composer')
			await expect(composer).toBeVisible({ timeout: 20_000 })
			await composer.locator('textarea.chat-page__input').fill(phrase)

			// The stream's request ends when `final` has been written; until then
			// any text in the assistant bubble arrived through `token` events.
			let streamDone = false
			page.on('requestfinished', (request) => {
				if (request.url().includes('/apps/hermiq/api/chat/stream')) {
					streamDone = true
				}
			})
			await composer.getByRole('button', { name: 'Send message' }).click()

			const assistant = page.locator('.chat-page__message--assistant').last()
			let textBeforeFinal = ''
			const deadline = Date.now() + 180_000
			while (!streamDone && Date.now() < deadline) {
				const text = ((await assistant.textContent().catch(() => '')) ?? '').trim()
				if (text !== '' && textBeforeFinal === '') {
					textBeforeFinal = text
				}
				const failed = await composer.getByRole('alert').count()
				if (failed > 0 && textBeforeFinal === '') {
					test.skip(true, 'No streaming model on this instance: the turn ended in the composer error note.')
				}
				await page.waitForTimeout(100)
			}
			expect(streamDone, 'the event stream must end within three minutes').toBe(true)
			expect(
				textBeforeFinal,
				'the answer bubble must show words before the final event',
			).not.toBe('')

			// After `final` the bubble holds the whole answer, which starts with
			// what was shown while it streamed.
			await expect(assistant.locator('.chat-page__feedback')).toBeVisible({
				timeout: 30_000,
			})
			const whole = ((await assistant.textContent()) ?? '').trim()
			expect(whole.length).toBeGreaterThanOrEqual(textBeforeFinal.length)

			// Thumbs down with a comment.
			const feedbackResponse = page.waitForResponse(
				(res) =>
					/\/api\/conversations\/[^/]+\/messages\/[^/]+\/feedback/.test(res.url())
					&& res.request().method() === 'POST',
			)
			await assistant.getByRole('button', { name: 'Not helpful' }).click()
			expect((await feedbackResponse).ok()).toBe(true)
			await page.getByRole('textbox', { name: 'Feedback details' }).fill('Mist de bron')
			const commentResponse = page.waitForResponse(
				(res) =>
					/\/api\/conversations\/[^/]+\/messages\/[^/]+\/feedback/.test(res.url())
					&& res.request().method() === 'POST',
			)
			await page.getByRole('button', { name: 'Send feedback' }).click()
			expect((await commentResponse).ok()).toBe(true)

			// Reload, find the conversation by what was said in it, reopen it.
			await page.reload({ waitUntil: 'domcontentloaded' })
			await dismissTour(page)
			await page.getByRole('textbox', { name: 'Search sessions' }).fill(TEST_PREFIX)
			const row = page.getByTestId('chat-session-row').first()
			await expect(row).toBeVisible({ timeout: 20_000 })
			await row.click()

			const reopened = page.locator('.chat-page__message--assistant').last()
			await expect(reopened.locator('.chat-page__feedback--active-negative')).toBeVisible({
				timeout: 20_000,
			})
			await expect(reopened.locator('.chat-page__feedback--active-positive')).toHaveCount(0)
		} finally {
			if (sessionUuid !== '') {
				await page.request
					.delete(`${API}/sessions/${sessionUuid}`, { headers: jsonHeaders(token) })
					.catch(() => null)
				await page.request
					.delete(`${API}/sessions/${sessionUuid}/permanent`, {
						headers: jsonHeaders(token),
					})
					.catch(() => null)
			}
			await cleanupFamily(page.request, token, 'agent').catch(() => {})
		}
	})
})
