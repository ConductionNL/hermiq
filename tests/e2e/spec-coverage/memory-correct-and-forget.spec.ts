/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * memory-correct-and-forget: the owner of an agent corrects one remembered
 * fact and makes the agent forget another, from the memory list on the agent
 * page. The fact is seeded through the owner-only memory endpoint, the same
 * one the panel's "Remember" button calls.
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
} from './_fixtures.ts'

test.describe('hermiq memory: correct and forget', () => {
	test.afterAll(async ({ browser }) => {
		const page = await browser.newPage()
		const token = await harvestToken(page)
		await cleanupFamily(page.request, token, 'agent')
		await page.close()
	})

	// @e2e agent-memory::an-owner-corrects-a-wrong-fact
	// @e2e agent-memory::an-owner-removes-a-fact
	test('the owner corrects a fact and then forgets it', async ({ page }) => {
		const token = await harvestToken(page)
		const agent = await seedAgent(page.request, token, {
			name: `${TEST_PREFIX}-permit-helper`,
		})
		const seeded = await page.request.post(
			`/index.php/apps/hermiq/api/agents/${agent.id}/memory`,
			{
				headers: jsonHeaders(token),
				data: { text: 'The permit desk closes at 16:00' },
			},
		)
		expect(seeded.status()).toBe(200)

		const root = await appRoot(page)
		await page.goto(`${root}/agents/${agent.id}`, {
			waitUntil: 'domcontentloaded',
		})
		await dismissTour(page)

		const panel = page.locator('.agent-memory-panel')
		const oldFact = panel.getByText('The permit desk closes at 16:00')
		await expect(oldFact).toBeVisible({ timeout: 15_000 })

		// Correct.
		await panel.getByRole('button', { name: 'Correct this fact' }).click()
		const field = panel.getByLabel('Corrected fact')
		await field.fill('The permit desk closes at 17:00')
		await panel.getByRole('button', { name: 'Save' }).click()
		await expect(
			panel.getByText('The permit desk closes at 17:00'),
		).toBeVisible()
		await expect(oldFact).toHaveCount(0)

		// Forget.
		await panel.getByRole('button', { name: 'Forget this fact' }).click()
		const dialog = page.getByRole('dialog', { name: 'Forget this fact?' })
		await dialog.getByRole('button', { name: 'Forget' }).click()
		await expect(panel.getByText('The permit desk closes at 17:00')).toHaveCount(
			0,
		)

		// The agent no longer recalls it.
		const recall = await page.request.get(
			`/index.php/apps/hermiq/api/agents/${agent.id}/memory`,
			{ headers: jsonHeaders(token) },
		)
		const memory = await recall.json()
		const live = (memory.entries ?? []).filter((e) => !e.deletedAt)
		expect(live).toHaveLength(0)
	})
})
