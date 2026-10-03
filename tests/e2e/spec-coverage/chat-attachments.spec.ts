/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spec-coverage e2e: a file the person already has in Files is attached from the
 * Chat page's Files picker and sent by its file id (chat-attachments-and-images).
 *
 * The turn itself needs no model to be checked: the sent turn is stored with its
 * attachment before the model is called, so the thread shows it after a refresh
 * whatever the provider answers. Every seeded object and file carries the
 * e2espec- family prefix and is removed in afterAll.
 *
 * Run against a running Nextcloud with Hermiq + OpenRegister installed:
 *
 *     NEXTCLOUD_URL=http://localhost:8080 NC_USER=admin NC_PASS=admin \
 *       npx playwright test --project=chromium chat-attachments
 *
 * Covers openspec/changes/chat-attachments-and-images/specs/chat-attachments/spec.md (REQ-CATT-002).
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
const USER = process.env.NC_USER || 'admin'
const DAV = `/remote.php/dav/files/${USER}`
const FILE_NAME = `${TEST_PREFIX}-nota-warmtetransitie.pdf`

test.describe('chat-attachments: choose a file from Files', () => {
	let token = ''
	let agentId = ''

	test.beforeAll(async ({ browser }) => {
		const page = await browser.newPage()
		token = await harvestToken(page)
		const seeded = await seedAgent(page.request, token, {
			name: `${TEST_PREFIX}-attach`,
			prompt: 'Summarise the attached memo.',
		})
		agentId = seeded.id
		const put = await page.request.put(`${DAV}/${FILE_NAME}`, {
			headers: { requesttoken: token, 'Content-Type': 'application/pdf' },
			data: '%PDF-1.4\n%%EOF\n',
		})
		expect([201, 204]).toContain(put.status())
		await page.close()
	})

	test.afterAll(async ({ browser }) => {
		const page = await browser.newPage()
		await page.request.delete(`${DAV}/${FILE_NAME}`, {
			headers: { requesttoken: token },
		})
		await cleanupFamily(page.request, token)
		await page.close()
	})

	// @e2e chat-attachments::a-policy-officer-asks-about-a-memo-already-in-files
	test('a file chosen from Files is sent on the turn by id, without a copy', async ({
		page,
	}) => {
		const created = await page.request.post(`${API}/sessions`, {
			headers: { 'OCS-APIRequest': 'true', requesttoken: token },
			data: { agentUuid: agentId, title: `${TEST_PREFIX}-attach-session` },
		})
		expect(created.status()).toBe(201)

		await page.goto(`${await appRoot(page)}/chat`)
		await dismissTour(page)
		await page.getByText(`${TEST_PREFIX}-attach-session`).first().click()

		await page.getByTestId('chat-attach').getByRole('button').first().click()
		await page.getByTestId('chat-attach-files').click()

		const picker = page.getByRole('dialog', { name: 'Choose from Files' })
		await picker.getByText(FILE_NAME).click()
		await picker.getByRole('button', { name: 'Attach' }).click()

		await expect(page.getByTestId('chat-pending-attachments')).toContainText(
			FILE_NAME,
		)

		await page
			.getByLabel('Message', { exact: true })
			.fill('Vat de drie hoofdpunten samen')
		await page.getByRole('button', { name: 'Send message' }).click()

		await expect(
			page
				.getByTestId('chat-message-attachment')
				.filter({ hasText: FILE_NAME }),
		).toBeVisible({ timeout: 60_000 })

		// No copy: nothing was written under Hermiq/Attachments for this file.
		const listing = await page.request.fetch(`${DAV}/Hermiq/Attachments`, {
			method: 'PROPFIND',
			headers: { requesttoken: token, Depth: 'infinity' },
		})
		if (listing.status() === 207) {
			expect(await listing.text()).not.toContain(FILE_NAME)
		}
	})
})
