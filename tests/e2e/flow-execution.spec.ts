/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Regression e2e — a flow RUNS when a human runs it from the browser.
 *
 * ## Why this exists
 *
 * The builder specs that used to sit beside this one — 12 tests about ports,
 * branch carrying, the sidebar and zoom — are gone with the forked builder they
 * described; that rendering is the shared canvas's, and nc-vue's to prove. They
 * are worth remembering for WHY this file is separate: every one of them could
 * pass while no flow on the instance was capable of running, and on 2026-08-11
 * every one of them DID,
 * for about a hundred minutes, while openregister's `vendor/` was empty and the
 * engine died on `MarkingStoreInterface not found` in all 24 runs it attempted.
 * Nothing in the suite noticed, because nothing in the suite executed a flow.
 *
 * So this spec asserts the one thing that suite structurally cannot: press Run
 * in the UI and watch OBJECTS APPEAR. It fails if the engine is broken, if the
 * Run button is wired to nothing, or if the run is queued and never advances.
 *
 * ## No worker needed
 *
 * The Run button runs SYNCHRONOUSLY (`sync: true`), so the engine executes
 * inline and the response carries the finished run. That is what makes this
 * test runnable anywhere: asynchronously a run is only queued, and this dev
 * stack sets `backgroundjobs_mode=cron` with nothing calling cron.php — so the
 * run would sit untouched forever and the test would fail for want of a
 * scheduler, which reads exactly like a broken engine.
 *
 * Run against a running Nextcloud with Hermiq + OpenRegister installed:
 *
 *     NEXTCLOUD_URL=http://localhost:8080 \
 *       NC_USER=admin NC_PASS=admin \
 *       npx playwright test tests/e2e/flow-execution.spec.ts --project chromium
 *
 * @spec openspec/specs/flow-canvas/spec.md
 */

import { test, expect, type APIRequestContext, type Page } from '@playwright/test'

const NC_USER = process.env.NC_USER || 'admin'
const NC_PASS = process.env.NC_PASS || 'admin'

/** A run-scoped suffix so a re-run never collides with its own leftovers. */
const STAMP = `e2e${Date.now().toString(36)}`

/** Everything the test creates, torn down in afterAll. */
const made: { flow?: string; register?: number; schema?: number; source?: string } =
	{}

/**
 * Authenticated API helper bound to the Nextcloud OCS surface.
 *
 * @param request The Playwright request context.
 * @param method  HTTP method.
 * @param path    Path below the instance root.
 * @param data    Optional JSON body.
 *
 * @return The parsed body, or null when the response was not JSON.
 */
async function api(
	request: APIRequestContext,
	method: 'GET' | 'POST' | 'PUT' | 'DELETE',
	path: string,
	data?: unknown,
): Promise<Record<string, unknown> | null> {
	const response = await request.fetch(path, {
		method,
		headers: {
			'OCS-APIRequest': 'true',
			'Content-Type': 'application/json',
			Authorization:
				'Basic ' + Buffer.from(`${NC_USER}:${NC_PASS}`).toString('base64'),
		},
		data: data === undefined ? undefined : JSON.stringify(data),
	})

	try {
		return (await response.json()) as Record<string, unknown>
	} catch {
		return null
	}
}

/**
 * Dismiss the setup wizard / walkthrough overlays.
 *
 * Both are driven by an async status fetch, so they can mount AFTER first paint
 * and steal a click — which reads as "the button never appeared".
 *
 * @param page The Playwright page.
 *
 * @return void
 */
async function dismissOnboarding(page: Page): Promise<void> {
	const modal = page.locator('[data-testid="cn-modal"]')
	for (let attempt = 0; attempt < 5; attempt++) {
		if ((await modal.isVisible().catch(() => false)) === false) {
			return
		}

		await modal
			.first()
			.getByRole('button', { name: 'Close' })
			.click({ timeout: 2_000 })
			.catch(() => {})
		await page.keyboard.press('Escape').catch(() => {})
		await page.waitForTimeout(600)
	}
}

test.describe('hermiq regression: the engine runs a flow from the browser', () => {
	test.describe.configure({ mode: 'serial' })

	test.beforeAll(async ({ playwright }) => {
		const request = await playwright.request.newContext({
			baseURL: process.env.NEXTCLOUD_URL || 'http://localhost:8080',
		})

		// A register + schema of its own, so teardown is total: dropping the
		// register takes the objects' shard table with it, leaving nothing to
		// find later in a dev database.
		const register = await api(
			request,
			'POST',
			'/apps/openregister/api/registers',
			{
				title: `flow-e2e-${STAMP}`,
				slug: `flow-e2e-${STAMP}`,
				description: 'flow execution e2e',
			},
		)
		made.register = register?.id as number

		const schema = await api(request, 'POST', '/apps/openregister/api/schemas', {
			title: `flow-e2e-item-${STAMP}`,
			slug: `flow-e2e-item-${STAMP}`,
			properties: { name: { type: 'string' }, status: { type: 'string' } },
		})
		made.schema = schema?.id as number
		await api(
			request,
			'PUT',
			`/apps/openregister/api/registers/${made.register}`,
			{ schemas: [made.schema] },
		)

		const source = await api(
			request,
			'POST',
			'/apps/openregister/api/objects/openconnector/source',
			{
				name: `flow-e2e-src-${STAMP}`,
				location: 'https://jsonplaceholder.typicode.com',
				type: 'api',
				isEnabled: true,
				version: '1.0.0',
			},
		)
		made.source = ((source?.['@self'] as Record<string, unknown>)?.id
			?? source?.id) as string

		// The flow under test: fetch, fan out, write. Authored through the API
		// because this spec is about EXECUTION — authoring is what the builder
		// suite already covers, and drawing it by hand here would make a slow
		// test that fails for drawing reasons.
		const flow = await api(request, 'POST', '/apps/openregister/api/flows', {
			name: `flow-e2e-${STAMP}`,
			description: 'Runs from the browser and writes objects',
			app: 'hermiq',
			enabled: true,
			executionMode: 'async',
			nodes: [
				{ id: 't1', type: 'openregister.trigger-manual', config: {} },
				{
					id: 'call1',
					type: 'openconnector.source-call',
					config: {
						method: 'GET',
						source: made.source,
						endpoint: '/users?_limit=3',
					},
				},
				{
					id: 'x1',
					type: 'openregister.explode',
					config: { path: 'response.body', as: 'item', keepRecord: false },
				},
				{
					id: 'w1',
					type: 'openregister.object-write',
					config: {
						register: String(made.register),
						schema: String(made.schema),
						operation: 'create',
						fields: {
							name: '{{ item.name }}',
							status: 'created-by-ui-run',
						},
					},
				},
				{ id: 'e1', type: 'openregister.end', config: {} },
			],
			edges: [
				{ id: 'a', from: 't1', to: 'call1' },
				{ id: 'b', from: 'call1', to: 'x1' },
				{ id: 'c', from: 'x1', to: 'w1' },
				{ id: 'd', from: 'w1', to: 'e1' },
			],
		})
		made.flow = flow?.uuid as string

		expect(
			made.flow,
			'fixture flow was not created — the test would assert nothing',
		).toBeTruthy()
		await request.dispose()
	})

	test.afterAll(async ({ playwright }) => {
		const request = await playwright.request.newContext({
			baseURL: process.env.NEXTCLOUD_URL || 'http://localhost:8080',
		})

		// Flow first: deleting it cascades its runs, steps and state, so the
		// order is what keeps the dev database clean rather than merely smaller.
		if (made.flow) {
			await api(request, 'DELETE', `/apps/openregister/api/flows/${made.flow}`)
		}

		// Objects BEFORE the schema and register: both refuse to be deleted
		// while they still hold rows (`register-has-objects`, 409), and there is
		// no bulk delete — only one object at a time. Without this the fixture
		// register survives every run and the dev database fills up quietly.
		if (made.register && made.schema) {
			const objects = await api(
				request,
				'GET',
				`/apps/openregister/api/objects/${made.register}/${made.schema}?_limit=500`,
			)
			const rows = (
				Array.isArray(objects?.results) ? objects.results : []
			) as Array<Record<string, unknown>>
			for (const row of rows) {
				const id = ((row['@self'] as Record<string, unknown>)?.id
					?? row.id) as string
				if (id) {
					await api(
						request,
						'DELETE',
						`/apps/openregister/api/objects/${made.register}/${made.schema}/${id}`,
					)
				}
			}
		}

		if (made.schema) {
			await api(
				request,
				'DELETE',
				`/apps/openregister/api/schemas/${made.schema}`,
			)
		}

		if (made.register) {
			await api(
				request,
				'DELETE',
				`/apps/openregister/api/registers/${made.register}`,
			)
		}

		if (made.source) {
			await api(
				request,
				'DELETE',
				`/apps/openregister/api/objects/openconnector/source/${made.source}`,
			)
		}

		await request.dispose()
	})

	test('pressing Run in the canvas executes the flow and writes real objects', async ({
		page,
		request,
	}) => {
		// Nothing yet — captured so the assertion is a DELTA. An absolute count
		// would pass on a register that happened to be populated already.
		const before = await api(
			request,
			'GET',
			`/apps/openregister/api/objects/${made.register}/${made.schema}?_limit=100`,
		)
		const beforeCount = Array.isArray(before?.results)
			? (before.results as unknown[]).length
			: 0
		expect(beforeCount, 'fixture register should start empty').toBe(0)

		await page.goto(`/apps/hermiq/flows/${made.flow}`, {
			waitUntil: 'domcontentloaded',
		})
		await dismissOnboarding(page)

		// Run lives on the sidebar's "Flow" TAB, and the canvas opens on the
		// node palette — so the button is not merely hidden, it is not in the
		// DOM until the tab is selected. Without this the locator times out
		// against a page that is working perfectly.
		await page.getByRole('tab', { name: 'Flow' }).click({ timeout: 30_000 })

		// The button stays disabled until the editor has a flow id, so waiting
		// for ENABLED (not merely visible) is what proves the editor loaded the
		// flow rather than just painted its chrome.
		const runOpen = page.getByRole('button', { name: /^Run…$/ })
		await expect(runOpen).toBeEnabled({ timeout: 30_000 })
		await runOpen.click()

		// The dialog's own Run. This flow fetches its own work, so it needs no
		// subject object — the dialog must allow running with none.
		const dialog = page
			.locator('[data-testid="cn-modal"], .modal-container')
			.last()
		const confirm = dialog.getByRole('button', { name: /^Run$/ })
		await expect(confirm).toBeEnabled({ timeout: 15_000 })
		await confirm.click()

		// The UI runs SYNCHRONOUSLY, so by the time the dialog settles the run
		// has finished and its trace exists. Asynchronously this needed a
		// background worker driven from the test plus a polling loop — and on a
		// stack whose cron is idle the poll never resolved, so a healthy engine
		// read as a dead one.
		// Wait for a TERMINAL run, not merely for one to exist. A synchronous
		// run is written as `running` the moment it starts and only settles when
		// the browser's POST returns, so a loop that stops at "a run appeared"
		// reads the row mid-flight and reports `running` as a failure — against
		// an engine that is working.
		const terminal = ['stopped', 'completed', 'failed']
		let runs: Record<string, unknown> | null = null
		let list: Array<Record<string, unknown>> = []
		let latest: Record<string, unknown> | undefined

		for (let attempt = 0; attempt < 90; attempt++) {
			await page.waitForTimeout(1_000)
			runs = await api(
				request,
				'GET',
				`/apps/openregister/api/flow-runs?flowId=${made.flow}`,
			)
			list = (Array.isArray(runs?.results) ? runs.results : []) as Array<
				Record<string, unknown>
			>
			latest = list[list.length - 1]
			if (latest !== undefined && terminal.includes(String(latest.status))) {
				break
			}
		}

		expect(
			list.length,
			'pressing Run recorded no run — the button is not wired to the engine',
		).toBeGreaterThan(0)
		expect(
			terminal,
			`the run never settled — still ${String(latest?.status)}`,
		).toContain(String(latest?.status))

		const steps = (latest?.log ?? []) as Array<Record<string, unknown>>
		const failed = steps.filter((s) => s.status === 'failed')
		expect(
			failed,
			`a step failed: ${failed.map((s) => `${s.transition}: ${s.error}`).join(' | ')}`,
		).toEqual([])

		// `stopped` is the SUCCESS terminal state — the End node halts the token.
		expect(['stopped', 'completed']).toContain(String(latest?.status))

		// The claim that matters: real rows, written by the engine, because a
		// human pressed a button in a browser.
		const after = await api(
			request,
			'GET',
			`/apps/openregister/api/objects/${made.register}/${made.schema}?_limit=100`,
		)
		const written = (
			Array.isArray(after?.results) ? after.results : []
		) as Array<Record<string, unknown>>
		expect(
			written.length,
			'the run reported success but wrote nothing',
		).toBeGreaterThan(0)
		expect(written.every((o) => o.status === 'created-by-ui-run')).toBe(true)
	})

	test("pressing Save keeps every node's type — the editor must not disarm the flow", async ({
		page,
		request,
	}) => {
		// A DESTRUCTIVE save is the failure this pins. `flowDocument` used to
		// `delete place.type` and `delete place.config` on the way out — the
		// PRE-inversion model, where a transition was the action and a place was
		// a dumb waypoint. Under ADR-065 the node IS the action, so stripping it
		// reduced a working flow to anonymous boxes reading "No step type", and
		// the flow silently stopped being runnable. Measured on the demo flow:
		// five nodes, all `type: null`, one click.
		//
		// It cannot be caught by reading the canvas — the editor still holds the
		// types in memory and draws them correctly after saving. Only the stored
		// document shows it, which is why this asserts through the API.
		const before = await api(
			request,
			'GET',
			`/apps/openregister/api/flows/${made.flow}`,
		)
		const typesBefore = (
			(before?.nodes ?? []) as Array<Record<string, unknown>>
		).map((n) => n.type)
		expect(
			typesBefore.filter(Boolean).length,
			'fixture flow should start fully typed',
		).toBe(5)

		await page.goto(`/apps/hermiq/flows/${made.flow}`, {
			waitUntil: 'domcontentloaded',
		})
		await dismissOnboarding(page)

		// The toolbar is the shared canvas's now (`cn-flow-detail__toolbar`), not
		// the app's old `.flow-builder__verbs`. The assertion below is unchanged
		// and is the point of this test: a save must not rewrite node types.
		const save = page
			.locator('.cn-flow-detail__toolbar')
			.getByRole('button', { name: 'Save' })
		await expect(save).toBeEnabled({ timeout: 30_000 })
		await save.click()
		await page.waitForTimeout(4_000)

		const after = await api(
			request,
			'GET',
			`/apps/openregister/api/flows/${made.flow}`,
		)
		const typesAfter = (
			(after?.nodes ?? []) as Array<Record<string, unknown>>
		).map((n) => n.type)

		expect(
			typesAfter,
			"saving from the editor changed the nodes' types — a save must never disarm a flow",
		).toEqual(typesBefore)
		expect(
			typesAfter.filter(Boolean).length,
			'a node lost its type on save',
		).toBe(5)
	})
})
