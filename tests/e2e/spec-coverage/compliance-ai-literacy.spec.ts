/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spec-coverage e2e: Working with AI (compliance-ai-literacy).
 *
 * One lesson is completed through the page, and the course requirement is
 * switched on for the admin's organisation to show that a chat message is
 * refused before any model is called; the requirement is switched off again
 * in afterAll, so the instance is left as it was.
 *
 * Run against a running Nextcloud with Hermiq + OpenRegister installed:
 *
 *     NEXTCLOUD_URL=http://localhost:8080 NC_USER=admin NC_PASS=admin \
 *       npx playwright test --project=chromium compliance-ai-literacy
 *
 * Covers openspec/specs/compliance-control-packs/spec.md
 */

import { expect, test } from '@playwright/test'
import {
	appRoot,
	cleanupFamily,
	dismissTour,
	harvestToken,
	seedAgent,
	TEST_PREFIX,
} from './_fixtures.ts'

const API = '/index.php/apps/hermiq/api'

test.describe('compliance-ai-literacy: Working with AI', () => {
	let token = ''

	test.beforeAll(async ({ browser }) => {
		const page = await browser.newPage()
		token = await harvestToken(page)
		await page.close()
	})

	test.afterAll(async ({ browser }) => {
		const page = await browser.newPage()
		await page.request.put(`${API}/literacy/requirement`, {
			headers: { 'OCS-APIRequest': 'true', requesttoken: token },
			data: { required: false },
		})
		await cleanupFamily(page.request, token)
		await page.close()
	})

	// @e2e compliance-control-packs::a-new-case-handler-completes-a-lesson
	test('a right answer marks the lesson done and the count goes up', async ({
		page,
	}) => {
		const root = await appRoot(page)
		await page.goto(`${root}/ai-literacy`)
		await dismissTour(page)
		await expect(
			page.getByRole('heading', { name: 'Working with AI' }),
		).toBeVisible()

		const lesson = page.getByTestId('ai-literacy-lesson-check-the-sources')
		await lesson.getByRole('button').first().click()
		await lesson
			.getByText('Check the figure in the source system before you use it')
			.click()
		await lesson.getByRole('button', { name: 'Check answer' }).click()

		await expect(lesson.getByText('Right. This lesson is done.')).toBeVisible()
		await expect(page.getByTestId('ai-literacy-progress')).toContainText(
			/of 6 done/,
		)
	})

	// @e2e compliance-control-packs::a-person-who-skipped-the-course-is-sent-to-it
	test('with the course required, a chat message is refused with the course link', async ({
		page,
	}) => {
		const headers = { 'OCS-APIRequest': 'true', requesttoken: token }
		const lessons = await (
			await page.request.get(`${API}/literacy/lessons`, { headers })
		).json()
		test.skip(
			lessons.done === lessons.total,
			'The test user has already finished every lesson, so there is nothing to refuse.',
		)
		test.skip(
			!lessons.mayAdminister,
			'The test user does not administer an organisation here.',
		)

		const put = await page.request.put(`${API}/literacy/requirement`, {
			headers,
			data: { required: true },
		})
		expect(put.ok()).toBeTruthy()

		const agent = await seedAgent(page.request, token, {
			name: `${TEST_PREFIX}-literacy`,
		})
		const send = await page.request.post(`${API}/chat/send`, {
			headers,
			data: { message: 'Hallo', agentUuid: agent.id },
		})
		expect(send.status()).toBe(403)
		const body = await send.json()
		expect(body.errorCode).toBe('ai_literacy_required')
		expect(body.message).toBe('Finish the short course Working with AI first.')
		expect(body.courseUrl).toBe('/apps/hermiq/ai-literacy')
	})
})
