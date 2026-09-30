/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spec-coverage e2e: the task tools' grant surface (tools-nextcloud-tasks).
 *
 * The scenario that needs a browser is the grant editor: the classification is
 * declared in Hermiq, carried through OpenRegister's tool registry and read back
 * in Hermiq, and a hint lost on the way does not error, it just makes a write
 * look like a read. The chat scenarios need a live model to turn a sentence into
 * a tool call and carry a reason-bearing `@e2e exclude` in the spec instead.
 *
 * Run against a running Nextcloud with Hermiq + OpenRegister installed:
 *
 *     NEXTCLOUD_URL=http://localhost:8080 NC_USER=admin NC_PASS=admin \
 *       npx playwright test --project=chromium tools-nextcloud-tasks
 *
 * Covers openspec/specs/nc-native-tools/spec.md
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

/** The two task writes, which must need an explicit grant. */
const TASK_WRITES = ['hermiq.createTask', 'hermiq.completeTask']

test.describe('tools-nextcloud-tasks: grant surface', () => {
	let token = ''
	let agentId = ''

	test.beforeAll(async ({ browser }) => {
		const page = await browser.newPage()
		token = await harvestToken(page)
		const seeded = await seedAgent(page.request, token, {
			name: `${TEST_PREFIX}-tasktools`,
		})
		agentId = seeded.id
		await page.close()
	})

	test.afterAll(async ({ browser }) => {
		const page = await browser.newPage()
		await cleanupFamily(page.request, token)
		await page.close()
	})

	// @e2e nc-native-tools::the-grant-editor-shows-the-task-tools-honestly
	test('the task tools show with their classes and reach, ungranted', async ({
		page,
	}) => {
		const response = await page.request.get(
			`/index.php/apps/hermiq/api/agents/${agentId}/tool-catalog`,
			{ headers: { 'OCS-APIRequest': 'true', requesttoken: token } },
		)
		expect(response.ok()).toBeTruthy()
		const payload = await response.json()
		const tools: Record<string, unknown>[] = payload.tools ?? []
		const byId = new Map(tools.map((t) => [String(t.id), t]))

		const list = byId.get('hermiq.listTasks')
		expect(list, 'hermiq.listTasks must appear in the catalogue').toBeTruthy()
		expect(list?.reach).toBe('user')
		// The control: a read tool must not be announced as needing a grant, or
		// the assertions below would pass on a surface that marks everything.
		expect(list?.requiresExplicitGrant).toBe(false)

		for (const id of TASK_WRITES) {
			const entry = byId.get(id)
			expect(entry, `${id} must appear in the catalogue`).toBeTruthy()
			expect(entry?.reach, `${id} reaches whoever shares the list`).toBe('instance')
			expect(entry?.granted, `${id} must not be granted by default`).toBe(false)
			expect(entry?.requiresExplicitGrant, `${id} is a write`).toBe(true)
		}

		// And the editor shows them to the owner.
		const root = await appRoot(page)
		await page.goto(`${root}/agents/${agentId}`)
		await dismissTour(page)
		await expect(page.getByRole('heading', { name: /tool grants/i })).toBeVisible()
		const filter = page.getByPlaceholder('Filter by cluster, subject or tool')
		await filter.fill('hermiq.createTask')
		const headers = page.locator('.grant-matrix__cluster-header')
		for (let i = 0; i < (await headers.count()); i++) {
			const header = headers.nth(i)
			if ((await header.getAttribute('aria-expanded')) === 'false') {
				await header.click()
			}
		}
		await expect(page.locator('.grant-matrix tbody tr').first()).toBeVisible()
		await expect(page.getByLabel(/requires explicit grant/i).first()).toBeVisible()
	})
})
