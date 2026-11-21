/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spec-coverage e2e: the built rows that have a screen
 * (spec-coverage-built-rows 1.3).
 *
 * WHAT THE UNIT TESTS CANNOT SHOW. The guards and services behind these rows are
 * pinned by PHPUnit and vitest with stubbed OpenRegister reads. They cannot show
 * that the agent page puts the skills, grants and model on one screen, that a
 * temperature outside 0 to 2 is refused on the real write path and the stored
 * agent keeps its old value, that only the owner can consolidate a memory, that
 * an exported skill imports again unchanged, or that chat health reaches the
 * connections page without a secret. That sits between OpenRegister, the
 * controllers and the pages, so it is here.
 *
 * The scenarios without a screen (the dispatcher clock, the time zone rule, the
 * model's tool calls, Talk delivery, the first-install wizard) carry a
 * reason-bearing `@e2e exclude` in the spec deltas instead.
 *
 * The administrator runs it (the project's admin storage state); a second,
 * non-admin user is provisioned for the owner-only scenario and for the
 * first-visit walkthrough. Every seeded object carries the e2espec- family prefix
 * and is removed in afterAll, with the second user.
 *
 * Not run when written: it needs a running Nextcloud with Hermiq and
 * OpenRegister installed.
 *
 *     NEXTCLOUD_URL=http://localhost:8080 NC_USER=admin NC_PASS=admin \
 *       npx playwright test --project=chromium spec-coverage-built-rows
 *
 * @spec openspec/changes/spec-coverage-built-rows/tasks.md
 */

import type { APIRequestContext, Browser, BrowserContext } from '@playwright/test'
import type { SecondUser } from './_fixtures.ts'

import { expect, test } from '@playwright/test'
import {
	appRoot,
	cleanupFamily,
	createSecondUser,
	deleteSecondUser,
	dismissTour,
	harvestToken,
	jsonHeaders,
	openAgentForm,
	OR_API,
	seedAgent,
	TEST_PREFIX,
} from './_fixtures.ts'

const API = '/index.php/apps/hermiq/api'

/** The refusal the agent form and the pre-save guard both give. */
const OUT_OF_RANGE = 'The temperature must be between 0 and 2.'

/** The schemas this file seeds, swept in afterAll. */
const SEEDED_SCHEMAS = ['agent', 'skill', 'memory']

/**
 * A skill package in the agentskills.io format: frontmatter, then the body.
 *
 * @param name The skill name.
 * @return The package text.
 */
function skillPackage(name: string): string {
	return [
		'---',
		`name: ${name}`,
		'description: Tidies meeting notes into decisions and actions. Seeded by tests/e2e/spec-coverage (safe to delete).',
		'---',
		'',
		'# Meeting notes cleanup',
		'',
		'List the decisions first, then each action with its owner and date.',
		'',
	].join('\n')
}

/**
 * The frontmatter and the body of a package, each trimmed.
 *
 * @param pkg The package text.
 * @return The two parts.
 */
function packageParts(pkg: string): { frontmatter: string; body: string } {
	const match = /^---\n([\s\S]*?)\n---\n([\s\S]*)$/.exec(
		pkg.replace(/\r\n/g, '\n'),
	)
	expect(match, 'the export is a package with frontmatter').not.toBeNull()
	return {
		frontmatter: (match?.[1] ?? '').trim(),
		body: (match?.[2] ?? '').trim(),
	}
}

/**
 * Import a skill package through hermiq and answer the created skill's id.
 *
 * @param req   The request context.
 * @param token The CSRF request-token.
 * @param pkg   The package text.
 * @return The skill id.
 */
async function importSkill(
	req: APIRequestContext,
	token: string,
	pkg: string,
): Promise<string> {
	const res = await req.post(`${API}/skills`, {
		headers: jsonHeaders(token),
		data: { package: pkg },
	})
	expect(res.ok(), `import skill: HTTP ${res.status()}`).toBeTruthy()
	const body = await res.json()
	const id = String(body.id ?? body.uuid ?? body['@self']?.id ?? '')
	expect(id, 'the imported skill carries an id').not.toEqual('')
	return id
}

/**
 * The stored agent, read back through OpenRegister.
 *
 * @param req   The request context.
 * @param token The CSRF request-token.
 * @param id    The agent id.
 * @return The stored object.
 */
async function storedAgent(
	req: APIRequestContext,
	token: string,
	id: string,
): Promise<Record<string, unknown>> {
	const res = await req.get(`${OR_API}/objects/hermiq/agent/${id}`, {
		headers: jsonHeaders(token),
	})
	expect(res.ok(), `read agent: HTTP ${res.status()}`).toBeTruthy()
	return res.json()
}

/**
 * A browser context signed in as the second user, by basic auth.
 *
 * @param browser The Playwright browser.
 * @param user    The second user.
 * @return The context.
 */
async function contextAs(
	browser: Browser,
	user: SecondUser,
): Promise<BrowserContext> {
	return browser.newContext({
		storageState: { cookies: [], origins: [] },
		httpCredentials: {
			username: user.uid,
			password: user.password,
			send: 'always',
		},
	})
}

test.describe('spec-coverage-built-rows: the rows with a screen', () => {
	let token = ''
	let member: SecondUser | null = null

	test.beforeAll(async ({ browser }) => {
		const page = await browser.newPage()
		token = await harvestToken(page)
		member = await createSecondUser(page.request, token, 'built-rows')
		await page.close()
	})

	test.afterAll(async ({ browser }) => {
		const page = await browser.newPage()
		for (const schema of SEEDED_SCHEMAS) {
			await cleanupFamily(page.request, token, schema)
		}
		if (member !== null) {
			await deleteSecondUser(page.request, token, member.uid)
		}
		await page.close()
	})

	// @e2e agent-management-ui::an-owner-checks-what-an-agent-can-reach
	test('the agent page lists its skill, its grant and its model without opening a dialog', async ({
		page,
	}) => {
		const skillName = `${TEST_PREFIX}-summarise-a-permit`
		const skillId = await importSkill(
			page.request,
			token,
			skillPackage(skillName),
		)
		const agent = await seedAgent(page.request, token, {
			name: `${TEST_PREFIX}-permit-helper`,
			provider: 'ollama',
			model: 'qwen2.5',
			tools: ['hermiq.agent.search'],
		})
		const install = await page.request.post(`${API}/skills/${skillId}/install`, {
			headers: jsonHeaders(token),
			data: { agentId: agent.id },
		})
		expect(install.ok(), `install skill: HTTP ${install.status()}`).toBeTruthy()

		await page.goto(`${await appRoot(page)}/agents/${agent.id}`, {
			waitUntil: 'domcontentloaded',
		})
		await dismissTour(page)

		await expect(page.getByText(skillName).first()).toBeVisible({
			timeout: 30_000,
		})
		await expect(page.getByText('hermiq.agent.search').first()).toBeVisible()
		await expect(page.getByText('ollama').first()).toBeVisible()
		await expect(page.getByText('qwen2.5').first()).toBeVisible()
		await expect(
			page.locator('[data-testid="cn-modal"]'),
			'no dialog was needed',
		).toHaveCount(0)
	})

	// @e2e agent-management-ui::a-value-out-of-range-is-refused
	test('a temperature of 3 is refused on the form and the agent keeps its old temperature', async ({
		page,
	}) => {
		const agent = await seedAgent(page.request, token, {
			name: `${TEST_PREFIX}-temperature`,
			temperature: 0.2,
		})

		await page.goto(`${await appRoot(page)}/agents/${agent.id}`, {
			waitUntil: 'domcontentloaded',
		})
		await dismissTour(page)
		const form = await openAgentForm(page)
		await form.getByTestId('agent-form-temperature').locator('input').fill('3')
		await expect(form.getByText(OUT_OF_RANGE).first()).toBeVisible()
		await page.getByRole('button', { name: 'Save' }).last().click()
		await expect(page.getByText(OUT_OF_RANGE).first()).toBeVisible()

		// The write path refuses it too, not only the form.
		const direct = await page.request.put(
			`${OR_API}/objects/hermiq/agent/${agent.id}`,
			{
				headers: jsonHeaders(token),
				data: { name: agent.name, temperature: 3 },
			},
		)
		expect(direct.ok(), 'a direct write of 3 is refused').toBeFalsy()

		const stored = await storedAgent(page.request, token, agent.id)
		expect(
			stored.temperature,
			'the stored agent keeps its old temperature',
		).toBe(0.2)
	})

	// @e2e agent-memory::someone-else-cannot-consolidate
	test('a user who does not own the agent cannot consolidate its memory', async ({
		browser,
		page,
	}) => {
		const agent = await seedAgent(page.request, token, {
			name: `${TEST_PREFIX}-memory-guard`,
		})
		const fact = await page.request.post(`${API}/agents/${agent.id}/memory`, {
			headers: jsonHeaders(token),
			data: { text: 'Permit applications are answered within eight weeks.' },
		})
		expect(fact.ok(), `remember a fact: HTTP ${fact.status()}`).toBeTruthy()
		const before = await (
			await page.request.get(`${API}/agents/${agent.id}/memory`, {
				headers: jsonHeaders(token),
			})
		).json()

		const context = await contextAs(browser, member as SecondUser)
		const res = await context.request.post(
			`${API}/agents/${agent.id}/memory/consolidate`,
			{
				headers: {
					'OCS-APIRequest': 'true',
					'Content-Type': 'application/json',
				},
				data: { entries: [] },
			},
		)
		expect(
			[403, 404],
			`the non-owner is refused (HTTP ${res.status()})`,
		).toContain(res.status())
		await context.close()

		const after = await (
			await page.request.get(`${API}/agents/${agent.id}/memory`, {
				headers: jsonHeaders(token),
			})
		).json()
		expect(after.entries, 'the memory is unchanged').toEqual(before.entries)
	})

	// @e2e agent-memory::an-owner-consolidates-an-over-budget-memory
	test('the owner consolidates an over-budget memory from the Memory page', async ({
		page,
	}) => {
		const agent = await seedAgent(page.request, token, {
			name: `${TEST_PREFIX}-memory-tidy`,
		})
		const filler =
			'The permit desk answers by phone on weekdays and checks every application against the zoning plan first. '
		for (let i = 0; i < 40; i++) {
			const res = await page.request.post(`${API}/agents/${agent.id}/memory`, {
				headers: jsonHeaders(token),
				data: { text: `${i + 1}. ${filler.repeat(2)}` },
			})
			expect(
				res.ok(),
				`remember fact ${i + 1}: HTTP ${res.status()}`,
			).toBeTruthy()
		}
		const before = await (
			await page.request.get(`${API}/agents/${agent.id}/memory`, {
				headers: jsonHeaders(token),
			})
		).json()
		expect(
			before.needsConsolidation,
			'the seeded memory is over its budget',
		).toBe(true)

		await page.goto(`${await appRoot(page)}/memory`, {
			waitUntil: 'domcontentloaded',
		})
		await dismissTour(page)
		await page.locator('.agent-memory').getByRole('combobox').first().click()
		await page.getByRole('option', { name: agent.name }).click()
		await expect(
			page.getByText('Over budget — consolidation suggested', {
				exact: false,
			}),
		).toBeVisible({ timeout: 20_000 })

		const consolidated = page.waitForResponse((r) =>
			r.url().includes(`/agents/${agent.id}/memory/consolidate`),
		)
		await page.getByRole('button', { name: 'Consolidate memory' }).click()
		const response = await consolidated
		test.skip(
			!response.ok(),
			`consolidation needs an answering model (HTTP ${response.status()})`,
		)

		const after = await (
			await page.request.get(`${API}/agents/${agent.id}/memory`, {
				headers: jsonHeaders(token),
			})
		).json()
		expect(after.entries.length, 'the memory holds fewer facts').toBeLessThan(
			before.entries.length,
		)
		expect(after.needsConsolidation, 'the memory is within its budget').toBe(
			false,
		)
		await expect(
			page.getByText('Over budget — consolidation suggested', {
				exact: false,
			}),
		).toHaveCount(0)
	})

	// @e2e skills-catalog::a-skill-is-downloaded
	test('an exported skill carries its frontmatter and body and imports again unchanged', async ({
		page,
	}) => {
		const name = `${TEST_PREFIX}-meeting-notes-cleanup`
		const skillId = await importSkill(page.request, token, skillPackage(name))

		await page.goto(`${await appRoot(page)}/skills`, {
			waitUntil: 'domcontentloaded',
		})
		await dismissTour(page)
		const row = page.getByRole('row').filter({ hasText: name })
		await expect(row).toBeVisible({ timeout: 30_000 })
		await expect(row.getByRole('button', { name: 'Export' })).toBeVisible()

		const exported = await page.request.get(`${API}/skills/${skillId}/export`, {
			headers: jsonHeaders(token),
		})
		expect(exported.ok(), `export: HTTP ${exported.status()}`).toBeTruthy()
		const pkg = String((await exported.json()).package ?? '')
		const first = packageParts(pkg)
		expect(first.frontmatter).toContain(`name: ${name}`)
		expect(first.body).toContain('List the decisions first')

		const againId = await importSkill(page.request, token, pkg)
		const again = await page.request.get(`${API}/skills/${againId}/export`, {
			headers: jsonHeaders(token),
		})
		const second = packageParts(String((await again.json()).package ?? ''))
		expect(
			second,
			'the re-imported skill has the same frontmatter and body',
		).toEqual(first)
	})

	// @e2e observability::a-provider-is-configured
	// @e2e observability::no-provider-is-configured
	test('chat health answers ok or not configured, and never holds a secret', async ({
		page,
	}) => {
		const res = await page.request.get(`${API}/chat/health`, {
			headers: jsonHeaders(token),
		})
		const text = await res.text()
		const body = JSON.parse(text)

		if (body.configured === true) {
			expect(res.status()).toBe(200)
			expect(body.status).toBe('ok')
		} else {
			expect(body.status).toBe('unconfigured')
		}
		expect(text, 'no key in the answer').not.toMatch(
			/api[_-]?key|secret|password|bearer|sk-[A-Za-z0-9]/i,
		)
	})

	// @e2e observability::the-connections-page-shows-chat-health-with-each-dependency
	test('the connections page shows chat health above its dependencies, without a secret', async ({
		page,
	}) => {
		await page.goto(`${await appRoot(page)}/settings/integrations?app=hermiq`, {
			waitUntil: 'domcontentloaded',
		})
		await dismissTour(page)

		const block = page.locator('.chat-health')
		await expect(block).toBeVisible({ timeout: 30_000 })
		await expect(block.locator('.chat-health__headline')).toBeVisible()
		const rows = block.locator('.chat-health__row')
		expect(
			await rows.count(),
			'each dependency has its own row',
		).toBeGreaterThan(0)
		for (const row of await rows.all()) {
			await expect(row.locator('.chat-health__state')).toBeVisible()
			await expect(row.locator('.chat-health__checked')).toBeVisible()
		}
		await expect(block).not.toContainText(
			/api[_-]?key|secret|password|bearer|sk-[A-Za-z0-9]/i,
		)
	})

	// @e2e agent-tool-governance::no-app-offers-a-tool
	test('the MCP tools page shows an empty state when no app offers a tool', async ({
		page,
	}) => {
		// Hermiq registers tools itself, so the empty catalogue is answered here.
		await page.route('**/apps/hermiq/api/agents/tools', (route) =>
			route.fulfill({ json: { results: [] } }),
		)
		await page.goto(`${await appRoot(page)}/mcp-tools`, {
			waitUntil: 'domcontentloaded',
		})
		await dismissTour(page)

		await expect(page.getByText('No MCP tools available').first()).toBeVisible({
			timeout: 30_000,
		})
	})

	// @e2e first-time-setup::a-new-user-gets-the-walkthrough
	test('a new user gets the walkthrough on the first visit, pointing at the agents', async ({
		browser,
	}) => {
		const context = await contextAs(browser, member as SecondUser)
		const page = await context.newPage()
		await page.goto(`${await appRoot(page)}/`, { waitUntil: 'domcontentloaded' })

		await expect(page.getByRole('button', { name: 'Close tour' })).toBeVisible({
			timeout: 30_000,
		})
		await expect(page.getByText('Agents').first()).toBeVisible()
		await context.close()
	})
})
