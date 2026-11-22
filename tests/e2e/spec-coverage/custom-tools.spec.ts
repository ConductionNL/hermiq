/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spec-coverage e2e — custom tools without code
 * (tools-custom-tools-without-code, Task 5).
 *
 * Browser scenarios of openspec/changes/tools-custom-tools-without-code:
 *  - REQ-CUSTOOL-001 "An organisation admin adds the permit check": on the MCP
 *    tools page the admin presses "Add a custom tool", lands on the Custom tools
 *    page, opens the editor, names the tool "Vergunningcheck", adds the inputs
 *    `adres` and `activiteit`, chooses "Changes nothing", picks the flow
 *    "Vergunningcheck" and saves. The tool is then a row on the Custom tools
 *    page, and the MCP tools catalogue offers it as `hermiq.custom_<slug>`
 *    under its name. The same test checks Task 5's second criterion: every
 *    NcSelect in the editor ("Type", "What does this tool change?", "Target
 *    type", "Flow", and "Method" for an integriq target) is a combobox named by
 *    its inputLabel.
 *  - Task 5's first criterion, "Try it" (REQ-CUSTOOL-001): on a saved tool the
 *    editor shows the warning "This really runs the flow or calls the system.",
 *    takes one sample value per input, sends them typed to the try route and
 *    shows the answer that comes back.
 *
 * Left out:
 *  - REQ-CUSTOOL-001 "An agent owner cannot create one": a crafted POST by a
 *    non-admin to /api/custom-tools, with no screen; PHPUnit on the rights and
 *    Newman for admin and non-admin (tasks.md Task 1).
 *  - REQ-CUSTOOL-002 "A wildcard grant does not reach custom tools":
 *    `@e2e exclude` in the spec, grant resolution per turn; PHPUnit.
 *  - REQ-CUSTOOL-002 "An agent owner grants the tool": the grant editor
 *    (src/components/ToolGrantMatrix.vue) does not show a custom tool with
 *    "Changes nothing"; it renders only its write/del marker, so the
 *    scenario's THEN is not built. Grant resolution is PHPUnit (Task 2).
 *  - REQ-CUSTOOL-003 "A case handler's question uses the tool": needs a model
 *    that decides to call the tool and a flow that answers; the live chat turn
 *    stays the manual check in tasks.md Verification.
 *  - REQ-CUSTOOL-003 "Wrong input is refused before the call": `@e2e exclude`
 *    in the spec, needs a model that sends a wrong value; PHPUnit on
 *    validation.
 *  - REQ-CUSTOOL-004 "A tool that changes data waits for a reviewer": needs a
 *    model to make the call and the approval gate in the engine; PHPUnit on the
 *    FacadeToolInvoker chain for a custom id (Task 4).
 *
 * 🔑 WHAT IS REAL AND WHAT IS NOT.
 *  - The writes are real: the editor saves through hermiq's /api/custom-tools
 *    (CustomToolController), the only write path (the CustomTool schema lets
 *    the objects API read only). The second test seeds its tool through that
 *    same route, not through the objects API, for the same reason.
 *  - The Custom tools list is real: CnIndexPage reads the `customtool` objects
 *    through OpenRegister.
 *  - The MCP tools catalogue is real: /api/agents/tools is OpenRegister's tool
 *    registry, which asks HermiqToolProvider, which builds the custom tool's
 *    descriptor from the stored object (CustomToolDescriptors::describe()).
 *  - The flow list is stubbed with page.route: the editor's flow picker reads
 *    /apps/openregister/api/flows, and a fresh instance has no "Vergunningcheck"
 *    flow. The stub offers one with a made-up uuid. Saving does not resolve the
 *    flow (CustomToolDefinition only requires a flowId), so the stored tool
 *    points at a flow that does not exist and can never run.
 *  - "Try it" is stubbed with page.route: the real route would queue the flow,
 *    which needs a flow engine and a real flow. The stub answers in the shape
 *    CustomToolInvoker gives a flow target (`{result: ...}`), and the test
 *    checks the arguments the editor sent and that the answer is shown.
 *  - Every tool carries TEST_PREFIX in its name and a run-unique slug. afterAll
 *    first stores each one with `enabled: false` (so a refused delete still
 *    leaves nothing in an agent's catalogue), then deletes it and sweeps the
 *    `customtool` schema for the family prefix.
 *  - OpenRegister is required (flows, objects, the tool registry); without it
 *    the suite is skipped, never failed.
 *
 * Runs as the suite's administrator (the default storage state), who passes
 * the organisation-admin gate as instance admin:
 *
 *     PLAYWRIGHT_BASE_URL=<your rig> NC_USER=admin NC_PASS=admin \
 *       npx playwright test -c tests/e2e/spec-coverage/playwright.config.ts custom-tools
 *
 * Covers openspec/changes/tools-custom-tools-without-code/specs/custom-tools/spec.md
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
	TEST_FAMILY,
	TEST_PREFIX,
} from './_fixtures.ts'

const API = '/index.php/apps/hermiq/api'

/** The run-unique part of TEST_PREFIX, usable in a slug (`[a-z0-9_]`). */
const RUN = TEST_PREFIX.slice(TEST_FAMILY.length)

/** The flow the stubbed picker offers; never resolved on save. */
const FLOW_ID = '3f9c2a71-6b4e-4d8a-9c1f-5e2d7a8b0c41'
const FLOW_NAME = 'Vergunningcheck'

const WARNING = 'This really runs the flow or calls the system.'

/** The rule the stubbed flow answers with. */
const RULE = 'APV artikel 2:10: een terras op de openbare weg heeft een vergunning nodig'

/** Tools this run stored, with the payload to switch each one off again. */
const created: Array<{ id: string, payload: Record<string, unknown> }> = []

/**
 * The permit check as the API stores it.
 *
 * @param slug The tool's slug.
 * @param name The tool's name.
 * @return The payload for POST /api/custom-tools.
 */
function permitCheck(slug: string, name: string): Record<string, unknown> {
	return {
		name,
		slug,
		description: 'Checks whether an activity at an address needs a permit, and names the rule.',
		inputSchema: {
			type: 'object',
			properties: {
				adres: { type: 'string' },
				activiteit: { type: 'string', enum: ['terras', 'evenement', 'kap'] },
			},
			required: ['adres'],
		},
		changesData: 'no',
		target: { type: 'flow', flowId: FLOW_ID },
		enabled: true,
	}
}

/**
 * Store a custom tool through hermiq's write route.
 *
 * @param req     The request context.
 * @param token   The CSRF request-token.
 * @param payload The tool.
 * @return The stored tool.
 */
async function createTool(
	req: APIRequestContext,
	token: string,
	payload: Record<string, unknown>,
): Promise<Record<string, any>> {
	const res = await req.post(`${API}/custom-tools`, {
		headers: jsonHeaders(token),
		data: payload,
	})
	expect(res.status(), await res.text()).toBe(201)
	const tool = await res.json()
	created.push({ id: String(tool.id), payload })
	return tool
}

/**
 * Stub the flow list the editor's flow picker reads.
 *
 * @param page The page.
 * @return Resolves once the route is installed.
 */
async function stubFlows(page: Page): Promise<void> {
	await page.route(/\/apps\/openregister\/api\/flows(\?.*)?$/, (route) => {
		if (route.request().method() !== 'GET') {
			return route.fallback()
		}
		return route.fulfill({
			status: 200,
			contentType: 'application/json',
			body: JSON.stringify({
				results: [{ id: 9101, uuid: FLOW_ID, name: FLOW_NAME }],
			}),
		})
	})
}

/**
 * Open the Custom tools index page.
 *
 * @param page The page.
 * @return The index page locator.
 */
async function openCustomTools(page: Page) {
	const root = await appRoot(page)
	await page.goto(`${root}/custom-tools`, { waitUntil: 'domcontentloaded' })
	await dismissTour(page)
	const index = page.locator('[data-testid-page-id="CustomTools"]')
	await expect(index).toBeVisible({ timeout: 30_000 })
	return index
}

/**
 * Pick one option of an NcSelect, found by its inputLabel.
 *
 * @param page   The page.
 * @param scope  The dialog.
 * @param label  The select's inputLabel.
 * @param option The option's label.
 * @return Resolves once the option is chosen.
 */
async function choose(
	page: Page,
	scope: ReturnType<Page['locator']>,
	label: string,
	option: string,
): Promise<void> {
	await scope.getByRole('combobox', { name: label, exact: true }).click()
	await page.getByRole('option', { name: option, exact: true }).click()
}

test.describe('tools-custom-tools-without-code: the Custom tools page and editor', () => {
	let token = ''

	test.beforeAll(async ({ browser }) => {
		const page = await browser.newPage()
		token = await harvestToken(page)
		const present = await appEnabled(page.request, token, 'openregister')
		await page.close()
		test.skip(!present, 'openregister is not enabled: custom tools, flows and the tool registry live there')
	})

	test.afterAll(async ({ browser }) => {
		const page = await browser.newPage()
		const cleanupToken = await harvestToken(page)
		for (const tool of created) {
			await page.request
				.put(`${API}/custom-tools/${tool.id}`, {
					headers: jsonHeaders(cleanupToken),
					data: { ...tool.payload, enabled: false },
				})
				.catch(() => null)
			await deleteObject(page.request, cleanupToken, 'customtool', tool.id)
		}
		await cleanupFamily(page.request, cleanupToken, 'customtool').catch(() => undefined)
		await page.close()
	})

	// e2e: custom-tools::an-organisation-admin-adds-the-permit-check
	test('an organisation admin adds the permit check', async ({ page }) => {
		const name = `${TEST_PREFIX}-Vergunningcheck`
		const slug = `vergunningcheck_${RUN}`
		const toolId = `hermiq.custom_${slug}`
		await stubFlows(page)

		// From the MCP tools page, "Add a custom tool" leads to the Custom tools page.
		const root = await appRoot(page)
		await page.goto(`${root}/mcp-tools`, { waitUntil: 'domcontentloaded' })
		await dismissTour(page)
		await page.getByRole('button', { name: 'Add a custom tool', exact: true }).click()
		await expect(page).toHaveURL(new RegExp(`${root}/custom-tools$`))
		await expect(page.locator('[data-testid-page-id="CustomTools"]')).toBeVisible({ timeout: 30_000 })

		await page.getByRole('button', { name: /^Add\b/ }).first().click()
		const dialog = page.getByRole('dialog', { name: 'Add a custom tool' })
		await expect(dialog).toBeVisible({ timeout: 15_000 })

		await dialog.getByLabel('Name').first().fill(name)
		await dialog.getByLabel('Slug').first().fill(slug)
		await dialog
			.getByLabel('Description')
			.first()
			.fill('Checks whether an activity at an address needs a permit, and names the rule.')

		// Two inputs: adres (required text) and activiteit (text with three choices).
		await dialog.getByRole('button', { name: 'Add input', exact: true }).click()
		await dialog.getByRole('button', { name: 'Add input', exact: true }).click()
		const inputs = dialog.locator('.custom-tool-form__input')
		await expect(inputs).toHaveCount(2)
		await inputs.nth(0).getByLabel('Input name').fill('adres')
		await inputs.nth(0).getByText('Required', { exact: true }).click()
		await inputs.nth(1).getByLabel('Input name').fill('activiteit')
		await inputs.nth(1).getByLabel('Choices').fill('terras, evenement, kap')

		// Every NcSelect is a combobox named by its inputLabel.
		await expect(inputs.nth(0).getByRole('combobox', { name: 'Type', exact: true })).toBeVisible()
		await expect(dialog.getByRole('combobox', { name: 'What does this tool change?', exact: true })).toBeVisible()
		await expect(dialog.getByRole('combobox', { name: 'Target type', exact: true })).toBeVisible()

		await choose(page, dialog, 'What does this tool change?', 'Changes nothing')

		// The integriq target names its method select too; then back to a flow.
		await choose(page, dialog, 'Target type', 'A system connected through integriq')
		await expect(dialog.getByRole('combobox', { name: 'Method', exact: true })).toBeVisible()
		await choose(page, dialog, 'Target type', 'An OpenRegister flow')
		await choose(page, dialog, 'Flow', FLOW_NAME)

		const saved = page.waitForResponse(
			(res) =>
				/\/apps\/hermiq\/api\/custom-tools$/.test(new URL(res.url()).pathname)
				&& res.request().method() === 'POST',
		)
		await dialog.getByRole('button', { name: 'Save', exact: true }).click()
		const response = await saved
		const sent = response.request().postDataJSON()
		const answer = await response.json()
		expect(response.status(), JSON.stringify(answer)).toBe(201)
		created.push({ id: String(answer.id), payload: sent })

		expect(sent).toEqual({
			name,
			slug,
			description: 'Checks whether an activity at an address needs a permit, and names the rule.',
			inputSchema: {
				type: 'object',
				properties: {
					adres: { type: 'string' },
					activiteit: { type: 'string', enum: ['terras', 'evenement', 'kap'] },
				},
				required: ['adres'],
			},
			changesData: 'no',
			target: { type: 'flow', flowId: FLOW_ID },
			enabled: true,
		})
		expect(answer.toolId).toBe(toolId)
		expect(answer.slug).toBe(slug)
		expect(answer.changesData).toBe('no')
		await expect(dialog).toBeHidden({ timeout: 15_000 })

		// It is a row on the Custom tools page, with its slug.
		const index = await openCustomTools(page)
		const row = index.getByRole('row').filter({ hasText: name })
		await expect(row).toBeVisible({ timeout: 20_000 })
		await expect(row.getByText(slug, { exact: true })).toBeVisible()

		// And the MCP tools catalogue offers it as hermiq.custom_<slug>, under its name.
		const catalogue = page.waitForResponse(
			(res) => res.url().includes('/apps/hermiq/api/agents/tools') && res.request().method() === 'GET',
		)
		await page.goto(`${root}/mcp-tools`, { waitUntil: 'domcontentloaded' })
		await dismissTour(page)
		const listed = await catalogue
		expect(listed.status()).toBe(200)
		const results = (await listed.json()).results ?? []
		const tools: Array<Record<string, any>> = Array.isArray(results)
			? results
			: Object.entries(results).map(([id, tool]) => ({ id, ...(tool as Record<string, any>) }))
		const descriptor = tools.find((tool) => tool.id === toolId)
		expect(descriptor, `the catalogue must offer ${toolId}`).toBeTruthy()
		expect(descriptor?.name).toBe(name)
		await expect(page.getByRole('row').filter({ hasText: name })).toBeVisible({ timeout: 20_000 })
	})

	// e2e: custom-tools::try-it-runs-the-saved-tool-once
	test('Try it warns, sends the sample inputs and shows the answer', async ({ page }) => {
		const name = `${TEST_PREFIX}-Vergunningcheck-try`
		const slug = `vergunningcheck_try_${RUN}`
		const tool = await createTool(page.request, token, permitCheck(slug, name))
		await stubFlows(page)

		const tried: Array<{ url: string, body: any }> = []
		await page.route(/\/apps\/hermiq\/api\/custom-tools\/[^/]+\/try$/, (route) => {
			tried.push({ url: route.request().url(), body: route.request().postDataJSON() })
			return route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({ result: { vergunningNodig: true, regel: RULE } }),
			})
		})

		const index = await openCustomTools(page)
		await index.getByText(name, { exact: true }).click()
		const dialog = page.getByRole('dialog', { name: 'Edit custom tool' })
		await expect(dialog).toBeVisible({ timeout: 15_000 })

		const tryIt = dialog.locator('.custom-tool-form__try')
		await expect(tryIt.getByText(WARNING, { exact: true })).toBeVisible()
		await tryIt.getByLabel('adres', { exact: true }).fill('Markt 3')
		await tryIt.getByLabel('activiteit', { exact: true }).fill('terras')
		await tryIt.getByRole('button', { name: 'Try it', exact: true }).click()

		const shown = tryIt.locator('.custom-tool-form__answer')
		await expect(shown).toContainText(RULE, { timeout: 15_000 })
		expect(JSON.parse(await shown.innerText())).toEqual({
			result: { vergunningNodig: true, regel: RULE },
		})

		expect(tried, 'the editor must call the try route once').toHaveLength(1)
		expect(tried[0].url).toContain(`/custom-tools/${tool.id}/try`)
		expect(tried[0].body).toEqual({ arguments: { adres: 'Markt 3', activiteit: 'terras' } })
	})
})
