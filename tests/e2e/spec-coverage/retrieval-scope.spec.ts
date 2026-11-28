/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spec-coverage e2e — retrieval scope and validated documents
 * (memory-retrieval-scope, Tasks 2 and 5).
 *
 * Browser scenarios of openspec/changes/memory-retrieval-scope:
 *  - REQ-RSCOPE-002 "An administrator sets up validation": in the
 *    "Validated documents" section of hermiq's admin settings the
 *    administrator picks the group that may put the tag on files and chooses
 *    "Create the Validated tag". The tag then exists as a restricted Nextcloud
 *    tag (visible to everyone, settable only by that group), hermiq's
 *    setting names it, and a member of another group is refused when tagging
 *    a file of their own with it.
 *  - REQ-RSCOPE-004 "A reviewer checks an abstention": a seeded abstained turn
 *    in a session shows, under the answer, the abstention line, validated mode
 *    with the floor of 0.55, no passage at or above it and a best match of 0.41.
 *
 * Left out, each marked `@e2e exclude` in the spec:
 *  - REQ-RSCOPE-001 "A legal advisor limits the agent to recent team
 *    documents": needs an indexed vector backend; PHPUnit on the resolver.
 *  - REQ-RSCOPE-003 "A citizen question outside the validated documents":
 *    proving no provider call needs a counter on the provider; PHPUnit on the
 *    engine with a fake driver.
 *  - REQ-RSCOPE-003 "A session cannot switch objects back on": a crafted
 *    request body; PHPUnit and Newman.
 * The live check in tasks.md (tag one file, ask a question it answers and one
 * it does not) needs a real model and a vector store, and stays a live check.
 *
 * 🔑 HOW THE TWO SCENARIOS ARE MADE REPEATABLE.
 *  - The validated tag setting cannot be unset, and once a tag is named the
 *    section shows its save button (`validated-tag-save`) instead of the create
 *    button. The section's first read is therefore answered through page.route with the
 *    unset state; the create itself goes to the real backend, so the tag, its
 *    groups and the stored setting are real. The tag and group carry
 *    TEST_PREFIX so a run never takes over an existing "Validated" tag, and
 *    afterAll names the previous tag again (when there was one) and deletes
 *    the test tag, its group and the second user.
 *  - "cannot tag a file with it in Files" is asserted on the WebDAV route Files itself
 *    uses to tag a file (`systemtags-relations`), from a separate request
 *    context with an EMPTY storage state so it never rides on the admin session.
 *  - The abstained turn is seeded as an `agentsessionturn` object carrying the
 *    `retrievalScope` record MessageHistoryHandler::storeMessage() writes. An
 *    abstention is decided without the model, but reaching it for real needs
 *    the vector store; the seed is the stored result the reviewer reads.
 *
 * Run against a disposable Nextcloud with hermiq + OpenRegister installed:
 *
 *     PLAYWRIGHT_BASE_URL=<your rig> NC_USER=admin NC_PASS=admin \
 *       npx playwright test -c tests/e2e/spec-coverage/playwright.config.ts retrieval-scope
 *
 * Covers openspec/changes/memory-retrieval-scope/specs/retrieval-scope/spec.md
 */

import type { APIRequestContext } from '@playwright/test'
import type { SecondUser } from './_fixtures.ts'

import { expect, request as playwrightRequest, test } from '@playwright/test'
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
	resolveRegisterSchema,
	seedAgent,
	seedObject,
	TEST_PREFIX,
} from './_fixtures.ts'

const API = '/index.php/apps/hermiq/api'

/** The group that may mark a document as validated (the spec's `informatiebeheer`). */
const GROUP = `${TEST_PREFIX}-informatiebeheer`

/** The name the test tag is created under. */
const TAG_NAME = `${TEST_PREFIX}-Validated`

/** The agent's abstention message, as the change's seed data words it. */
const ABSTENTION = 'Dit kan ik niet beantwoorden op basis van de gevalideerde documenten. Neem contact op met het Klantcontactcentrum.'

/** The line the Chat page shows under an abstention (decision D5). */
const ABSTAINED_LINE = 'No validated document matched this question. The assistant did not answer from other knowledge.'

/** The PROPFIND body asking for a system tag's restriction and groups. */
const TAG_PROPS = '<?xml version="1.0"?>'
	+ '<d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns"><d:prop>'
	+ '<oc:display-name/><oc:user-visible/><oc:groups/>'
	+ '</d:prop></d:propfind>'

/** The PROPFIND body asking for a file's id. */
const FILEID_PROPS = '<?xml version="1.0"?>'
	+ '<d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns"><d:prop>'
	+ '<oc:fileid/></d:prop></d:propfind>'

/**
 * The text of one `oc:` property in a PROPFIND answer, or '' when absent.
 *
 * @param xml  The multistatus body.
 * @param prop The property's local name.
 * @return The property text.
 */
function ocProp(xml: string, prop: string): string {
	const match = xml.match(new RegExp(`<oc:${prop}>([^<]*)</oc:${prop}>`))
	return match ? match[1].trim() : ''
}

/**
 * Read the validated tag setting as an administrator.
 *
 * @param req   The request context.
 * @param token The CSRF request-token.
 * @return The setting.
 */
async function readSetting(
	req: APIRequestContext,
	token: string,
): Promise<{ tagId: string | null; name: string | null; groups: string[] }> {
	const res = await req.get(`${API}/settings/validated-tag`, {
		headers: jsonHeaders(token),
	})
	expect(res.status(), 'the administrator reads the setting').toBe(200)
	return res.json()
}

test.describe('memory-retrieval-scope: the validated tag and the recorded scope', () => {
	let token = ''
	let previous: { tagId: string | null; groups: string[] } = { tagId: null, groups: [] }
	let createdTagId = ''
	let second: SecondUser | null = null
	let secondCtx: APIRequestContext | null = null
	const sessions: string[] = []

	test.beforeAll(async ({ browser }) => {
		const page = await browser.newPage()
		token = await harvestToken(page)
		const headers = { ...jsonHeaders(token), 'OCS-APIRequest': 'true' }

		const before = await readSetting(page.request, token)
		previous = { tagId: before.tagId, groups: before.groups ?? [] }

		await page.request.post('/ocs/v1.php/cloud/groups?format=json', {
			headers,
			data: { groupid: GROUP },
		})

		// A member of another group: the second user is in none of the tag's groups.
		second = await createSecondUser(page.request, token, 'zaakbehandelaar')
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
		const headers = { ...jsonHeaders(cleanupToken), 'OCS-APIRequest': 'true' }

		// Name the tag the instance had before, so the run leaves the setting as it found it.
		if (previous.tagId) {
			await page.request
				.put(`${API}/settings/validated-tag`, {
					headers,
					data: { tagId: previous.tagId, groups: previous.groups },
				})
				.catch(() => null)
		}
		if (createdTagId !== '' && createdTagId !== previous.tagId) {
			await page.request
				.delete(`/remote.php/dav/systemtags/${createdTagId}`, { headers })
				.catch(() => null)
		}

		for (const uuid of sessions) {
			await page.request
				.delete(`${API}/sessions/${uuid}`, { headers })
				.catch(() => null)
			await page.request
				.delete(`${API}/sessions/${uuid}/permanent`, { headers })
				.catch(() => null)
		}
		await cleanupFamily(page.request, cleanupToken, 'agent')

		if (second !== null) {
			await deleteSecondUser(page.request, cleanupToken, second.uid)
		}
		await secondCtx?.dispose()
		await page.request
			.delete(`/ocs/v1.php/cloud/groups/${GROUP}?format=json`, { headers })
			.catch(() => null)
		await page.close()
	})

	// e2e: retrieval-scope::an-administrator-sets-up-validation
	test('an administrator sets up validation', async ({ page }) => {
		// The section's first read: the unset state, whatever this instance holds.
		await page.route('**/apps/hermiq/api/settings/validated-tag', (route) =>
			route.request().method() === 'GET'
				? route.fulfill({ json: { tagId: null, name: null, groups: [] } })
				: route.continue(),
		)

		await page.goto('/index.php/settings/admin/hermiq', {
			waitUntil: 'domcontentloaded',
		})
		await dismissTour(page)

		const section = page.locator('#section-validated-documents')
		await expect(section).toBeVisible({ timeout: 30_000 })
		const block = section.getByTestId('validated-tag-settings')
		await expect(block.getByTestId('validated-tag-current')).toHaveText(
			'No validated tag is set. Agents in validated mode find no documents until one is.',
		)

		await block.getByLabel('Tag name').fill(TAG_NAME)
		const groups = block.getByRole('combobox', {
			name: 'Groups that may mark a document as validated',
		})
		await groups.click()
		await groups.fill(GROUP)
		await page.getByRole('option', { name: GROUP }).click()

		const created = page.waitForResponse(
			(res) =>
				res.url().includes('/apps/hermiq/api/settings/validated-tag')
				&& res.request().method() === 'POST',
		)
		await block.getByRole('button', { name: 'Create the Validated tag' }).click()
		const response = await created
		expect(response.status(), 'the administrator may create the tag').toBe(200)
		const answer = await response.json()
		createdTagId = String(answer.tagId ?? '')
		expect(createdTagId).not.toEqual('')
		expect(answer.groups).toEqual([GROUP])

		await expect(block.getByTestId('validated-tag-current')).toHaveText(
			`Validated documents carry the tag “${TAG_NAME}”.`,
		)
		await expect(block.getByTestId('validated-tag-save')).toBeVisible()
		await expect(block.getByTestId('validated-tag-create')).toHaveCount(0)

		// Hermiq's setting names it (read past the stub, straight from the server).
		const stored = await readSetting(page.request, token)
		expect(stored.tagId).toBe(createdTagId)
		expect(stored.name).toBe(TAG_NAME)

		// The tag exists as a restricted Nextcloud tag: visible, limited to the group.
		const tag = await page.request.fetch(`/remote.php/dav/systemtags/${createdTagId}`, {
			method: 'PROPFIND',
			headers: {
				requesttoken: token,
				Depth: '0',
				'Content-Type': 'application/xml',
			},
			data: TAG_PROPS,
		})
		expect(tag.status(), 'the tag is readable over WebDAV').toBe(207)
		const tagXml = await tag.text()
		expect(ocProp(tagXml, 'display-name')).toBe(TAG_NAME)
		expect(ocProp(tagXml, 'user-visible')).toBe('true')
		expect(ocProp(tagXml, 'groups').split('|')).toContain(GROUP)

		// A member of another group cannot tag a file of their own with it; that
		// refusal is what makes the tag restricted rather than a plain one.
		expect(secondCtx, 'the second user context must exist').not.toBeNull()
		const ctx = secondCtx as APIRequestContext
		const uid = (second as SecondUser).uid
		const fileName = `${TEST_PREFIX}-mandaatregister.txt`
		const upload = await ctx.put(`/remote.php/dav/files/${uid}/${fileName}`, {
			data: 'Mandaatregister Juridische Zaken',
		})
		expect([201, 204], `upload HTTP ${upload.status()}`).toContain(upload.status())
		const props = await ctx.fetch(`/remote.php/dav/files/${uid}/${fileName}`, {
			method: 'PROPFIND',
			headers: { Depth: '0', 'Content-Type': 'application/xml' },
			data: FILEID_PROPS,
		})
		const fileId = ocProp(await props.text(), 'fileid')
		expect(fileId, 'the uploaded file has an id').not.toEqual('')

		const tagging = await ctx.put(
			`/remote.php/dav/systemtags-relations/files/${fileId}/${createdTagId}`,
		)
		expect(tagging.status(), 'only the tag\'s groups may put it on a file').toBe(403)
	})

	// e2e: retrieval-scope::a-reviewer-checks-an-abstention
	test('a reviewer checks an abstention', async ({ page }) => {
		await resolveRegisterSchema(page.request, token, 'agentsessionturn')
		const agent = await seedAgent(page.request, token, {
			name: `${TEST_PREFIX}-Informatiepunt Veiligheidsregio`,
			answerOnlyFromValidated: true,
			ragMinSimilarity: 0.55,
			abstentionMessage: ABSTENTION,
		})

		const title = `${TEST_PREFIX}-vuurwerk`
		const started = await page.request.post(`${API}/sessions`, {
			headers: jsonHeaders(token),
			data: { agentId: agent.id, title },
		})
		expect(started.status(), await started.text()).toBe(201)
		const sessionUuid = String((await started.json()).uuid ?? '')
		sessions.push(sessionUuid)

		await seedObject(page.request, token, 'agentsessionturn', {
			sessionId: sessionUuid,
			agentId: agent.id,
			role: 'user',
			content: 'Mag ik vuurwerk afsteken op Koningsdag?',
		})
		await seedObject(page.request, token, 'agentsessionturn', {
			sessionId: sessionUuid,
			agentId: agent.id,
			role: 'assistant',
			content: ABSTENTION,
			retrievalScope: {
				fileFilters: {},
				validated: true,
				floor: 0.55,
				passed: 0,
				bestSimilarity: 0.41,
				abstained: true,
			},
		})

		const root = await appRoot(page)
		await page.goto(`${root}/chat`, { waitUntil: 'domcontentloaded' })
		await dismissTour(page)
		await page.getByTestId('chat-session-row').filter({ hasText: title }).click()

		const answer = page
			.locator('.chat-page__message--assistant')
			.filter({ hasText: ABSTENTION })
		await expect(answer).toBeVisible({ timeout: 30_000 })

		const scope = answer.getByTestId('chat-retrieval-scope')
		await expect(scope).toBeVisible()
		await expect(scope.locator('.chat-page__scope-abstained')).toHaveText(ABSTAINED_LINE)
		// The similarities are written in the reader's locale (0.55, or 0,55 in Dutch).
		await expect(scope.locator('.chat-page__scope-detail')).toHaveText(
			/Validated mode, floor 0[.,]55\. Passages at or above it: 0\. Best match: 0[.,]41\./,
		)
		// No file filters applied, so the filter note is absent.
		await expect(
			scope.getByText('The agent’s file filters limited which files were searched.'),
		).toHaveCount(0)
	})
})
