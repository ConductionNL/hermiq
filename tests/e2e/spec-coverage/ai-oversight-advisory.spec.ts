/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The AI oversight surface shows only advisory records
 * (ai-oversight-advisory-approvals 4.2).
 *
 * WHAT THE UNIT TESTS CANNOT SHOW. AiOversightServiceTest pins how a record
 * becomes an Approval. It cannot show that the `/ai-oversight` dashboard's
 * filter reaches OpenRegister, so that a blocking approval of another source
 * (a held tool call, a schedule) stays off the oversight log, or that the
 * three counts read the advisory statuses. That sits between the manifest,
 * nextcloud-vue and OpenRegister, so it is here.
 *
 * Two Approvals are seeded with this run's prefix in the subject: one
 * advisory (overridden, with a suggestion and the value the human used) and
 * one blocking `toolcall` approval still pending. The page must list the
 * first and not the second. The administrator runs it (the project's admin
 * storage state), as the scenario says; base URL from the environment.
 *
 * Not run when written: it needs a live instance.
 *
 * @spec openspec/specs/ai-oversight/spec.md
 */

import { expect, test } from '@playwright/test'
import { appRoot, deleteObject, dismissTour, harvestToken, seedObject, TEST_PREFIX } from './_fixtures.ts'

/** The suggestion type both seeds carry, so the rows can be told apart. */
const ADVISORY_TYPE = `${TEST_PREFIX}-advisory`
const BLOCKING_SUBJECT = `${TEST_PREFIX}-blocking`

test.describe('AI oversight lists advisory records only', () => {
	const seeded: string[] = []
	let token = ''

	test.beforeAll(async ({ browser, request }) => {
		const page = await browser.newPage()
		token = await harvestToken(page)
		await page.close()

		const now = new Date().toISOString()
		const advisory = await seedObject(request, token, 'approval', {
			sourceType: 'advisory',
			status: 'overridden',
			requestedAt: now,
			decidedAt: now,
			decidedVia: 'origin-app',
			advisoryContext: {
				originApp: 'e2e',
				suggestionType: ADVISORY_TYPE,
				action: 'overridden',
				subjectType: 'case',
				subjectId: `${TEST_PREFIX}-case`,
				model: 'e2e-model',
				suggestion: 'high',
				actualValue: 'normal',
			},
		})
		seeded.push(advisory.id)

		const blocking = await seedObject(request, token, 'approval', {
			sourceType: 'toolcall',
			status: 'pending',
			requestedAt: now,
			reason: BLOCKING_SUBJECT,
			toolId: BLOCKING_SUBJECT,
		})
		seeded.push(blocking.id)
	})

	test.afterAll(async ({ request }) => {
		for (const id of seeded) {
			await deleteObject(request, token, 'approval', id)
		}
	})

	/**
	 * @spec openspec/specs/ai-oversight/spec.md#requirement-the-oversight-log-is-a-surface-of-its-own
	 */
	test('the log lists the advisory record and not the blocking approval, under the three counts @spec ai-oversight', async ({ page }) => {
		await page.goto(`${await appRoot(page)}/ai-oversight`, { waitUntil: 'domcontentloaded' })
		await dismissTour(page)

		await expect(page.getByText(ADVISORY_TYPE).first(), 'the advisory record is on the log').toBeVisible({ timeout: 30_000 })
		await expect(page.getByText(BLOCKING_SUBJECT), 'a blocking approval stays off the oversight log').toHaveCount(0)

		for (const count of ['Accepted', 'Overridden', 'Rejected']) {
			await expect(page.getByText(count, { exact: true }).first(), `the ${count} count is shown`).toBeVisible()
		}
	})
})
