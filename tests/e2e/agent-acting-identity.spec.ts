/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * agent-acting-identity-picker, in a browser (gate 19).
 *
 * Covers the scenarios of openspec/changes/agent-acting-identity-picker without
 * an `@e2e exclude`:
 *
 * - an administrator gives an agent its own service account: the agent form's
 *   "On whose behalf the agent works" choice stores the picked user in
 *   `actingUser`, and reopening the form shows it selected;
 * - going back to the person who uses it stores an empty `actingUser`.
 *
 * The second scenario's last line, the next scheduled run running as the
 * schedule's owner, needs a real dispatch and is asserted by the scheduler's
 * unit tests; this file checks the stored field that decides it.
 *
 * The excluded scenarios (an owner without the action, a disabled account, the
 * disabled option, a departed account) need a non-admin or a disabled user and
 * are covered by AgentActingUserGuardListenerTest and the Vitest tests of the
 * agent form.
 *
 * The config supplies `use.storageState`, so the suite starts signed in as the
 * administrator. Every seeded agent and the throwaway user are removed in afterAll.
 */

import type { APIRequestContext, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import {
	appRoot,
	cleanupFamily,
	createSecondUser,
	deleteSecondUser,
	dismissTour,
	harvestToken,
	jsonHeaders,
	openAgentForm,
	OR_API,
	seedAgent,
	TEST_PREFIX,
} from './spec-coverage/_fixtures.ts'

/**
 * The stored `actingUser` of an agent, read back from OpenRegister.
 *
 * @param req   The request context.
 * @param token The CSRF request-token.
 * @param id    The agent id.
 * @return The stored value, '' when unset.
 */
async function storedActingUser(
	req: APIRequestContext,
	token: string,
	id: string,
): Promise<string> {
	const res = await req.get(`${OR_API}/objects/hermiq/agent/${id}`, {
		headers: jsonHeaders(token),
	})
	expect(res.status()).toBe(200)
	const body = await res.json()
	return typeof body.actingUser === 'string' ? body.actingUser : ''
}

/**
 * Save the open agent form.
 *
 * @param page The page with the agent form open.
 * @return void
 */
async function saveAgentForm(page: Page): Promise<void> {
	await page
		.getByRole('dialog', { name: 'Edit agent' })
		.getByRole('button', { name: 'Save', exact: true })
		.click()
	await expect(page.locator('.agent-form')).toHaveCount(0, { timeout: 20_000 })
}

test.describe('agent-acting-identity-picker: whose rights the agent works with', () => {
	let token = ''
	let account = { uid: '', password: '' }

	test.beforeAll(async ({ browser }) => {
		const page = await browser.newPage()
		token = await harvestToken(page)
		account = await createSecondUser(page.request, token, 'service')
		await page.close()
	})

	test.afterAll(async ({ browser }) => {
		const page = await browser.newPage()
		await cleanupFamily(page.request, token, 'agent')
		await deleteSecondUser(page.request, token, account.uid)
		await page.close()
	})

	// @e2e agent-identity::an-administrator-gives-an-agent-its-own-service-account
	test('an administrator gives an agent its own service account', async ({ page }) => {
		const agent = await seedAgent(page.request, token, {
			name: `${TEST_PREFIX}-triage`,
		})
		const root = await appRoot(page)
		await page.goto(`${root}/agents/${agent.id}`)
		await dismissTour(page)

		const form = await openAgentForm(page)
		const acting = form.getByTestId('agent-form-acting')
		await acting.getByText('As its own service account').click()
		const picker = form.getByTestId('agent-form-acting-user')
		await picker.locator('input').fill(account.uid)
		await page.locator('.vs__dropdown-option', { hasText: account.uid }).click()
		await saveAgentForm(page)

		expect(await storedActingUser(page.request, token, agent.id)).toBe(account.uid)

		await page.reload()
		await dismissTour(page)
		const reopened = await openAgentForm(page)
		await expect(
			reopened.getByTestId('agent-form-acting').getByRole('radio', {
				name: 'As its own service account',
			}),
		).toBeChecked()
		await expect(reopened.getByTestId('agent-form-acting-user')).toContainText(account.uid)
		await expect(reopened.getByTestId('agent-form-acting-unresolved')).toHaveCount(0)
	})

	// @e2e agent-identity::going-back-to-the-person-who-uses-it
	test('going back to the person who uses it clears the account', async ({ page }) => {
		const agent = await seedAgent(page.request, token, {
			name: `${TEST_PREFIX}-back-to-person`,
			actingUser: account.uid,
		})
		const root = await appRoot(page)
		await page.goto(`${root}/agents/${agent.id}`)
		await dismissTour(page)

		const form = await openAgentForm(page)
		const acting = form.getByTestId('agent-form-acting')
		await expect(
			acting.getByRole('radio', { name: 'As its own service account' }),
		).toBeChecked()
		await acting.getByText('On behalf of the person who uses it').click()
		await expect(form.getByTestId('agent-form-acting-user')).toHaveCount(0)
		await saveAgentForm(page)

		expect(await storedActingUser(page.request, token, agent.id)).toBe('')
	})
})
