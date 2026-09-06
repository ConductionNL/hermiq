/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spec-coverage e2e — the reach axis, the `#noapproval` waiver, and owner-only
 * grant writes (agent-capability-reach).
 *
 * Three `@e2e` scenarios live here; every other scenario in the change carries a
 * reason-bearing `@e2e exclude` because it is a classification decision with no
 * browser surface.
 *
 * 🔴 The catalogue test is the one that cannot be replaced by a unit test.
 * `reach` is declared in Hermiq, forwarded by OpenRegister's
 * `McpProviderBridge`, and consumed back in Hermiq — three hops across an app
 * boundary that `tests/bootstrap.php` stubs out. The axis fails CLOSED, so a
 * key dropped in transit does not error: it silently reclassifies every native
 * tool as `external` and strips the lot. Only a live instance with both apps
 * installed exercises that round trip.
 *
 * 🔴 The owner test asserts BOTH write paths. Hermiq's tool-grants endpoint was
 * already owner-guarded before this change, so a pass on that path alone would
 * prove nothing about the hole that was actually reproduced — a non-owner PUT
 * to the generic OpenRegister objects API, which returned HTTP 200 and replaced
 * an admin-owned agent's grants.
 *
 * Run against a running Nextcloud with Hermiq + OpenRegister installed:
 *
 *     NEXTCLOUD_URL=http://localhost:8080 NC_USER=admin NC_PASS=admin \
 *       npx playwright test --project=chromium tool-grant-reach
 *
 * Covers openspec/changes/agent-capability-reach/specs/agent-capability-reach/spec.md
 */

import type { APIRequestContext } from '@playwright/test'
import type { SecondUser } from './_fixtures.ts'

import { expect, request as playwrightRequest, test } from '@playwright/test'
import {
	assertSecondUserAuthenticates,
	cleanupFamily,
	createSecondUser,
	deleteSecondUser,
	harvestToken,
	jsonHeaders,
	OR_API,
	seedAgent,
	TEST_PREFIX,
} from './_fixtures.ts'

/** The closed reach vocabulary, ordered least to most far-reaching. */
const REACHES = ['self', 'user', 'instance', 'external']

/** The waiver fragment under test. */
const WAIVER = '#noapproval'

/** Hermiq's own read tools — the ones that prove the annotation survived transit. */
const NATIVE_READS = ['hermiq.listFiles', 'hermiq.readFile', 'hermiq.searchContacts']

test.describe('agent-capability-reach: catalogue reach, waiver round-trip, owner-only writes', () => {
	let token = ''
	let agentId = ''
	let second: SecondUser | null = null
	let secondCtx: APIRequestContext | null = null

	test.beforeAll(async ({ browser }) => {
		const page = await browser.newPage()
		token = await harvestToken(page)

		const seeded = await seedAgent(page.request, token, {
			name: `${TEST_PREFIX}-reach`,
		})
		agentId = seeded.id

		second = await createSecondUser(page.request, token, 'nonowner')

		// A SEPARATE request context authenticated as the second user — not the
		// admin session with a different header, which would still carry the
		// owner's cookie and quietly test nothing.
		// 🔴 `send: 'always'` is required, not optional.
		//
		// Playwright withholds Basic credentials until the server answers 401.
		// Nextcloud's OCS layer answers 200 with an unauthorised code in the
		// BODY, so the challenge never comes, the credentials are never sent,
		// and every request runs ANONYMOUSLY. The non-owner test would then have
		// passed for entirely the wrong reason — "refused" because nobody was
		// logged in, not because the guard works. `assertSecondUserAuthenticates`
		// is what caught it (HTTP 200, identity=undefined).
		// 🔴 `storageState` must be explicitly EMPTIED, not merely omitted.
		//
		// `request.newContext()` inherits the config's `use` block, and this
		// suite sets `use.storageState` to the admin session. Omitting it here
		// therefore does not mean "no session" — it means "the ADMIN session",
		// and the whole test silently runs as the very user it is supposed to
		// be excluding. Round 4 reported `identity="admin"` for a context built
		// from the second user's credentials; that is what this line fixes.
		secondCtx = await playwrightRequest.newContext({
			baseURL:
				process.env.NEXTCLOUD_URL
				|| process.env.BASE_URL
				|| 'http://localhost:8080',
			storageState: { cookies: [], origins: [] },
			httpCredentials: {
				username: second.uid,
				password: second.password,
				send: 'always',
			},
		})

		await page.close()
	})

	test.afterAll(async ({ browser }) => {
		const page = await browser.newPage()
		const cleanupToken = await harvestToken(page)
		await cleanupFamily(page.request, cleanupToken, 'agent')
		if (second !== null) {
			await deleteSecondUser(page.request, cleanupToken, second.uid)
		}
		await secondCtx?.dispose()
		await page.close()
	})

	test('every tool-catalogue entry carries a reach from the closed vocabulary', async ({
		page,
	}) => {
		const res = await page.request.get(
			`/index.php/apps/hermiq/api/agents/${agentId}/tool-catalog`,
			{ headers: jsonHeaders(token) },
		)

		expect(
			res.status(),
			'tool-catalog must be readable by the agent owner',
		).toBe(200)

		const body = await res.json()
		const tools = body?.tools
		expect(Array.isArray(tools), 'tool-catalog must return a tools array').toBe(
			true,
		)

		// A catalogue of zero would make every per-entry assertion below
		// vacuously true — the classic "passes because it checked nothing".
		expect(
			tools.length,
			'The catalogue must not be empty with both apps installed',
		).toBeGreaterThan(0)

		const missing = tools.filter(
			(t: any) => typeof t?.reach !== 'string' || t.reach === '',
		)

		expect(
			missing.map((t: any) => t.id),
			'Every entry must carry a reach',
		).toEqual([])

		const outOfVocabulary = tools

			.filter((t: any) => !REACHES.includes(t.reach))

			.map((t: any) => `${t.id}=${t.reach}`)
		expect(
			outOfVocabulary,
			'Every reach must come from the closed vocabulary',
		).toEqual([])

		// 🔴 THE CROSS-APP ASSERTION. The two checks above would both PASS if the
		// bridge were dropping `reach` — every entry would still carry one, and
		// `external` is in the vocabulary. Only the distribution gives it away.

		const byId = new Map(tools.map((t: any) => [t.id, t]))
		for (const id of NATIVE_READS) {
			const tool = byId.get(id)
			expect(tool, `${id} must be present in the catalogue`).toBeTruthy()

			expect(
				(tool as any).reach,
				`${id} must resolve to 'user'. Getting 'external' means the reach `
					+ 'annotation is being DROPPED between Hermiq and OpenRegister — check '
					+ 'McpProviderBridge PASSTHROUGH_KEYS.',
			).toBe('user')
		}

		// Same `read` scope, different reach — the axis earning its existence.

		const webFetch = byId.get('hermiq.webFetch') as any
		expect(webFetch, 'hermiq.webFetch must be present').toBeTruthy()
		expect(webFetch.reach).toBe('external')
		expect(
			webFetch.requiresExplicitGrant,
			'An external-reach tool must need naming',
		).toBe(true)
	})

	test('a #noapproval waiver survives a persist and read-back', async ({
		page,
	}) => {
		const waived = `openregister.runFlow?flowId=00000000-0000-0000-0000-000000000000${WAIVER}`

		const put = await page.request.put(
			`/index.php/apps/hermiq/api/agents/${agentId}/tool-grants`,
			{
				headers: jsonHeaders(token),
				data: { grants: [waived, 'hermiq.listFiles'] },
			},
		)
		// Carry the BODY into the failure message. A bare status number turns a
		// server-side exception into a guessing game — this run cost a full CI
		// cycle to learn only that it was "500".
		const putBody = await put.text().catch(() => '<unreadable>')
		expect(
			put.status(),
			`The owner may write grants (body: ${putBody.slice(0, 300)})`,
		).toBe(200)

		// Read back through a DIFFERENT endpoint than the one that wrote it: the
		// PUT response echoes what it just saved, so asserting on that alone
		// would pass even if nothing was persisted.
		const read = await page.request.get(
			`${OR_API}/objects/hermiq/agent/${agentId}`,
			{ headers: jsonHeaders(token) },
		)
		expect(read.status(), 'The agent read-back must succeed').toBe(200)

		const body = await read.json()
		const tools = body?.tools ?? body?.['@self']?.tools
		expect(
			Array.isArray(tools),
			'Agent.tools must persist as an array of strings',
		).toBe(true)

		// Verbatim: fragment, constraint and their order all intact. A parser
		// that stripped the fragment before saving, or absorbed it into the
		// constraint value, fails right here.
		expect(tools, 'The waived grant must round-trip byte for byte').toContain(
			waived,
		)
		expect(tools).toContain('hermiq.listFiles')

		const unexpected = tools.filter(
			(t: string) => t.includes(WAIVER) && t !== waived,
		)
		expect(unexpected, 'No other grant may come back carrying a waiver').toEqual(
			[],
		)
	})

	test('a non-owner is refused on BOTH grant write paths', async ({ page }) => {
		expect(secondCtx, 'The second-user context must exist').not.toBeNull()
		const ctx = secondCtx as APIRequestContext

		// 🔴 Prove the second user can authenticate BEFORE asserting anything
		// refuses them. Otherwise "refused" and "cannot log in at all" are the
		// same observation, and the guard under test is never reached.
		await assertSecondUserAuthenticates(ctx, (second as SecondUser).uid)

		const attack = ['hermiq.sendMail', 'hermiq.webFetch']

		// Path 1 — hermiq's own tool-grants endpoint. Already guarded before this
		// change, so a pass here alone proves nothing; it is the regression half.
		const viaHermiq = await ctx.put(
			`/index.php/apps/hermiq/api/agents/${agentId}/tool-grants`,
			{
				headers: { 'OCS-APIRequest': 'true', Accept: 'application/json' },
				data: { grants: attack },
			},
		)
		expect(
			[401, 403].includes(viaHermiq.status()),
			`Hermiq's tool-grants endpoint must refuse a non-owner, got ${viaHermiq.status()}`,
		).toBeTruthy()

		// Path 2 — the GENERIC OpenRegister objects API. THIS is the path the
		// reproduced IDOR used: it returned 200 and replaced the grants. It is
		// closed by the Agent schema's authorization block, not by app code.
		const viaGeneric = await ctx.put(
			`${OR_API}/objects/hermiq/agent/${agentId}`,
			{
				headers: { 'OCS-APIRequest': 'true', Accept: 'application/json' },
				data: { name: 'hijacked', tools: attack },
			},
		)
		expect(
			[401, 403, 404].includes(viaGeneric.status()),
			`The generic object path must refuse a non-owner, got ${viaGeneric.status()} — `
				+ 'a 200 here is the reproduced IDOR, reopened.',
		).toBeTruthy()

		// 🔴 The assertion that actually matters: whatever status came back, the
		// STORED grants must be untouched. A refusal that still wrote would be
		// the worst of both worlds, and status codes alone cannot see it.
		const read = await page.request.get(
			`${OR_API}/objects/hermiq/agent/${agentId}`,
			{ headers: jsonHeaders(token) },
		)
		expect(read.status()).toBe(200)
		const stored = (await read.json())?.tools ?? []
		expect(
			stored,
			'A refused attack must leave the grant list unchanged',
		).not.toContain('hermiq.sendMail')
	})
})
