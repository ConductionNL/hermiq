/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Computer use e2e (spec-coverage), change tools-browser-and-computer-use.
 *
 *   - REQ-COMPUTER-001: the admin settings carry the computer targets section;
 *     a target without an integriq source is refused; a user who is not an
 *     admin is refused every computer-target route.
 *   - REQ-COMPUTER-003: a held computer action shows the reviewer the machine
 *     and the action with its point, and offers "Approve for 15 minutes".
 *
 * The test screenshot and an approved click need a registered Windows machine
 * behind an integriq source, so they are live-only (tasks.md, Verification).
 *
 * Auth: the suite's admin storageState; the refusals run as a second user in
 * a context with an empty storage state.
 */

import { expect, request, test } from '@playwright/test'
import { BASE_URL } from '../base-url.ts'
import {
	appRoot,
	createSecondUser,
	deleteObject,
	deleteSecondUser,
	dismissTour,
	harvestToken,
	jsonHeaders,
	seedObject,
	TEST_PREFIX,
} from './_fixtures.ts'

const TARGETS = '/index.php/apps/hermiq/api/settings/computer-targets'

test.describe('hermiq computer use', () => {
	test('the admin settings list the computer targets with an add button', async ({ page }) => {
		await page.goto('/index.php/settings/admin/hermiq', { waitUntil: 'domcontentloaded' })
		await expect(page.getByRole('button', { name: 'Add computer target' })).toBeVisible({ timeout: 20_000 })
		const empty = page.getByText('No machine is registered. Agents cannot operate a computer until an admin adds one.')
		const table = page.getByRole('columnheader', { name: 'Agents may use it' })
		await expect(empty.or(table).first()).toBeVisible()
	})

	test('a computer target without an integriq source is refused', async ({ page }) => {
		const token = await harvestToken(page)
		const res = await page.request.post(TARGETS, {
			headers: jsonHeaders(token),
			data: { name: `${TEST_PREFIX}-werkplek`, source: '', os: 'windows', enabled: true },
		})
		expect(res.status()).toBe(400)
		expect(JSON.stringify(await res.json())).toContain('Choose an integriq source that reaches the machine.')
	})

	test('a user who is not an admin is refused the computer-target routes', async ({ page }) => {
		const token = await harvestToken(page)
		const other = await createSecondUser(page.request, token, 'computer')
		const ctx = await request.newContext({
			baseURL: BASE_URL,
			storageState: { cookies: [], origins: [] },
			httpCredentials: { username: other.uid, password: other.password },
			extraHTTPHeaders: { 'OCS-APIRequest': 'true', Accept: 'application/json' },
		})
		try {
			const who = await ctx.get('/ocs/v1.php/cloud/user?format=json')
			expect((await who.json())?.ocs?.data?.id).toBe(other.uid)

			expect((await ctx.get(TARGETS)).status()).toBe(403)
			expect((await ctx.post(TARGETS, { data: { name: 'x', source: 'y' } })).status()).toBe(403)
			expect((await ctx.post(`${TARGETS}/00000000-0000-0000-0000-000000000000/screenshot`)).status()).toBe(403)
		} finally {
			await ctx.dispose()
			await deleteSecondUser(page.request, token, other.uid)
		}
	})

	test('a held click shows the machine and the point, with "Approve for 15 minutes"', async ({ page }) => {
		const token = await harvestToken(page)
		const me = await page.request.get('/ocs/v1.php/cloud/user?format=json', {
			headers: { 'OCS-APIRequest': 'true', Accept: 'application/json' },
		})
		const uid = String((await me.json())?.ocs?.data?.id ?? '')
		expect(uid).not.toBe('')

		const machine = `${TEST_PREFIX} Werkplek 03`
		const approval = await seedObject(page.request, token, 'approval', {
			status: 'pending',
			sourceType: 'toolcall',
			toolId: 'hermiq.computerClick',
			toolArguments: { target: machine, x: 412, y: 230 },
			runId: `${TEST_PREFIX}-run`,
			reviewer: uid,
			reviewerType: 'user',
			requestedAt: new Date().toISOString(),
		})
		try {
			const root = await appRoot(page)
			await page.goto(`${root}/approvals`, { waitUntil: 'domcontentloaded' })
			await dismissTour(page)

			const card = page.getByTestId('approval-computer-action').filter({ hasText: machine })
			await expect(card).toBeVisible({ timeout: 20_000 })
			await expect(card).toContainText(`Machine: ${machine}`)
			await expect(card).toContainText('Click at 412, 230')
			await expect(page.getByTestId('approval-machine-run').first()).toContainText('Approve for 15 minutes')
		} finally {
			await deleteObject(page.request, token, 'approval', approval.id)
		}
	})
})
