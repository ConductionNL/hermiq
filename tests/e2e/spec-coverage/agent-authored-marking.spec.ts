/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spec-coverage e2e: the AI label on the oversight page and the author of a
 * chat answer (agent-authored-marking 4.1).
 *
 * WHAT THE UNIT TESTS CANNOT SHOW. The AiLabelSection Vitest test renders the
 * section with a stubbed translation. It cannot show that a person who uses
 * Nextcloud in Dutch gets "Gemaakt met AI" from the shipped l10n catalogue on
 * the real page, nor that the section's Files link carries the id of the
 * stored `Agent authored` tag. Nor can a unit test show that the chat page
 * puts the agent's name on a stored answer and the person's name on their own
 * turn. Those are here.
 *
 * The other scenarios of the change carry a reason-bearing `@e2e exclude` in
 * the spec (Notes not installed in CI, vCard and iCalendar bytes, a forced
 * tagging failure, a second user without read access).
 *
 * The Dutch scenario switches the signed-in administrator's language to `nl`
 * through the provisioning API and restores the previous language afterwards.
 * Every seeded object carries the e2espec- family prefix and is removed in
 * afterAll.
 *
 * Not run when written: it needs a running Nextcloud with Hermiq and
 * OpenRegister installed.
 *
 *     NEXTCLOUD_URL=http://localhost:8080 NC_USER=admin NC_PASS=admin \
 *       npx playwright test --project=chromium agent-authored-marking
 *
 * @spec openspec/changes/agent-authored-marking/specs/nc-native-tools/spec.md
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import {
	appRoot,
	cleanupFamily,
	dismissTour,
	harvestToken,
	jsonHeaders,
	resolveRegisterSchema,
	seedAgent,
	seedObject,
	TEST_PREFIX,
} from './_fixtures.ts'

const API = '/index.php/apps/hermiq/api'

/** The stored, untranslated name of the agent-authored system tag. */
const STORED_TAG = 'Agent authored'

/** The Dutch display text of the label (REQ-AILBL-004). */
const DUTCH_LABEL = 'Gemaakt met AI'

/**
 * The signed-in user's uid and language, read through the provisioning API.
 *
 * @param req   The request context.
 * @param token The CSRF request-token.
 * @return The uid and the stored language ('' when none is set).
 */
async function currentUser(req: APIRequestContext, token: string): Promise<{ uid: string, language: string }> {
	const res = await req.get('/ocs/v1.php/cloud/user?format=json', {
		headers: { ...jsonHeaders(token), 'OCS-APIRequest': 'true' },
	})
	const body = await res.json().catch(() => null)
	const uid = String(body?.ocs?.data?.id ?? '')
	expect(uid, `read the signed-in user: HTTP ${res.status()}`).not.toBe('')
	return { uid, language: String(body?.ocs?.data?.language ?? '') }
}

/**
 * Set a user's language through the provisioning API.
 *
 * OCS v1 answers 200 for failures too, so the outcome is read from
 * `ocs.meta.statuscode`.
 *
 * @param req      The request context.
 * @param token    The CSRF request-token.
 * @param uid      The user.
 * @param language The language code, such as `nl` or `en`.
 */
async function setLanguage(req: APIRequestContext, token: string, uid: string, language: string): Promise<void> {
	const res = await req.put(`/ocs/v1.php/cloud/users/${encodeURIComponent(uid)}?format=json`, {
		headers: { ...jsonHeaders(token), 'OCS-APIRequest': 'true' },
		data: { key: 'language', value: language },
	})
	const body = await res.json().catch(() => null)
	expect(Number(body?.ocs?.meta?.statuscode), `set language ${language}: HTTP ${res.status()}`).toBe(100)
}

test.describe('agent-authored-marking: the label on hermiq\'s own pages', () => {
	let token = ''
	const sessions: string[] = []

	test.beforeAll(async ({ browser }) => {
		const page = await browser.newPage()
		token = await harvestToken(page)
		await page.close()
	})

	test.afterAll(async ({ browser }) => {
		const page = await browser.newPage()
		const headers = jsonHeaders(token)
		for (const uuid of sessions) {
			await page.request.delete(`${API}/sessions/${uuid}`, { headers }).catch(() => null)
			await page.request.delete(`${API}/sessions/${uuid}/permanent`, { headers }).catch(() => null)
		}
		await cleanupFamily(page.request, token)
		await page.close()
	})

	// @e2e nc-native-tools::the-oversight-section-shows-the-display-text
	test('a person using Nextcloud in Dutch sees "Gemaakt met AI", never the stored tag name, and the link filters on the stored tag', async ({ page }) => {
		const me = await currentUser(page.request, token)
		await setLanguage(page.request, token, me.uid, 'nl')

		try {
			await page.goto(`${await appRoot(page)}/ai-oversight`, { waitUntil: 'domcontentloaded' })
			await dismissTour(page)

			const section = page.locator('.ai-label-section')
			await expect(section.getByTestId('ai-label-files'), 'the label section has loaded').toBeVisible({ timeout: 30_000 })
			await expect(section, 'the section names the label in Dutch').toContainText(DUTCH_LABEL)
			await expect(section, 'the stored identifier does not appear in the section').not.toContainText(STORED_TAG)

			const stats = await (await page.request.get(`${API}/ai-label/stats`, { headers: jsonHeaders(token) })).json()
			const tagId = String(stats.tagId ?? '')
			test.skip(tagId === '', 'no agent has written yet, so the `Agent authored` tag does not exist and the section shows no link')

			const link = section.getByTestId('ai-label-files-link')
			await expect(link).toContainText(DUTCH_LABEL)
			const href = String(await link.getAttribute('href'))
			expect(href, 'the link opens the Files tag view').toContain('/apps/files/tags')
			expect(href, 'the link filters on the stored tag id').toContain(`dir=/${tagId}`)

			const tag = await page.request.fetch(`/remote.php/dav/systemtags/${tagId}`, {
				method: 'PROPFIND',
				headers: { requesttoken: token, Depth: '0', 'Content-Type': 'application/xml' },
				data: '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns"><d:prop><oc:display-name/></d:prop></d:propfind>',
			})
			expect(await tag.text(), 'the linked tag is the stored, untranslated `Agent authored`').toContain(`<oc:display-name>${STORED_TAG}</oc:display-name>`)
		} finally {
			await setLanguage(page.request, token, me.uid, me.language === '' ? 'en' : me.language)
		}
	})

	// @e2e nc-native-tools::an-answer-shows-who-wrote-it
	test('the answer bubble shows the agent\'s name and the person\'s own turn shows theirs', async ({ page }) => {
		await resolveRegisterSchema(page.request, token, 'agentsessionturn')
		const agentName = `${TEST_PREFIX}-Informatiepunt`
		const agent = await seedAgent(page.request, token, { name: agentName })

		const title = `${TEST_PREFIX}-who-wrote-it`
		const started = await page.request.post(`${API}/sessions`, {
			headers: jsonHeaders(token),
			data: { agentId: agent.id, title },
		})
		expect(started.status(), await started.text()).toBe(201)
		const sessionUuid = String((await started.json()).uuid ?? '')
		sessions.push(sessionUuid)

		const question = `${TEST_PREFIX} When is the town hall open?`
		const answerText = `${TEST_PREFIX} The town hall is open from nine to five.`
		await seedObject(page.request, token, 'agentsessionturn', {
			sessionId: sessionUuid,
			agentId: agent.id,
			role: 'user',
			content: question,
		})
		await seedObject(page.request, token, 'agentsessionturn', {
			sessionId: sessionUuid,
			agentId: agent.id,
			role: 'assistant',
			content: answerText,
		})

		await page.goto(`${await appRoot(page)}/chat`, { waitUntil: 'domcontentloaded' })
		await dismissTour(page)
		await page.getByTestId('chat-session-row').filter({ hasText: title }).click()

		const answer = page.locator('.chat-page__message--assistant').filter({ hasText: answerText })
		await expect(answer).toBeVisible({ timeout: 30_000 })
		await expect(answer.locator('.chat-page__sender'), 'the answer is shown under the agent\'s name').toHaveText(agentName)
		await expect(answer, 'no separate AI label is added to a chat answer').not.toContainText(STORED_TAG)

		const own = page.locator('.chat-page__message--user').filter({ hasText: question })
		await expect(own).toBeVisible()
		await expect(own.locator('.chat-page__sender'), 'the person\'s own turn is not shown under the agent\'s name').not.toHaveText(agentName)
		await expect(own.locator('.chat-page__sender')).not.toHaveText('')
	})
})
