/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spec-coverage e2e — knowledge bases (memory-knowledge-bases, Task 6).
 *
 * Browser scenarios of openspec/changes/memory-knowledge-bases:
 *  - REQ-KB-001: the owner follows "Knowledge bases" from the Memory page, the
 *    list opens, and documents uploaded on the detail page land in the
 *    knowledge base's folder and show with an indexing state.
 *  - REQ-KB-003: a document only the owner can read carries the only-you
 *    notice on the owner's detail page; a non-owner is refused the sources
 *    and the upload (404), from a separate request context with an EMPTY
 *    storage state so it never rides on the admin session.
 *  - REQ-KB-004: a source labelled by integriq names the outside system.
 *  - REQ-KB-005: without a file scope on the vector facade the page says so
 *    instead of pretending to search.
 *  - The agent form offers the knowledge base picker (knowledgeBaseRefs).
 *
 * The answer-quality scenarios (REQ-KB-002 "cites the conditions", the
 * colleague who gets no passage from a private folder) need a vector backend
 * and a real model, and stay with the live check in tasks.md.
 *
 * Run against a disposable Nextcloud with hermiq + OpenRegister installed:
 *
 *     PLAYWRIGHT_BASE_URL=<your rig> NC_USER=admin NC_PASS=admin \
 *       npx playwright test -c tests/e2e/spec-coverage/playwright.config.ts knowledge-bases
 *
 * Covers openspec/changes/memory-knowledge-bases/specs/knowledge-bases/spec.md
 */

import type { APIRequestContext } from '@playwright/test'
import type { SecondUser } from './_fixtures.ts'

import { expect, request as playwrightRequest, test } from '@playwright/test'
import * as path from 'path'
import { BASE_URL } from '../base-url.ts'
import {
	appRoot,
	assertSecondUserAuthenticates,
	cleanupFamily,
	createSecondUser,
	deleteSecondUser,
	dismissTour,
	harvestToken,
	jsonHeaders,
	openAgentForm,
	seedAgent,
	seedObject,
	TEST_PREFIX,
} from './_fixtures.ts'

/** The OpenRegister schema slug of a knowledge base. */
const SCHEMA = 'agentknowledgebase'

/** A small PDF the Newman collection uploads too. */
const PDF = path.resolve(__dirname, '../../integration/fixtures/offerte-newman.pdf')

/** The default folder uploads land in when a knowledge base names none. */
const DEFAULT_FOLDER = 'Hermiq/Knowledge bases'

/** What the page says when the facade has no file scope (REQ-KB-005). */
const NO_SEARCH = 'Search is not available on this server. Ask your administrator to set up vector search in OpenRegister.'

/** The only-you notice on a file source (REQ-KB-003). */
const ONLY_YOU_FILE = 'Only you can read this file. Other people who use the agent get no answers from it. Share it with them, or use a team folder.'

/** The integriq label of the seeded outside source (REQ-KB-004). */
const INTEGRIQ_LABEL = 'Synced by integriq from SharePoint: Beleidsdocumenten'

/**
 * The knowledge base's sources as the owner's detail page reads them.
 *
 * @param req   A request context.
 * @param token The CSRF request-token (empty for a Basic-auth context).
 * @param id    The knowledge base id.
 * @return The HTTP status and body.
 */
async function readSources(
	req: APIRequestContext,
	token: string,
	id: string,
): Promise<{ status: number; body: any }> {
	const res = await req.get(`/index.php/apps/hermiq/api/knowledge-bases/${id}/sources`, {
		headers: token !== '' ? jsonHeaders(token) : { 'OCS-APIRequest': 'true', Accept: 'application/json' },
	})
	return { status: res.status(), body: await res.json().catch(() => null) }
}

test.describe('memory-knowledge-bases: pages, upload, notices and the owner-only sources', () => {
	let token = ''
	let uploadKb = ''
	let labelledKb = ''
	let second: SecondUser | null = null
	let secondCtx: APIRequestContext | null = null

	test.beforeAll(async ({ browser }) => {
		const page = await browser.newPage()
		token = await harvestToken(page)

		uploadKb = (await seedObject(page.request, token, SCHEMA, {
			name: `${TEST_PREFIX}-kb-upload`,
			description: 'Seeded by tests/e2e/spec-coverage (safe to delete)',
		})).id

		labelledKb = (await seedObject(page.request, token, SCHEMA, {
			name: `${TEST_PREFIX}-kb-integriq`,
			description: 'Seeded by tests/e2e/spec-coverage (safe to delete)',
			sources: [{
				type: 'folder',
				path: 'Integraties/SharePoint/Beleidsdocumenten',
				label: INTEGRIQ_LABEL,
			}],
		})).id

		second = await createSecondUser(page.request, token, 'kbreader')

		// A separate context as the second user. `storageState` is emptied on
		// purpose: omitted, it inherits the suite's ADMIN session and the
		// refusal below would test nothing. `send: 'always'` because Nextcloud
		// never answers the 401 challenge Playwright waits for.
		secondCtx = await playwrightRequest.newContext({
			baseURL: BASE_URL,
			storageState: { cookies: [], origins: [] },
			httpCredentials: { username: second.uid, password: second.password, send: 'always' },
		})
		await assertSecondUserAuthenticates(secondCtx, second.uid)

		await page.close()
	})

	test.afterAll(async ({ browser }) => {
		const page = await browser.newPage()
		const cleanupToken = await harvestToken(page)
		await cleanupFamily(page.request, cleanupToken, SCHEMA)
		await cleanupFamily(page.request, cleanupToken, 'agent')
		if (second !== null) {
			await deleteSecondUser(page.request, cleanupToken, second.uid)
		}
		await secondCtx?.dispose()
		await page.close()
	})

	test('the Memory page leads to the knowledge base list', async ({ page }) => {
		const root = await appRoot(page)
		await page.goto(`${root}/memory`)
		await dismissTour(page)

		const link = page.locator('.agent-memory__knowledge-bases')
		await expect(link).toBeVisible()
		await expect(link).toHaveText(/Knowledge bases/)
		await link.click()

		await expect(page).toHaveURL(/\/knowledge-bases\/?$/)
		await expect(page.getByText(`${TEST_PREFIX}-kb-upload`).first()).toBeVisible()
	})

	test('documents uploaded on the detail page land in its folder with an indexing state', async ({ page }) => {
		const root = await appRoot(page)
		await page.goto(`${root}/knowledge-bases/${uploadKb}`)
		await dismissTour(page)

		const widget = page.locator('.kb-sources')
		await expect(widget).toBeVisible()
		await expect(widget.locator('.kb-sources__empty')).toBeVisible()

		const uploaded = page.waitForResponse((res) =>
			res.url().includes(`/api/knowledge-bases/${uploadKb}/documents`)
			&& res.request().method() === 'POST',
		)
		await widget.locator('input.kb-sources__file-input').setInputFiles([PDF])
		expect((await uploaded).status(), 'the owner may upload').toBe(200)

		const item = widget.locator('.kb-sources__item').first()
		await expect(item).toBeVisible()
		await expect(item.locator('.kb-sources__name')).toContainText(`${DEFAULT_FOLDER}/${TEST_PREFIX}-kb-upload/`)
		await expect(item.locator('.kb-sources__name')).toContainText('offerte-newman')
		await expect(item.locator('.kb-sources__state')).not.toHaveText('')

		// The detail page reads what the server holds: one file source, in the folder.
		const { status, body } = await readSources(page.request, token, uploadKb)
		expect(status).toBe(200)
		const files = (body?.results ?? []).filter((s: any) => s.type === 'file')
		expect(files).toHaveLength(1)
		expect(typeof files[0].fileId).toBe('number')
		expect(String(files[0].path)).toContain(`${DEFAULT_FOLDER}/${TEST_PREFIX}-kb-upload/`)

		// REQ-KB-003: the upload sits in the admin's own, unshared Files.
		expect(files[0].onlyYou).toBe(true)
		await expect(item.locator('.kb-sources__notice')).toHaveText(ONLY_YOU_FILE)

		// REQ-KB-005: no file scope, no pretending.
		const notice = widget.getByText(NO_SEARCH)
		if (body?.searchAvailable === false) {
			await expect(notice).toBeVisible()
		} else {
			await expect(notice).toHaveCount(0)
		}
	})

	test('a source integriq fills names the outside system', async ({ page }) => {
		const root = await appRoot(page)
		await page.goto(`${root}/knowledge-bases/${labelledKb}`)
		await dismissTour(page)

		const item = page.locator('.kb-sources__item').filter({ hasText: 'Integraties/SharePoint/Beleidsdocumenten' })
		await expect(item).toBeVisible()
		await expect(item.locator('.kb-sources__label')).toHaveText(INTEGRIQ_LABEL)
	})

	test('a non-owner is refused the sources and the upload', async () => {
		expect(secondCtx, 'the second user context must exist').not.toBeNull()
		const ctx = secondCtx as APIRequestContext

		const sources = await readSources(ctx, '', uploadKb)
		expect(sources.status, 'the sources are the owner\'s only').toBe(404)

		const upload = await ctx.post(`/index.php/apps/hermiq/api/knowledge-bases/${uploadKb}/documents`, {
			headers: { 'OCS-APIRequest': 'true' },
			multipart: {
				file: { name: 'offerte-newman.pdf', mimeType: 'application/pdf', buffer: Buffer.from('%PDF-1.4\n%%EOF\n') },
			},
		})
		expect(upload.status(), 'anyone but the owner gets 404').toBe(404)
	})

	test('the agent form offers the knowledge base picker', async ({ page }) => {
		const agent = await seedAgent(page.request, token, { name: `${TEST_PREFIX}-kb-agent` })
		const root = await appRoot(page)
		await page.goto(`${root}/agents/${agent.id}`)
		await dismissTour(page)

		const form = await openAgentForm(page)
		const picker = form.getByRole('combobox', { name: 'Knowledge bases' })
		await expect(picker).toBeVisible()
		await picker.click()
		await expect(page.getByRole('option', { name: `${TEST_PREFIX}-kb-upload` })).toBeVisible()
	})
})
