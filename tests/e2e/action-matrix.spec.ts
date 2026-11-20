/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * action-matrix-screen, in a browser (gate 19).
 *
 * Covers the scenarios of openspec/changes/action-matrix-screen without an
 * `@e2e exclude`:
 *
 * - an administrator gives a group one action on the tenant management page,
 *   and the stored matrix maps that action to admin plus the group;
 * - an administrator resets a changed action to its seeded value, and the
 *   section no longer marks it as changed.
 *
 * The scenarios for a member (the hidden section, the refused API write), an
 * unknown group and removing admin need a non-admin user or no screen, and are
 * covered by ActionMatrixControllerTest and the Vitest test of the section.
 *
 * The config supplies `use.storageState`, so the suite starts signed in as the
 * administrator. The test group is deleted and both actions are put back to
 * their seeded value in afterAll.
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { appRoot, dismissTour, harvestToken, jsonHeaders, TEST_PREFIX } from './spec-coverage/_fixtures.ts'

const API = '/index.php/apps/hermiq/api'
const GROUP = `${TEST_PREFIX}-dpo`

interface ActionRow {
	action: string
	groups: string[]
	seeded: string[]
	changed: boolean
}

/**
 * One row of the stored matrix, read through the endpoint the section uses.
 *
 * @param req    The request context.
 * @param token  The CSRF request-token.
 * @param action The action name.
 * @return The row.
 */
async function actionRow(
	req: APIRequestContext,
	token: string,
	action: string,
): Promise<ActionRow> {
	const res = await req.get(`${API}/actions`, { headers: jsonHeaders(token) })
	expect(res.status()).toBe(200)
	const body = await res.json()
	const row = (body.actions as ActionRow[]).find((entry) => entry.action === action)
	expect(row, `the matrix must list ${action}`).toBeTruthy()
	return row as ActionRow
}

/**
 * Put an action's groups through the matrix endpoint.
 *
 * @param req    The request context.
 * @param token  The CSRF request-token.
 * @param action The action name.
 * @param groups The groups to store.
 * @return void
 */
async function putGroups(
	req: APIRequestContext,
	token: string,
	action: string,
	groups: string[],
): Promise<void> {
	const res = await req.put(`${API}/actions/${action}`, {
		headers: jsonHeaders(token),
		data: { groups },
	})
	expect(res.status(), await res.text()).toBe(200)
}

test.describe('action-matrix-screen: rights per action on the tenant management page', () => {
	let token = ''

	test.beforeAll(async ({ browser }) => {
		const page = await browser.newPage()
		token = await harvestToken(page)
		await page.request.post('/ocs/v1.php/cloud/groups?format=json', {
			headers: { ...jsonHeaders(token), 'OCS-APIRequest': 'true' },
			data: { groupid: GROUP },
		})
		await page.close()
	})

	test.afterAll(async ({ browser }) => {
		const page = await browser.newPage()
		for (const action of ['aifeature.acknowledge', 'compliance.view-dashboard']) {
			const row = await actionRow(page.request, token, action)
			await putGroups(page.request, token, action, row.seeded)
		}
		await page.request.delete(`/ocs/v1.php/cloud/groups/${GROUP}?format=json`, {
			headers: { ...jsonHeaders(token), 'OCS-APIRequest': 'true' },
		})
		await page.close()
	})

	// @e2e action-authorization::an-administrator-gives-the-dpo-group-one-action
	test('an administrator gives a group one action', async ({ page }) => {
		const root = await appRoot(page)
		await page.goto(`${root}/tenant-ops`, { waitUntil: 'domcontentloaded' })
		await dismissTour(page)

		const section = page.getByTestId('action-matrix')
		await expect(section).toBeVisible({ timeout: 30_000 })
		await expect(section.getByTestId('action-matrix-scope')).toContainText(
			'every organisation',
		)

		const row = section.locator('[data-testid="action-matrix-row"][data-action="aifeature.acknowledge"]')
		await expect(row).toBeVisible()
		await row.getByRole('combobox').fill(GROUP)
		await page.locator('.vs__dropdown-option', { hasText: GROUP }).click()
		const saved = page.waitForResponse(
			(res) => res.url().includes('/api/actions/aifeature.acknowledge') && res.request().method() === 'PUT',
		)
		await row.getByTestId('action-matrix-save').click()
		expect((await saved).status()).toBe(200)

		const stored = await actionRow(page.request, token, 'aifeature.acknowledge')
		expect([...stored.groups].sort()).toEqual(['admin', GROUP].sort())
		await expect(row.getByTestId('action-matrix-changed')).toBeVisible()
	})

	// @e2e action-authorization::reset-to-the-seeded-value
	test('reset to the seeded value', async ({ page }) => {
		const before = await actionRow(page.request, token, 'compliance.view-dashboard')
		await putGroups(page.request, token, 'compliance.view-dashboard', [...before.seeded, GROUP])

		const root = await appRoot(page)
		await page.goto(`${root}/tenant-ops`, { waitUntil: 'domcontentloaded' })
		await dismissTour(page)

		const row = page.locator('[data-testid="action-matrix-row"][data-action="compliance.view-dashboard"]')
		await expect(row).toBeVisible({ timeout: 30_000 })
		await expect(row.getByTestId('action-matrix-changed')).toBeVisible()

		const reset = page.waitForResponse(
			(res) => res.url().includes('/api/actions/compliance.view-dashboard') && res.request().method() === 'PUT',
		)
		await row.getByTestId('action-matrix-reset').click()
		expect((await reset).status()).toBe(200)

		const stored = await actionRow(page.request, token, 'compliance.view-dashboard')
		expect([...stored.groups].sort()).toEqual([...before.seeded].sort())
		expect(stored.changed).toBe(false)
		await expect(row.getByTestId('action-matrix-changed')).toHaveCount(0)
	})
})
