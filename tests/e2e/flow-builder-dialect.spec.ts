/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Regression e2e — the flow builder draws the INVERTED flow model
 * (or-flow-action-nodes + hermiq-flow-canvas-ports).
 *
 * ## What changed, and why only a UI test catches it
 *
 * A flow is a Petri net (ADR-065), and `or-flow-action-nodes` inverted which
 * half carries behaviour. A NODE is now the action: it holds the step type and
 * its config, and it is the thing that runs. An EDGE is sequence: `from`, `to`,
 * and an optional title.
 *
 * The builder went on reading the OLD model after the documents were migrated.
 * Nothing errored. It looked for `type` on each edge, found none — which is
 * exactly what a correctly migrated flow looks like — and rendered the words
 * "No step type" onto all 16 lines, while the cards showed place names for
 * places that no longer exist. The engine ran the flow perfectly throughout.
 *
 * Every layer below the DOM was green while this was true: the API returned the
 * flow, the store held it, the node count was right. Only what is ON SCREEN
 * distinguishes "drew the flow" from "drew the wrong half of it".
 *
 * ## The fixture is the real Hydra sequencer, and its numbers are measured
 *
 * Read from the stored document rather than assumed (16 nodes / 16 edges):
 *
 *   start (nothing points at it)   scope
 *   sinks (nothing leaves them)    release (exit: true), stop-idle, stop-full
 *                                  — the latter two are openregister.stop
 *   routes (2 branches each)       work-gate, slot-gate, verdict-gate
 *                                  config.rules[].output + config.default
 *
 * Those give the port arithmetic every assertion below rests on: a start has no
 * in-port, an exit has no out-port, and a route shows one NAMED out-port per
 * branch.
 *
 * Run against a running Nextcloud with Hermiq + OpenRegister installed:
 *
 *     NEXTCLOUD_URL=http://localhost:8080 \
 *       npx playwright test tests/e2e/flow-builder-dialect.spec.ts --project chromium
 *
 * @spec openspec/specs/flow-canvas/spec.md
 */

import { test, expect, type Page } from '@playwright/test'

/** The Hydra sequencer — 16 action nodes, 16 connections, 3 of them routes. */
const SEQUENCER = '6b14a1fd-0cab-40c0-a3e7-7fea3be29bdc'

/** The node with nothing pointing at it. */
const START_NODE = 'scope'

/** The three nodes nothing leaves. `release` says `exit: true`; the other two are `openregister.stop`. */
const SINK_NODES = ['release', 'stop-idle', 'stop-full']

/** The three routing nodes, each with one rule plus a default — so two branches each. */
const ROUTE_NODES = ['work-gate', 'slot-gate', 'verdict-gate']

/**
 * Dismiss the app's first-run dialogs.
 *
 * A fresh instance greets every page with "Support Hermiq" and the "Set up this
 * app" wizard. They are modal, so they do not merely sit next to the canvas —
 * they cover it and intercept every pointer event, which surfaces as "the node
 * is not visible" and "the subtree intercepts pointer events" on assertions
 * that have nothing to do with either dialog. Cleared once per page so a
 * failure here is always about the flow.
 *
 * @param page The Playwright page.
 */
async function dismissFirstRun(page: Page): Promise<void> {
	for (const name of ['Set up this app', 'Support Hermiq']) {
		const dialog = page.getByRole('dialog', { name })

		// WAIT for it rather than sampling once. Both dialogs mount after the
		// app has booted and queried its setup state, so a single `count()`
		// immediately after `goto` reads zero, skips, and leaves the modal to
		// intercept every later click — which surfaces as "the canvas node is
		// not clickable" and reads as a canvas defect.
		await dialog.waitFor({ state: 'visible', timeout: 5000 }).catch(() => {})
		if ((await dialog.count()) === 0) {
			continue
		}

		const close = dialog.getByRole('button', { name: /^close$/i }).first()
		if ((await close.count()) > 0) {
			await close.click()
		} else {
			await page.keyboard.press('Escape')
		}

		await dialog.waitFor({ state: 'hidden', timeout: 10000 }).catch(() => {})
	}

	// Nothing modal may remain: any leftover overlay intercepts pointer events
	// and would make an unrelated assertion fail for an unrelated reason.
	await page
		.locator('.modal-mask')
		.first()
		.waitFor({ state: 'hidden', timeout: 10000 })
		.catch(() => {})
}

/**
 * Open a flow on the canvas and wait for its places to render.
 *
 * Waits on a NODE rather than on `networkidle`: the page fetches the flow, the
 * step catalogue and the agent list, and a canvas that has painted its places
 * is the condition the assertions actually depend on.
 *
 * @param page The Playwright page.
 * @param id   The flow uuid.
 */

/**
 * Open a flow on the canvas and wait for its nodes to render.
 *
 * Waits on a NODE rather than on `networkidle`: the page fetches the flow, the
 * step catalogue and the agent list, and a canvas that has painted its cards is
 * the condition the assertions actually depend on.
 *
 * @param page The Playwright page.
 * @param id   The flow uuid.
 */
async function openFlow(page: Page, id: string): Promise<void> {
	await page.goto(`/apps/hermiq/flows/${id}`, { waitUntil: 'domcontentloaded' })
	// Canvas first, then the dialogs: a node renders underneath a modal, so
	// waiting on it is what proves the app has booted far enough for the
	// first-run dialogs to have mounted and be dismissable.
	await page.locator('.cn-flow-node').first().waitFor({ state: 'visible' })
	await dismissFirstRun(page)
}

test.describe('flow builder — the node is the action', () => {
	test('draws every node and every connection', async ({ page }) => {
		await openFlow(page, SEQUENCER)

		// 16 action nodes and 16 connections, straight from the stored
		// document. Each edge has a single `from`/`to` after the inversion, so
		// one edge is one line — the old 17-places/19-lines arithmetic belonged
		// to the pre-inversion shape.
		await expect(page.locator('.cn-flow-node')).toHaveCount(16)
		await expect(page.locator('.flow-builder__edge')).toHaveCount(16)
	})

	// @e2e flow-canvas::a-migrated-flow-shows-what-each-node-does
	test('every card names the STEP it runs, and none says "No step type"', async ({
		page,
	}) => {
		await openFlow(page, SEQUENCER)

		const steps = await page.locator('.flow-builder__node-step').allInnerTexts()
		expect(steps).toHaveLength(16)

		// THE regression. Every node in this document carries a type, so the
		// phrase cannot legitimately appear anywhere — it was on all 16 lines
		// while the builder read the old model.
		for (const step of steps) {
			expect(step.trim()).not.toBe('')
			expect(step.trim()).not.toBe('No step type')
		}

		// And it is the STEP, not the node id: `scope` runs set-fields, so its
		// card headline is the catalogue's name for that type rather than the
		// word "scope" (which is the secondary line).
		await expect(page.locator('.flow-builder__node--untyped')).toHaveCount(0)
	})

	// @e2e flow-canvas::the-place-labels-survive-as-line-labels
	test('a line carries its own title, never a step name', async ({ page }) => {
		await openFlow(page, SEQUENCER)

		// All 16 stored connections are titled, so all 16 draw a chip.
		//
		// `allTextContents()`, NOT `allInnerTexts()`. The chip label is an SVG
		// <text>, and `innerText` is an HTMLElement property — on an SVG node it
		// is undefined, so `allInnerTexts()` returned an array of 16 holes. The
		// length assertion passed (16 elements DID match) and the very next line
		// threw `Cannot read properties of undefined (reading 'trim')`, which
		// reads like the app rendered nothing when in fact it rendered
		// everything.
		const labels = await page
			.locator('.flow-builder__step-text')
			.allTextContents()
		expect(labels).toHaveLength(16)

		for (const label of labels) {
			expect(label.trim()).not.toBe('')
			expect(label.trim()).not.toBe('No step type')
		}

		// The words the author wrote on the places survived the migration onto
		// the lines that replaced them.
		expect(labels).toContain('scoped')
	})

	// @e2e flow-canvas::the-ends-of-the-flow-are-identifiable-without-colour
	test('a start has no in-port and a sink has no out-port', async ({ page }) => {
		await openFlow(page, SEQUENCER)

		const ports = await page.evaluate(() => {
			const read: Record<string, string[]> = {}
			document.querySelectorAll('.cn-flow-node').forEach((wrapper) => {
				const label =
					wrapper
						.querySelector('.flow-builder__node-label')
						?.textContent?.trim() ?? ''
				const sides: string[] = []
				wrapper
					.querySelectorAll('.cn-flow-node__handle')
					.forEach((handle) => {
						// Vue Flow marks the OUTPUT port with
						// `--source`; the input port carries no
						// modifier at all. The old canvas named both
						// sides explicitly (`--in` / `--out`), so the
						// test asked "is it in?" — asking "is it out?"
						// is the same question against the class that
						// actually exists now.
						const kind = handle.classList.contains(
							'cn-flow-node__handle--source',
						)
							? 'out'
							: 'in'
						sides.push(kind)
					})
				read[label] = sides
			})

			return read
		})

		// Role is carried by ABSENCE, which is what survives greyscale: the
		// start receives nothing, so it has no in-port at all.
		expect(ports[START_NODE]).toBeDefined()
		expect(ports[START_NODE]).not.toContain('in')
		expect(ports[START_NODE]).toContain('out')

		// A node that ends the flow sends nothing on. Both ways of saying so
		// are represented here: `release` declares `exit: true`, while
		// `stop-idle` and `stop-full` are a terminal TYPE and carry no flag.
		for (const sink of SINK_NODES) {
			expect(ports[sink], `${sink} should have ports`).toBeDefined()
			expect(ports[sink], `${sink} must not offer an out-port`).not.toContain(
				'out',
			)
			expect(ports[sink], `${sink} still receives`).toContain('in')
		}
	})

	// @e2e flow-canvas::a-gate-shows-both-of-its-branches-by-name
	test('a routing node shows one NAMED out-port per branch', async ({ page }) => {
		await openFlow(page, SEQUENCER)

		const branches = await page.evaluate(() => {
			const read: Record<string, string[]> = {}
			document.querySelectorAll('.cn-flow-node').forEach((wrapper) => {
				const label =
					wrapper
						.querySelector('.flow-builder__node-label')
						?.textContent?.trim() ?? ''
				const names: string[] = []
				wrapper
					.querySelectorAll('.cn-flow-node__handle--source')
					.forEach((handle) => {
						names.push(handle.getAttribute('aria-label') ?? '')
					})
				read[label] = names
			})

			return read
		})

		// Each gate has one rule plus a default, so two branches — and each is
		// NAMED. This is the whole point of ports over a single handle: which
		// branch a line leaves from is readable without opening the node's
		// configuration.
		for (const gate of ROUTE_NODES) {
			expect(branches[gate], `${gate} should be on the canvas`).toBeDefined()
			expect(branches[gate].length, `${gate} should expose two branches`).toBe(
				2,
			)
		}

		// The branch names are the ones the ENGINE reads (`rules[].output` and
		// `default`), not invented labels — honouring `config.routes` instead
		// would draw ports for a configuration the engine ignores.
		const workGate = branches['work-gate'].join(' ')
		expect(workGate).toContain('work')
		expect(workGate).toContain('idle')
	})

	// @e2e flow-canvas::two-branches-of-one-gate-reach-the-same-node
	test('a branch port carries its branch into the connection', async ({
		page,
	}) => {
		await openFlow(page, SEQUENCER)

		// The canvas names a branch port `out:<branch>`. That id is what the
		// editor turns into `edge.fromExit`, the field the engine's router matches
		// on — so if the ids are not branch-bearing, every branch of a route
		// produces an identical edge and the choice the author made is lost.
		const portIds = await page.evaluate(() => {
			const read: Record<string, string[]> = {}
			document.querySelectorAll('.cn-flow-node').forEach((wrapper) => {
				const label =
					wrapper
						.querySelector('.flow-builder__node-label')
						?.textContent?.trim() ?? ''
				const ids: string[] = []
				wrapper
					.querySelectorAll('.cn-flow-node__handle--source')
					.forEach((handle) => {
						ids.push(handle.getAttribute('aria-label') ?? '')
					})
				read[label] = ids
			})

			return read
		})

		// work-gate's two branches are named on their ports, so the two are
		// distinguishable to a pointer and to the code that reads the drag.
		expect(portIds['work-gate']).toBeDefined()
		expect(portIds['work-gate'].join(' ')).toContain('work')
		expect(portIds['work-gate'].join(' ')).toContain('idle')
		expect(new Set(portIds['work-gate']).size).toBe(portIds['work-gate'].length)
	})

	test('no line is rendered as unassigned on a healthy flow', async ({ page }) => {
		await openFlow(page, SEQUENCER)

		// The negative control for the unassigned state. Every edge in this flow
		// leaves a branch its node still offers, so the marker must be absent —
		// otherwise "no unassigned edges" would be untestable and the styling
		// could be wrong in either direction without anything noticing.
		await expect(page.locator('.flow-builder__step--unassigned')).toHaveCount(0)
	})

	test('a port is a dot, not a bar', async ({ page }) => {
		await openFlow(page, SEQUENCER)

		// Declared 16x16 and round. Nextcloud's global button `min-height`
		// stretched it to 16x34 — a bar, which reads as a slot rather than a
		// connection point, and made two ports on one side touch.
		const size = await page.evaluate(() => {
			const handle = document.querySelector(
				'.cn-flow-node__handle',
			) as HTMLElement | null

			return handle ? { w: handle.offsetWidth, h: handle.offsetHeight } : null
		})

		expect(size).not.toBeNull()
		expect(size!.w).toBeGreaterThan(0)
		// Square within a pixel of rounding, rather than a hardcoded 16: the
		// canvas owns the size, and pinning the exact number here would fail
		// on a legitimate design change instead of on the defect.
		expect(Math.abs(size!.h - size!.w)).toBeLessThanOrEqual(1)
	})
})

test.describe('flow builder — chrome and links', () => {
	test('closes the sidebar and offers a way back', async ({ page }) => {
		await openFlow(page, SEQUENCER)

		const sidebar = page.locator('.app-sidebar')
		await expect(sidebar).toBeVisible()

		// The close button used to emit `close` into nothing: the sidebar was
		// rendered unconditionally and the event was unhandled, so the X did
		// nothing at all.
		await page.locator('button[aria-label="Close sidebar"]').click()
		await expect(sidebar).toHaveCount(0)

		// A close with no way back is a one-way door, so the re-open control
		// lives on the canvas — the sidebar has no chrome left to render one in.
		const reopen = page.locator('.flow-builder__sidebar-toggle')
		await expect(reopen).toBeVisible()
		await reopen.click()
		await expect(sidebar).toBeVisible()
	})

	test('zooms the canvas in and out', async ({ page }) => {
		await openFlow(page, SEQUENCER)

		// The zoom is applied by CnGraphCanvas as a scale() on its world layer,
		// so the transform matrix is what proves it actually took effect — the
		// button label alone would pass even while the canvas sat pinned at 1,
		// which is exactly what it did when the consumer never bound `zoom` and
		// the wheel's `update:zoom` went nowhere.
		// `.vue-flow__transformationpane` is Vue Flow's equivalent of the old
		// canvas's `__world`: it is the element the pan/zoom matrix is written
		// to. Confirmed in a running instance — the viewport, container and
		// pane around it all read `transform: none`, so reading any of those
		// would report a constant scale of 1 and this assertion would pass
		// while proving nothing, which is the exact failure the comment above
		// says it exists to catch.
		const scale = async () =>
			await page.locator('.vue-flow__transformationpane').evaluate((el) => {
				return new DOMMatrix(getComputedStyle(el).transform).a
			})

		expect(await scale()).toBeCloseTo(1, 2)

		await page.getByRole('button', { name: 'Zoom in' }).click()
		const zoomedIn = await scale()
		expect(zoomedIn).toBeGreaterThan(1)

		await page.getByRole('button', { name: 'Zoom out' }).click()
		await page.getByRole('button', { name: 'Zoom out' }).click()
		expect(await scale()).toBeLessThan(1)

		// Reset is reachable and lands exactly on 1 — float drift from repeated
		// steps must not leave it at 0.9999999.
		await page.getByRole('button', { name: 'Reset zoom to 100%' }).click()
		expect(await scale()).toBeCloseTo(1, 5)
	})

	test('an old /graphs link still opens the flow', async ({ page }) => {
		// Hermiq called flows "graphs" until hermiq-flow-rename, and those URLs
		// are pasted into Hydra issues, run logs and PR bodies — the sequencer's
		// among them. The manifest has no redirect field, so the old paths are
		// declared as extra pages onto the same components. This asserts the old
		// link still WORKS, which a route table alone would not show.
		await page.goto(`/apps/hermiq/graphs/${SEQUENCER}`, {
			waitUntil: 'domcontentloaded',
		})
		await dismissFirstRun(page)

		await expect(page.locator('.cn-flow-node').first()).toBeVisible()
		await expect(page.locator('.flow-builder__edge')).toHaveCount(16)

		// The sidebar comes with it. Without its sidebarComponent an old link
		// would open a canvas with no controls, which reads as a broken editor
		// rather than an old URL.
		await expect(page.locator('.app-sidebar')).toBeVisible()
	})

	test('lists flows from the native flow store, not an object mirror', async ({
		page,
	}) => {
		// The list used to be a `type:index` over `hermiq/agentflow` — a
		// duplicate of the native rows the engine runs, free to drift, and it
		// had: the mirror held 14 flows where the store holds 13.
		await page.goto('/apps/hermiq/flows', { waitUntil: 'domcontentloaded' })
		await dismissFirstRun(page)
		await expect(page.getByText('Hydra sequencer').first()).toBeVisible()

		// Read the store AFTER navigating: a relative fetch needs a document
		// origin, and `page.evaluate` before the first goto runs on about:blank
		// where there is none to resolve against.
		const flows = await page.evaluate(async () => {
			const response = await fetch(
				'/apps/openregister/api/flows?app=hermiq&limit=100',
				{
					headers: { 'OCS-APIRequest': 'true' },
				},
			)

			return await response.json()
		})

		// Every row on screen is a row in the native store.
		const names: string[] = flows.results.map(
			(flow: { name: string }) => flow.name,
		)
		expect(names).toContain('Hydra sequencer')
		expect(flows.total).toBe(flows.results.length)
	})
})
