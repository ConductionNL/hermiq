/**
 * Identical reports collapse into one.
 *
 * Two hundred meldingen about one street-wide power cut become one item with a
 * count, and the near-duplicates are listed beside it rather than buried in it.
 * hermiq decides whether two reports describe the same thing; what a group means is
 * the owning app's, and stays statutory.
 *
 * What a browser can see of that is the administered part: both thresholds, on a
 * screen, editable, with the refusal that keeps a middle band from being squeezed
 * out of existence. The judgement itself is pinned in the unit tests, which can
 * evaluate two hundred reports in a tenth of a second and can age one by seven
 * months without waiting.
 *
 * The write is probed with the least privileged principal that should be refused,
 * because moving these two numbers moves what the public's reports get folded into.
 *
 * @e2e report-similarity::the-thresholds-are-the-administrators
 * @e2e exclude report-similarity::two-hundred-reports-of-one-power-cut-answer-as-one-group {two hundred evaluations against a live instance is a load test, not a journey; pinned in ReportSimilarityServiceTest::testTwoHundredReportsOfOnePowerCutAnswerAsOneGroup}
 * @e2e exclude report-similarity::hermiq-creates-nothing {the assertion is an absence of records in another app; pinned in ReportSimilarityServiceTest, which also asserts a group holds references rather than copies}
 * @e2e exclude report-similarity::a-near-duplicate-is-visible-not-buried {needs two reports scoring into the middle band, which is a property of the texts rather than of the screen; pinned in ReportSimilarityServiceTest::testANearDuplicateIsVisibleNotBuried}
 * @e2e exclude report-similarity::a-clearly-separate-report-stands-alone {same; pinned in ReportSimilarityServiceTest::testAClearlySeparateReportStandsAlone}
 * @e2e exclude report-similarity::pulling-one-report-out-costs-nothing {needs a seeded group of two hundred; pinned in ReportSimilarityServiceTest::testPullingOneReportOutCostsNothing}
 * @e2e exclude report-similarity::no-report-is-destroyed-by-grouping {pinned in ReportSimilarityServiceTest::testAGroupHoldsReferencesRatherThanCopies}
 * @e2e exclude report-similarity::two-hundred-reporters-two-hundred-confirmations {the acknowledgement duty is dossiq's to carry out; hermiq's half, that the answer carries no instruction about it, is pinned in ReportSimilarityServiceTest::testGroupingCarriesNoAcknowledgementEffect}
 * @e2e exclude report-similarity::grouping-carries-no-acknowledgement-effect {the same assertion, on the same test}
 * @e2e exclude report-similarity::the-key-decides-where-it-can {pinned in ReportSimilarityServiceTest::testTheKeyDecidesWhereItCan}
 * @e2e exclude report-similarity::the-model-covers-what-the-key-misses {pinned in ReportSimilarityServiceTest::testTheModelCoversWhatTheKeyMisses}
 * @e2e exclude report-similarity::an-old-report-is-out-of-scope {needs a report from last month, which a browser run cannot age; pinned in ReportSimilarityServiceTest::testAnOldReportIsOutOfScope}
 * @e2e exclude report-similarity::the-window-is-part-of-the-record {pinned in ReportSimilarityServiceTest::testTheWindowIsPartOfTheRecord}
 * @e2e exclude report-similarity::why-these-are-one-thing-is-answerable {reads a formed group's reasons; pinned in ReportSimilarityServiceTest::testWhyTheseAreOneThingIsAnswerable}
 * @e2e exclude report-similarity::each-judgement-is-on-the-audit-trail {pinned in ReportSimilarityServiceTest::testEachJudgementIsOnTheAuditTrail}
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

/** The grouping settings endpoint, which is admin-gated on the server. */
const SETTINGS = '/index.php/apps/hermiq/api/settings/report-similarity'

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
 * Read the grouping settings, returning the status so a refusal reads as one.
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
 * Write the grouping settings, returning the status rather than throwing.
 *
 * @param page The Playwright page.
 * @param settings The thresholds to send.
 *
 * @return The HTTP status and the parsed body.
 */
async function writeSettings(
	page: Page,
	settings: Record<string, number>,
): Promise<{ status: number, body: Record<string, unknown> }> {
	return await page.evaluate(
		async ({ url, settings }) => {
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
				body: JSON.stringify(settings),
			})

			const body = await res.json().catch(() => ({})) as Record<string, unknown>
			return { status: res.status, body }
		},
		{ url: SETTINGS, settings },
	)
}

test.describe('identical reports collapse into one', () => {
	// @e2e report-similarity::the-thresholds-are-the-administrators
	test('both thresholds are on the screen and both are editable', async ({ page }) => {
		await login(page, NC_USER, NC_PASS)

		const read = await readSettings(page)
		expect(read.status).toBe(200)
		expect(typeof read.body.upper).toBe('number')
		expect(typeof read.body.lower).toBe('number')
		expect(read.body.lower as number).toBeLessThan(read.body.upper as number)

		await page.goto('/settings/admin/hermiq', { waitUntil: 'domcontentloaded' })
		await expect(page.locator('.report-grouping').first()).toBeVisible({ timeout: 30_000 })

		// Two fields, not one: the middle band is the point of the feature, and a
		// single threshold on the screen would quietly remove it.
		const fields = page.locator('.report-grouping__row input[type="number"]')
		await expect(fields).toHaveCount(2)
		await expect(fields.first()).toBeEditable()
		await expect(fields.nth(1)).toBeEditable()
	})

	test('a lower threshold at or above the upper one is refused, saying why', async ({
		page,
	}) => {
		await login(page, NC_USER, NC_PASS)

		const before = await readSettings(page)

		const refused = await writeSettings(page, { upper: 0.5, lower: 0.9 })

		expect(refused.status).toBe(422)
		expect(String(refused.body.error)).toContain('below')

		// Unchanged: a refused write must not half-apply.
		const after = await readSettings(page)
		expect(after.body.upper).toBe(before.body.upper)
		expect(after.body.lower).toBe(before.body.lower)
	})

	test('an ordinary user cannot move where the line falls', async ({ page }) => {
		test.skip(
			ORDINARY_USER === '' || ORDINARY_PASS === '',
			'set NC_ORDINARY_USER / NC_ORDINARY_PASS to probe the boundary with a non-admin',
		)

		// The least privileged principal that should be refused: these two numbers
		// decide what the public's reports get folded into.
		await login(page, ORDINARY_USER, ORDINARY_PASS)

		const read = await readSettings(page)
		const written = await writeSettings(page, { upper: 0.99, lower: 0.01 })

		expect(read.status).not.toBe(200)
		expect(written.status).not.toBe(200)
		expect([401, 403, 404]).toContain(written.status)
	})
})
