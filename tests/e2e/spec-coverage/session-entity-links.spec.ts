/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * What a session is about (session-entity-links), as the companion shows it: the
 * sessions menu leads with the sessions about the open file, headed with its label;
 * a page without a file keeps the unscoped list; the session settings in the
 * titlebar list a session's links and remove one; the microphone precedes the
 * paperclip in the composer.
 *
 * The scoped list and the removal answer from the real routes in the nightly. A
 * link is recorded only when a turn is SENT from a page (D2), which needs a
 * model, so the scoped-list and removal scenarios serve the list from a fixture
 * through `page.route` and assert the requests the companion makes against the
 * route contract (`GET /api/conversations?linkedType=&linkedId=` with its
 * `scope`, `DELETE /api/sessions/{uuid}/links?type=&id=`). The server-side filter
 * and the owner-only removal are PHPUnit (`SessionControllerLinksTest`).
 *
 * Needs nextcloud-vue with ai-chat-sessions-about-the-page (ConductionNL/nextcloud-vue#2316).
 * Not run when written: it needs a live instance. Runs in the nightly.
 *
 * @e2e session-entity-links::the-scoped-list-names-its-scope
 * @e2e session-entity-links::no-context-means-no-scoping
 * @e2e session-entity-links::a-user-removes-a-link
 * @e2e session-entity-links::the-speech-control-sits-left-of-the-attachment-control
 *
 * @spec openspec/specs/session-entity-links/spec.md
 */

import type { APIRequestContext, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { dismissTour, harvestToken, TEST_PREFIX } from './_fixtures.ts'

const USER = process.env.NC_USER || 'admin'
const DAV = `/remote.php/dav/files/${USER}`

const FAB = '[data-testid="cn-ai-fab"]'
const COMPOSER = '[data-testid="cn-ai-input-textarea"]'
const SESSIONS_MENU = '[data-testid="cn-ai-panel-sessions"]'
const SESSION_LINKS = '[data-testid="cn-ai-panel-session-links"]'

const FILE_NAME = `${TEST_PREFIX}-subsidiebesluit.txt`
const SESSION_UUID = `${TEST_PREFIX}-session-about-the-file`
const SESSION_TITLE = `${TEST_PREFIX} About the decision`

/**
 * Put one small text file in the user's Files and return its file id.
 *
 * @param req   The request context (the user's session).
 * @param token The CSRF request-token.
 * @return The file id.
 */
async function putFile(req: APIRequestContext, token: string): Promise<number> {
	const put = await req.put(`${DAV}/${encodeURIComponent(FILE_NAME)}`, {
		headers: { requesttoken: token, 'Content-Type': 'text/plain' },
		data: 'Besluit op de aanvraag.\n',
	})
	expect([201, 204], `PUT HTTP ${put.status()}`).toContain(put.status())
	const fileId = parseInt(put.headers()['oc-fileid'] ?? '', 10)
	expect(fileId).toBeGreaterThan(0)
	return fileId
}

/**
 * Open the companion and wait for its composer.
 *
 * @param page The page.
 * @return Nothing.
 */
async function openCompanion(page: Page): Promise<void> {
	await dismissTour(page)
	const fab = page.locator(FAB).first()
	await expect(fab).toBeVisible({ timeout: 30_000 })
	await fab.click()
	await expect(page.locator(COMPOSER)).toBeVisible({ timeout: 20_000 })
}

/**
 * Open an NcActions menu by its wrapper test id and return the open menu.
 *
 * @param page   The page.
 * @param testId The wrapper selector.
 * @return Nothing.
 */
async function openMenu(page: Page, testId: string): Promise<void> {
	await page.locator(`${testId} button`).first().click()
}

/**
 * Serve the sessions about the file from a fixture, with the `scope` label.
 *
 * @param page   The page.
 * @param fileId The file the session is about.
 * @return The removals the companion asked for.
 */
async function serveSessionsAbout(page: Page, fileId: number): Promise<string[]> {
	const link = { type: 'file', id: String(fileId), label: FILE_NAME }
	const session = { uuid: SESSION_UUID, title: SESSION_TITLE, links: [link], updatedAt: new Date().toISOString() }
	const removals: string[] = []
	await page.route(/\/apps\/hermiq\/api\/conversations\?.*linkedType=/, (route) => route.fulfill({
		json: { results: [session], total: 1, limit: 50, offset: 0, scope: { type: 'file', id: String(fileId), label: FILE_NAME } },
	}))
	await page.route(new RegExp(`/apps/hermiq/api/conversations/${SESSION_UUID}/messages`), (route) => route.fulfill({
		json: { results: [], messages: [] },
	}))
	await page.route(new RegExp(`/apps/hermiq/api/sessions/${SESSION_UUID}/links`), (route) => {
		removals.push(route.request().url())
		return route.fulfill({ json: { ...session, links: [] } })
	})
	return removals
}

test.describe('What a session is about', () => {
	let fileId = 0

	test.beforeEach(async ({ page }) => {
		const token = await harvestToken(page)
		if (fileId === 0) {
			fileId = await putFile(page.request, token)
		}
	})

	test.afterAll(async ({ browser }) => {
		const page = await browser.newPage()
		const token = await harvestToken(page)
		await page.request.delete(`${DAV}/${encodeURIComponent(FILE_NAME)}`, { headers: { requesttoken: token } })
		await page.close()
	})

	test('the scoped list names its scope and keeps the way to all sessions', async ({ page }) => {
		await serveSessionsAbout(page, fileId)
		const scoped = page.waitForRequest((req) => req.url().includes('linkedType=file') && req.url().includes(`linkedId=${fileId}`))
		await page.goto(`/index.php/apps/files/files/${fileId}?dir=/`)
		await openCompanion(page)
		await scoped

		await openMenu(page, SESSIONS_MENU)
		await expect(page.getByText(new RegExp(`(Sessions about|Sessies over) ${FILE_NAME}`))).toBeVisible({ timeout: 10_000 })
		await expect(page.getByRole('menuitem', { name: SESSION_TITLE })).toBeVisible()
		await expect(page.getByRole('menuitem', { name: /View all conversations|Alle gesprekken bekijken/ })).toBeVisible()
	})

	test('a page with no file keeps the unscoped list, and asks for no scope', async ({ page }) => {
		const scopedRequests: string[] = []
		page.on('request', (req) => {
			if (req.url().includes('linkedType=')) {
				scopedRequests.push(req.url())
			}
		})
		await page.goto('/index.php/apps/files/')
		await openCompanion(page)

		await openMenu(page, SESSIONS_MENU)
		await expect(page.getByText(/Recent conversations|Recente gesprekken/)).toBeVisible({ timeout: 10_000 })
		await expect(page.getByText(/Sessions about|Sessies over/)).toHaveCount(0)
		expect(scopedRequests).toEqual([])
	})

	test('a user removes a link from the session settings, and the session leaves the scoped list', async ({ page }) => {
		const removals = await serveSessionsAbout(page, fileId)
		await page.goto(`/index.php/apps/files/files/${fileId}?dir=/`)
		await openCompanion(page)

		await openMenu(page, SESSIONS_MENU)
		await page.getByRole('menuitem', { name: SESSION_TITLE }).click()

		await openMenu(page, SESSION_LINKS)
		const remove = page.getByRole('menuitem', { name: new RegExp(FILE_NAME) })
		await expect(remove).toBeVisible({ timeout: 10_000 })
		await remove.click()

		await expect.poll(() => removals.length).toBe(1)
		const url = new URL(removals[0], 'http://x')
		expect(url.searchParams.get('type')).toBe('file')
		expect(url.searchParams.get('id')).toBe(String(fileId))
		await expect(page.getByText(/This session is not linked to anything|Deze sessie is nergens aan gekoppeld/)).toBeVisible()

		await page.keyboard.press('Escape')
		await openMenu(page, SESSIONS_MENU)
		await expect(page.getByText(/Recent conversations|Recente gesprekken/)).toBeVisible()
	})

	test('the speech control sits left of the attachment control', async ({ page }) => {
		await page.goto('/index.php/apps/files/')
		await openCompanion(page)

		const mic = page.locator('[data-testid="cn-ai-input-mic"]')
		test.skip(await mic.count() === 0, 'This browser offers no speech input, so no microphone is rendered.')
		const precedes = await page.evaluate(() => {
			const micEl = document.querySelector('[data-testid="cn-ai-input-mic"]')
			const attachEl = document.querySelector('[data-testid="cn-ai-input-attach"]')
			return micEl !== null && attachEl !== null
				&& (micEl.compareDocumentPosition(attachEl) & Node.DOCUMENT_POSITION_FOLLOWING) !== 0
		})
		expect(precedes, 'the microphone precedes the paperclip in the composer row').toBe(true)
	})
})
