/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spec-coverage e2e — the voice map in admin settings
 * (chat-translate-a-recording, Task 4).
 *
 * Browser scenario of openspec/changes/chat-translate-a-recording:
 *  - REQ-STRN-001, Task 4 "GIVEN an administrator WHEN they map `fr` to a voice
 *    THEN `speech_voices` holds it": in hermiq's admin settings, under "Voices
 *    for translated recordings", the administrator presses "Edit voices", adds
 *    a language, enters `fr` and the voice `ff_siwis` and presses "Save voices".
 *    The PUT carries `{voices: {fr: 'ff_siwis'}}`, the stored map read back
 *    through the route holds it, and the section lists "fr: ff_siwis".
 *  - Removing a language in the modal and saving drops it from the stored map.
 *
 * Left out:
 *  - "a non-admin gets 403 on the route": a crafted request with no screen;
 *    PHPUnit (`SpeechVoiceSettingsControllerTest`, the admin attribute).
 *  - Speaking a translation with the mapped voice: needs the speech sidecar;
 *    `speech-translate.spec.ts` stubs the service.
 *
 * 🔑 WHAT IS REAL AND WHAT IS NOT. Everything is real: the section, the modal
 * and the admin-gated `/api/settings/speech-voices` route. No voice is checked
 * against an installed speech model on save. The map found before the run is
 * put back in afterAll.
 *
 * Runs as the suite's administrator (the default storage state):
 *
 *     PLAYWRIGHT_BASE_URL=<your rig> NC_USER=admin NC_PASS=admin \
 *       npx playwright test -c tests/e2e/spec-coverage/playwright.config.ts speech-voice-map
 *
 * Covers openspec/changes/chat-translate-a-recording/specs/speech-services/spec.md
 */

import type { APIRequestContext, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { dismissTour, harvestToken, jsonHeaders } from './_fixtures.ts'

const API = '/index.php/apps/hermiq/api'

/**
 * Read the stored voice map.
 *
 * @param req   The request context.
 * @param token The CSRF request-token.
 * @return The `{<language>: <voice>}` map.
 */
async function readVoices(req: APIRequestContext, token: string): Promise<Record<string, string>> {
	const res = await req.get(`${API}/settings/speech-voices`, { headers: jsonHeaders(token) })
	expect(res.status(), 'the administrator reads the voice map').toBe(200)
	return (await res.json()).voices ?? {}
}

/**
 * Store a voice map through the route.
 *
 * @param req    The request context.
 * @param token  The CSRF request-token.
 * @param voices The map.
 * @return Resolves once stored.
 */
async function writeVoices(req: APIRequestContext, token: string, voices: Record<string, string>): Promise<void> {
	const res = await req.put(`${API}/settings/speech-voices`, {
		headers: jsonHeaders(token),
		data: { voices },
	})
	expect(res.status(), await res.text()).toBe(200)
}

/**
 * Open the voice section of hermiq's admin settings.
 *
 * @param page The page.
 * @return The section locator.
 */
async function openSection(page: Page) {
	await page.goto('/index.php/settings/admin/hermiq', { waitUntil: 'domcontentloaded' })
	await dismissTour(page)
	const section = page.getByTestId('speech-voice-settings')
	await expect(section).toBeVisible({ timeout: 30_000 })
	await expect(section.getByTestId('speech-voice-edit')).toBeEnabled()
	return section
}

/**
 * Press "Save voices" and wait for the stored answer.
 *
 * @param page   The page.
 * @param dialog The modal.
 * @return The PUT body sent and the answer.
 */
async function save(page: Page, dialog: ReturnType<Page['locator']>) {
	const saved = page.waitForResponse(
		(res) => res.url().includes('/apps/hermiq/api/settings/speech-voices') && res.request().method() === 'PUT',
	)
	await dialog.getByTestId('voice-map-save').click()
	const response = await saved
	expect(response.status(), `save HTTP ${response.status()}`).toBe(200)
	return { sent: response.request().postDataJSON(), answer: await response.json() }
}

test.describe('chat-translate-a-recording: the voice map in admin settings', () => {
	let token = ''
	let original: Record<string, string> | null = null

	test.beforeAll(async ({ browser }) => {
		const page = await browser.newPage()
		token = await harvestToken(page)
		original = await readVoices(page.request, token)
		// Start from a map without fr, so the test adds it.
		await writeVoices(page.request, token, Object.fromEntries(Object.entries(original).filter(([language]) => language !== 'fr')))
		await page.close()
	})

	test.afterAll(async ({ browser }) => {
		if (original === null) {
			return
		}
		const page = await browser.newPage()
		const cleanupToken = await harvestToken(page)
		await writeVoices(page.request, cleanupToken, original).catch(() => undefined)
		await page.close()
	})

	// @e2e speech-translate::an-administrator-maps-fr-to-a-voice
	test('an administrator maps fr to a voice', async ({ page }) => {
		const section = await openSection(page)
		await section.getByTestId('speech-voice-edit').click()

		const dialog = page.getByTestId('voice-map-form')
		await expect(dialog).toBeVisible({ timeout: 15_000 })
		const before = await dialog.getByTestId('voice-map-row').count()
		await dialog.getByTestId('voice-map-add').click()
		const rows = dialog.getByTestId('voice-map-row')
		await expect(rows).toHaveCount(before + 1)
		const row = rows.nth(before)
		await row.getByLabel('Language code').fill('fr')
		await row.getByLabel('Voice id').fill('ff_siwis')

		const { sent, answer } = await save(page, dialog)
		expect(sent.voices.fr).toBe('ff_siwis')
		expect(answer.voices.fr).toBe('ff_siwis')
		expect((await readVoices(page.request, token)).fr).toBe('ff_siwis')
		await expect(section.locator('li', { hasText: /^\s*fr: ff_siwis\s*$/ })).toBeVisible()
	})

	// @e2e speech-translate::removing-a-language-drops-its-voice
	test('removing a language drops its voice', async ({ page }) => {
		await writeVoices(page.request, token, { ...(await readVoices(page.request, token)), fr: 'ff_siwis' })
		const section = await openSection(page)
		await section.getByTestId('speech-voice-edit').click()

		const dialog = page.getByTestId('voice-map-form')
		await expect(dialog).toBeVisible({ timeout: 15_000 })
		const rows = dialog.getByTestId('voice-map-row')
		await expect(rows.first()).toBeVisible()
		const count = await rows.count()
		let frIndex = -1
		for (let i = 0; i < count; i++) {
			if ((await rows.nth(i).getByLabel('Language code').inputValue()) === 'fr') {
				frIndex = i
			}
		}
		expect(frIndex, 'the modal lists fr').toBeGreaterThanOrEqual(0)
		await rows.nth(frIndex).getByRole('button', { name: 'Remove this language' }).click()
		await expect(rows).toHaveCount(count - 1)

		const { sent } = await save(page, dialog)
		expect(sent.voices).not.toHaveProperty('fr')
		expect(await readVoices(page.request, token)).not.toHaveProperty('fr')
		await expect(section.locator('li', { hasText: /^\s*fr: / })).toHaveCount(0)
	})
})
