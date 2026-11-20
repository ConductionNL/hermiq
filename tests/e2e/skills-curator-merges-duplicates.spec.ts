/**
 * The curator merges duplicate skills (skills-curator-merges-duplicates).
 *
 * A merge proposal sits on the duplicate skill, as the curator job writes it, and a
 * person decides it on the Skills page: "Merge" archives the duplicate and moves its
 * agents to the skill that stays, "Keep both" leaves both active and remembers the
 * pair. The proposal is seeded through OpenRegister's object API in exactly the shape
 * the curator writes (`mergeProposal: { survivor, reason, proposedAt }`), because the
 * curator is a background job a browser cannot start; the decision and its effects are
 * driven and read through the real page and the real objects.
 *
 * The decision routes need the `skill.merge-duplicates` action; this runs as the admin,
 * who holds it by default. A person without it sees no section, which is pinned in
 * SkillMergeControllerTest and tests/vitest/skill-merge-proposals.spec.js.
 *
 * @e2e skills-marketplace::an-approved-merge-archives-the-duplicate-and-moves-its-agents
 * @e2e skills-marketplace::a-rejected-merge-changes-nothing
 * @e2e exclude skills-marketplace::two-duplicate-skills-get-one-merge-proposal {the curator is a background job a browser run cannot start or wait for; pinned in SkillMergeServiceTest::testTwoDuplicateSkillsGetOneProposal and ::testTheSameInstructionsAreADuplicateAndTheOlderStays}
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

import type { APIRequestContext, Page } from '@playwright/test'

import { expect, request, test } from '@playwright/test'
import { BASE_URL } from './base-url.ts'

const NC_USER = process.env.NC_USER || 'admin'
const NC_PASS = process.env.NC_PASS || 'admin'

/** OpenRegister's object API for hermiq's register. */
const OBJECTS = '/index.php/apps/openregister/api/objects/hermiq'

/** A prefix unique to this run, so parallel runs cannot see each other's skills. */
const RUN = `e2e-merge-${Date.now()}-${Math.floor(Math.random() * 1e6)}`

/**
 * An API context signed in as the admin, with no browser state of its own.
 *
 * @return The context.
 */
async function adminApi(): Promise<APIRequestContext> {
	return request.newContext({
		baseURL: BASE_URL,
		storageState: { cookies: [], origins: [] },
		httpCredentials: { username: NC_USER, password: NC_PASS, send: 'always' },
		extraHTTPHeaders: { Accept: 'application/json', 'OCS-APIRequest': 'true' },
	})
}

/**
 * Create one object and return its id; a refused seed fails loudly.
 *
 * @param api The API context.
 * @param schema The schema.
 * @param data The object.
 *
 * @return The id.
 */
async function create(api: APIRequestContext, schema: string, data: object): Promise<string> {
	const res = await api.post(`${OBJECTS}/${schema}`, { data })
	expect(res.ok(), `seeding ${schema} failed ${res.status()}: ${await res.text()}`).toBe(true)
	const body = await res.json()
	const id = body?.['@self']?.id ?? body?.id
	expect(id, `the seeded ${schema} must carry an id`).toBeTruthy()

	return String(id)
}

/**
 * Read one object.
 *
 * @param api The API context.
 * @param schema The schema.
 * @param id The id.
 *
 * @return The status and the object.
 */
async function read(api: APIRequestContext, schema: string, id: string): Promise<{ status: number; body: Record<string, any> }> {
	const res = await api.get(`${OBJECTS}/${schema}/${id}`)
	return { status: res.status(), body: res.ok() ? await res.json() : {} }
}

/**
 * Log the admin in through Nextcloud's own login form (idempotent).
 *
 * @param page The page.
 */
async function login(page: Page): Promise<void> {
	await page.goto('/login', { waitUntil: 'domcontentloaded' })
	const userField = page.locator('#user')
	if ((await userField.count()) === 0) {
		return
	}
	await userField.fill(NC_USER)
	await page.locator('#password').fill(NC_PASS)
	await page.locator('button[type="submit"], input[type="submit"]').first().click()
	await page.locator('#user').waitFor({ state: 'hidden', timeout: 30_000 })
}

/**
 * A survivor, a duplicate carrying the curator's proposal, and an agent on the duplicate.
 *
 * @param api The API context.
 * @param label What tells this pair apart.
 *
 * @return The ids.
 */
async function seedPair(api: APIRequestContext, label: string): Promise<{ survivor: string; duplicate: string; agent: string; name: string }> {
	const name = `${RUN}-${label}`
	const body = `Summarise the tender in five bullet points (${label}).`
	const survivor = await create(api, 'agentskill', { name, body, state: 'active', installedOn: [] })
	const agent = await create(api, 'agent', { name: `${name}-agent`, skillInstalls: [] })
	const duplicate = await create(api, 'agentskill', {
		name: `${name.toUpperCase()}!`,
		body,
		state: 'active',
		installedOn: [agent],
		mergeProposal: { survivor, reason: 'same-name', proposedAt: new Date().toISOString() },
	})

	return { survivor, duplicate, agent, name }
}

/**
 * The proposal row naming a duplicate, on the Skills page.
 *
 * @param page The page.
 * @param duplicateName The duplicate's name.
 *
 * @return The row.
 */
async function proposalRow(page: Page, duplicateName: string) {
	await page.goto('/apps/hermiq/skills', { waitUntil: 'domcontentloaded' })
	const section = page.getByRole('region', { name: 'Merge proposals' })
	await expect(section, 'the Skills page must show the merge proposals to the admin').toBeVisible({ timeout: 30_000 })
	const row = section.locator('.skill-merges__item').filter({ hasText: duplicateName })
	await expect(row, 'the seeded proposal must be listed').toBeVisible({ timeout: 15_000 })

	return row
}

test.describe('the curator merges duplicate skills', () => {
	let api: APIRequestContext
	const cleanup: Array<[string, string]> = []

	test.beforeAll(async () => {
		api = await adminApi()
	})

	test.afterAll(async () => {
		for (const [schema, id] of cleanup.reverse()) {
			await api.delete(`${OBJECTS}/${schema}/${id}`).catch(() => undefined)
		}
		await api.dispose()
	})

	test.beforeEach(async ({ page }) => {
		await login(page)
	})

	// @e2e skills-marketplace::an-approved-merge-archives-the-duplicate-and-moves-its-agents
	test('merging archives the duplicate, keeps it, and moves its agent to the survivor', async ({ page }) => {
		test.setTimeout(120_000)
		const pair = await seedPair(api, 'approve')
		cleanup.push(['agentskill', pair.survivor], ['agentskill', pair.duplicate], ['agent', pair.agent])

		const row = await proposalRow(page, `${pair.name.toUpperCase()}!`)
		await expect(row).toContainText(pair.name)
		await row.getByRole('button', { name: 'Merge', exact: true }).click()
		await expect(row, 'a decided proposal leaves the list').toBeHidden({ timeout: 15_000 })

		const duplicate = await read(api, 'agentskill', pair.duplicate)
		expect(duplicate.status, 'the duplicate is archived, not deleted').toBe(200)
		expect(duplicate.body.state).toBe('archived')
		expect(duplicate.body.mergedInto).toBe(pair.survivor)
		expect(duplicate.body.mergeProposal ?? null).toBeNull()
		expect(duplicate.body.installedOn ?? []).not.toContain(pair.agent)

		const survivor = await read(api, 'agentskill', pair.survivor)
		expect(survivor.body.state ?? 'active').toBe('active')
		expect(survivor.body.installedOn, 'the agent now uses the surviving skill').toContain(pair.agent)

		const agent = await read(api, 'agent', pair.agent)
		expect(agent.body.skillInstalls ?? []).toContain(pair.survivor)
		expect(agent.body.skillInstalls ?? []).not.toContain(pair.duplicate)
	})

	// @e2e skills-marketplace::a-rejected-merge-changes-nothing
	test('keeping both leaves both skills active and remembers the pair', async ({ page }) => {
		test.setTimeout(120_000)
		const pair = await seedPair(api, 'reject')
		cleanup.push(['agentskill', pair.survivor], ['agentskill', pair.duplicate], ['agent', pair.agent])

		const row = await proposalRow(page, `${pair.name.toUpperCase()}!`)
		await row.getByRole('button', { name: 'Keep both', exact: true }).click()
		await expect(row).toBeHidden({ timeout: 15_000 })

		const duplicate = await read(api, 'agentskill', pair.duplicate)
		expect(duplicate.body.state ?? 'active').toBe('active')
		expect(duplicate.body.mergeProposal ?? null).toBeNull()
		expect(duplicate.body.mergeRejectedWith, 'the pair is remembered so it is not proposed again').toContain(pair.survivor)
		expect(duplicate.body.installedOn).toContain(pair.agent)

		const survivor = await read(api, 'agentskill', pair.survivor)
		expect(survivor.body.state ?? 'active').toBe('active')
		expect(survivor.body.installedOn ?? []).not.toContain(pair.agent)

		// Reloaded, the proposal does not come back.
		await page.reload({ waitUntil: 'domcontentloaded' })
		await expect(page.locator('.skill-merges__item').filter({ hasText: `${pair.name.toUpperCase()}!` })).toHaveCount(0)
	})
})
