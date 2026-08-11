/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Regression e2e — a flow RUNS when a human runs it from the browser.
 *
 * ## Why this exists
 *
 * `flow-builder-dialect.spec.ts` is 12 tests about the builder: ports are dots
 * not bars, a branch port carries its branch, the sidebar closes, the canvas
 * zooms, `/graphs` still routes. Every one of them can pass while no flow on
 * the instance is capable of running — and on 2026-08-11 every one of them DID,
 * for about a hundred minutes, while openregister's `vendor/` was empty and the
 * engine died on `MarkingStoreInterface not found` in all 24 runs it attempted.
 * Nothing in the suite noticed, because nothing in the suite executed a flow.
 *
 * So this spec asserts the one thing that suite structurally cannot: press Run
 * in the UI and watch OBJECTS APPEAR. It fails if the engine is broken, if the
 * Run button is wired to nothing, or if the run is queued and never advances.
 *
 * ## The worker
 *
 * A run is QUEUED, not executed inline. This dev stack sets
 * `backgroundjobs_mode=cron` with nothing calling cron.php, so a queued run sits
 * untouched forever. The test therefore drives a FlowRunWorker itself, via occ,
 * after pressing Run. Without that it would fail for want of a scheduler — which
 * reads exactly like a broken engine and would send the next person hunting the
 * wrong bug.
 *
 * Run against a running Nextcloud with Hermiq + OpenRegister installed:
 *
 *     NEXTCLOUD_URL=http://localhost:8080 \
 *       NC_USER=admin NC_PASS=admin \
 *       npx playwright test tests/e2e/flow-execution.spec.ts --project chromium
 *
 * @spec openspec/specs/flow-canvas/spec.md
 */

import { execSync } from 'node:child_process'
import { test, expect, type APIRequestContext, type Page } from '@playwright/test'

const NC_USER = process.env.NC_USER || 'admin'
const NC_PASS = process.env.NC_PASS || 'admin'
const CONTAINER = process.env.NC_CONTAINER || 'nextcloud'

/** A run-scoped suffix so a re-run never collides with its own leftovers. */
const STAMP = `e2e${Date.now().toString(36)}`

/** Everything the test creates, torn down in afterAll. */
const made: { flow?: string, register?: number, schema?: number, source?: string } = {}

/**
 * Drive a FlowRunWorker for a bounded time.
 *
 * `occ background-job:worker -t N` returns only when its budget expires, so the
 * budget IS the wait. Failures are swallowed deliberately: the assertion that
 * matters is whether objects appeared, and a worker that could not start should
 * surface as "the run never advanced", not as an opaque exec error.
 *
 * @param seconds How long the worker may run.
 *
 * @return void
 */
function drainQueue(seconds: number): void {
	try {
		execSync(
			`timeout ${seconds + 20} docker exec -u www-data ${CONTAINER} `
			+ `php occ background-job:worker -t ${seconds} 'OCA\\OpenRegister\\Cron\\FlowRunWorker'`,
			{ stdio: 'ignore' },
		)
	} catch {
		// Budget expiry kills the process; that is the normal exit path.
	}
}

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
			Authorization: 'Basic ' + Buffer.from(`${NC_USER}:${NC_PASS}`).toString('base64'),
		},
		data: data === undefined ? undefined : JSON.stringify(data),
	})

	try {
		return await response.json() as Record<string, unknown>
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
		if (await modal.isVisible().catch(() => false) === false) {
			return
		}

		await modal.first().getByRole('button', { name: 'Close' }).click({ timeout: 2_000 }).catch(() => {})
		await page.keyboard.press('Escape').catch(() => {})
		await page.waitForTimeout(600)
	}
}

test.describe('hermiq regression: the engine runs a flow from the browser', () => {
	test.describe.configure({ mode: 'serial' })

	test.beforeAll(async ({ playwright }) => {
		const request = await playwright.request.newContext({ baseURL: process.env.NEXTCLOUD_URL || 'http://localhost:8080' })

		// A register + schema of its own, so teardown is total: dropping the
		// register takes the objects' shard table with it, leaving nothing to
		// find later in a dev database.
		const register = await api(request, 'POST', '/apps/openregister/api/registers', {
			title: `flow-e2e-${STAMP}`, slug: `flow-e2e-${STAMP}`, description: 'flow execution e2e',
		})
		made.register = register?.id as number

		const schema = await api(request, 'POST', '/apps/openregister/api/schemas', {
			title: `flow-e2e-item-${STAMP}`,
			slug: `flow-e2e-item-${STAMP}`,
			properties: { name: { type: 'string' }, status: { type: 'string' } },
		})
		made.schema = schema?.id as number
		await api(request, 'PUT', `/apps/openregister/api/registers/${made.register}`, { schemas: [made.schema] })

		const source = await api(request, 'POST', '/apps/openregister/api/objects/openconnector/source', {
			name: `flow-e2e-src-${STAMP}`,
			location: 'https://jsonplaceholder.typicode.com',
			type: 'api',
			isEnabled: true,
			version: '1.0.0',
		})
		made.source = ((source?.['@self'] as Record<string, unknown>)?.id ?? source?.id) as string

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
				{ id: 'call1', type: 'openconnector.source-call', config: { method: 'GET', source: made.source, endpoint: '/users?_limit=3' } },
				{ id: 'x1', type: 'openregister.explode', config: { path: 'response.body', as: 'item', keepRecord: false } },
				{
					id: 'w1',
					type: 'openregister.object-write',
					config: {
						register: String(made.register),
						schema: String(made.schema),
						operation: 'create',
						fields: { name: '{{ item.name }}', status: 'created-by-ui-run' },
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

		expect(made.flow, 'fixture flow was not created — the test would assert nothing').toBeTruthy()
		await request.dispose()
	})

	test.afterAll(async ({ playwright }) => {
		const request = await playwright.request.newContext({ baseURL: process.env.NEXTCLOUD_URL || 'http://localhost:8080' })

		// Flow first: deleting it cascades its runs, steps and state, so the
		// order is what keeps the dev database clean rather than merely smaller.
		if (made.flow) {
			await api(request, 'DELETE', `/apps/openregister/api/flows/${made.flow}`)
		}

		if (made.schema) {
			await api(request, 'DELETE', `/apps/openregister/api/schemas/${made.schema}`)
		}

		if (made.register) {
			await api(request, 'DELETE', `/apps/openregister/api/registers/${made.register}`)
		}

		if (made.source) {
			await api(request, 'DELETE', `/apps/openregister/api/objects/openconnector/source/${made.source}`)
		}

		await request.dispose()
	})

	test('pressing Run in the canvas executes the flow and writes real objects', async ({ page, request }) => {
		// Nothing yet — captured so the assertion is a DELTA. An absolute count
		// would pass on a register that happened to be populated already.
		const before = await api(request, 'GET', `/apps/openregister/api/objects/${made.register}/${made.schema}?_limit=100`)
		const beforeCount = Array.isArray(before?.results) ? (before.results as unknown[]).length : 0
		expect(beforeCount, 'fixture register should start empty').toBe(0)

		await page.goto(`/apps/hermiq/flows/${made.flow}`, { waitUntil: 'domcontentloaded' })
		await dismissOnboarding(page)

		// The canvas has to be up before Run means anything — the button is
		// disabled until the editor has a flow id.
		const runOpen = page.getByRole('button', { name: /^Run…$/ })
		await expect(runOpen).toBeEnabled({ timeout: 30_000 })
		await runOpen.click()

		// The dialog's own Run. This flow fetches its own work, so it needs no
		// subject object — the dialog must allow running with none.
		const dialog = page.locator('[data-testid="cn-modal"], .modal-container').last()
		const confirm = dialog.getByRole('button', { name: /^Run$/ })
		await expect(confirm).toBeEnabled({ timeout: 15_000 })
		await confirm.click()

        // A run record must exist before draining the queue, otherwise a worker
        // that finds an empty queue would look identical to a Run button wired
        // to nothing.
		let queued: unknown[] = []
		for (let attempt = 0; attempt < 20 && queued.length === 0; attempt++) {
			await page.waitForTimeout(1_000)
			const runs = await api(request, 'GET', `/apps/openregister/api/flow-runs?flowId=${made.flow}`)
			queued = Array.isArray(runs?.results) ? runs.results as unknown[] : []
		}

		expect(queued.length, 'pressing Run queued no run — the button is not wired to the engine').toBeGreaterThan(0)

		// Now let the engine actually advance it.
		drainQueue(60)

		const runs = await api(request, 'GET', `/apps/openregister/api/flow-runs?flowId=${made.flow}`)
		const list = (Array.isArray(runs?.results) ? runs.results : []) as Array<Record<string, unknown>>
		const latest = list[list.length - 1]

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
		const after = await api(request, 'GET', `/apps/openregister/api/objects/${made.register}/${made.schema}?_limit=100`)
		const written = (Array.isArray(after?.results) ? after.results : []) as Array<Record<string, unknown>>
		expect(written.length, 'the run reported success but wrote nothing').toBeGreaterThan(0)
		expect(written.every((o) => o.status === 'created-by-ui-run')).toBe(true)
	})
})
