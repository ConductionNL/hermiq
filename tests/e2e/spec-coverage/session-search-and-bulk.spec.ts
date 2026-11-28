/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spec-coverage e2e: searching the session list and acting on several sessions
 * at once on the Chat page (chat-session-search-and-bulk-delete).
 *
 * No model is needed: the turn the search finds is stored through the
 * OpenRegister object API, and archive, restore and delete never call one.
 * Every seeded session and agent carries the e2espec- family prefix; sessions
 * are deleted permanently and agents removed in afterAll.
 *
 * Run against a running Nextcloud with Hermiq + OpenRegister installed:
 *
 *     NEXTCLOUD_URL=http://nextcloud.local NC_USER=admin NC_PASS=admin \
 *       npx playwright test -c tests/e2e/spec-coverage session-search-and-bulk
 *
 * Covers openspec/changes/archive/2026-11-24-chat-session-search-and-bulk-delete/specs/session-surface/spec.md
 * (REQ-SSRCH-001, REQ-SSRCH-002, REQ-SSRCH-003).
 */

import type { APIRequestContext, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import {
	appRoot,
	cleanupFamily,
	dismissTour,
	harvestToken,
	jsonHeaders,
	seedAgent,
	seedObject,
	TEST_PREFIX,
} from './_fixtures.ts'

const API = '/index.php/apps/hermiq/api'

/** A word only the seeded search turn holds. */
const SEARCH_WORD = 'monumentenadvies'

let token = ''
let agentId = ''
const created: string[] = []

/**
 * Start a session of the signed-in person and remember it for cleanup.
 *
 * @param req   The request context.
 * @param title The session title.
 * @return The session uuid.
 */
async function startSession(req: APIRequestContext, title: string): Promise<string> {
	const res = await req.post(`${API}/sessions`, {
		headers: jsonHeaders(token),
		data: { agentId, title },
	})
	expect(res.status(), `start session ${title}`).toBe(201)
	const uuid = String((await res.json()).uuid ?? '')
	expect(uuid).not.toEqual('')
	created.push(uuid)
	return uuid
}

/**
 * Run a bulk action through the API, as set-up for a UI check.
 *
 * @param req    The request context.
 * @param action archive, restore or delete.
 * @param uuids  The sessions.
 * @return The per-uuid statuses.
 */
async function bulk(
	req: APIRequestContext,
	action: string,
	uuids: string[],
): Promise<string[]> {
	const res = await req.post(`${API}/sessions/bulk`, {
		headers: jsonHeaders(token),
		data: { action, uuids },
	})
	expect(res.status()).toBe(200)
	return ((await res.json()).results as Array<{ status: string }>).map(
		(r) => r.status,
	)
}

/**
 * Open the Chat page with the first-run overlay out of the way.
 *
 * @param page The page.
 * @return Nothing.
 */
async function openChat(page: Page): Promise<void> {
	await page.goto(`${await appRoot(page)}/chat`)
	await dismissTour(page)
}

/**
 * The session list row whose title is the given text.
 *
 * @param page  The page.
 * @param title The session title.
 * @return The row locator.
 */
function row(page: Page, title: string) {
	return page.getByTestId('chat-session-row').filter({ hasText: title })
}

test.describe('chat-session-search-and-bulk-delete', () => {
	test.beforeAll(async ({ browser }) => {
		const page = await browser.newPage()
		token = await harvestToken(page)
		agentId = (
			await seedAgent(page.request, token, {
				name: `${TEST_PREFIX}-search-agent`,
				prompt: 'Answer questions about planning advice.',
			})
		).id
		await page.close()
	})

	test.afterAll(async ({ browser }) => {
		const page = await browser.newPage()
		for (const uuid of created) {
			await page.request
				.delete(`${API}/sessions/${uuid}/permanent`, {
					headers: jsonHeaders(token),
				})
				.catch(() => null)
		}
		await cleanupFamily(page.request, token, 'agent')
		await page.close()
	})

	// @e2e session-surface::a-tax-officer-finds-the-session-about-a-woz-objection
	test('a search shows matching sessions with an excerpt and opens the turn', async ({
		page,
	}) => {
		const foundTitle = `${TEST_PREFIX}-search-found`
		const otherTitle = `${TEST_PREFIX}-search-other`
		const found = await startSession(page.request, foundTitle)
		await startSession(page.request, otherTitle)
		const turn = await seedObject(page.request, token, 'agentsessionturn', {
			sessionId: found,
			role: 'user',
			content: `Wat staat er in het ${SEARCH_WORD} over de Kerkstraat?`,
		})

		await openChat(page)
		await expect(row(page, otherTitle)).toBeVisible()

		await page.getByLabel('Search sessions').fill(SEARCH_WORD)

		await expect(row(page, foundTitle)).toBeVisible()
		await expect(row(page, otherTitle)).toHaveCount(0)
		await expect(
			row(page, foundTitle).getByTestId('chat-session-row-excerpt'),
		).toContainText(SEARCH_WORD)

		// Opening the result lands on the matching turn, highlighted.
		await row(page, foundTitle).click()
		const foundTurn = page.locator(`[data-turn-uuid="${turn.id}"]`)
		await expect(foundTurn).toBeVisible({ timeout: 30_000 })
		await expect(foundTurn).toHaveClass(/chat-page__message--found/)

		// Clearing the field brings the full list back.
		await page.getByRole('button', { name: 'Clear search' }).click()
		await expect(page.getByLabel('Search sessions')).toHaveValue('')
		await expect(row(page, otherTitle)).toBeVisible()
	})

	test('a search without matches says so', async ({ page }) => {
		await openChat(page)
		await page.getByLabel('Search sessions').fill(`${TEST_PREFIX}-nergens`)
		await expect(
			page.getByText(`No sessions match ${TEST_PREFIX}-nergens.`),
		).toBeVisible()
	})

	// @e2e session-surface::a-team-lead-clears-out-last-years-test-sessions
	test('rows ticked with the keyboard on the Archive tab are deleted in one action', async ({
		page,
	}) => {
		const first = `${TEST_PREFIX}-bulk-delete-1`
		const second = `${TEST_PREFIX}-bulk-delete-2`
		const a = await startSession(page.request, first)
		const b = await startSession(page.request, second)
		expect(await bulk(page.request, 'archive', [a, b])).toEqual(['ok', 'ok'])

		await openChat(page)
		await page
			.locator('.chat-page__tabs')
			.getByText('Archive', { exact: true })
			.click()
		await expect(row(page, first)).toBeVisible()
		await expect(row(page, second)).toBeVisible()

		await page.getByTestId('chat-session-select').click()
		await expect(page.getByTestId('chat-session-bulk-bar')).toBeVisible()

		// Space on a focused row ticks it.
		await row(page, first).focus()
		await page.keyboard.press('Space')
		await row(page, second).focus()
		await page.keyboard.press('Space')
		await expect(page.getByTestId('chat-session-bulk-count')).toHaveText(
			'2 selected',
		)

		await page.getByTestId('chat-session-bulk-delete').click()
		await expect(page.getByTestId('session-bulk-delete-question')).toHaveText(
			'Delete 2 sessions? This cannot be undone.',
		)
		await page.getByTestId('session-bulk-delete-confirm').click()

		await expect(row(page, first)).toHaveCount(0)
		await expect(row(page, second)).toHaveCount(0)

		for (const uuid of [a, b]) {
			const res = await page.request.get(`${API}/sessions/${uuid}`, {
				headers: jsonHeaders(token),
			})
			expect(res.status(), `session ${uuid} is gone`).toBe(404)
		}
	})

	test('archiving the open session in bulk shows the start surface', async ({
		page,
	}) => {
		const title = `${TEST_PREFIX}-bulk-archive-open`
		await startSession(page.request, title)

		await openChat(page)
		await row(page, title).click()
		await expect(page.getByTestId('chat-start-surface')).toHaveCount(0)

		await page.getByTestId('chat-session-select').click()
		await row(page, title).focus()
		await page.keyboard.press('Space')
		await expect(page.getByTestId('chat-session-bulk-count')).toHaveText(
			'1 selected',
		)
		await page.getByTestId('chat-session-bulk-archive').click()

		await expect(row(page, title)).toHaveCount(0)
		await expect(page.getByTestId('chat-start-surface')).toBeVisible()

		await page
			.locator('.chat-page__tabs')
			.getByText('Archive', { exact: true })
			.click()
		await expect(row(page, title)).toBeVisible()
	})
})
