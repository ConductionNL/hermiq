/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * flows-hand-off-and-wait tasks 4 and 7: the waiting line a handoff puts in
 * the chat, and registering a remote agent in Admin settings.
 *
 * What is real and what is answered by page.route, and why:
 * - A handoff is written by the run path when an agent starts a flow, and a
 *   flow run needs a model that calls `openregister.runFlow`. So the
 *   session's messages and its `agenthandoff` objects are answered with one
 *   open and one finished handoff, in the shape OpenRegister returns. The
 *   record itself is pinned by HandoffServiceTest.
 * - Registering a remote agent fetches its card through integriq from a
 *   remote server, which CI does not have. So the integriq sources and the
 *   remote agent routes are answered, and what is asserted is what the form
 *   sends and what the list shows. RemoteAgentControllerTest pins the server half.
 *
 * Written from the change's scenarios; not run here, it needs a running Nextcloud.
 *
 * @spec openspec/changes/flows-hand-off-and-wait/specs/agent-handoffs/spec.md#requirement-work-an-agent-starts-and-waits-for-is-recorded-as-a-handoff-req-handoff-001
 * @spec openspec/changes/flows-hand-off-and-wait/specs/remote-agents/spec.md#requirement-an-admin-registers-a-remote-agent-through-an-integriq-source-req-a2a-001
 */

import type { Page, Route } from '@playwright/test'

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
const HANDOFFS = /\/apps\/openregister\/api\/objects\/hermiq\/agenthandoff(\?.*)?$/
const SOURCES = /\/apps\/openregister\/api\/objects\/integriq\/source(\?.*)?$/
const REMOTE_AGENTS = /\/apps\/hermiq\/api\/remote-agents(\?.*)?$/

test.describe('flows-hand-off-and-wait: the waiting line in the chat', () => {
	let token = ''
	let sessionUuid = ''
	let sessionTitle = ''

	test.beforeAll(async ({ browser }) => {
		const page = await browser.newPage()
		token = await harvestToken(page)
		const agent = await seedAgent(page.request, token, {
			name: `${TEST_PREFIX}-handoff`,
		})
		sessionTitle = `${TEST_PREFIX}-handoff-session`
		const created = await page.request.post(`${API}/sessions`, {
			headers: { 'OCS-APIRequest': 'true', requesttoken: token },
			data: { agentUuid: agent.id, title: sessionTitle },
		})
		expect(created.status()).toBe(201)
		sessionUuid = (await created.json()).uuid
		await page.close()
	})

	test.afterAll(async ({ browser }) => {
		const page = await browser.newPage()
		await page.request.delete(`${API}/sessions/${sessionUuid}`, {
			headers: { 'OCS-APIRequest': 'true', requesttoken: token },
		})
		await cleanupFamily(page.request, token, 'agent')
		await page.close()
	})

	/**
	 * Answer the session's messages and its handoffs.
	 *
	 * @param page     The Playwright page.
	 * @param handoffs The handoffs to list.
	 */
	async function seedThread(
		page: Page,
		handoffs: Array<Record<string, unknown>>,
	): Promise<void> {
		const asked = '2026-11-21T14:02:00+00:00'
		await page.route(
			new RegExp(`/api/sessions/${sessionUuid}/messages(\\?.*)?$`),
			async (route: Route) => {
				if (route.request().method() !== 'GET') {
					await route.fallback()
					return
				}
				await route.fulfill({
					json: {
						total: 2,
						results: [
							{
								id: 21,
								role: 'user',
								content:
									'Controleer of voor Kerkstraat 12 een vergunning nodig is',
								created: asked,
							},
							{
								id: 22,
								role: 'assistant',
								content:
									'De vergunningcheck loopt. Ik laat het weten zodra de uitkomst er is.',
								created: asked,
							},
						],
					},
				})
			},
		)
		await page.route(HANDOFFS, async (route: Route) => {
			if (route.request().method() !== 'GET') {
				await route.fallback()
				return
			}
			await route.fulfill({
				json: {
					total: handoffs.length,
					results: handoffs.map((handoff) => ({
						conversationId: sessionUuid,
						...handoff,
					})),
				},
			})
		})
	}

	/**
	 * Open the chat on the seeded session.
	 *
	 * @param page The Playwright page.
	 */
	async function openSession(page: Page): Promise<void> {
		await page.goto(`${await appRoot(page)}/chat`, {
			waitUntil: 'domcontentloaded',
		})
		await dismissTour(page)
		await page.getByText(sessionTitle).first().click()
		await expect(
			page.getByText('De vergunningcheck loopt.', { exact: false }),
		).toBeVisible({ timeout: 20_000 })
	}

	// @e2e agent-handoffs::a-case-handler-starts-a-check-and-keeps-working
	test('an open handoff shows "Waiting for" under the answer that started it', async ({
		page,
	}) => {
		await seedThread(page, [
			{
				id: `${TEST_PREFIX}-handoff-open`,
				kind: 'flow',
				label: 'Vergunningcheck',
				status: 'waiting',
				requestedAt: '2026-11-21T14:02:30+00:00',
			},
		])

		await openSession(page)
		const lines = page.getByTestId('chat-handoffs')
		await expect(lines).toHaveCount(1)
		await expect(lines.locator('.chat-page__handoff--waiting')).toContainText(
			/Waiting for “Vergunningcheck” \(started \d{1,2}[:.]\d{2}\)/,
		)
	})

	test('a finished handoff says so instead of waiting', async ({ page }) => {
		await seedThread(page, [
			{
				id: `${TEST_PREFIX}-handoff-done`,
				kind: 'flow',
				label: 'Vergunningcheck',
				status: 'done',
				requestedAt: '2026-11-21T14:02:30+00:00',
			},
		])

		await openSession(page)
		await expect(page.locator('.chat-page__handoff--done')).toHaveText(
			'“Vergunningcheck” has finished; its result is in the conversation.',
		)
		await expect(page.getByText(/Waiting for “Vergunningcheck”/)).toHaveCount(0)
	})
})

test.describe('flows-hand-off-and-wait: remote agents', () => {
	// @e2e remote-agents::an-admin-adds-the-kvk-agent
	test('an admin adds a remote agent through an integriq source; it reads its card and stays off', async ({
		page,
	}) => {
		const registered: Array<Record<string, unknown>> = []
		const agents: Array<Record<string, unknown>> = []

		await page.route(SOURCES, async (route: Route) => {
			await route.fulfill({
				json: { total: 1, results: [{ id: 'kvk-a2a', name: 'kvk-a2a' }] },
			})
		})
		await page.route(REMOTE_AGENTS, async (route: Route) => {
			const method = route.request().method()
			if (method === 'POST') {
				const body = route.request().postDataJSON() as Record<
					string,
					unknown
				>
				registered.push(body)
				const saved = {
					id: `${TEST_PREFIX}-remote`,
					name: body.name,
					slug: 'kvk-assistent',
					description: body.description,
					enabled: false,
					agentCard: {
						name: 'KvK-assistent',
						version: '1.0.0',
						skills: [
							{
								id: 'lookup',
								name: 'Bedrijfsgegevens opzoeken op KvK-nummer',
							},
						],
					},
				}
				agents.push(saved)
				await route.fulfill({ status: 201, json: saved })
				return
			}
			if (method === 'GET') {
				await route.fulfill({
					json: { results: agents, total: agents.length },
				})
				return
			}
			await route.fallback()
		})

		await page.goto('/index.php/settings/admin/hermiq', {
			waitUntil: 'domcontentloaded',
		})
		const section = page.getByTestId('remote-agents')
		await section.scrollIntoViewIfNeeded()
		await expect(section).toBeVisible({ timeout: 20_000 })

		await section.getByRole('button', { name: 'Add remote agent' }).click()
		const modal = page.getByRole('dialog')
		await modal.getByLabel('Name').fill('KvK-assistent')
		await modal
			.getByLabel('What the remote agent does')
			.fill('Looks up companies by KvK number')
		await modal
			.getByLabel('Integriq source that reaches the remote server')
			.click()
		await page.getByRole('option', { name: 'kvk-a2a' }).click()
		await modal.getByRole('button', { name: 'Save' }).click()

		await expect.poll(() => registered.length).toBe(1)
		expect(registered[0]).toMatchObject({
			name: 'KvK-assistent',
			source: 'kvk-a2a',
		})

		const row = section.getByTestId('remote-agent')
		await expect(row).toHaveCount(1)
		await expect(row).toContainText('KvK-assistent')
		await expect(row).toContainText('Bedrijfsgegevens opzoeken op KvK-nummer')
		await expect(
			row.getByRole('switch', { name: 'Agents may hand work to it' }),
		).not.toBeChecked()
	})
})
