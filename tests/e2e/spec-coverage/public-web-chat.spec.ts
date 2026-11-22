/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spec-coverage e2e — the public web chat
 * (delivery-public-web-chat, Tasks 5 and 6).
 *
 * Browser scenarios of openspec/changes/delivery-public-web-chat:
 *  - REQ-PUBCHAT-001 "A communications advisor puts the assistant on the
 *    municipal website": with the `public-chat` AI feature enabled, the
 *    administrator opens the "Public chat" settings page, creates the channel
 *    "Website" with the agent "Public information", the origin
 *    https://www.example.nl and the published publications as scope, switches
 *    "Answer visitors" on and saves. The dialog then shows the script snippet
 *    and the iframe snippet carrying the token the server gave the channel.
 *  - REQ-PUBCHAT-001 "An agent with tools cannot be public": the picker does
 *    not offer an agent holding `hermiq.sendMail`; when it is offered anyway
 *    and saved, the server refuses with "A public assistant cannot use tools.
 *    Pick an agent without tools." and no channel is stored.
 *  - REQ-PUBCHAT-002 "A resident asks about bulky waste on the website": in a
 *    browser that never signed in, the channel's chat page shows the channel's
 *    disclosure before the question field; the resident asks "When is bulky
 *    waste collected in my area?" and the answer shows with the published
 *    waste collection page as a link. A second question continues the visit
 *    with the conversation token of the first answer.
 * And one check of the REQ-PUBCHAT-003 sentence, which has no scenario of its
 * own: a channel that is switched off shows "This assistant is not available
 * right now." on its page, without a question field, and its endpoint answers
 * a signed-out post with 503 and that sentence.
 *
 * Left out:
 *  - REQ-PUBCHAT-002 "A non-public object is never a source": `@e2e exclude`,
 *    retrieval scope, covered by PHPUnit (PublicRetrievalServiceTest) and a
 *    live check reading a private object through a channel.
 *  - REQ-PUBCHAT-003 "Another website cannot use the channel": `@e2e exclude`,
 *    the CORS contract, covered by PHPUnit (PublicChatControllerTest) and
 *    Newman. A browser test cannot send an Origin of its own choosing.
 *  - REQ-PUBCHAT-004 "A portal asks hermiq on behalf of an anonymous
 *    visitor": `@e2e exclude`, the in-process PHP contract of
 *    PublicChatEntryPoint::ask(), covered by PHPUnit.
 *  - The accessibility check tasks.md names for the chat window needs axe and
 *    is not part of this file.
 *
 * 🔑 WHAT IS REAL AND WHAT IS NOT.
 *  - The settings page, the channel dialog, the save through OpenRegister and
 *    the save guard (PublicChannelGuardListener: the token, the tools refusal)
 *    are real. Agents and channels are seeded through the OpenRegister
 *    objects API with TEST_PREFIX and deleted in afterAll.
 *  - The `public-chat` AI feature is enabled through hermiq's own route for
 *    the length of the suite and disabled again in afterAll when it was off.
 *    Enabling needs the DPO acknowledgement; when the instance has none, the
 *    suite records one as the administrator, and that record stays.
 *  - The tools refusal in the browser: the picker leaves an agent with tools
 *    out, so the scenario's "picks it" cannot happen by clicking alone. The
 *    test answers the picker's agent list once with that agent's `tools`
 *    emptied (page.route), so the dialog offers it; the save then goes to the
 *    real server, which reads the stored agent and refuses it.
 *  - The chat page and its disclosure are real, rendered by
 *    PublicChatPageController for a browser context with no cookies at all.
 *    Only the model's answer is stubbed (page.route on the channel's
 *    messages endpoint): a real answer needs an LLM and a published waste
 *    page, and neither exists on the test instance. What the window posts is
 *    read from the stubbed request and checked.
 *  - The switched-off channel is fully real: the page and the endpoint answer
 *    without any model.
 *
 * Runs as the suite's administrator (the default storage state); the visitor
 * tests open their own signed-out context:
 *
 *     PLAYWRIGHT_BASE_URL=<your rig> NC_USER=admin NC_PASS=admin \
 *       npx playwright test -c tests/e2e/spec-coverage/playwright.config.ts public-web-chat
 *
 * Covers openspec/changes/delivery-public-web-chat/specs/public-chat-channel/spec.md
 */

import type { APIRequestContext, Locator, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import {
	appRoot,
	cleanupFamily,
	deleteObject,
	dismissTour,
	harvestToken,
	jsonHeaders,
	OR_API,
	seedAgent,
	seedObject,
	TEST_PREFIX,
} from './_fixtures.ts'

const API = '/index.php/apps/hermiq/api'

const CHANNEL_SCHEMA = 'agentpublicchannel'

const FEATURE_SLUG = 'public-chat'

const ORIGIN = 'https://www.example.nl'

/** "The published publications", in the dialog's `register/schema` form. */
const SCOPE_LINE = 'opencatalogi/publication'

const REFUSED_TOOLS = 'A public assistant cannot use tools. Pick an agent without tools.'

const NOT_AVAILABLE = 'This assistant is not available right now.'

const DISCLOSURE = 'You are chatting with an AI assistant. It can make mistakes. Do not share personal details.'

const QUESTION = 'When is bulky waste collected in my area?'

const FOLLOW_UP = 'And on public holidays?'

/** The stubbed answer and the published page it cites. */
const ANSWER = 'Bulky waste is collected on the first Monday of the month. Book a pickup online first.'
const FOLLOW_UP_ANSWER = 'On a public holiday the pickup moves to the next working day.'
const WASTE_PAGE = { title: 'Bulky waste collection', url: 'https://www.example.nl/waste/bulky-waste' }

/** A conversation token of the shape hermiq mints. */
const CONVERSATION = 'Conv0123456789abcdefABCDEF'

/**
 * Read one hermiq AI feature by its slug.
 *
 * @param req   The request context.
 * @param token The CSRF request-token.
 * @param slug  The feature slug.
 * @return The feature, with its `uuid` and `lifecycle`.
 */
async function readFeature(req: APIRequestContext, token: string, slug: string): Promise<any> {
	const res = await req.get(`${API}/ai-features`, { headers: jsonHeaders(token) })
	expect(res.status(), 'the administrator reads the AI features').toBe(200)
	const feature = ((await res.json()).results ?? []).find((f: any) => f.slug === slug)
	expect(feature, `the AI feature '${slug}' must be seeded (SeedAiFeatures)`).toBeTruthy()
	return feature
}

/**
 * Enable the `public-chat` feature, recording the DPO acknowledgement first when the guard asks for it.
 *
 * @param req   The request context.
 * @param token The CSRF request-token.
 * @param uuid  The feature's uuid.
 * @return Resolves once the feature reads enabled.
 */
async function enableFeature(req: APIRequestContext, token: string, uuid: string): Promise<void> {
	let res = await req.post(`${API}/ai-features/${uuid}/enable`, { headers: jsonHeaders(token) })
	if (res.status() === 409) {
		const ack = await req.post(`${API}/ai-features/${FEATURE_SLUG}/acknowledge`, {
			headers: jsonHeaders(token),
		})
		expect(ack.status(), await ack.text()).toBe(200)
		res = await req.post(`${API}/ai-features/${uuid}/enable`, { headers: jsonHeaders(token) })
	}
	expect(res.status(), await res.text()).toBe(200)
	expect((await res.json()).lifecycle).toBe('enabled')
}

/**
 * Read one stored channel back through OpenRegister.
 *
 * @param req   The request context.
 * @param token The CSRF request-token.
 * @param id    The channel id.
 * @return The stored channel.
 */
async function readChannel(req: APIRequestContext, token: string, id: string): Promise<any> {
	const res = await req.get(`${OR_API}/objects/hermiq/${CHANNEL_SCHEMA}/${id}`, {
		headers: jsonHeaders(token),
	})
	expect(res.status(), `read channel ${id}`).toBe(200)
	return res.json()
}

/**
 * The stored channels with a given name.
 *
 * @param req   The request context.
 * @param token The CSRF request-token.
 * @param name  The channel name.
 * @return The matching channels.
 */
async function channelsNamed(req: APIRequestContext, token: string, name: string): Promise<any[]> {
	const res = await req.get(`${OR_API}/objects/hermiq/${CHANNEL_SCHEMA}?_limit=200`, {
		headers: jsonHeaders(token),
	})
	expect(res.status(), 'list the channels').toBe(200)
	const body = await res.json()
	const list = (Array.isArray(body) ? body : (body.results ?? [])) as any[]
	return list.filter((c) => (c.name ?? c['@self']?.name) === name)
}

/**
 * Open the Public chat settings page and its "new channel" dialog.
 *
 * @param page The page.
 * @return The dialog locator, once the agent picker is there.
 */
async function openNewChannel(page: Page): Promise<Locator> {
	const root = await appRoot(page)
	await page.goto(`${root}/settings/public-chat`, { waitUntil: 'domcontentloaded' })
	await dismissTour(page)
	const index = page.getByTestId('cn-index-page')
	await expect(index).toBeVisible({ timeout: 30_000 })
	await index.getByRole('button', { name: /add|new/i }).first().click()
	const dialog = page.getByRole('dialog', { name: 'New public chat channel' })
	await expect(dialog).toBeVisible({ timeout: 20_000 })
	await expect(dialog.getByRole('combobox', { name: 'Agent' })).toBeVisible()
	return dialog
}

/**
 * Open the dialog's agent picker and return its options.
 *
 * NcSelect appends its listbox to <body>, outside the aria-modal dialog, so the
 * options are found on the page, not in the dialog.
 *
 * @param page   The page.
 * @param dialog The channel dialog.
 * @return The option locator.
 */
async function agentOptions(page: Page, dialog: Locator): Promise<Locator> {
	await dialog.getByRole('combobox', { name: 'Agent' }).click()
	const options = page.locator('.vs__dropdown-option')
	await expect(options.first()).toBeVisible({ timeout: 20_000 })
	return options
}

/**
 * Fill the fields every website channel needs.
 *
 * @param page      The page.
 * @param dialog    The channel dialog.
 * @param name      The channel name.
 * @param agentName The agent to pick.
 * @return Resolves once filled.
 */
async function fillWebsiteChannel(page: Page, dialog: Locator, name: string, agentName: string): Promise<void> {
	await dialog.getByLabel('Name', { exact: true }).fill(name)
	const options = await agentOptions(page, dialog)
	await options.filter({ hasText: agentName }).click()
	await dialog.getByLabel('Allowed origins, one per line').fill(ORIGIN)
	await dialog.getByLabel('Public sources, one register/schema per line').fill(SCOPE_LINE)
}

/**
 * Press Save and wait for OpenRegister's answer to the channel write.
 *
 * @param page   The page.
 * @param dialog The channel dialog.
 * @return The response.
 */
async function saveChannel(page: Page, dialog: Locator) {
	const written = page.waitForResponse(
		(res) =>
			res.url().includes(`/objects/hermiq/${CHANNEL_SCHEMA}`)
			&& ['POST', 'PUT'].includes(res.request().method()),
	)
	await dialog.getByRole('button', { name: 'Save', exact: true }).click()
	return written
}

/**
 * Escape a string for use inside a RegExp.
 *
 * @param text The literal text.
 * @return The escaped text.
 */
function literal(text: string): string {
	return text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
}

test.describe('delivery-public-web-chat: the public chat channel', () => {
	let token = ''
	let featureUuid = ''
	let featureWasEnabled = false
	let publicAgentId = ''
	let toolAgentId = ''
	let liveChannelToken = ''
	let offChannelToken = ''
	const seededChannels: string[] = []

	const publicAgentName = `${TEST_PREFIX} Public information`
	const toolAgentName = `${TEST_PREFIX} Mail sender`
	const liveChannelName = `${TEST_PREFIX} Waste desk`
	const offChannelName = `${TEST_PREFIX} Switched off`

	test.beforeAll(async ({ browser }) => {
		const page = await browser.newPage()
		token = await harvestToken(page)

		const feature = await readFeature(page.request, token, FEATURE_SLUG)
		featureUuid = String(feature.uuid)
		featureWasEnabled = feature.lifecycle === 'enabled'
		if (featureWasEnabled === false) {
			await enableFeature(page.request, token, featureUuid)
		}

		publicAgentId = (await seedAgent(page.request, token, { name: publicAgentName, tools: [] })).id
		toolAgentId = (await seedAgent(page.request, token, { name: toolAgentName, tools: ['hermiq.sendMail'] })).id

		// Two website channels for the visitor tests: one answering, one switched off.
		const live = await seedObject(page.request, token, CHANNEL_SCHEMA, {
			name: liveChannelName,
			kind: 'website',
			agentId: publicAgentId,
			allowedOrigins: [ORIGIN],
			sourceScope: [{ register: 'opencatalogi', schema: 'publication' }],
			disclosureText: DISCLOSURE,
			dailyQuestionLimit: 500,
			enabled: true,
		})
		seededChannels.push(live.id)
		liveChannelToken = String((await readChannel(page.request, token, live.id)).channelToken ?? '')
		expect(liveChannelToken, 'the save guard gives a new channel its token').toMatch(/^[A-Za-z0-9]{32}$/)

		const off = await seedObject(page.request, token, CHANNEL_SCHEMA, {
			name: offChannelName,
			kind: 'website',
			agentId: publicAgentId,
			allowedOrigins: [ORIGIN],
			sourceScope: [{ register: 'opencatalogi', schema: 'publication' }],
			enabled: false,
		})
		seededChannels.push(off.id)
		offChannelToken = String((await readChannel(page.request, token, off.id)).channelToken ?? '')
		expect(offChannelToken).toMatch(/^[A-Za-z0-9]{32}$/)

		await page.close()
	})

	test.afterAll(async ({ browser }) => {
		const page = await browser.newPage()
		const cleanupToken = await harvestToken(page)
		for (const id of seededChannels) {
			await deleteObject(page.request, cleanupToken, CHANNEL_SCHEMA, id)
		}
		await cleanupFamily(page.request, cleanupToken, CHANNEL_SCHEMA)
		for (const id of [publicAgentId, toolAgentId]) {
			if (id !== '') {
				await deleteObject(page.request, cleanupToken, 'agent', id)
			}
		}
		if (featureUuid !== '' && featureWasEnabled === false) {
			await page.request
				.post(`${API}/ai-features/${featureUuid}/disable`, { headers: jsonHeaders(cleanupToken) })
				.catch(() => null)
		}
		await page.close()
	})

	// e2e: public-chat-channel::a-communications-advisor-puts-the-assistant-on-the-municipal-website
	test('a communications advisor puts the assistant on the municipal website', async ({ page }) => {
		const name = `${TEST_PREFIX} Website`
		const dialog = await openNewChannel(page)

		await fillWebsiteChannel(page, dialog, name, publicAgentName)
		await expect(dialog.getByLabel('Notice shown to visitors')).toHaveValue(DISCLOSURE)
		await expect(dialog.getByLabel('Questions per day')).toHaveValue('500')
		await dialog.getByText('Answer visitors', { exact: true }).click()

		const response = await saveChannel(page, dialog)
		expect([200, 201], `save HTTP ${response.status()}`).toContain(response.status())
		const body = await response.json()
		const id = String(body.id ?? body['@self']?.id ?? '')
		expect(id).not.toEqual('')
		seededChannels.push(id)

		// What the server stored: the declaration, switched on, with a token it minted.
		const stored = await readChannel(page.request, token, id)
		expect(stored.name).toBe(name)
		expect(stored.kind).toBe('website')
		expect(stored.agentId).toBe(publicAgentId)
		expect(stored.allowedOrigins).toEqual([ORIGIN])
		expect(stored.sourceScope).toEqual([{ register: 'opencatalogi', schema: 'publication' }])
		expect(stored.enabled).toBe(true)
		const channelToken = String(stored.channelToken ?? '')
		expect(channelToken).toMatch(/^[A-Za-z0-9]{32}$/)

		// The dialog now shows both snippets for that token.
		await expect(dialog.getByText('Put the chat on the website', { exact: true })).toBeVisible()
		const origin = new URL(page.url()).origin
		const root = await appRoot(page)
		await expect(dialog.getByLabel('Script', { exact: true })).toHaveValue(
			new RegExp(`^<script src="${literal(origin)}[^"]*/js/public-chat\\.js\\?channel=${channelToken}" async></script>$`),
		)
		await expect(dialog.getByLabel('Iframe', { exact: true })).toHaveValue(
			`<iframe src="${origin}${root}/public/${channelToken}" title="Chat" width="380" height="560" style="border:0"></iframe>`,
		)

		await dialog.getByRole('button', { name: 'Done', exact: true }).click()
		await expect(page.getByTestId('cn-index-page').getByText(name, { exact: true })).toBeVisible({
			timeout: 15_000,
		})
	})

	// e2e: public-chat-channel::an-agent-with-tools-cannot-be-public
	test('an agent with tools cannot be public', async ({ page }) => {
		const name = `${TEST_PREFIX} Website with tools`

		// The server refuses the declaration whoever sends it.
		const direct = await page.request.post(`${OR_API}/objects/hermiq/${CHANNEL_SCHEMA}`, {
			headers: jsonHeaders(token),
			data: { name, kind: 'website', agentId: toolAgentId, allowedOrigins: [ORIGIN] },
		})
		const directText = await direct.text()
		expect([200, 201], `a channel on an agent with tools must not save: ${directText}`).not.toContain(direct.status())
		expect(directText).toContain(REFUSED_TOOLS)

		// The picker offers the agent without tools and leaves the one with tools out.
		let dialog = await openNewChannel(page)
		let options = await agentOptions(page, dialog)
		await expect(options.filter({ hasText: publicAgentName })).toHaveCount(1)
		await expect(options.filter({ hasText: toolAgentName })).toHaveCount(0)
		await page.keyboard.press('Escape')
		await dialog.getByRole('button', { name: 'Cancel', exact: true }).click()
		await expect(dialog).toBeHidden()

		// Offer it anyway, as a stale list would: the stored agent still holds the grant.
		await page.route(
			(url) => url.pathname.endsWith('/objects/hermiq/agent'),
			async (route) => {
				if (route.request().method() !== 'GET') {
					return route.fallback()
				}
				const res = await route.fetch()
				const json = await res.json()
				const strip = (a: any) => (String(a?.id ?? a?.uuid ?? '') === toolAgentId ? { ...a, tools: [] } : a)
				const patched = Array.isArray(json)
					? json.map(strip)
					: { ...json, results: (json.results ?? []).map(strip) }
				return route.fulfill({ response: res, json: patched })
			},
		)
		await page.getByTestId('cn-index-page').getByRole('button', { name: /add|new/i }).first().click()
		dialog = page.getByRole('dialog', { name: 'New public chat channel' })
		await expect(dialog).toBeVisible({ timeout: 20_000 })
		options = await agentOptions(page, dialog)
		await expect(options.filter({ hasText: toolAgentName })).toHaveCount(1)
		await page.keyboard.press('Escape')

		await fillWebsiteChannel(page, dialog, name, toolAgentName)
		const response = await saveChannel(page, dialog)
		expect([200, 201], 'the save is refused').not.toContain(response.status())

		const problems = dialog.locator('.notecard, [role="alert"], .notecard--error').filter({
			hasText: 'Could not save the channel',
		})
		await expect(problems.first()).toBeVisible()
		await expect(problems.first()).toContainText(REFUSED_TOOLS)
		// The form stays open, and no snippet is offered for a channel that does not exist.
		await expect(dialog.getByLabel('Script', { exact: true })).toHaveCount(0)
		expect(await channelsNamed(page.request, token, name)).toHaveLength(0)
	})

	// e2e: public-chat-channel::a-resident-asks-about-bulky-waste-on-the-website
	test('a resident asks about bulky waste on the website', async ({ browser }) => {
		const context = await browser.newContext({ storageState: { cookies: [], origins: [] } })
		const page = await context.newPage()
		try {
			const posted: any[] = []
			await page.route(`**/apps/hermiq/api/public/${liveChannelToken}/messages`, async (route) => {
				if (route.request().method() !== 'POST') {
					return route.fallback()
				}
				const body = route.request().postDataJSON()
				posted.push(body)
				const first = posted.length === 1
				return route.fulfill({
					json: {
						answer: first ? ANSWER : FOLLOW_UP_ANSWER,
						sources: first ? [WASTE_PAGE] : [],
						conversationToken: CONVERSATION,
						disclosure: DISCLOSURE,
					},
				})
			})

			const opened = await page.goto(`/index.php/apps/hermiq/public/${liveChannelToken}`, {
				waitUntil: 'domcontentloaded',
			})
			expect(opened?.status(), 'the chat page opens without signing in').toBe(200)
			expect(new URL(page.url()).pathname).toContain(`/apps/hermiq/public/${liveChannelToken}`)

			const chat = page.locator('#hermiq-public-chat')
			await expect(chat.getByRole('heading', { level: 1 })).toHaveText(liveChannelName, { timeout: 20_000 })
			const disclosure = chat.locator('.hermiq-public-chat__disclosure')
			await expect(disclosure).toHaveText(DISCLOSURE)

			// The disclosure comes before the question field.
			const input = chat.getByLabel('Your question')
			await expect(input).toBeVisible()
			const disclosureFirst = await page.evaluate(() => {
				const notice = document.querySelector('.hermiq-public-chat__disclosure')
				const field = document.querySelector('#hermiq-public-chat-question')
				return notice !== null && field !== null
					&& (notice.compareDocumentPosition(field) & Node.DOCUMENT_POSITION_FOLLOWING) !== 0
			})
			expect(disclosureFirst).toBe(true)

			await input.fill(QUESTION)
			await chat.getByRole('button', { name: 'Send', exact: true }).click()

			const assistant = chat.locator('.hermiq-public-chat__message--assistant')
			await expect(assistant).toHaveCount(1, { timeout: 15_000 })
			await expect(chat.locator('.hermiq-public-chat__message--visitor').first()).toHaveText(QUESTION)
			await expect(assistant.first().locator('p').first()).toHaveText(ANSWER)
			await expect(assistant.first().locator('.hermiq-public-chat__sources-label')).toHaveText('Sources')
			const link = assistant.first().getByRole('link', { name: WASTE_PAGE.title, exact: true })
			await expect(link).toHaveAttribute('href', WASTE_PAGE.url)
			await expect(link).toHaveAttribute('rel', 'noopener noreferrer')

			// The first post carries the question and no conversation yet.
			expect(posted[0].question).toBe(QUESTION)
			expect(posted[0].conversationToken).toBeUndefined()
			expect(String(posted[0].locale)).toMatch(/^[a-z]{2,3}([_-][A-Za-z]{2,4})?$/)

			// A follow-up continues the same visit.
			await input.fill(FOLLOW_UP)
			await chat.getByRole('button', { name: 'Send', exact: true }).click()
			await expect(assistant).toHaveCount(2, { timeout: 15_000 })
			await expect(assistant.nth(1).locator('p').first()).toHaveText(FOLLOW_UP_ANSWER)
			expect(posted[1]).toMatchObject({ question: FOLLOW_UP, conversationToken: CONVERSATION })
		} finally {
			await context.close()
		}
	})

	// Supports REQ-PUBCHAT-003's "not available" sentence; the requirement has no scenario of its own for it.
	test('a switched-off channel says it is not available', async ({ browser }) => {
		const context = await browser.newContext({ storageState: { cookies: [], origins: [] } })
		const page = await context.newPage()
		try {
			const opened = await page.goto(`/index.php/apps/hermiq/public/${offChannelToken}`, {
				waitUntil: 'domcontentloaded',
			})
			expect(opened?.status()).toBe(200)
			const chat = page.locator('#hermiq-public-chat')
			await expect(chat.getByRole('heading', { level: 1 })).toHaveText(offChannelName, { timeout: 20_000 })
			await expect(chat.locator('.hermiq-public-chat__message--assistant')).toHaveText(NOT_AVAILABLE)
			await expect(chat.getByLabel('Your question')).toHaveCount(0)

			// The endpoint, posted to by nobody signed in, says the same.
			const res = await context.request.post(`${API}/public/${offChannelToken}/messages`, {
				headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
				data: { question: QUESTION, locale: 'en' },
			})
			expect(res.status()).toBe(503)
			expect(await res.json()).toEqual({ message: NOT_AVAILABLE, available: false })
		} finally {
			await context.close()
		}
	})
})
