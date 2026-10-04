/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spec-coverage e2e: the chat action "Create an image" and the image in the
 * answer (chat-attachments-and-images, image-generation).
 *
 * A text-to-image provider cannot be assumed on a test instance, so these tests
 * stub hermiq's own image routes (availability and create) and the thread
 * listing. They check what the Chat page shows for a given answer: the action
 * only when it is available, and a created image as a thumbnail that opens the
 * file in Files. Every seeded object carries the e2espec- family prefix and is
 * removed in afterAll.
 *
 * Run against a running Nextcloud with Hermiq + OpenRegister installed:
 *
 *     NEXTCLOUD_URL=http://localhost:8080 NC_USER=admin NC_PASS=admin \
 *       npx playwright test --project=chromium image-generation
 *
 * Covers openspec/changes/chat-attachments-and-images/specs/image-generation/spec.md (REQ-CIMG-001, REQ-CIMG-002, REQ-CIMG-004).
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

const IMAGE = {
	fileId: 902,
	name: 'image-2026-10-04-101500.png',
	mimeType: 'image/png',
	size: 2048,
	origin: 'generated',
}

test.describe('image-generation: create an image from the chat', () => {
	let token = ''
	let agentId = ''

	test.beforeAll(async ({ browser }) => {
		const page = await browser.newPage()
		token = await harvestToken(page)
		const seeded = await seedAgent(page.request, token, {
			name: `${TEST_PREFIX}-image`,
			prompt: 'Help with illustrations.',
		})
		agentId = seeded.id
		await page.close()
	})

	test.afterAll(async ({ browser }) => {
		const page = await browser.newPage()
		await cleanupFamily(page.request, token)
		await page.close()
	})

	/**
	 * Open a fresh session of the seeded agent on the Chat page.
	 *
	 * @param page The page.
	 * @param title The session title.
	 */
	async function openSession(page, title: string): Promise<void> {
		const created = await page.request.post(`${API}/sessions`, {
			headers: { 'OCS-APIRequest': 'true', requesttoken: token },
			data: { agentUuid: agentId, title },
		})
		expect(created.status()).toBe(201)
		await page.goto(`${await appRoot(page)}/chat`)
		await dismissTour(page)
		await page.getByText(title).first().click()
	}

	// @e2e image-generation::no-provider-no-button
	test('without a provider the attach menu has no "Create an image"', async ({
		page,
	}) => {
		await page.route(
			'**/apps/hermiq/api/chat/images/availability',
			async (route) => {
				await route.fulfill({
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify({ available: false }),
				})
			},
		)

		await openSession(page, `${TEST_PREFIX}-no-image-session`)
		await page.getByTestId('chat-attach').getByRole('button').first().click()

		await expect(page.getByTestId('chat-attach-files')).toBeVisible()
		await expect(page.getByTestId('chat-create-image')).toHaveCount(0)
	})

	// @e2e image-generation::a-communications-advisor-asks-for-an-illustration
	// @e2e image-generation::the-owner-sees-the-image-where-they-asked-for-it
	test('a created image shows in the answer as a thumbnail that opens Files', async ({
		page,
	}) => {
		const prompt = 'Een fietsenstalling bij station Zwolle in de ochtendzon'
		const userTurn = {
			id: `${TEST_PREFIX}-turn-1`,
			uuid: `${TEST_PREFIX}-turn-1`,
			role: 'user',
			content: prompt,
			attachments: [],
			created: new Date().toISOString(),
		}
		const assistantTurn = {
			id: `${TEST_PREFIX}-turn-2`,
			uuid: `${TEST_PREFIX}-turn-2`,
			role: 'assistant',
			content: 'Here is the image you asked for.',
			attachments: [IMAGE],
			created: new Date().toISOString(),
		}
		let created = false
		let sentPrompt = ''

		await page.route(
			'**/apps/hermiq/api/chat/images/availability',
			async (route) => {
				await route.fulfill({
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify({ available: true }),
				})
			},
		)
		await page.route('**/apps/hermiq/api/chat/images', async (route) => {
			sentPrompt = route.request().postDataJSON()?.prompt || ''
			created = true
			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({ userTurn, assistantTurn }),
			})
		})
		await page.route(
			'**/apps/hermiq/api/sessions/*/messages**',
			async (route) => {
				if (!created) {
					await route.continue()
					return
				}
				await route.fulfill({
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify({ results: [userTurn, assistantTurn] }),
				})
			},
		)

		await openSession(page, `${TEST_PREFIX}-image-session`)
		await page.getByTestId('chat-attach').getByRole('button').first().click()
		await page.getByTestId('chat-create-image').click()
		await page.getByTestId('image-prompt').locator('textarea').fill(prompt)
		await page.getByTestId('image-create').click()

		const thumbnail = page.getByTestId('chat-message-image').first()
		await expect(thumbnail).toBeVisible({ timeout: 30_000 })
		expect(sentPrompt).toBe(prompt)
		await expect(thumbnail).toHaveAttribute('href', /\/f\/902$/)
		await expect(thumbnail.locator('img')).toHaveAttribute('alt', IMAGE.name)
		await expect(thumbnail.locator('img')).toHaveAttribute(
			'src',
			/core\/preview\?fileId=902/,
		)
	})
})
