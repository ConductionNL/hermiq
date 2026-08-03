/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Regression e2e — the reach axis and the `#noapproval` waiver
 * (agent-capability-reach).
 *
 * Two scenarios in that spec are marked `@e2e`, and both are here. Everything
 * else in the change is a classification decision with no browser surface and is
 * asserted by PHPUnit per the spec's own `@e2e exclude` notes.
 *
 *   1. "The reach of every catalogue entry is readable through the tool-catalogue
 *      API" — every entry carries a reach from the closed vocabulary.
 *   2. "A waiver survives a persist and read-back" — a `#noapproval` fragment
 *      written through the tool-grants API comes back verbatim.
 *
 * 🔴 The first test is the one that matters most, and NOT because the API shape
 * is interesting. `reach` is declared in Hermiq, forwarded by OpenRegister's
 * `McpProviderBridge`, and consumed back in Hermiq — three hops across an app
 * boundary. Hermiq's unit suite mocks that boundary, so it cannot see a key the
 * bridge drops, and the axis fails CLOSED: a dropped `reach` does not error, it
 * silently reclassifies every native tool as `external` and strips the lot from
 * every agent. This test runs against a real instance with both apps installed,
 * which is the only place that round trip actually happens.
 *
 * Run against a running Nextcloud with Hermiq + OpenRegister installed:
 *
 *     NEXTCLOUD_URL=http://localhost:8080 \
 *       NC_USER=admin NC_PASS=admin \
 *       npx playwright test --project chromium tool-grants-reach-and-waiver
 *
 * Covers openspec/changes/agent-capability-reach/specs/agent-capability-reach/spec.md
 */

import { test, expect, type Page } from '@playwright/test'

const NC_USER = process.env.NC_USER || 'admin'
const NC_PASS = process.env.NC_PASS || 'admin'

/** The closed reach vocabulary, ordered least to most far-reaching. */
const REACHES = ['self', 'user', 'instance', 'external']

/** The waiver fragment under test. */
const WAIVER = '#noapproval'

type ApiResult = { status: number, body: any }

/**
 * Log the configured user in through Nextcloud's real login form. Idempotent.
 *
 * @param page The Playwright page.
 */
async function login(page: Page): Promise<void> {
	await page.goto('/login', { waitUntil: 'domcontentloaded' })
	const userField = page.locator('#user')
	if (await userField.count() === 0) {
		return
	}
	await userField.fill(NC_USER)
	await page.locator('#password').fill(NC_PASS)
	await page.locator('button[type="submit"], input[type="submit"]').first().click()
	await page.locator('#user').waitFor({ state: 'hidden', timeout: 30_000 })
}

/**
 * Call a Hermiq/OpenRegister JSON API from inside the authenticated page.
 *
 * 🔴 Returns the STATUS as well as the body. A payload-only probe cannot tell a
 * 403 from an empty result — both arrive as "nothing useful" — and every
 * assertion below would then pass against an endpoint that refused us.
 *
 * @param page   The Playwright page.
 * @param path   The API path.
 * @param method HTTP method.
 * @param body   Optional JSON body.
 * @return The status code and parsed body.
 */
async function api(page: Page, path: string, method = 'GET', body?: unknown): Promise<ApiResult> {
	return await page.evaluate(async ({ path, method, body }) => {
		const token = (window as unknown as { OC?: { requestToken?: string } }).OC?.requestToken
			|| document.head.querySelector<HTMLMetaElement>('meta[name=requesttoken]')?.content || ''
		const res = await fetch(path, {
			method,
			headers: {
				requesttoken: token,
				'OCS-APIRequest': 'true',
				'Content-Type': 'application/json',
			},
			body: body === undefined ? undefined : JSON.stringify(body),
		})
		let parsed: any = null
		try {
			parsed = await res.json()
		} catch {
			parsed = null
		}
		return { status: res.status, body: parsed }
	}, { path, method, body })
}

/**
 * Create an agent owned by the acting user and return its uuid.
 *
 * @param page  The Playwright page.
 * @param name  The agent name.
 * @param tools The initial grant list.
 * @return The created agent's uuid.
 */
async function createAgent(page: Page, name: string, tools: string[] = []): Promise<string> {
	const created = await api(page, '/apps/openregister/api/objects/hermiq/agent', 'POST', {
		name,
		prompt: 'E2E fixture for agent-capability-reach.',
		tools,
	})
	expect(created.status, `Agent create failed: ${JSON.stringify(created.body)}`).toBeLessThan(300)

	const uuid = created.body?.['@self']?.id || created.body?.id || created.body?.uuid
	expect(uuid, 'The created agent must report a uuid.').toBeTruthy()
	return String(uuid)
}

/**
 * Soft-delete the fixture agent so repeated runs do not accumulate objects.
 *
 * @param page The Playwright page.
 * @param uuid The agent uuid.
 */
async function deleteAgent(page: Page, uuid: string): Promise<void> {
	await api(page, `/apps/openregister/api/objects/hermiq/agent/${uuid}`, 'DELETE')
}

test.describe('agent-capability-reach: tool catalogue and grant waiver', () => {
	test('every tool-catalogue entry carries a reach from the closed vocabulary', async ({ page }) => {
		await login(page)

		const uuid = await createAgent(page, `E2E reach ${Date.now()}`)

		try {
			const res = await api(page, `/apps/hermiq/api/agents/${uuid}/tool-catalog`)

			expect(res.status, `tool-catalog returned ${res.status}: ${JSON.stringify(res.body)}`).toBe(200)

			const tools = res.body?.tools
			expect(Array.isArray(tools), 'tool-catalog must return a tools array.').toBe(true)

			// A catalogue of zero would make every per-entry assertion below
			// vacuously true — the classic "passes because it checked nothing".
			expect(tools.length, 'The catalogue must not be empty on an instance with both apps installed.')
				.toBeGreaterThan(0)

			const missing = tools.filter((t: any) => typeof t?.reach !== 'string' || t.reach === '')
			expect(
				missing.map((t: any) => t.id),
				'Every catalogue entry must carry a reach; these do not.',
			).toEqual([])

			const outOfVocabulary = tools
				.filter((t: any) => !REACHES.includes(t.reach))
				.map((t: any) => `${t.id}=${t.reach}`)
			expect(outOfVocabulary, 'Every reach must come from the closed vocabulary.').toEqual([])

			// 🔴 THE CROSS-APP ASSERTION, and the reason this test exists.
			//
			// If OpenRegister's McpProviderBridge is not forwarding `reach`
			// (ConductionNL/openregister#2302), every native Hermiq tool arrives
			// unannotated and fails closed to `external`. The two checks above
			// would STILL PASS — every entry would carry a reach, and `external`
			// is in the vocabulary. Only the distribution gives it away: a real
			// catalogue has low-reach reads in it.
			//
			// So this asserts on Hermiq's own read tools by name. If they come
			// back `external`, the annotation is being dropped in transit, and
			// the deploy order was wrong.
			const byId = new Map(tools.map((t: any) => [t.id, t]))
			for (const id of ['hermiq.listFiles', 'hermiq.readFile', 'hermiq.searchContacts']) {
				const tool = byId.get(id)
				expect(tool, `${id} must be present in the catalogue.`).toBeTruthy()
				expect(
					(tool as any).reach,
					`${id} must resolve to 'user' reach. Getting 'external' here means the reach `
					+ 'annotation is being DROPPED between Hermiq and OpenRegister — deploy '
					+ 'openregister#2302 (McpProviderBridge PASSTHROUGH_KEYS) first.',
				).toBe('user')
			}

			// And the egress tools are honestly labelled, which is the whole
			// point of the axis: same `read` scope, different reach.
			const webFetch = byId.get('hermiq.webFetch') as any
			expect(webFetch, 'hermiq.webFetch must be present.').toBeTruthy()
			expect(webFetch.reach).toBe('external')
			expect(webFetch.requiresExplicitGrant, 'An external-reach tool must need naming.').toBe(true)
		} finally {
			await deleteAgent(page, uuid)
		}
	})

	test('a #noapproval waiver survives a persist and read-back', async ({ page }) => {
		await login(page)

		const uuid = await createAgent(page, `E2E waiver ${Date.now()}`)
		const waived = `openregister.runFlow?flowId=00000000-0000-0000-0000-000000000000${WAIVER}`

		try {
			const put = await api(page, `/apps/hermiq/api/agents/${uuid}/tool-grants`, 'PUT', {
				grants: [waived, 'hermiq.listFiles'],
			})
			expect(put.status, `tool-grants PUT returned ${put.status}: ${JSON.stringify(put.body)}`).toBe(200)

			// Read back through a DIFFERENT endpoint than the one that wrote it.
			// The PUT response echoes what it just saved, so asserting only on
			// that would pass even if nothing was persisted at all.
			const read = await api(page, `/apps/openregister/api/objects/hermiq/agent/${uuid}`)
			expect(read.status, 'The agent read-back must succeed.').toBe(200)

			const tools = read.body?.tools || read.body?.['@self']?.tools
			expect(Array.isArray(tools), 'Agent.tools must persist as an array of strings.').toBe(true)

			// Verbatim: the fragment, the constraint and their order all intact.
			// A parser that stripped the fragment before saving, or absorbed it
			// into the constraint value, fails right here.
			expect(tools, 'The waived grant must round-trip byte for byte.').toContain(waived)
			expect(tools).toContain('hermiq.listFiles')

			// And nothing acquired a fragment it was not written with.
			const unexpected = tools.filter((t: string) => t.includes(WAIVER) && t !== waived)
			expect(unexpected, 'No other grant may come back carrying a waiver.').toEqual([])
		} finally {
			await deleteAgent(page, uuid)
		}
	})
})
