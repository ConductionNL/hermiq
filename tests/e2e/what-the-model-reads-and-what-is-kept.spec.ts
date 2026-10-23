/**
 * What the model reads, and what is kept.
 *
 * Two questions with one answer between them: what did the model see, and for how
 * long do we keep the record. "No citizen data reaches the model" is the question
 * every functionaris gegevensbescherming asks about an assistant, and it is
 * answerable as a capability rather than as a policy. A policy is a promise; a
 * capability is a refusal.
 *
 * This suite pins the parts a unit test cannot: that the retention an administrator
 * sets is readable on the screen together with the report saying something enforces
 * it, and that neither the setting nor the report is writable by a caller who may
 * not administer the instance. The boundary is probed with the least privileged
 * principal that should be refused, an ordinary authenticated user, because what a
 * model may read is a privacy boundary and a superuser success proves nothing about
 * who else can move it.
 *
 * @e2e run-audit-log::a-run-knows-its-own-expiry
 * @e2e run-audit-log::the-last-cleanup-is-an-answerable-question
 * @e2e run-audit-log::a-job-that-has-never-run-is-visible-as-such
 * @e2e exclude run-audit-log::changing-the-default-does-not-move-an-old-promise {needs two runs recorded under two different defaults; pinned in RunRetentionPolicyTest::testChangingTheDefaultDoesNotMoveAnOldPromise}
 * @e2e exclude run-audit-log::a-feature-may-keep-less {a per-feature override read on a run entry, pinned in RunRetentionPolicyTest::testAFeatureMayKeepLess}
 * @e2e exclude run-audit-log::expired-runs-lose-their-payload {needs a run older than its retention, which a browser run cannot age; pinned in RunRetentionCleanerTest::testExpiredRunsLoseTheirPayload}
 * @e2e exclude run-audit-log::the-chain-still-verifies-after-a-cleanup {chain verification is OpenRegister's own suite; hermiq's half, that nothing is deleted, is pinned in RunRetentionCleanerTest::testACleanupUpdatesEntriesAndDeletesNone}
 * @e2e exclude run-audit-log::the-processing-is-still-recorded-after-the-data-is-gone {reads a tombstoned entry, pinned in RunRetentionCleanerTest::testTheProcessingIsStillRecordedAfterTheDataIsGone}
 * @e2e exclude run-audit-log::no-personal-data-survives-the-tombstone {the assertion is an absence in a persisted record, pinned in RunRetentionCleanerTest::testNoPersonalDataSurvivesTheTombstone}
 * @e2e exclude woo-llm-anonymisation::an-unredacted-document-does-not-reach-the-model {the assertion is that NO request leaves, which a browser cannot observe; pinned in RedactionGateTest::testAnUnredactedDocumentDoesNotReachTheModel}
 * @e2e exclude woo-llm-anonymisation::a-note-with-no-document-still-runs {needs a configured provider to complete a turn; pinned in RedactionGateTest::testANoteWithNoDocumentStillRuns}
 * @e2e exclude woo-llm-anonymisation::a-redacted-document-proceeds {needs filinq to have redacted a fixture document on the instance under test; pinned in RedactionGateTest::testARedactedDocumentProceedsAndIsRecorded}
 * @e2e exclude woo-llm-anonymisation::a-detection-result-does-not-unlock-a-redaction-requiring-feature {needs a detection recorded without a redaction; pinned in RedactionGateTest::testADetectionResultDoesNotUnlockTheFeature}
 * @e2e exclude woo-llm-anonymisation::without-filinq-a-redaction-requiring-feature-does-not-run {uninstalling filinq mid-suite would edit the instance under test; pinned in RedactionGateTest::testWithoutFilinqTheFeatureDoesNotRun}
 * @e2e exclude woo-llm-anonymisation::features-that-require-nothing-are-unaffected {the same instance-shape dependency; pinned in RedactionGateTest::testAFeatureThatRequiresNothingIsUnaffected}
 * @e2e exclude woo-llm-anonymisation::hermiq-ships-no-redactor {an assertion over the source tree, pinned in RedactionGateTest::testHermiqShipsNoRedactor}
 * @e2e exclude woo-llm-anonymisation::nothing-is-sent-before-every-check-has-passed {the same unobservable absence; pinned in RedactionGateTest}
 * @e2e exclude woo-llm-anonymisation::the-refusing-step-is-identifiable {three refusals compared side by side, pinned across FeatureProviderResolverTest and RedactionGateTest}
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

/** The retention settings endpoint, which is admin-gated on the server. */
const RETENTION = '/index.php/apps/hermiq/api/settings/run-retention'

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
 * Read the retention settings, returning the status so a refusal reads as one.
 *
 * @param page The Playwright page.
 *
 * @return The HTTP status and the parsed body.
 */
async function readRetention(
	page: Page,
): Promise<{ status: number; body: Record<string, unknown> }> {
	return await page.evaluate(async (url) => {
		const res = await fetch(url, { headers: { Accept: 'application/json' } })
		const body = (await res.json().catch(() => ({}))) as Record<string, unknown>
		return { status: res.status, body }
	}, RETENTION)
}

/**
 * Write the retention, returning the status rather than throwing.
 *
 * @param page The Playwright page.
 * @param defaultDays The retention to send.
 *
 * @return The HTTP status and the parsed body.
 */
async function writeRetention(
	page: Page,
	defaultDays: number,
): Promise<{ status: number; body: Record<string, unknown> }> {
	return await page.evaluate(
		async ({ url, defaultDays }) => {
			const token =
				(
					document.querySelector(
						'head[data-requesttoken]',
					) as HTMLElement | null
				)?.dataset.requesttoken
				|| (window as unknown as { OC?: { requestToken?: string } }).OC
					?.requestToken
				|| ''

			const res = await fetch(url, {
				method: 'PUT',
				headers: {
					'Content-Type': 'application/json',
					Accept: 'application/json',
					requesttoken: token,
				},
				body: JSON.stringify({ defaultDays }),
			})

			const body = (await res.json().catch(() => ({}))) as Record<
				string,
				unknown
			>
			return { status: res.status, body }
		},
		{ url: RETENTION, defaultDays },
	)
}

test.describe('what the model reads and what is kept', () => {
	// @e2e run-audit-log::a-run-knows-its-own-expiry
	// @e2e run-audit-log::the-last-cleanup-is-an-answerable-question
	// @e2e run-audit-log::a-job-that-has-never-run-is-visible-as-such
	test('the instance always has a retention, and says whether anything enforces it', async ({
		page,
	}) => {
		await login(page, NC_USER, NC_PASS)

		const read = await readRetention(page)
		expect(read.status).toBe(200)

		// Never absent: a retention nobody set is a retention of forever, so the
		// instance answers with a number even when nothing was ever configured.
		const defaultDays = read.body.defaultDays as number
		expect(typeof defaultDays).toBe('number')
		expect(defaultDays).toBeGreaterThanOrEqual(1)

		// And the enforcement is a question with an answer. "Never ran" is an answer;
		// silence is not, and neither is a zero that could mean either.
		const lastCleanup = read.body.lastCleanup as {
			ran: boolean
			removed: number | null
		}
		expect(typeof lastCleanup.ran).toBe('boolean')
		if (lastCleanup.ran === false) {
			expect(lastCleanup.removed).toBeNull()
		} else {
			expect(typeof lastCleanup.removed).toBe('number')
		}

		await page.goto('/settings/admin/hermiq', { waitUntil: 'domcontentloaded' })
		await expect(page.locator('.run-retention').first()).toBeVisible({
			timeout: 30_000,
		})

		// The report is on the screen beside the setting, because a setting without
		// a report says ninety days while the data is still there in year three.
		await expect(page.locator('.run-retention__report').first()).not.toBeEmpty()
	})

	test('a retention outside the permitted range is refused, naming the range', async ({
		page,
	}) => {
		await login(page, NC_USER, NC_PASS)

		const before = await readRetention(page)
		const original = before.body.defaultDays as number

		const refused = await writeRetention(page, 0)

		expect(refused.status).toBe(422)
		expect(String(refused.body.error)).toContain('days')

		// Unchanged: a refused write must not half-apply.
		const after = await readRetention(page)
		expect(after.body.defaultDays).toBe(original)
	})

	test('an ordinary user can neither read nor move the retention', async ({
		page,
	}) => {
		test.skip(
			ORDINARY_USER === '' || ORDINARY_PASS === '',
			'set NC_ORDINARY_USER / NC_ORDINARY_PASS to probe the boundary with a non-admin',
		)

		// The least privileged principal that should be refused.
		await login(page, ORDINARY_USER, ORDINARY_PASS)

		const read = await readRetention(page)
		const written = await writeRetention(page, 1)

		expect(read.status).not.toBe(200)
		expect(written.status).not.toBe(200)
		expect([401, 403, 404]).toContain(written.status)
	})
})
