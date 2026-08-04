/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Regression e2e — the flow builder renders and authors OpenRegister's flow
 * dialect (flow-engine-unification).
 *
 * ## What broke, and why a UI test is the only thing that catches it
 *
 * A flow is a Petri net (ADR-065): a NODE is a place and carries no behaviour,
 * an EDGE is a transition and carries the step that runs. The builder read the
 * inverse — `edges[].source`/`.target` and `nodes[].type` — and neither key
 * exists in a stored flow. Nothing errored. `resolvedEdges` silently dropped
 * every edge whose endpoints did not resolve, and a place with no `type`
 * rendered its label as a dash. The result was a canvas of blank, unconnected
 * boxes over a 17-node / 16-edge flow that the ENGINE ran perfectly well.
 *
 * Every layer below the DOM was green while this was true: the API returned the
 * flow, the store held it, `nodes.length` was 17. Only what is on screen
 * distinguishes "rendered" from "rendered as nothing", which is why these are
 * measurements of computed style and element counts rather than of state.
 *
 * The fixture is the real Hydra sequencer, deliberately: it is the flow the
 * defects were reported against, it has three genuine SPLITS (`work-gate`,
 * `slot-gate`, `verdict-gate` each fan out to two places), and a split is the
 * case a naive one-line-per-edge renderer gets wrong. 16 stored edges must draw
 * 19 lines.
 *
 * Run against a running Nextcloud with Hermiq + OpenRegister installed:
 *
 *     NEXTCLOUD_URL=http://localhost:8080 \
 *       npx playwright test tests/e2e/flow-builder-dialect.spec.ts --project chromium
 *
 * @spec openspec/specs/manifest-driven-pages/spec.md
 */

import { test, expect, type Page } from '@playwright/test'

/** The Hydra sequencer — 17 places, 16 steps, 3 of them splits. */
const SEQUENCER = '6b14a1fd-0cab-40c0-a3e7-7fea3be29bdc'

/**
 * A CSS colour as `rgb(r, g, b)`, so a theme variable can be compared with a
 * computed `backgroundColor`.
 *
 * Nextcloud declares its palette as hex (`#027b3e`) while `getComputedStyle`
 * always reports `rgb(...)`, so the two are never string-equal without this.
 *
 * @param value A hex colour, or an already-rgb string.
 */
function toRgb(value: string): string {
	const hex = value.trim()
	if (hex.startsWith('#') === false) {
		return hex
	}

	const full = hex.length === 4
		? `#${hex[1]}${hex[1]}${hex[2]}${hex[2]}${hex[3]}${hex[3]}`
		: hex

	const r = parseInt(full.slice(1, 3), 16)
	const g = parseInt(full.slice(3, 5), 16)
	const b = parseInt(full.slice(5, 7), 16)

	return `rgb(${r}, ${g}, ${b})`
}

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
		if (await dialog.count() === 0) {
			continue
		}

		const close = dialog.getByRole('button', { name: /^close$/i }).first()
		if (await close.count() > 0) {
			await close.click()
		} else {
			await page.keyboard.press('Escape')
		}

		await dialog.waitFor({ state: 'hidden', timeout: 10000 }).catch(() => {})
	}

	// Nothing modal may remain: any leftover overlay intercepts pointer events
	// and would make an unrelated assertion fail for an unrelated reason.
	await page.locator('.modal-mask').first().waitFor({ state: 'hidden', timeout: 10000 }).catch(() => {})
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
async function openFlow(page: Page, id: string): Promise<void> {
	await page.goto(`/apps/hermiq/flows/${id}`, { waitUntil: 'domcontentloaded' })
	// Canvas first, then the dialogs: a node renders underneath a modal, so
	// waiting on it is what proves the app has booted far enough for the
	// first-run dialogs to have mounted and be dismissable.
	await page.locator('.cn-graph-canvas__node').first().waitFor({ state: 'visible' })
	await dismissFirstRun(page)
}

test.describe('flow builder — flow dialect', () => {
	test('draws every step, fanning splits out into one line each', async ({ page }) => {
		await openFlow(page, SEQUENCER)

		// 17 places from the stored document.
		await expect(page.locator('.cn-graph-canvas__node')).toHaveCount(17)

		// 16 stored edges, three of which have two `to` places, so 19 drawable
		// lines. This is the assertion that was at ZERO: the builder looked for
		// `source`/`target` on a document that says `from`/`to`, so every edge
		// resolved to nothing and was dropped without a word.
		await expect(page.locator('.flow-builder__edge')).toHaveCount(19)

		// Each line carries a step chip naming what it does — the behaviour is
		// on the edge, so that is where it has to be legible.
		await expect(page.locator('.flow-builder__step-chip')).toHaveCount(19)
	})

	test('names every place instead of rendering a dash', async ({ page }) => {
		await openFlow(page, SEQUENCER)

		const labels = await page.locator('.flow-builder__node-label').allInnerTexts()
		expect(labels).toHaveLength(17)

		// No place renders as the em-dash the old type lookup fell back to, and
		// none is blank. A place that has only an id is completely ordinary —
		// 14 of these 17 are written that way — so the id IS the label.
		for (const label of labels) {
			expect(label.trim()).not.toBe('')
			expect(label.trim()).not.toBe('—')
		}

		// The three places that DO carry a name show it rather than their id.
		expect(labels).toContain('Stage finished')
		expect(labels).toContain('Gates passed')
		expect(labels).toContain('Gates failed')

		// And the ones that do not show their id, which is what every edge
		// references and what the engine calls them.
		expect(labels).toContain('tick')
		expect(labels).toContain('done')
	})

	test('draws exactly one box per place — not two, and not none', async ({ page }) => {
		await openFlow(page, SEQUENCER)

		// There are two elements per node and exactly ONE may carry a frame: the
		// wrapper CnGraphCanvas positions, and the card body in our slot. Both
		// failure modes are one line apart and both have shipped — the body
		// drawing its own radius over the wrapper's border (a card inside a
		// card), and the wrapper's frame being reset away with the body drawing
		// none (no card at all, just an accent bar and floating text). So this
		// counts frames rather than asserting any single element's style.
		const chrome = await page.locator('.cn-graph-canvas__node').first().evaluate((el) => {
			const outer = getComputedStyle(el)
			const inner = getComputedStyle(el.querySelector('.flow-builder__node') as Element)

			return {
				outerBorderWidth: parseFloat(outer.borderTopWidth),
				outerBackground: outer.backgroundColor,
				outerRadius: parseFloat(outer.borderTopLeftRadius),
				innerBorderWidth: parseFloat(inner.borderTopWidth),
				innerRadius: parseFloat(inner.borderTopLeftRadius),
			}
		})

		// The wrapper is the card: a visible border, an opaque background, a
		// rounded corner.
		expect(chrome.outerBorderWidth).toBeGreaterThan(0)
		expect(chrome.outerBackground).not.toBe('rgba(0, 0, 0, 0)')
		expect(chrome.outerRadius).toBeGreaterThan(0)

		// The body adds nothing to it. A radius here over a border there is the
		// second frame that reads as a nested container.
		expect(chrome.innerBorderWidth).toBe(0)
		expect(chrome.innerRadius).toBe(0)
	})

	test('marks the start place success and the end place error, on the port', async ({ page }) => {
		await openFlow(page, SEQUENCER)

		// Roles are inferred the way the ENGINE infers them
		// (`FlowDefinitionBuilder::resolveInitialPlaces()`): a start is a place
		// no edge points at, an end is a place no edge leaves. For this flow
		// that is exactly `tick` and `done`.
		await expect(page.locator('.flow-builder__node--start')).toHaveCount(1)
		await expect(page.locator('.flow-builder__node--end')).toHaveCount(1)

		const roles = await page.evaluate(() => {
			const read = (role: string) => {
				const card = document.querySelector(`.flow-builder__node--${role}`)
				const wrapper = card?.closest('.cn-graph-canvas__node')
				const handle = wrapper?.querySelector('.cn-graph-canvas__handle') as HTMLElement

				const style = handle ? getComputedStyle(handle) : null

				return {
					label: card?.querySelector('.flow-builder__node-label')?.textContent?.trim(),
					handleBackground: style ? style.backgroundColor : null,
					// Custom properties are resolved AT THE ELEMENT, not globally:
					// Nextcloud redefines parts of its palette in nested scopes, so
					// `--color-success` is rgb(2,123,62) on documentElement and a
					// different value down here. Reading it at the handle is the
					// only comparison that means "this port is painted with the
					// success colour" rather than "with some green".
					successVar: style ? style.getPropertyValue('--color-success').trim() : '',
					errorVar: style ? style.getPropertyValue('--color-error').trim() : '',
					primaryVar: style ? style.getPropertyValue('--color-primary-element').trim() : '',
					// The port is declared 16x16 round; Nextcloud's global button
					// min-height stretched it to 16x34, a bar rather than a dot.
					handleWidth: handle?.offsetWidth,
					handleHeight: handle?.offsetHeight,
				}
			}

			return { start: read('start'), end: read('end'), middle: read('step') }
		})

		expect(roles.start.label).toBe('tick')
		expect(roles.end.label).toBe('done')

		// Success on the start, error on the end — and NOT the primary colour
		// all three used to carry regardless of role.
		//
		// Asserted against the variable RESOLVED AT THE HANDLE, never a pinned
		// literal. An earlier version asserted `rgb(70, 186, 97)` — the
		// hardcoded fallback in `var(--color-success, #46ba61)` — which only
		// holds when the variable is MISSING, so it failed against correct code.
		expect(roles.start.handleBackground).not.toBe(roles.end.handleBackground)
		expect(roles.start.handleBackground).not.toBe(roles.middle.handleBackground)
		expect(roles.end.handleBackground).not.toBe(roles.middle.handleBackground)

		// And they are the SEMANTIC colours, not merely three different ones.
		expect(roles.start.handleBackground).toBe(toRgb(roles.start.successVar))
		expect(roles.end.handleBackground).toBe(toRgb(roles.end.errorVar))
		expect(roles.middle.handleBackground).toBe(toRgb(roles.middle.primaryVar))

		// Round again, in both places.
		expect(roles.start.handleWidth).toBe(16)
		expect(roles.start.handleHeight).toBe(16)
		expect(roles.end.handleHeight).toBe(16)
	})

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
		const scale = async () => await page.locator('.cn-graph-canvas__world').evaluate((el) => {
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

	test('configures the step, not the place', async ({ page }) => {
		await openFlow(page, SEQUENCER)

		// Selecting a place offers a NAME and nothing else: a place carries no
		// configuration, and `FlowDefinitionBuilder::extractPlaces()` throws on
		// one that does. The palette that used to sit here put catalogue step
		// types onto nodes, so every flow authored through it was unrunnable.
		await page.locator('.cn-graph-canvas__node').first().click()
		await expect(page.locator('[data-testid="flow-step-pane"]')).toHaveCount(0)

		// Selecting a step opens the pane that owns behaviour, pre-filled with
		// the type the engine will dispatch.
		await page.locator('.flow-builder__step-label').first().click()
		const stepPane = page.locator('[data-testid="flow-step-pane"]')
		await expect(stepPane).toBeVisible()
		await expect(stepPane).toContainText('→')
	})

	test('an old /graphs link still opens the flow', async ({ page }) => {
		// Hermiq called flows "graphs" until hermiq-flow-rename, and those URLs
		// are pasted into Hydra issues, run logs and PR bodies — the sequencer's
		// among them. The manifest has no redirect field, so the old paths are
		// declared as extra pages onto the same components. This asserts the old
		// link still WORKS, which a route table alone would not show.
		await page.goto(`/apps/hermiq/graphs/${SEQUENCER}`, { waitUntil: 'domcontentloaded' })
		await dismissFirstRun(page)

		await expect(page.locator('.cn-graph-canvas__node').first()).toBeVisible()
		await expect(page.locator('.flow-builder__edge')).toHaveCount(19)

		// The sidebar comes with it. Without its sidebarComponent an old link
		// would open a canvas with no controls, which reads as a broken editor
		// rather than an old URL.
		await expect(page.locator('.app-sidebar')).toBeVisible()
	})

	test('lists flows from the native flow store, not an object mirror', async ({ page }) => {
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
			const response = await fetch('/apps/openregister/api/flows?app=hermiq&limit=100', {
				headers: { 'OCS-APIRequest': 'true' },
			})

			return await response.json()
		})

		// Every row on screen is a row in the native store.
		const names: string[] = flows.results.map((flow: { name: string }) => flow.name)
		expect(names).toContain('Hydra sequencer')
		expect(flows.total).toBe(flows.results.length)
	})
})
