/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spec-coverage e2e for chat-fork-and-share-link: the owner forks a session at
 * an answer from the Chat page, shares the session with a group through the
 * share dialog, a member of that group reads it read-only at
 * `/chat/shared/<uuid>`, the copied link shows "This session is not available"
 * to anyone else, and taking the share back closes it at once.
 *
 * Fixtures: one agent and one session of the admin with ten question and
 * answer exchanges, seeded through the OpenRegister objects API so no model is
 * needed; a group with one member, and an outsider. The member and the
 * outsider are not admins and sign in with an empty storage state.
 *
 * @spec openspec/changes/chat-fork-and-share-link/specs/session-fork/spec.md
 * @spec openspec/changes/chat-fork-and-share-link/specs/session-sharing/spec.md
 */

import type { Browser, BrowserContext, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import {
	appEnabled,
	appRoot,
	cleanupFamily,
	createSecondUser,
	deleteSecondUser,
	dismissTour,
	harvestToken,
	jsonHeaders,
	seedAgent,
	seedObject,
	TEST_PREFIX,
} from './_fixtures.ts'

const API = '/index.php/apps/hermiq/api'
const GROUP = `${TEST_PREFIX}-inkoop`
const TITLE = `${TEST_PREFIX} Omgevingsvisie 2040`
const EXCHANGES = 10

test.describe.configure({ mode: 'serial' })

test.describe('chat-fork-and-share-link: fork from here, share, copy link, revoke', () => {
	let token = ''
	let member = { uid: '', password: '' }
	let outsider = { uid: '', password: '' }
	let sessionUuid = ''
	let forkUuid = ''
	const answers: string[] = []

	/**
	 * A browser context signed in as a non-admin fixture user.
	 *
	 * @param browser The Playwright browser.
	 * @param user The user.
	 * @param user.uid The user id.
	 * @param user.password The password.
	 */
	async function contextAs(
		browser: Browser,
		user: { uid: string; password: string },
	): Promise<BrowserContext> {
		return browser.newContext({
			storageState: { cookies: [], origins: [] },
			httpCredentials: {
				username: user.uid,
				password: user.password,
				send: 'always',
			},
		})
	}

	/**
	 * Open the Chat page and the share dialog of the fixture session.
	 *
	 * @param page The admin page.
	 */
	async function openShareDialog(page: Page): Promise<void> {
		const root = await appRoot(page)
		await page.goto(`${root}/chat`)
		await dismissTour(page)
		const row = page
			.locator('li, .chat-page__row')
			.filter({ has: page.getByText(TITLE, { exact: true }) })
			.first()
		await expect(row).toBeVisible({ timeout: 20_000 })
		await row.getByRole('button', { name: 'Session actions' }).click()
		await page.getByTestId('chat-session-share').click()
		await expect(page.getByTestId('session-share-modal')).toBeVisible()
	}

	test.beforeAll(async ({ browser }) => {
		const page = await browser.newPage()
		token = await harvestToken(page)
		const headers = { ...jsonHeaders(token), 'OCS-APIRequest': 'true' }
		await page.request.post('/ocs/v1.php/cloud/groups?format=json', {
			headers,
			data: { groupid: GROUP },
		})
		// The owner shares with a group they are a member of.
		await page.request.post('/ocs/v1.php/cloud/users/admin/groups?format=json', {
			headers,
			data: { groupid: GROUP },
		})
		member = await createSecondUser(page.request, token, 'inkoop')
		await page.request.post(
			`/ocs/v1.php/cloud/users/${member.uid}/groups?format=json`,
			{
				headers,
				data: { groupid: GROUP },
			},
		)
		outsider = await createSecondUser(page.request, token, 'outsider')

		const agent = await seedAgent(page.request, token, {
			name: `${TEST_PREFIX}-beleid`,
		})
		const created = await page.request.post(`${API}/sessions`, {
			headers,
			data: { agentId: agent.id, title: TITLE },
		})
		expect(created.status(), await created.text()).toBe(201)
		sessionUuid = (await created.json()).uuid

		for (let n = 1; n <= EXCHANGES; n++) {
			await seedObject(page.request, token, 'agentsessionturn', {
				sessionId: sessionUuid,
				agentId: agent.id,
				role: 'user',
				content: `Vraag ${n} over de Omgevingsvisie 2040`,
			})
			const answer = await seedObject(
				page.request,
				token,
				'agentsessionturn',
				{
					sessionId: sessionUuid,
					agentId: agent.id,
					role: 'assistant',
					content: `Antwoord ${n}: de visie kiest voor verdichting rond de stations.`,
				},
			)
			answers.push(answer.id)
		}
		await page.close()
	})

	test.afterAll(async ({ browser }) => {
		const page = await browser.newPage()
		const headers = { ...jsonHeaders(token), 'OCS-APIRequest': 'true' }
		for (const uuid of [forkUuid, sessionUuid].filter(Boolean)) {
			await page.request
				.delete(`${API}/sessions/${uuid}`, { headers })
				.catch(() => undefined)
		}
		await cleanupFamily(page.request, token)
		await page.request
			.delete(`/ocs/v1.php/cloud/users/admin/groups?format=json`, {
				headers,
				data: { groupid: GROUP },
			})
			.catch(() => undefined)
		await deleteSecondUser(page.request, token, member.uid)
		await deleteSecondUser(page.request, token, outsider.uid)
		await page.request
			.delete(`/ocs/v1.php/cloud/groups/${GROUP}?format=json`, { headers })
			.catch(() => undefined)
		await page.close()
	})

	// @e2e session-fork::a-policy-advisor-tries-a-different-line-of-questioning
	test('the owner forks at the fourth answer and both sessions are listed', async ({
		page,
	}) => {
		const root = await appRoot(page)
		await page.goto(`${root}/chat`)
		await dismissTour(page)
		await page
			.getByText(TITLE, { exact: true })
			.first()
			.click({ timeout: 20_000 })
		const fork = page.getByTestId('chat-fork-from-here')
		await expect(fork).toHaveCount(EXCHANGES, { timeout: 20_000 })
		await fork.nth(3).click()

		await expect(
			page.getByText(`${TITLE} (fork)`, { exact: true }).first(),
		).toBeVisible({
			timeout: 20_000,
		})
		await expect(page.getByText(TITLE, { exact: true }).first()).toBeVisible()

		const headers = { ...jsonHeaders(token), 'OCS-APIRequest': 'true' }
		const list = await page.request.get(`${API}/sessions?limit=100`, { headers })
		const forked = ((await list.json()).results ?? []).find(
			(s: { title?: string }) => s.title === `${TITLE} (fork)`,
		)
		expect(forked, 'the fork is in the list').toBeTruthy()
		forkUuid = forked.uuid

		// Four exchanges: the question and answer turns up to the fourth answer.
		const copied = await page.request.get(
			`${API}/sessions/${forkUuid}/messages?limit=100`,
			{
				headers,
			},
		)
		const turns = (await copied.json()).results
		expect(turns).toHaveLength(8)
		expect(turns[7].content).toContain('Antwoord 4')

		const source = await page.request.get(
			`${API}/sessions/${sessionUuid}/messages?limit=100`,
			{
				headers,
			},
		)
		expect(
			(await source.json()).results,
			'the original keeps every turn',
		).toHaveLength(EXCHANGES * 2)
	})

	// @e2e session-sharing::a-case-handler-shares-an-analysis-with-the-inkoop-team
	test('the owner shares the session with a group and its members are told', async ({
		page,
	}) => {
		await openShareDialog(page)
		const modal = page.getByTestId('session-share-modal')
		await expect(modal.getByText('This session is not shared.')).toBeVisible()
		await modal.locator('input').first().fill(GROUP)
		// NcSelect appends its listbox to <body>, outside the dialog.
		await page
			.locator('.vs__dropdown-option', { hasText: GROUP })
			.first()
			.click({ timeout: 20_000 })
		await modal.getByTestId('session-share-add').click()
		const row = modal.getByTestId('session-share-row').filter({ hasText: GROUP })
		await expect(row).toBeVisible({ timeout: 20_000 })
		await expect(row).toContainText('Everyone in this group, now and later')

		if (await appEnabled(page.request, token, 'notifications')) {
			const listed = await page.request.get(
				'/ocs/v2.php/apps/notifications/api/v2/notifications?format=json',
				{
					headers: {
						'OCS-APIRequest': 'true',
						Accept: 'application/json',
						Authorization:
							'Basic '
							+ Buffer.from(
								`${member.uid}:${member.password}`,
							).toString('base64'),
					},
				},
			)
			expect(listed.status()).toBe(200)
			const mine = ((await listed.json()).ocs?.data ?? []).filter(
				(n: { app?: string; subject?: string; link?: string }) =>
					n.app === 'hermiq'
					&& (n.link ?? '').includes(`/chat/shared/${sessionUuid}`),
			)
			expect(
				mine.length,
				'the member is notified with a link to the shared view',
			).toBeGreaterThan(0)
			expect(mine[0].subject).toContain(TITLE)
		}
	})

	// @e2e session-sharing::a-colleague-reads-the-shared-analysis
	test('a member of the group reads the session and has no message box', async ({
		browser,
	}) => {
		const context = await contextAs(browser, member)
		const page = await context.newPage()
		const root = await appRoot(page)
		await page.goto(`${root}/chat/shared/${sessionUuid}`)
		const shared = page.getByTestId('shared-session')
		await expect(shared).toBeVisible({ timeout: 20_000 })
		await expect(shared).toContainText(TITLE)
		await expect(shared).toContainText('Antwoord 10')
		await expect(page.getByTestId('shared-session-by')).toContainText(
			'Shared by',
		)
		await expect(shared.locator('textarea')).toHaveCount(0)
		await expect(page.getByTestId('shared-session-unavailable')).toHaveCount(0)

		await page.goto(`${root}/chat`)
		await expect(
			page.getByTestId('chat-session-group-shared-with-you'),
		).toBeVisible({
			timeout: 20_000,
		})
		await expect(
			page
				.getByTestId('chat-session-row-shared-with-you')
				.filter({ hasText: TITLE }),
		).toBeVisible()
		await context.close()
	})

	// @e2e session-sharing::anne-pastes-the-link-in-a-talk-chat
	// @e2e session-sharing::the-link-reaches-someone-the-session-is-not-shared-with
	test('the copied link opens the session for a member and for nobody else', async ({
		page,
		browser,
		context,
	}) => {
		await context.grantPermissions(['clipboard-read', 'clipboard-write'])
		await openShareDialog(page)
		await page.getByTestId('session-share-copy-link').click()
		const link = await page.evaluate(() => navigator.clipboard.readText())
		expect(link).toContain(`/apps/hermiq/chat/shared/${sessionUuid}`)
		const url = new URL(link, page.url())

		const memberContext = await contextAs(browser, member)
		const memberPage = await memberContext.newPage()
		await memberPage.goto(url.pathname)
		await expect(memberPage.getByTestId('shared-session')).toContainText(TITLE, {
			timeout: 20_000,
		})
		await expect(memberPage.getByTestId('shared-session-by')).toContainText(
			'Shared by',
		)
		await memberContext.close()

		const outsiderContext = await contextAs(browser, outsider)
		const outsiderPage = await outsiderContext.newPage()
		await outsiderPage.goto(url.pathname)
		await expect(
			outsiderPage.getByTestId('shared-session-unavailable'),
		).toBeVisible({
			timeout: 20_000,
		})
		await expect(
			outsiderPage.getByText('This session is not available'),
		).toBeVisible()
		await expect(outsiderPage.getByText(TITLE)).toHaveCount(0)
		await outsiderContext.close()
	})

	// @e2e session-sharing::anne-takes-the-share-back
	test('taking the share back closes the session for the member at once', async ({
		page,
		browser,
	}) => {
		await openShareDialog(page)
		const modal = page.getByTestId('session-share-modal')
		await modal
			.getByTestId('session-share-row')
			.filter({ hasText: GROUP })
			.getByRole('button', { name: 'Remove' })
			.click()
		await expect(modal.getByText('This session is not shared.')).toBeVisible({
			timeout: 20_000,
		})

		const context = await contextAs(browser, member)
		const memberPage = await context.newPage()
		const root = await appRoot(memberPage)
		await memberPage.goto(`${root}/chat/shared/${sessionUuid}`)
		await expect(
			memberPage.getByTestId('shared-session-unavailable'),
		).toBeVisible({
			timeout: 20_000,
		})
		await expect(
			memberPage.getByText('This session is not available'),
		).toBeVisible()

		await memberPage.goto(`${root}/chat`)
		await expect(
			memberPage
				.getByTestId('chat-session-row-shared-with-you')
				.filter({ hasText: TITLE }),
		).toHaveCount(0)
		await context.close()
	})
})
