/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spec-coverage e2e: who can use an agent, and the AgentCatalog columns
 * (agents-sharing-and-catalog-columns).
 *
 * The admin shares a seeded agent with a throwaway group through the agent
 * form, a second user in that group then gets the agent from /api/agents and
 * a second user outside it gets 404; the catalog shows the five columns.
 * Every seeded object, the user and the group are removed in afterAll.
 *
 * Run against a running Nextcloud with Hermiq + OpenRegister installed:
 *
 *     NEXTCLOUD_URL=http://localhost:8080 NC_USER=admin NC_PASS=admin \
 *       npx playwright test --project=chromium agents-sharing-and-catalog-columns
 *
 * Covers openspec/changes/agents-sharing-and-catalog-columns (REQ-AGSHARE-001 to -003).
 */

import { expect, test } from '@playwright/test'
import {
	appRoot,
	cleanupFamily,
	createSecondUser,
	deleteSecondUser,
	dismissTour,
	harvestToken,
	jsonHeaders,
	seedAgent,
	TEST_PREFIX,
} from './_fixtures.ts'

const API = '/index.php/apps/hermiq/api'
const GROUP = `${TEST_PREFIX}-planning`

test.describe('agents-sharing-and-catalog-columns: the AgentCatalog and the sharing choice', () => {
	let token = ''
	let member = { uid: '', password: '' }

	test.beforeAll(async ({ browser }) => {
		const page = await browser.newPage()
		token = await harvestToken(page)
		const headers = { ...jsonHeaders(token), 'OCS-APIRequest': 'true' }
		await page.request.post('/ocs/v1.php/cloud/groups?format=json', {
			headers,
			data: { groupid: GROUP },
		})
		member = await createSecondUser(page.request, token, 'member')
		await page.request.post(
			`/ocs/v1.php/cloud/users/${member.uid}/groups?format=json`,
			{ headers, data: { groupid: GROUP } },
		)
		await page.close()
	})

	test.afterAll(async ({ browser }) => {
		const page = await browser.newPage()
		await cleanupFamily(page.request, token)
		await deleteSecondUser(page.request, token, member.uid)
		await page.request.delete(`/ocs/v1.php/cloud/groups/${GROUP}?format=json`, {
			headers: { ...jsonHeaders(token), 'OCS-APIRequest': 'true' },
		})
		await page.close()
	})

	// @e2e agent-management-ui::a-team-lead-shares-an-agent-with-the-planning-desk
	// @e2e agent-management-ui::a-member-of-a-shared-group-opens-the-agent
	test('an agent shared with a group reaches its members', async ({
		page,
		browser,
	}) => {
		const agent = await seedAgent(page.request, token, {
			name: `${TEST_PREFIX}-planning-helper`,
			isPrivate: true,
		})
		const root = await appRoot(page)
		await page.goto(`${root}/agents/${agent.id}`)
		await dismissTour(page)
		await page.getByRole('button', { name: 'Edit agent' }).click()
		await page
			.getByTestId('agent-form-sharing')
			.getByText('People and groups I choose')
			.click()
		const groups = page.getByTestId('agent-form-sharing-groups')
		await groups.locator('input').fill(GROUP)
		await page.getByRole('option', { name: GROUP }).click()
		await page.getByRole('button', { name: 'Save' }).click()

		const context = await browser.newContext({
			httpCredentials: { username: member.uid, password: member.password },
		})
		const shown = await context.request.get(`${API}/agents/${agent.id}`, {
			headers: { 'OCS-APIRequest': 'true' },
		})
		expect(shown.status()).toBe(200)
		expect((await shown.json()).sharing).toBe('people-and-groups')
		await context.close()
	})

	// @e2e agent-management-ui::someone-outside-the-group-cannot-confirm-the-agent-exists
	test('someone outside the group gets 404', async ({ page, browser }) => {
		const agent = await seedAgent(page.request, token, {
			name: `${TEST_PREFIX}-private`,
			isPrivate: true,
			groups: ['admin-only-group-that-the-user-is-not-in'],
		})
		const outsider = await createSecondUser(page.request, token, 'outsider')
		const context = await browser.newContext({
			httpCredentials: { username: outsider.uid, password: outsider.password },
		})
		const shown = await context.request.get(`${API}/agents/${agent.id}`, {
			headers: { 'OCS-APIRequest': 'true' },
		})
		expect(shown.status()).toBe(404)
		await context.close()
		await deleteSecondUser(page.request, token, outsider.uid)
	})

	// @e2e agent-management-ui::a-case-handler-looks-for-an-agent-to-use
	// @e2e agent-management-ui::a-new-agent-is-private
	test('the AgentCatalog shows owner, who can use it and status', async ({
		page,
	}) => {
		await seedAgent(page.request, token, {
			name: `${TEST_PREFIX}-catalog`,
			isPrivate: true,
		})
		const root = await appRoot(page)
		await page.goto(`${root}/agents`)
		await dismissTour(page)
		for (const column of [
			'Name',
			'Owner',
			'Who can use it',
			'Status',
			'Model',
		]) {
			await expect(
				page.getByRole('columnheader', { name: column }),
			).toBeVisible()
		}
		const row = page.getByRole('row', {
			name: new RegExp(`${TEST_PREFIX}-catalog`),
		})
		await expect(row).toContainText('Only the owner')
		await expect(row).toContainText('On')
	})
	// @e2e agent-management-ui::an-organisation-admin-reviews-all-agents
	test("an organisation admin lists a colleague's private agent without its prompt", async ({
		page,
		browser,
	}) => {
		const colleague = await createSecondUser(page.request, token, 'colleague')
		const context = await browser.newContext({
			httpCredentials: {
				username: colleague.uid,
				password: colleague.password,
			},
		})
		const created = await context.request.post(
			'/index.php/apps/openregister/api/objects/hermiq/agent',
			{
				headers: { 'OCS-APIRequest': 'true' },
				data: {
					name: `${TEST_PREFIX}-colleague-private`,
					isPrivate: true,
					prompt: 'secret',
				},
			},
		)
		expect(created.ok()).toBeTruthy()
		await context.close()

		const list = await (
			await page.request.get(`${API}/agents?_limit=200`, {
				headers: { 'OCS-APIRequest': 'true' },
			})
		).json()
		const row = list.results.find(
			(agent) => agent.name === `${TEST_PREFIX}-colleague-private`,
		)
		expect(row).toBeTruthy()
		expect(row.visibleBecause).toBe('organisation admin')
		expect(row.prompt).toBeUndefined()
		await deleteSecondUser(page.request, token, colleague.uid)
	})
})
