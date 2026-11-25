/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spec-coverage e2e for chat-about-a-document-cites-its-sources: a turn about
 * one document or one folder, its numbered sources, and the source links under
 * every answer on the Chat page.
 *
 * Covered openspec scenarios (the `// @e2e` line above each test is the hook):
 *   - document-chat::an-unscoped-answer-shows-linked-sources (REQ-DCHAT-005):
 *     real. Two files are uploaded to the admin's Files and an answer turn
 *     carrying them as `sources` is seeded through the OpenRegister objects
 *     API, so no model is needed. The Chat page must list both under the
 *     answer, each as a link to `/f/{fileId}`, and the link must open the file.
 *
 * The scoped scenarios (Tasks 4 and 5 are built: nextcloud-vue 2.215.0 sends
 * `scope` from the companion's page context, filinq passes the open document)
 * run against the real server, because what they check (which passages
 * retrieval kept, and whether the model was asked at all) happens there. They
 * need a chat model behind the instance; a stub that always answers with a
 * sentence citing [1] is enough, since retrieval, not the model, picks the
 * sources:
 *   - document-chat::a-question-about-the-open-document-uses-only-that-document
 *   - document-chat::a-question-about-a-dossier-folder-stays-inside-it
 *   - document-chat::nothing-in-scope-matches
 *   - document-chat::the-person-asks-from-a-filinq-document-page (skips
 *     without filinq)
 * Their documents are plain text. A scoped file's text is read directly when it
 * is text; a PDF's text comes from OpenRegister's file text facade
 * (`extractText`, the vector-rag change), which these scenarios do not test.
 *
 * Not written here, per their `@e2e exclude` lines in the spec:
 *   - a-document-the-person-cannot-open-is-refused (negative API path, PHPUnit
 *     ContextRetrievalScopeTest)
 *   - claims-point-at-numbered-sources (prompt and storage contract, PHPUnit
 *     with a stub model)
 *   - removing-the-chip-widens-the-question-again (component behaviour, vitest
 *     in nextcloud-vue)
 *
 * Every seeded object and file carries the e2espec- family prefix and is
 * removed in afterAll.
 *
 * Run against a disposable Nextcloud with hermiq + OpenRegister installed:
 *
 *     PLAYWRIGHT_BASE_URL=<your rig> NC_USER=admin NC_PASS=admin \
 *       npx playwright test -c tests/e2e/spec-coverage/playwright.config.ts document-chat
 *
 * Covers openspec/changes/chat-about-a-document-cites-its-sources/specs/document-chat/spec.md
 */

import type { APIRequestContext, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import {
	appEnabled,
	appRoot,
	cleanupFamily,
	deleteObject,
	dismissTour,
	harvestToken,
	jsonHeaders,
	seedAgent,
	seedObject,
	TEST_PREFIX,
} from './_fixtures.ts'

const API = '/index.php/apps/hermiq/api'
const USER = process.env.NC_USER || 'admin'
const DAV = `/remote.php/dav/files/${USER}`

/** The OpenRegister schema slug of one turn of a session. */
const TURN_SCHEMA = 'agentsessionturn'

/** The companion launcher and its composer (nextcloud-vue CnAiCompanion). */
const FAB = '[data-testid="cn-ai-fab"]'
const COMPOSER = '[data-testid="cn-ai-input-textarea"]'

/** The chip the companion shows for a file or folder scope (REQ-DCHAT-004). */
const DOCUMENT_CHIP = /Over dit document|About this document/
const FOLDER_CHIP = /Over deze map|About this folder/


/** The two files the unscoped answer rests on. */
const FILE_A = `${TEST_PREFIX}-besluit-2026-0042.pdf`
const FILE_B = `${TEST_PREFIX}-aanvraag-omgevingsvergunning.pdf`

/**
 * The documents the scoped turns read (plain text, read directly): a decision
 * that answers "Wat is de beslistermijn?", and a permit application about a
 * building, which shares no word with a question about parking fines.
 */
const DOC_A = `${TEST_PREFIX}-besluit-2026-0042.txt`
const DOC_A_TEXT = 'Besluit 2026-0042 op de aanvraag van de heer Jansen.\n\nDe beslistermijn voor deze aanvraag bedraagt acht weken na ontvangst.\n'
const DOC_B = `${TEST_PREFIX}-aanvraag-omgevingsvergunning.txt`
const DOC_B_TEXT = 'Aanvraag omgevingsvergunning van de heer Jansen.\n\nHij wil een dakkapel bouwen aan de Dorpsstraat 1. Bijgevoegd zijn tekeningen en een situatieschets.\n'

/** The dossier folder, its document, and a file outside it that matches better (REQ-DCHAT-002). */
const DOSSIER = `${TEST_PREFIX}-dossier-2026-0042`
const DOSSIER_DOC = 'besluit.txt'
const DOSSIER_DOC_TEXT = 'In dit dossier van de heer Jansen geldt een beslistermijn van acht weken.\n'
const OUTSIDE = `${TEST_PREFIX}-beslistermijnen-overzicht.txt`
const OUTSIDE_TEXT = 'Overzicht beslistermijn: de beslistermijn per zaaktype. Elke beslistermijn staat hier, en een beslistermijn kan worden verlengd.\n'

const SESSION_TITLE = `${TEST_PREFIX} Bronnen bij een antwoord`

/**
 * Put one small PDF in the admin's Files and return its file id.
 *
 * Nextcloud answers a WebDAV PUT with `OC-FileId` (`00000123oc…`): the leading
 * digits are the file id that `/f/{fileId}` opens.
 *
 * @param req   The request context (admin session).
 * @param token The CSRF request-token.
 * @param path  The path under the admin's Files.
 * @return The file id.
 */
async function putPdf(
	req: APIRequestContext,
	token: string,
	path: string,
): Promise<number> {
	const put = await req.put(`${DAV}/${path}`, {
		headers: { requesttoken: token, 'Content-Type': 'application/pdf' },
		data: '%PDF-1.4\n%%EOF\n',
	})
	expect([201, 204], `PUT ${path} HTTP ${put.status()}`).toContain(put.status())
	const fileId = parseInt(put.headers()['oc-fileid'] ?? '', 10)
	expect(fileId, `PUT ${path} must answer with an OC-FileId`).toBeGreaterThan(0)
	return fileId
}

/**
 * Put one plain-text document in the admin's Files and return its file id.
 *
 * @param req   The request context (admin session).
 * @param token The CSRF request-token.
 * @param path  The path under the admin's Files.
 * @param text  The document's text.
 * @return The file id.
 */
async function putText(
	req: APIRequestContext,
	token: string,
	path: string,
	text: string,
): Promise<number> {
	const put = await req.put(`${DAV}/${path}`, {
		headers: { requesttoken: token, 'Content-Type': 'text/plain' },
		data: text,
	})
	expect([201, 204], `PUT ${path} HTTP ${put.status()}`).toContain(put.status())
	const fileId = parseInt(put.headers()['oc-fileid'] ?? '', 10)
	expect(fileId, `PUT ${path} must answer with an OC-FileId`).toBeGreaterThan(0)
	return fileId
}

/**
 * Open the AI companion on the current page and wait for its composer.
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
 * Ask the companion a question and return the body of the turn it sent.
 *
 * @param page     The page, with the companion open.
 * @param question The question.
 * @return The JSON body of the streamed turn.
 */
async function askCompanion(
	page: Page,
	question: string,
): Promise<Record<string, unknown>> {
	const sent = page.waitForRequest(
		(r) => r.url().includes('/apps/hermiq/api/chat/stream') && r.method() === 'POST',
	)
	await page.locator(COMPOSER).fill(question)
	await page.locator(COMPOSER).press('Enter')
	return (await sent).postDataJSON() as Record<string, unknown>
}

/**
 * The file ids of every source a streamed answer carries.
 *
 * Walks each `data:` frame of the server-sent stream and collects the
 * `file_id` of every entry in any `sources` list it holds.
 *
 * @param stream The raw text/event-stream body.
 * @return The file ids, in the order they were sent.
 */
function sourceFileIds(stream: string): number[] {
	const ids: number[] = []
	const walk = (value: unknown): void => {
		if (Array.isArray(value)) {
			value.forEach(walk)
			return
		}
		if (value === null || typeof value !== 'object') {
			return
		}
		for (const [key, inner] of Object.entries(value as Record<string, unknown>)) {
			if (key === 'sources' && Array.isArray(inner)) {
				for (const source of inner as Array<Record<string, unknown>>) {
					const id = Number(source?.file_id ?? source?.fileId)
					if (Number.isFinite(id) && id > 0) {
						ids.push(id)
					}
				}
			} else {
				walk(inner)
			}
		}
	}
	for (const line of stream.split('\n')) {
		if (line.startsWith('data:') === false) {
			continue
		}
		try {
			walk(JSON.parse(line.slice(5).trim()))
		} catch {
			// Not every frame is JSON (keep-alives); those carry no sources.
		}
	}
	return ids
}

test.describe.configure({ mode: 'serial' })

test.describe('chat-about-a-document-cites-its-sources: scoped turns and linked sources', () => {
	let token = ''
	let agentId = ''
	let sessionUuid = ''
	let fileA = 0
	let fileB = 0
	let docA = 0
	let docB = 0
	let dossierFolder = 0
	let dossierFile = 0
	let outsideFile = 0
	const turnIds: string[] = []

	test.beforeAll(async ({ browser }) => {
		const page = await browser.newPage()
		token = await harvestToken(page)
		const headers = { ...jsonHeaders(token), 'OCS-APIRequest': 'true' }

		fileA = await putPdf(page.request, token, FILE_A)
		fileB = await putPdf(page.request, token, FILE_B)

		docA = await putText(page.request, token, DOC_A, DOC_A_TEXT)
		docB = await putText(page.request, token, DOC_B, DOC_B_TEXT)

		// A dossier folder with a document in it, and a better match outside it.
		const mkcol = await page.request.fetch(`${DAV}/${DOSSIER}`, {
			method: 'MKCOL',
			headers: { requesttoken: token },
		})
		dossierFolder = parseInt(mkcol.headers()['oc-fileid'] ?? '', 10)
		dossierFile = await putText(page.request, token, `${DOSSIER}/${DOSSIER_DOC}`, DOSSIER_DOC_TEXT)
		outsideFile = await putText(page.request, token, OUTSIDE, OUTSIDE_TEXT)

		agentId = (await seedAgent(page.request, token, {
			name: `${TEST_PREFIX}-bronnen`,
			prompt: 'Answer from the documents and cite them.',
		})).id

		const created = await page.request.post(`${API}/sessions`, {
			headers,
			data: { agentId, title: SESSION_TITLE },
		})
		expect(created.status(), await created.text()).toBe(201)
		sessionUuid = (await created.json()).uuid

		// An unscoped question and an answer resting on two files. Stored as the
		// engine stores them (ResponseGenerationHandler): `sources` in citation
		// order, each file source with its file id and, when known, its page.
		const question = await seedObject(page.request, token, TURN_SCHEMA, {
			sessionId: sessionUuid,
			agentId,
			role: 'user',
			content: 'Wat is de beslistermijn voor deze aanvraag?',
		})
		turnIds.push(question.id)
		const answer = await seedObject(page.request, token, TURN_SCHEMA, {
			sessionId: sessionUuid,
			agentId,
			role: 'assistant',
			content: 'De beslistermijn is acht weken [1], met één verlenging van zes weken [2].',
			sources: [
				{
					id: String(fileA),
					file_id: fileA,
					type: 'file',
					name: FILE_A,
					text: 'De beslistermijn bedraagt acht weken.',
					page: 2,
				},
				{
					id: String(fileB),
					file_id: fileB,
					type: 'file',
					name: FILE_B,
					text: 'De termijn kan eenmaal met zes weken worden verlengd.',
				},
			],
		})
		turnIds.push(answer.id)
		await page.close()
	})

	test.afterAll(async ({ browser }) => {
		const page = await browser.newPage()
		const cleanupToken = await harvestToken(page)
		const headers = { ...jsonHeaders(cleanupToken), 'OCS-APIRequest': 'true' }
		for (const id of turnIds) {
			await deleteObject(page.request, cleanupToken, TURN_SCHEMA, id)
		}
		if (sessionUuid !== '') {
			await page.request
				.delete(`${API}/sessions/${sessionUuid}`, { headers })
				.catch(() => undefined)
			await page.request
				.delete(`${API}/sessions/${sessionUuid}/permanent`, { headers })
				.catch(() => undefined)
		}
		for (const path of [FILE_A, FILE_B, DOC_A, DOC_B, OUTSIDE, DOSSIER]) {
			await page.request
				.delete(`${DAV}/${path}`, { headers: { requesttoken: cleanupToken } })
				.catch(() => undefined)
		}
		await cleanupFamily(page.request, cleanupToken, 'agent')
		await page.close()
	})

	// @e2e document-chat::an-unscoped-answer-shows-linked-sources
	test('an unscoped answer lists both files it rests on, each as a link that opens it', async ({
		page,
	}) => {
		await page.goto(`${await appRoot(page)}/chat`)
		await dismissTour(page)
		await page
			.getByText(SESSION_TITLE, { exact: true })
			.first()
			.click({ timeout: 20_000 })

		const answer = page
			.locator('.chat-page__message--assistant')
			.filter({ hasText: 'De beslistermijn is acht weken' })
		await expect(answer).toBeVisible({ timeout: 20_000 })

		const sources = answer.locator('.chat-page__sources')
		await expect(sources).toBeVisible()
		await expect(sources).toContainText('Sources')

		// In the stored order, numbered, each a link to its file.
		const rows = sources.locator('.chat-page__source')
		await expect(rows).toHaveCount(2)
		await expect(rows.nth(0)).toContainText('[1]')
		await expect(rows.nth(0)).toContainText(FILE_A)
		await expect(rows.nth(0).locator('.chat-page__source-page')).toHaveText('page 2')
		await expect(rows.nth(1)).toContainText('[2]')
		await expect(rows.nth(1)).toContainText(FILE_B)

		const linkA = sources.getByRole('link', { name: FILE_A })
		const linkB = sources.getByRole('link', { name: FILE_B })
		await expect(linkA).toHaveAttribute('href', new RegExp(`/f/${fileA}$`))
		await expect(linkB).toHaveAttribute('href', new RegExp(`/f/${fileB}$`))

		// The link opens the file: Nextcloud resolves /f/{id} into Files at that file.
		const [opened] = await Promise.all([
			page.waitForEvent('popup'),
			linkA.click(),
		])
		await opened.waitForLoadState('domcontentloaded')
		await expect(opened).toHaveURL(new RegExp(`(/f/|/files/|fileid=)${fileA}\\b`))
		await opened.close()
	})

	// @e2e document-chat::a-question-about-the-open-document-uses-only-that-document
	test('a question about the open document uses only that document', async ({ page }) => {
		// The companion takes the file id from the open file's URL (src/companion.js).
		await page.goto(`/index.php/apps/files/files/${docA}?dir=/&openfile=true`)
		await openCompanion(page)
		await expect(page.getByText(DOCUMENT_CHIP).first()).toBeVisible()

		const body = await askCompanion(page, 'Wat is de beslistermijn?')
		expect(body.scope).toEqual({ type: 'file', fileId: docA })

		// Every source of the answer is that one file; the dossier document and
		// the overview mention the same term and are not used.
		const links = page.getByRole('link', { name: /\.txt/ })
		await expect(links.first()).toBeVisible({ timeout: 90_000 })
		for (const link of await links.all()) {
			await expect(link).toContainText(DOC_A)
			await expect(link).toHaveAttribute('href', new RegExp(`/f/${docA}\\b`))
		}
	})

	// @e2e document-chat::a-question-about-a-dossier-folder-stays-inside-it
	test('a question about a dossier folder stays inside it', async ({ page }) => {
		// A folder scope is set by a dossier page (filinq, pageKind "folder");
		// the turn it sends is sent here as such a page sends it.
		const reply = await page.request.post(`${API}/chat/stream`, {
			headers: { ...jsonHeaders(token), Accept: 'text/event-stream' },
			data: {
				message: 'Wat is de beslistermijn?',
				content: 'Wat is de beslistermijn?',
				newThread: true,
				agentUuid: agentId,
				scope: { type: 'folder', fileId: dossierFolder },
			},
			timeout: 90_000,
		})
		const stream = await reply.text()
		expect(reply.status(), stream.slice(0, 500)).toBe(200)

		const fileIds = sourceFileIds(stream)
		expect(fileIds.length, stream.slice(-1500)).toBeGreaterThan(0)
		// The file outside the folder matches the question better, and is not used.
		expect(fileIds).not.toContain(outsideFile)
		for (const id of fileIds) {
			expect(id).toBe(dossierFile)
		}
	})

	// @e2e document-chat::nothing-in-scope-matches
	test('a question the document does not answer says so and lists no sources', async ({ page }) => {
		await page.goto(`/index.php/apps/files/files/${docB}?dir=/&openfile=true`)
		await openCompanion(page)
		await expect(page.getByText(DOCUMENT_CHIP).first()).toBeVisible()

		const body = await askCompanion(page, 'Hoe hoog is de boete voor fout parkeren?')
		expect(body.scope).toEqual({ type: 'file', fileId: docB })

		await expect(
			page.getByText(/does not answer|geeft geen antwoord/i).first(),
		).toBeVisible({ timeout: 90_000 })
		await expect(page.getByRole('link', { name: DOC_B })).toHaveCount(0)
	})

	// @e2e document-chat::the-person-asks-from-a-filinq-document-page
	test('on a filinq document page the chip reads "Over dit document" and the answer links its sources', async ({
		page,
	}) => {
		test.fixme(true, `${NO_SCOPE_SCREEN} filinq does not pass the open document to the companion yet (tasks.md Task 5).`)
		test.skip(
			!(await appEnabled(page.request, token, 'filinq')),
			'filinq is not installed on this instance',
		)

		// filinq's document route is not in this repository; confirm the path when
		// Task 5 lands. The file id is the document the page shows.
		await page.goto(`/index.php/apps/filinq/documents/${fileA}`)
		await openCompanion(page)
		await expect(page.getByText(DOCUMENT_CHIP).first()).toBeVisible()

		const body = await askCompanion(page, 'Wat is de beslistermijn?')
		expect(body.scope).toEqual({ type: 'file', fileId: fileA })

		const link = page.getByRole('link', { name: FILE_A }).first()
		await expect(link).toBeVisible({ timeout: 90_000 })
		await expect(link).toHaveAttribute('href', new RegExp(`/f/${fileA}\\b`))
	})
})
