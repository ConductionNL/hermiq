/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spec-coverage e2e — project memories next to the shared memory
 * (memory-project-scopes, Tasks 4 and 5).
 *
 * Browser scenarios of openspec/changes/memory-project-scopes:
 *  - REQ-PMEM-001 "A policy advisor starts a project session": on /chat the
 *    person starts a session with an agent that has a project memory, picks
 *    the project in the "Project" dialog, and the session header reads
 *    "Project: Omgevingsvisie 2040". The session the server created carries
 *    the project key.
 *  - REQ-PMEM-004 "A general rule moves to shared": on the Memory page the
 *    owner opens the project's tab, chooses "Move to shared" on an entry, and
 *    the entry shows under "Shared" and no longer under the project.
 *
 * Left out, both marked `@e2e exclude` in the spec:
 *  - REQ-PMEM-002 "Facts from another project stay out": a tool result, not a
 *    page; PHPUnit on `recallEntries()` with three memory objects.
 *  - REQ-PMEM-003 "A deadline lands in the project": the model chooses when to
 *    call the tool; PHPUnit on the memory tool dispatch.
 * The live check in tasks.md (two project sessions, a fact from one does not
 * come back in the other) needs a real model and stays a live check.
 *
 * 🔑 THE PROJECT MEMORY IS SEEDED, NOT REMEMBERED. Only the model writes a
 * project entry (hermiq.rememberMemory in a project session), so the
 * project's `memory` object is created through the OpenRegister objects API in
 * the shape lib/Settings/hermiq_register.json declares (`scope: project`,
 * `projectKey`, `projectName`, `entries`). That is the same object
 * MemoryService::listProjects() and getMemory() read. Memory objects carry no
 * name, so cleanup sweeps them by the seeded agent's id.
 *
 * Run against a disposable Nextcloud with hermiq + OpenRegister installed:
 *
 *     PLAYWRIGHT_BASE_URL=<your rig> NC_USER=admin NC_PASS=admin \
 *       npx playwright test -c tests/e2e/spec-coverage/playwright.config.ts memory-project-scopes
 *
 * Covers openspec/specs/agent-memory/spec.md
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { randomUUID } from 'crypto'
import {
	appRoot,
	cleanupFamily,
	deleteObject,
	dismissTour,
	harvestToken,
	jsonHeaders,
	OR_API,
	resolveRegisterSchema,
	seedAgent,
	seedObject,
	TEST_PREFIX,
} from './_fixtures.ts'

const API = '/index.php/apps/hermiq/api'

/** The project of the scenarios, as the change's seed data names it. */
const PROJECT_KEY = 'omgevingsvisie-2040'
const PROJECT_NAME = 'Omgevingsvisie 2040'

/** The project entry that moves to shared (REQ-PMEM-004). */
const GENERAL_RULE = 'Beleidsstukken gebruiken B1-taalniveau.'

/**
 * Seed the agent's memory for one project, holding the given facts.
 *
 * @param req     The request context.
 * @param token   The CSRF request-token.
 * @param agentId The agent uuid.
 * @param facts   The entry texts.
 * @return The memory object's id.
 */
async function seedProjectMemory(
	req: APIRequestContext,
	token: string,
	agentId: string,
	facts: string[],
): Promise<string> {
	const now = new Date().toISOString()
	const memory = await seedObject(req, token, 'memory', {
		agentId,
		scope: 'project',
		projectKey: PROJECT_KEY,
		projectName: PROJECT_NAME,
		entries: facts.map((text) => ({ id: randomUUID(), text, createdAt: now })),
	})
	return memory.id
}

/**
 * Delete every memory object of the given agents (best-effort). Memory objects
 * have no name, so cleanupFamily() cannot find them.
 *
 * @param req      The request context.
 * @param token    The CSRF request-token.
 * @param agentIds The seeded agents.
 * @return Resolves once they are gone.
 */
async function cleanupMemories(
	req: APIRequestContext,
	token: string,
	agentIds: string[],
): Promise<void> {
	for (const agentId of agentIds) {
		const res = await req
			.get(`${OR_API}/objects/hermiq/memory?agentId=${agentId}&_limit=50`, {
				headers: jsonHeaders(token),
			})
			.catch(() => null)
		if (!res || !res.ok()) {
			continue
		}
		const body = await res.json().catch(() => ({}))
		const list = (
			Array.isArray(body) ? body : (body.results ?? body.data ?? [])
		) as Array<Record<string, any>>
		for (const obj of list) {
			const id = String(obj.id ?? obj['@self']?.id ?? '')
			if (id !== '' && String(obj.agentId ?? '') === agentId) {
				await deleteObject(req, token, 'memory', id)
			}
		}
	}
}

/**
 * The live (not forgotten) entry texts of one layer, as the Memory page reads them.
 *
 * @param req     The request context.
 * @param token   The CSRF request-token.
 * @param agentId The agent uuid.
 * @param project The project key, or '' for the shared memory.
 * @return The texts.
 */
async function liveTexts(
	req: APIRequestContext,
	token: string,
	agentId: string,
	project: string,
): Promise<string[]> {
	const query = project ? `?project=${project}` : ''
	const res = await req.get(`${API}/agents/${agentId}/memory${query}`, {
		headers: jsonHeaders(token),
	})
	expect(res.status(), `read the ${project || 'shared'} memory`).toBe(200)
	const memory = await res.json()
	return ((memory.entries ?? []) as Array<{ text: string; deletedAt?: string }>)
		.filter((e) => !e.deletedAt)
		.map((e) => e.text)
}

test.describe('memory-project-scopes: the project picker and moving entries between layers', () => {
	let token = ''
	const agentIds: string[] = []
	const sessions: string[] = []

	test.beforeAll(async ({ browser }) => {
		const page = await browser.newPage()
		token = await harvestToken(page)
		await resolveRegisterSchema(page.request, token, 'agent')
		await resolveRegisterSchema(page.request, token, 'memory')
		await page.close()
	})

	test.afterAll(async ({ browser }) => {
		const page = await browser.newPage()
		const cleanupToken = await harvestToken(page)
		for (const uuid of sessions) {
			await page.request
				.delete(`${API}/sessions/${uuid}`, { headers: jsonHeaders(cleanupToken) })
				.catch(() => null)
			await page.request
				.delete(`${API}/sessions/${uuid}/permanent`, {
					headers: jsonHeaders(cleanupToken),
				})
				.catch(() => null)
		}
		await cleanupMemories(page.request, cleanupToken, agentIds)
		await cleanupFamily(page.request, cleanupToken, 'agent')
		await page.close()
	})

	// e2e: agent-memory::a-policy-advisor-starts-a-project-session
	test('a policy advisor starts a project session', async ({ page }) => {
		const agent = await seedAgent(page.request, token, {
			name: `${TEST_PREFIX}-Beleidsondersteuner`,
		})
		agentIds.push(agent.id)
		await seedProjectMemory(page.request, token, agent.id, [
			'De raad behandelt het ontwerp op 14 november 2026.',
		])

		const root = await appRoot(page)
		await page.goto(`${root}/chat`, { waitUntil: 'domcontentloaded' })
		await dismissTour(page)

		const card = page
			.locator('.agent-selector__card')
			.filter({ hasText: agent.name })
		await expect(card).toBeVisible({ timeout: 20_000 })
		await card.getByRole('button', { name: 'Start session' }).click()

		// An agent with a project memory asks which project (decision D2).
		const dialog = page.getByRole('dialog', { name: 'Project' })
		await expect(dialog).toBeVisible()
		const choices = dialog.getByTestId('project-picker')
		await expect(choices.getByText(PROJECT_NAME, { exact: true })).toBeVisible()
		await expect(choices.getByText('New project', { exact: true })).toBeVisible()
		await expect(choices.getByText('No project', { exact: true })).toBeVisible()
		await choices.getByText(PROJECT_NAME, { exact: true }).click()

		const createRequest = page.waitForRequest(
			(req) =>
				req.url().includes('/apps/hermiq/api/sessions')
				&& req.method() === 'POST',
		)
		const createResponse = page.waitForResponse(
			(res) =>
				res.url().includes('/apps/hermiq/api/sessions')
				&& res.request().method() === 'POST',
		)
		await dialog.getByRole('button', { name: 'Start session' }).click()

		expect((await createRequest).postDataJSON()).toMatchObject({
			projectKey: PROJECT_KEY,
		})
		const created = await (await createResponse).json().catch(() => ({}))
		const uuid = String(created.uuid ?? created.id ?? '')
		if (uuid !== '') {
			sessions.push(uuid)
		}
		expect(created.projectKey, 'the session carries the project key').toBe(
			PROJECT_KEY,
		)

		await expect(page.getByTestId('chat-project')).toHaveText(
			`Project: ${PROJECT_NAME}`,
		)
		await expect(page.locator('.chat-page__composer')).toBeVisible({
			timeout: 20_000,
		})
	})

	// e2e: agent-memory::a-general-rule-moves-to-shared
	test('a general rule moves to shared', async ({ page }) => {
		const agent = await seedAgent(page.request, token, {
			name: `${TEST_PREFIX}-Beleidsondersteuner-move`,
		})
		agentIds.push(agent.id)
		await seedProjectMemory(page.request, token, agent.id, [GENERAL_RULE])

		const root = await appRoot(page)
		await page.goto(`${root}/memory`, { waitUntil: 'domcontentloaded' })
		await dismissTour(page)

		const picker = page.getByRole('combobox', { name: 'Agent' })
		await expect(picker).toBeVisible({ timeout: 20_000 })
		await picker.click()
		await picker.fill(agent.name)
		await page.getByRole('option', { name: agent.name }).click()

		// The layers: "Shared", then one tab per project.
		const layers = page.getByRole('tablist', { name: 'Memory layers' })
		await expect(layers).toBeVisible({ timeout: 15_000 })
		const sharedTab = layers.getByRole('tab', { name: 'Shared' })
		const projectTab = layers.getByRole('tab', { name: PROJECT_NAME })
		await expect(sharedTab).toHaveAttribute('aria-selected', 'true')
		await projectTab.click()
		await expect(projectTab).toHaveAttribute('aria-selected', 'true')

		const panel = page.locator('.agent-memory-panel')
		const entry = panel
			.locator('.agent-memory-panel__entry')
			.filter({ hasText: GENERAL_RULE })
		await expect(entry).toBeVisible({ timeout: 15_000 })

		const moved = page.waitForResponse(
			(res) =>
				/\/api\/agents\/[^/]+\/memory\/entries\/[^/]+\/move$/.test(res.url())
				&& res.request().method() === 'POST',
		)
		await entry.getByRole('button', { name: 'Move to shared' }).click()
		const move = await moved
		expect(move.status(), 'the owner may move an entry').toBe(200)
		expect(move.request().postDataJSON()).toEqual({ from: PROJECT_KEY, to: '' })

		// No longer under the project …
		await expect(entry).toHaveCount(0)

		// … and under "Shared".
		await sharedTab.click()
		await expect(sharedTab).toHaveAttribute('aria-selected', 'true')
		await expect(
			panel.locator('.agent-memory-panel__entry').filter({ hasText: GENERAL_RULE }),
		).toBeVisible({ timeout: 15_000 })

		// The page shows what the server holds.
		expect(await liveTexts(page.request, token, agent.id, PROJECT_KEY)).not.toContain(
			GENERAL_RULE,
		)
		expect(await liveTexts(page.request, token, agent.id, '')).toContain(GENERAL_RULE)
	})
})
