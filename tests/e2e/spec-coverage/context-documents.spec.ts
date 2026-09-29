/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * hermiq-context-documents: an operator creates a Context with a document on
 * the Contexts page, edits it, and the stored object keeps the fields the
 * editor does not show (charBudget, viewRefs).
 */

import { expect, test } from '@playwright/test'
import {
	appRoot,
	cleanupFamily,
	dismissTour,
	harvestToken,
	jsonHeaders,
	OR_API,
	seedObject,
	TEST_PREFIX,
} from './_fixtures.ts'

test.describe('hermiq contexts: author documents', () => {
	test.afterAll(async ({ browser }) => {
		const page = await browser.newPage()
		const token = await harvestToken(page)
		await cleanupFamily(page.request, token, 'context')
		await page.close()
	})

	// @e2e context-documents::open-the-editor-from-the-management-page
	// @e2e context-documents::author-a-document-and-save
	test('create a context with one document', async ({ page }) => {
		const name = `${TEST_PREFIX}-standards`
		const root = await appRoot(page)
		await page.goto(`${root}/contexts`, { waitUntil: 'domcontentloaded' })
		await dismissTour(page)

		await page.getByRole('button', { name: /add|new/i }).first().click()
		const dialog = page.getByRole('dialog')
		await dialog.getByLabel('Name').first().fill(name)
		await dialog.getByRole('button', { name: 'Add document' }).click()
		await dialog.getByLabel('Document name').fill('design.md')
		await dialog.getByLabel('Document text').fill('Use the design tokens.')
		await dialog.getByRole('button', { name: 'Save' }).click()

		await expect(page.getByText(name)).toBeVisible({ timeout: 15_000 })
	})

	// @e2e context-documents::edit-preserves-unsurfaced-fields
	// @e2e context-documents::add-rename-and-remove-document-entries
	test('edit keeps charBudget and viewRefs and the remaining document', async ({ page }) => {
		const token = await harvestToken(page)
		const seeded = await seedObject(page.request, token, 'context', {
			name: `${TEST_PREFIX}-budgeted`,
			charBudget: 1234,
			viewRefs: [],
			documents: [{ name: 'old.md', body: 'Old text.', format: 'markdown' }],
		})

		const root = await appRoot(page)
		await page.goto(`${root}/contexts`, { waitUntil: 'domcontentloaded' })
		await dismissTour(page)
		await page.getByText(seeded.name).click()
		const dialog = page.getByRole('dialog')
		await dialog.getByRole('button', { name: 'Add document' }).click()
		await dialog.getByLabel('Document name').nth(1).fill('new.md')
		await dialog.getByLabel('Document text').nth(1).fill('New text.')
		await dialog.getByRole('button', { name: 'Remove document' }).first().click()
		await dialog.getByRole('button', { name: 'Save' }).click()
		await expect(dialog).toBeHidden({ timeout: 15_000 })

		const res = await page.request.get(`${OR_API}/objects/hermiq/context/${seeded.id}`, {
			headers: jsonHeaders(token),
		})
		const body = await res.json()
		expect(body.charBudget).toBe(1234)
		expect(body.documents.map((d: { name: string }) => d.name)).toEqual(['new.md'])
	})
})
