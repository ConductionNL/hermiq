/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * personal-preferences, in a browser (gate 19).
 *
 * Covers the scenarios of openspec/changes/archive/2026-11-20-personal-preferences without an
 * `@e2e exclude`:
 *
 * - the preferences open in the settings dialog, and hermiq registers no
 *   personal settings page of its own;
 * - each change is saved at once and shown again when the dialog reopens;
 * - a chosen default agent opens the chat.
 *
 * The two run scenarios (asking before sending, a notification when a run
 * is done) need an agent that really runs against a model, so they are
 * excluded in the spec and covered by FacadeToolInvokerAskBeforeSendTest and
 * RunDoneNotifierTest.
 *
 * The config supplies `use.storageState`, so specs start signed in.
 */

// @e2e openspec/specs/personal-preferences/spec.md#the-preferences-open-in-the-settings-dialog
// @e2e openspec/specs/personal-preferences/spec.md#a-chosen-default-agent-opens-the-chat

import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'

const APP_BASE = '/index.php/apps/hermiq'

/**
 * Dismiss the first-run setup wizard if it is open; its modal intercepts
 * every click on a fresh instance.
 *
 * @param page The page.
 */
async function dismissSetupWizard(page: Page): Promise<void> {
	const modal = page.locator('[data-testid="cn-modal"]')
	if ((await modal.count()) === 0) {
		return
	}
	await modal.first().getByRole('button', { name: 'Close' }).click()
	await expect(modal).toHaveCount(0, { timeout: 15_000 })
}

/**
 * Open hermiq and its settings dialog from the app navigation.
 *
 * @param page The page.
 */
async function openSettingsDialog(page: Page): Promise<void> {
	await page.goto(`${APP_BASE}/`, { waitUntil: 'domcontentloaded' })
	const nav = page.locator('[data-testid="cn-nav"]')
	await expect(nav).toBeVisible({ timeout: 30_000 })
	await dismissSetupWizard(page)
	await nav.locator('[data-testid="cn-nav-settings"]').click()
	await nav.locator('[data-testid="cn-nav-personal-settings"]').click()
}

test.describe('personal preferences', () => {
	test('the preferences open in the settings dialog, not on a personal settings page', async ({
		page,
		request,
	}) => {
		await openSettingsDialog(page)

		const dialog = page.getByRole('dialog')
		await expect(
			dialog.getByRole('heading', { name: 'Preferences' }),
		).toBeVisible({
			timeout: 15_000,
		})
		await expect(dialog.getByTestId('pref-answer-language')).toBeVisible()
		await expect(dialog.getByTestId('pref-default-agent')).toBeVisible()
		await expect(dialog.getByTestId('pref-ask-before-send')).toBeVisible()
		await expect(dialog.getByTestId('pref-notify-run-done')).toBeVisible()

		// No personal settings page of hermiq's own.
		const personal = await request.get('/index.php/settings/user/hermiq', {
			maxRedirects: 0,
		})
		expect(personal.status()).not.toBe(200)
	})

	test('a change is saved at once and shown again when the dialog reopens', async ({
		page,
	}) => {
		await openSettingsDialog(page)
		const toggle = page.getByTestId('pref-notify-run-done')
		await expect(toggle).toBeVisible({ timeout: 15_000 })
		const checkbox = toggle.locator('input[type="checkbox"]')
		const before = await checkbox.isChecked()

		const saved = page.waitForResponse(
			(response) =>
				response.url().includes('/apps/hermiq/api/preferences/')
				&& response.request().method() === 'PUT',
		)
		await toggle.click()
		expect((await saved).ok()).toBe(true)

		await page.reload({ waitUntil: 'domcontentloaded' })
		await openSettingsDialog(page)
		const after = page
			.getByTestId('pref-notify-run-done')
			.locator('input[type="checkbox"]')
		await expect(after).toBeChecked({ checked: !before, timeout: 15_000 })

		// Leave the preference as it was.
		await page.getByTestId('pref-notify-run-done').click()
		await expect(after).toBeChecked({ checked: before })
	})

	test('a chosen default agent opens the chat', async ({ page }) => {
		const agentsRead = page.waitForResponse((response) =>
			/\/api\/objects\/hermiq\/agent(\?|$)/.test(response.url()),
		)
		let chosen = ''
		await page.route(
			'**/apps/hermiq/api/preferences/default-agent',
			async (route) => {
				if (route.request().method() !== 'GET') {
					await route.continue()
					return
				}
				await route.fulfill({
					json: { key: 'default-agent', value: chosen },
				})
			},
		)
		const sessionBodies: Array<Record<string, unknown>> = []
		await page.route('**/apps/hermiq/api/sessions', async (route) => {
			if (route.request().method() !== 'POST') {
				await route.continue()
				return
			}
			sessionBodies.push(
				route.request().postDataJSON() as Record<string, unknown>,
			)
			await route.fulfill({
				json: { uuid: 'session-e2e-default-agent', messages: [] },
			})
		})

		await page.goto(`${APP_BASE}/chat`, { waitUntil: 'domcontentloaded' })
		const body = await (await agentsRead).json()
		const agents = (Array.isArray(body) ? body : body.results) || []
		test.skip(
			agents.length === 0,
			'No agent on this instance to choose as the default.',
		)
		const agent = agents[0]
		chosen = String(agent.uuid || agent.id || agent['@self']?.id)

		// The preference is read when the chat loads: load it again with the choice set.
		await page.reload({ waitUntil: 'domcontentloaded' })
		await dismissSetupWizard(page)
		await page.getByRole('button', { name: 'New session' }).click()

		await expect.poll(() => sessionBodies.length, { timeout: 15_000 }).toBe(1)
		expect(sessionBodies[0].agentUuid).toBe(chosen)
	})
})
