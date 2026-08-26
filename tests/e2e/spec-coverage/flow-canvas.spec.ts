/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * THE CANVAS DRAWS ITS CONNECTIONS — the assertion that was missing.
 *
 * WHY THIS FILE EXISTS
 * --------------------
 * hermiq's flow builder passed a `<template #edge>` to `CnGraphCanvas` for
 * weeks after that slot had been REMOVED from the component. Vue discards a
 * slot the child does not render — no warning, no error — so ~120 lines of
 * template compiled, reviewed cleanly, and drew nothing. Gone with it: the
 * title on every connection, moving that title along its line (including the
 * keyboard path), the connection context menu, and the payload control that is
 * the whole point of a run replay.
 *
 * Four requirements in openspec/specs/flow-canvas/spec.md describe that
 * behaviour, and CI stayed green throughout.
 *
 * ⚠️ AN ASSERTION FOR IT ALREADY EXISTED, AND NEVER RAN.
 * `tests/e2e/flow-builder-dialect.spec.ts` asserts on
 * `.flow-builder__step-text` — the edge-label class — and would have failed the
 * moment the slot died. It sits at `tests/e2e/`, and this app's CI runs
 * Playwright scoped to `tests/e2e/spec-coverage` only (see
 * .github/workflows/code-quality.yml, which states the scope and names
 * widening it as the follow-up). So the one test that could have caught this
 * was never executed.
 *
 * This is that follow-up, for the canvas specifically: the same claim, made
 * where CI can see it.
 *
 * NO FIXTURES ARE CREATED HERE. `SeedHydraTriageFlow` writes the "Hydra
 * Triage" flow at install time, and flow-seed.spec.ts already asserts it is
 * readable. Its definition (lib/Repair/SeedHydraTriageFlow.php) carries titled
 * edges — `triaged`, `command`, `no-result` — so a clean CI instance always has
 * a graph with labelled connections to draw.
 *
 * ⚠️ NO HARDCODED COUNTS. flow-builder-dialect.spec.ts asserts
 * `toHaveLength(16)` on the labels of a dev-instance flow; that flow has since
 * grown to 93 connections, so the assertion now fails on a working canvas. A
 * count is data and drifts. What cannot drift is the INVARIANT: a flow with
 * edges must draw at least one line, and a titled edge must show its title.
 */
import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { appRoot, harvestToken, jsonHeaders } from './_fixtures.ts'

interface FlowRow {
	id?: string
	uuid?: string
	name?: string
	edges?: unknown[]
	nodes?: unknown[]
}

/**
 * The install-seeded flow, with its node/edge document.
 *
 * ⚠️ THE LIST IS USED ONLY TO FIND THE ID; the document is read from the
 * single-flow endpoint. A list projection is not obliged to carry every
 * property, and trusting one is how a sibling app ended up asserting on a
 * materialised field that the list quietly omits — the filter matched nothing
 * forever and the test reported it as "not seeded". Ask the endpoint that owns
 * the document for the document.
 *
 * @param page The Playwright page, for its authenticated request context.
 * @return The flow with its edges, or null when the store has none by that name.
 */
async function hydraTriageFlow(page: Page): Promise<FlowRow | null> {
	const token = await harvestToken(page)
	const list = await page.request.get(
		'/index.php/apps/openregister/api/flows?app=hermiq',
		{ headers: jsonHeaders(token) },
	)

	if (list.status() !== 200) {
		return null
	}

	const body = await list.json().catch(() => null)
	const rows = (body?.results ?? body?.data ?? []) as FlowRow[]
	const row = rows.find((f) => String(f.name ?? '') === 'Hydra Triage') ?? null

	if (row === null) {
		return null
	}

	const id = row.id ?? row.uuid
	const detail = await page.request.get(
		`/index.php/apps/openregister/api/flows/${id}`,
		{ headers: jsonHeaders(token) },
	)

	if (detail.status() !== 200) {
		return row
	}

	const doc = (await detail.json().catch(() => null)) as FlowRow | null

	return doc === null ? row : { ...row, ...doc }
}

test.describe('flow-canvas — a connection is drawn, and carries its title', () => {
	// @e2e openspec/specs/flow-canvas/spec.md#requirement-a-connection-shows-only-its-own-title
	test('the canvas draws a line for every stored connection', async ({ page }) => {
		const flow = await hydraTriageFlow(page)

		// A missing flow is a DIFFERENT failure from a flow that draws nothing,
		// and saying so is the difference between "the seed did not run" and
		// "the canvas is broken". flow-seed.spec.ts owns the first question.
		expect(
			flow,
			'the install-seeded "Hydra Triage" flow is absent — that is flow-seed.spec.ts\'s '
				+ "failure, not the canvas's, and this test cannot ask its question without it",
		).not.toBeNull()

		const stored = (flow?.edges ?? []).length
		expect(
			stored,
			'the seeded flow must carry connections for this test to mean anything',
		).toBeGreaterThan(0)

		const errors: string[] = []
		page.on('console', (msg) => {
			if (msg.type() === 'error') errors.push(msg.text())
		})

		const root = await appRoot(page)
		await page.goto(`${root}/flows/${flow?.id ?? flow?.uuid}`, {
			waitUntil: 'domcontentloaded',
		})

		// The node is what proves the canvas mounted at all. Without this, a
		// zero-edge assertion below could not tell "edges are broken" from
		// "the page never rendered".
		await page
			.locator('.cn-flow-node')
			.first()
			.waitFor({ state: 'visible', timeout: 20_000 })

		// 🔴 THE REGRESSION. `.vue-flow__edge-path` is the line Vue Flow draws.
		// While the dead `#edge` slot was in place this was ZERO on a flow with
		// 93 stored connections — a canvas indistinguishable from one with no
		// connections at all.
		const drawn = page.locator('.vue-flow__edge-path')
		await expect(
			drawn.first(),
			`the flow stores ${stored} connection(s); the canvas drew none`,
		).toBeAttached({ timeout: 20_000 })

		expect(
			await drawn.count(),
			'every stored connection should draw at least one line',
		).toBeGreaterThan(0)

		const fatal = errors.filter(
			(e) =>
				!e.includes('favicon')
				&& !e.includes('Failed to load resource')
				&& !e.includes('net::ERR_ABORTED'),
		)
		expect(
			fatal,
			`unexpected fatal errors while drawing the canvas: ${fatal.join(' | ')}`,
		).toHaveLength(0)
	})

	// @e2e openspec/specs/flow-canvas/spec.md#requirement-a-connection-shows-only-its-own-title
	test('a titled connection shows its title on the line', async ({ page }) => {
		const flow = await hydraTriageFlow(page)
		expect(
			flow,
			'the install-seeded "Hydra Triage" flow is absent',
		).not.toBeNull()

		// Read the titles from the DOCUMENT rather than hardcoding them, so the
		// assertion follows the seed if someone renames a transition. What is
		// asserted is the relationship — a stored title appears on screen — not
		// a particular word.
		const titles = ((flow?.edges ?? []) as Array<Record<string, unknown>>)
			.map((e) => String(e.title ?? '').trim())
			.filter((t) => t !== '')

		expect(
			titles.length,
			'the seeded flow must carry at least one TITLED connection',
		).toBeGreaterThan(0)

		const root = await appRoot(page)
		await page.goto(`${root}/flows/${flow?.id ?? flow?.uuid}`, {
			waitUntil: 'domcontentloaded',
		})
		await page
			.locator('.cn-flow-node')
			.first()
			.waitFor({ state: 'visible', timeout: 20_000 })

		// The label is rendered through the canvas's `edge-label` slot, on Vue
		// Flow's own EdgeLabelRenderer layer, as a real <button>. Asserting the
		// TEXT rather than the element keeps this honest if the chrome changes.
		const label = page.getByText(titles[0], { exact: false }).first()
		await expect(
			label,
			`the connection titled "${titles[0]}" is stored but its title is not on the canvas`,
		).toBeVisible({ timeout: 20_000 })
	})
})
