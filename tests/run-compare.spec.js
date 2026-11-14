#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// run-compare.spec.js — the run comparison helpers and their wiring
// (observability-compare-two-runs).
//
// Usage:
//   node tests/run-compare.spec.js
//
// Exit codes:
//   0 — every assertion holds.
//   1 — one or more assertions failed.
//
// @spec openspec/specs/run-replay-and-dry-run/spec.md#requirement-a-person-can-compare-any-two-runs-they-may-see-req-rcmp-001
// @spec openspec/specs/run-replay-and-dry-run/spec.md#requirement-a-person-can-compare-two-runs-of-a-flow-node-by-node-req-rcmp-003

'use strict'

const assert = require('assert')
const fs = require('fs')
const path = require('path')
const { pathToFileURL } = require('url')

const ROOT = path.resolve(__dirname, '..')
const read = (rel) => fs.readFileSync(path.join(ROOT, rel), 'utf8')

// The views' `t`: fills {placeholders} so the sentences can be asserted as read.
function t(_app, text, vars = {}) {
	return text.replace(/\{(\w+)\}/g, (_m, key) => String(vars[key]))
}

const failures = []
function check(name, fn) {
	try {
		fn()
		console.log(`ok - ${name}`)
	} catch (e) {
		failures.push(name)
		console.log(`not ok - ${name}\n  ${e.message}`)
	}
}

const step = (name, outcome = 'ok') => ({ name, outcome })

async function main() {
	const lib = await import(
		pathToFileURL(path.join(ROOT, 'src', 'utils', 'runCompare.js'))
	)

	check('two ticks are kept, a third is refused and changes nothing', () => {
		let state = lib.toggleSelection([], 'a')
		state = lib.toggleSelection(state.selected, 'b')
		assert.deepStrictEqual(state, { selected: ['a', 'b'], refused: false })
		const third = lib.toggleSelection(state.selected, 'c')
		assert.deepStrictEqual(third, { selected: ['a', 'b'], refused: true })
		assert.deepStrictEqual(lib.toggleSelection(['a', 'b'], 'a'), {
			selected: ['b'],
			refused: false,
		})
	})

	check('same outcome, one extra call and the time difference in one line', () => {
		const comparison = {
			steps: [
				{
					mark: 'same',
					left: step('Search contacts'),
					right: step('Search contacts'),
				},
				{ mark: 'same', left: step('Read file'), right: step('Read file') },
				{ mark: 'only-right', left: null, right: step('Read file') },
				{
					mark: 'same',
					left: step('Send email'),
					right: step('Send email'),
				},
			],
		}
		const line = lib.summaryLine(
			{ status: 'ok', durationMs: 1000 },
			{ status: 'ok', durationMs: 5200 },
			comparison,
			t,
		)
		assert.strictEqual(
			line,
			'Same outcome. Run B called Read file once more. Run B took 4.2 s longer.',
		)
	})

	check('a failed run names the step it stopped at', () => {
		const comparison = {
			steps: [
				{ mark: 'same', left: step('Read file'), right: step('Read file') },
				{
					mark: 'different-outcome',
					left: step('Send email'),
					right: step('Send email', 'error'),
				},
			],
		}
		const line = lib.summaryLine(
			{ status: 'ok', durationMs: 1000 },
			{ status: 'error', durationMs: 1000 },
			comparison,
			t,
		)
		assert.strictEqual(
			line,
			'Different outcome. Run A succeeded, run B failed at Send email.',
		)
	})

	check('flow runs line up by node id and flag a version difference', () => {
		const left = {
			flowVersion: 4,
			status: 'completed',
			log: [
				{ transition: 'intake', status: 'completed', durationMs: 20 },
				{ transition: 'check', status: 'completed', durationMs: 40 },
				{ transition: 'decide', status: 'completed', durationMs: 10 },
			],
		}
		const right = {
			flowVersion: 5,
			status: 'failed',
			log: [
				{ transition: 'intake', status: 'completed', durationMs: 25 },
				{ transition: 'check', status: 'failed', durationMs: 90 },
			],
		}
		const result = lib.compareFlowRuns(left, right)
		assert.deepStrictEqual(result.versions, [4, 5])
		assert.deepStrictEqual(result.unreadable, [])
		assert.deepStrictEqual(
			result.rows.map((row) => [row.node, row.differs]),
			[
				['intake', false],
				['check', true],
				['decide', true],
			],
		)
		assert.strictEqual(
			result.rows[2].right,
			null,
			'The failed run never reached decide.',
		)
	})

	check('a flow run log without node ids cannot be read', () => {
		const result = lib.compareFlowRuns(
			{ log: [{ status: 'completed' }] },
			{ log: [{ transition: 'a', status: 'completed' }] },
		)
		assert.deepStrictEqual(result.unreadable, ['left'])
		assert.deepStrictEqual(result.rows, [])
		assert.strictEqual(
			lib.compareFlowRuns(
				{ flowVersion: 3, log: [{ node: 'x' }] },
				{ flowVersion: 3, log: [{ node: 'x' }] },
			).versions,
			null,
		)
	})

	check(
		'the Runs page ticks through toggleSelection and opens the comparison route',
		() => {
			const runs = read('src/views/Runs.vue')
			assert.match(runs, /toggleSelection\(/)
			assert.match(runs, /Choose two runs to compare\./)
			assert.match(runs, /path: '\/runs\/compare'/)
			assert.match(runs, /type="checkbox"/)
		},
	)

	check('the comparison views are registered and declared', () => {
		const components = read('src/customComponents.js')
		assert.match(components, /RunCompare/)
		assert.match(components, /FlowRunCompare/)
		const manifest = JSON.parse(read('src/manifest.json'))
		const pages = Object.fromEntries(manifest.pages.map((p) => [p.id, p]))
		assert.strictEqual(pages.RunCompare?.route, '/runs/compare')
		assert.strictEqual(pages.RunCompare?.component, 'RunCompare')
		assert.strictEqual(pages.FlowRunCompare?.route, '/flow-runs/compare')
		assert.strictEqual(pages.FlowRunCompare?.component, 'FlowRunCompare')
	})

	check(
		'the comparison reads the compare route and flow runs as the person',
		() => {
			assert.match(
				read('src/api/analytics.js'),
				/\/apps\/hermiq\/api\/runs\/compare/,
			)
			assert.match(
				read('src/api/flowRuns.js'),
				/\/apps\/openregister\/api\/flow-runs/,
			)
			assert.match(read('src/views/RunCompare.vue'), /summaryLine\(/)
			assert.match(read('src/views/FlowRunCompare.vue'), /compareFlowRuns\(/)
			assert.match(
				read('src/widgets/AgentRunHistoryWidget.vue'),
				/Compare with/,
			)
		},
	)

	if (failures.length) {
		console.log(`\n${failures.length} failed`)
		process.exit(1)
	}
}

main().catch((e) => {
	console.error(e)
	process.exit(1)
})
