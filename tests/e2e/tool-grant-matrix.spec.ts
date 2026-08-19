/**
 * The tool-grant matrix on an agent's detail page.
 *
 * 🔴 This suite exists because the matrix's id parser was wrong about 35 of the
 * 87 undeclared tools while the widget looked entirely healthy: it rendered,
 * the clusters folded open, the checkboxes ticked. What was wrong was the
 * LABELS — `cms_create_page` became a subject called "create", and the whole
 * OpenRegister core lost its verb and spread across 30 one-off rows. A unit
 * test now pins the parse (tests/tool-taxonomy.spec.js); this pins the thing
 * the unit test cannot see, which is that the parse reaches the screen.
 *
 * The assertions are about SHAPE, not exact contents, so the suite survives
 * apps being installed or removed on the instance it runs against: a subject
 * row must never be a bare verb, and a cluster must have fewer rows than tools.
 * Those two properties are exactly what each defect violated.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

import { test, expect, type Page } from '@playwright/test'

const NC_USER = process.env.NC_USER || 'admin'
const NC_PASS = process.env.NC_PASS || 'admin'

/** The five columns every cluster shows, whether or not a tool ticks them. */
const CANONICAL = ['CREATE', 'READ', 'UPDATE', 'LIST', 'DELETE']

/**
 * Log the configured user in through Nextcloud's real login form.
 *
 * @param page The Playwright page.
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
 * Open the first agent's detail page and scroll the grant matrix into view.
 *
 * The agent is taken from the API rather than hard-coded, so the suite does not
 * depend on a fixture that a reset would remove.
 *
 * @param page The Playwright page.
 * @return The agent's id.
 */
async function openFirstAgent(page: Page): Promise<string> {
	await page.goto('/apps/hermiq/', { waitUntil: 'domcontentloaded' })

	const id = await page.evaluate(async () => {
		const response = await fetch('/apps/hermiq/api/agents', {
			headers: { 'OCS-APIRequest': 'true' },
			credentials: 'include',
		})
		const body = await response.json()
		const list = body.results ?? body.data ?? body
		return Array.isArray(list) && list.length > 0 ? String(list[0].id) : ''
	})

	expect(
		id,
		'the instance must have at least one agent to grant tools to',
	).not.toBe('')

	await page.goto(`/apps/hermiq/agents/${id}`, { waitUntil: 'domcontentloaded' })
	await expect(page.getByText('Tool grants')).toBeVisible({ timeout: 30_000 })

	return id
}

/**
 * Every cluster toggle, labelled `<app><n> subjects`.
 *
 * @param page The Playwright page.
 */
function clusterToggles(page: Page) {
	return page.getByRole('button', { name: /\d+ subjects/ })
}

test.describe('tool grant matrix', () => {
	test.beforeEach(async ({ page }) => {
		await login(page)
	})

	test('renders one cluster per app, each counting its subjects', async ({
		page,
	}) => {
		await openFirstAgent(page)

		const count = await clusterToggles(page).count()
		expect(
			count,
			'the matrix should group tools into per-app clusters',
		).toBeGreaterThan(3)
	})

	test('a cluster opens to the five canonical columns', async ({ page }) => {
		await openFirstAgent(page)

		const toggle = clusterToggles(page).first()
		await toggle.click()

		// The header row is the contract the whole grid rests on: five columns
		// meaning the same thing in every cluster, so the eye can run down one.
		const table = page.locator('table').first()
		await expect(table).toBeVisible({ timeout: 10_000 })

		const header = (
			await table.locator('thead').first().innerText()
		).toUpperCase()
		for (const column of CANONICAL) {
			expect(header, `the ${column} column must be present`).toContain(column)
		}
	})

	test('no subject row is named after a verb', async ({ page }) => {
		await openFirstAgent(page)

		// 🔴 The exact defect: `cms_create_page` was read backwards and produced
		// a row called "create". A verb is never a thing a right acts ON, so a
		// row named for one is proof the parse inverted — whatever the app.
		const verbs = new Set([
			'create',
			'read',
			'update',
			'list',
			'delete',
			'get',
			'set',
			'add',
			'search',
			'find',
			'remove',
			'edit',
			'new',
			'fetch',
			'upsert',
			'destroy',
		])

		const toggles = clusterToggles(page)
		const total = await toggles.count()

		for (let index = 0; index < total; index++) {
			await toggles.nth(index).click()
		}

		// The subject cell is a `th[scope=row]`, not a `td` — the row header IS
		// the subject, and the name is its own span so the description below it
		// does not end up in the comparison.
		const subjects = await page
			.locator('.grant-matrix__subject-name')
			.allInnerTexts()
		expect(
			subjects.length,
			'the open clusters must actually contain rows',
		).toBeGreaterThan(10)

		const offenders = subjects
			.map((text) => text.trim().toLowerCase())
			.filter((label) => verbs.has(label))

		expect(
			offenders,
			'a subject row named after a verb means the id parsed backwards',
		).toEqual([])
	})

	test('a cluster has fewer rows than it has tools', async ({ page }) => {
		await openFirstAgent(page)

		// 🔴 The other defect: 30 OpenRegister tools each became their own row,
		// which is the flat list the grid was built to replace. Collapsing is
		// the widget's entire purpose, so a cluster whose row count equals its
		// tool count has not collapsed anything.
		const counts = await page.evaluate(async () => {
			const response = await fetch('/apps/hermiq/api/agents/tools', {
				headers: { 'OCS-APIRequest': 'true' },
				credentials: 'include',
			})
			const body = await response.json()
			const perApp: Record<string, number> = {}
			for (const tool of body.results ?? []) {
				const app = String(tool.app ?? '')
				perApp[app] = (perApp[app] ?? 0) + 1
			}
			return perApp
		})

		const toggles = clusterToggles(page)
		const total = await toggles.count()
		let compared = 0

		for (let index = 0; index < total; index++) {
			const label = (await toggles.nth(index).innerText()).trim()
			const app = label.match(/^([a-z0-9]+)/)?.[1] ?? ''
			const subjects = Number(label.match(/(\d+)\s+subjects/)?.[1] ?? '0')
			const tools = counts[app] ?? 0

			if (tools < 6) {
				continue
			}

			compared++
			expect(
				subjects,
				`${app}: ${tools} tools collapsed into ${subjects} subjects`,
			).toBeLessThan(tools)
		}

		expect(
			compared,
			'at least one app must have enough tools to prove collapsing',
		).toBeGreaterThan(0)
	})

	test('a special verb is labelled with what it grants', async ({ page }) => {
		await openFirstAgent(page)

		// A verb with no canonical column keeps its own name, so the checkbox
		// says what ticking it actually allows. An unlabelled special column is
		// a checkbox nobody can make an informed decision about.
		const toggles = clusterToggles(page)
		const total = await toggles.count()
		for (let index = 0; index < total; index++) {
			await toggles.nth(index).click()
		}

		const headers = (await page.locator('table thead').allInnerTexts())
			.join(' ')
			.toUpperCase()
		expect(
			headers,
			'some app on this instance must have a non-canonical verb',
		).toContain('SPECIAL')

		// The name sits ABOVE its checkbox, which is what makes the tick
		// meaningful: "special" alone says a right exists without saying which.
		const named = await page
			.locator('.grant-matrix__special-name')
			.allInnerTexts()
		expect(
			named.length,
			'a special column must carry its verb name',
		).toBeGreaterThan(0)
		for (const label of named) {
			expect(label.trim(), 'a special verb must be named, not blank').not.toBe(
				'',
			)
		}
	})

	test('every tool in the catalogue DECLARES its subject and action', async ({
		page,
	}) => {
		// 🔴 The regression guard for the fix that made this suite's other
		// assertions hold. The tests above pin what the matrix RENDERS; this
		// pins what it is rendered FROM.
		//
		// 87 of 177 tools declared no `subject`/`action` at all. The matrix
		// coped by parsing the id, which is why it was wrong about 35 of them
		// while looking healthy. The fix was to DECLARE, and
		// `ToolRegistryFacade::describeTools()` deliberately returns null rather
		// than inferring — so a tool that forgets these keys produces no error,
		// no warning, and no failing test anywhere. It simply arrives
		// ungroupable and renders as a one-off row.
		//
		// ⚠️ That silence is exactly why this assertion has to exist. Without
		// it, the next tool added without a declaration reintroduces the bug and
		// every test above still passes.
		await openFirstAgent(page)

		const undeclared = await page.evaluate(async () => {
			const response = await fetch('/apps/hermiq/api/agents/tools', {
				headers: { 'OCS-APIRequest': 'true' },
				credentials: 'include',
			})
			const body = await response.json()
			const tools = body.results ?? body.data ?? body

			if (Array.isArray(tools) === false) {
				return { total: -1, missing: ['the endpoint returned no tool list'] }
			}

			const missing = tools
				.filter(
					(tool: Record<string, unknown>) =>
						String(tool.subject ?? '').trim() === ''
						|| String(tool.action ?? '').trim() === '',
				)
				.map(
					(tool: Record<string, unknown>) =>
						`${String(tool.name)} (subject=${String(tool.subject)} action=${String(tool.action)})`,
				)

			return { total: tools.length, missing }
		})

		// Check for the EXPECTED value, not merely against an absent one: an
		// endpoint that answered with an error object would give `missing: []`
		// and read as a pass.
		expect(
			undeclared.total,
			'the catalogue must actually contain tools — 0 or -1 means the read failed, not that everything is declared',
		).toBeGreaterThan(0)

		expect(
			undeclared.missing,
			'every tool must declare a subject and an action; an undeclared one is invisible until it reaches the matrix',
		).toEqual([])
	})
})
