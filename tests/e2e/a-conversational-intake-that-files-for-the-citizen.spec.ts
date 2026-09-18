/**
 * A conversational intake that files for the citizen.
 *
 * The digitale balie every gemeente is being sold, and the row that decides whether
 * a case system or a separate chatbot owns it. A citizen describes a problem in
 * their own words on whichever channel they reached for, and the assistant either
 * answers it or files it correctly. hermiq runs the conversation; the app that owns
 * the record decides what is created.
 *
 * What a browser can see of that is the surface itself and the one number that
 * decides whether it files or hands over. The conversation logic is pinned in the
 * unit tests, which can run a whole intake in a millisecond and can hand it a
 * catalogue the instance under test does not happen to have.
 *
 * @e2e conversational-intake::the-threshold-is-readable
 * @e2e conversational-intake::intake-is-classified-separately-from-the-handler-assistant
 * @e2e exclude conversational-intake::a-conversation-ends-in-a-filed-request {needs an owning app declaring an intake tool on the instance under test, and would create a real case; pinned in IntakeServiceTest::testAConversationEndsInAFiledRequest}
 * @e2e exclude conversational-intake::intake-cannot-touch-an-existing-record {the assertion is a refusal before any call leaves; pinned in IntakeServiceTest::testIntakeCannotTouchAnExistingRecord}
 * @e2e exclude conversational-intake::the-case-assistant-stays-tool-free {an assertion over the assistant's own source and OpenRegister's sentinel; pinned in IntakeServiceTest::testTheCaseAssistantStaysToolFree}
 * @e2e exclude conversational-intake::an-uncertain-intake-does-not-guess {needs a classification with a chosen confidence, which is an input rather than a screen; pinned in IntakeServiceTest::testAnUncertainIntakeDoesNotGuess}
 * @e2e exclude conversational-intake::the-catalogue-is-the-municipalitys {needs the owning app's declared catalogue; pinned in IntakeServiceTest::testAnInventedRequestTypeIsNotFiled}
 * @e2e exclude conversational-intake::an-unhelpable-conversation-reaches-a-person {pinned in IntakeServiceTest::testAHandoverCarriesTheTranscript}
 * @e2e exclude conversational-intake::every-terminal-state-is-one-of-two {an enumeration, asserted directly in IntakeServiceTest::testEveryTerminalStateIsOneOfTwo}
 * @e2e exclude conversational-intake::starting-by-e-mail-and-continuing-in-the-portal {needs a mail channel adapter on the instance; the join itself is pinned in IntakeServiceTest::testStartingByEmailAndContinuingInThePortal}
 * @e2e exclude conversational-intake::hermiq-transports-nothing {an assertion over the source tree; pinned in IntakeServiceTest::testHermiqTransportsNothing}
 * @e2e exclude conversational-intake::the-deterministic-score-wins {needs dossiq's SentimentService on the instance; pinned in IntakeServiceTest::testTheDeterministicScoreWins}
 * @e2e exclude conversational-intake::a-model-score-is-labelled-as-one {pinned in IntakeServiceTest::testAModelScoreIsLabelledAsOne}
 * @e2e exclude conversational-intake::a-reader-can-tell-them-apart {the same two labels, compared in the same test}
 * @e2e exclude conversational-intake::a-verdict-travels-into-the-conversation-with-its-author {needs an owning app declaring an external review; pinned in IntakeServiceTest::testAVerdictTravelsWithItsAuthor}
 * @e2e exclude conversational-intake::hermiq-does-not-review {an assertion over the source tree; pinned in IntakeServiceTest::testHermiqDoesNotReview}
 * @e2e exclude conversational-intake::the-existing-gate-applies {the DPO acknowledgement gate is ai-feature-governance's own, already covered by its suite}
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'

const NC_USER = process.env.NC_USER || 'admin'
const NC_PASS = process.env.NC_PASS || 'admin'

/** An ordinary account with no admin rights, if the instance has one seeded. */
const ORDINARY_USER = process.env.NC_ORDINARY_USER || ''
const ORDINARY_PASS = process.env.NC_ORDINARY_PASS || ''

/** The intake settings endpoint, which is admin-gated on the server. */
const SETTINGS = '/index.php/apps/hermiq/api/settings/intake'

/**
 * Log a user in through Nextcloud's own login form.
 *
 * @param page The Playwright page.
 * @param user The user id.
 * @param pass The password.
 */
async function login(page: Page, user: string, pass: string): Promise<void> {
	await page.goto('/login', { waitUntil: 'domcontentloaded' })
	const userField = page.locator('#user')
	if ((await userField.count()) === 0) {
		return
	}
	await userField.fill(user)
	await page.locator('#password').fill(pass)
	await page.locator('button[type="submit"], input[type="submit"]').first().click()
	await page.locator('#user').waitFor({ state: 'hidden', timeout: 30_000 })
}

/**
 * Read the intake settings, returning the status so a refusal reads as one.
 *
 * @param page The Playwright page.
 *
 * @return The HTTP status and the parsed body.
 */
async function readSettings(
	page: Page,
): Promise<{ status: number, body: Record<string, unknown> }> {
	return await page.evaluate(async (url) => {
		const res = await fetch(url, { headers: { Accept: 'application/json' } })
		const body = await res.json().catch(() => ({})) as Record<string, unknown>
		return { status: res.status, body }
	}, SETTINGS)
}

/**
 * Write the intake threshold, returning the status rather than throwing.
 *
 * @param page The Playwright page.
 * @param abstentionThreshold The threshold to send.
 *
 * @return The HTTP status and the parsed body.
 */
async function writeThreshold(
	page: Page,
	abstentionThreshold: number,
): Promise<{ status: number, body: Record<string, unknown> }> {
	return await page.evaluate(
		async ({ url, abstentionThreshold }) => {
			const token = (
				document.querySelector('head[data-requesttoken]') as HTMLElement | null
			)?.dataset.requesttoken
				|| (window as unknown as { OC?: { requestToken?: string } }).OC?.requestToken
				|| ''

			const res = await fetch(url, {
				method: 'PUT',
				headers: {
					'Content-Type': 'application/json',
					Accept: 'application/json',
					requesttoken: token,
				},
				body: JSON.stringify({ abstentionThreshold }),
			})

			const body = await res.json().catch(() => ({})) as Record<string, unknown>
			return { status: res.status, body }
		},
		{ url: SETTINGS, abstentionThreshold },
	)
}

test.describe('a conversational intake that files for the citizen', () => {
	// @e2e conversational-intake::the-threshold-is-readable
	test('whoever set the threshold can read what it is', async ({ page }) => {
		await login(page, NC_USER, NC_PASS)

		const read = await readSettings(page)
		expect(read.status).toBe(200)
		expect(typeof read.body.abstentionThreshold).toBe('number')
		expect(read.body.abstentionThreshold as number).toBeGreaterThan(0)
		expect(read.body.abstentionThreshold as number).toBeLessThanOrEqual(1)

		await page.goto('/settings/admin/hermiq', { waitUntil: 'domcontentloaded' })
		await expect(page.locator('.intake-settings').first()).toBeVisible({ timeout: 30_000 })
		await expect(page.locator('.intake-settings__row input[type="number"]')).toBeEditable()
	})

	// @e2e conversational-intake::intake-is-classified-separately-from-the-handler-assistant
	test('intake is its own AI feature, not the handler assistant', async ({ page }) => {
		await login(page, NC_USER, NC_PASS)

		const features = await page.evaluate(async () => {
			const res = await fetch('/index.php/apps/hermiq/api/ai-features', {
				headers: { Accept: 'application/json' },
			})
			const body = await res.json()
			return (body.results || []) as Array<Record<string, unknown>>
		})

		test.skip(features.length === 0, 'the AI feature register is not seeded on this instance')

		const intake = features.find((f) => f.slug === 'conversational-intake')
		const companion = features.find((f) => f.slug === 'chat-companion')

		expect(intake).toBeTruthy()

		// An assistant that files on a citizen's behalf carries its own risk
		// category, independently of the one that helps a handler read a case.
		if (companion) {
			expect(intake!.riskCategory).not.toBe(companion.riskCategory)
		}
		expect(intake!.riskCategory).toBe('high')
	})

	test('a threshold outside nought to one is refused', async ({ page }) => {
		await login(page, NC_USER, NC_PASS)

		const before = await readSettings(page)

		const refused = await writeThreshold(page, 1.5)

		expect(refused.status).toBe(422)

		const after = await readSettings(page)
		expect(after.body.abstentionThreshold).toBe(before.body.abstentionThreshold)
	})

	test('an ordinary user cannot lower the bar for filing on somebody else behalf', async ({
		page,
	}) => {
		test.skip(
			ORDINARY_USER === '' || ORDINARY_PASS === '',
			'set NC_ORDINARY_USER / NC_ORDINARY_PASS to probe the boundary with a non-admin',
		)

		// The least privileged principal that should be refused. Lowering this
		// number makes the assistant file on people's behalf when it is less sure,
		// which is not an ordinary user's call to make.
		await login(page, ORDINARY_USER, ORDINARY_PASS)

		const read = await readSettings(page)
		const written = await writeThreshold(page, 0.01)

		expect(read.status).not.toBe(200)
		expect(written.status).not.toBe(200)
		expect([401, 403, 404]).toContain(written.status)
	})
})
