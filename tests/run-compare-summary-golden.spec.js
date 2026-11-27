#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// run-compare-summary-golden.spec.js — golden test for summaryLine() in
// src/utils/runCompare.js.
//
// Builds generated pairs of runs (statuses in any case, failing or not,
// durations as numbers, strings, missing or not finite) and comparisons
// (steps only one side ran, steps both ran, unnamed and missing steps), and
// compares every summary line, and every call to the translate function, with
// a recorded fixture. GOLDEN_RECORD=1 rewrites it.
//
// Usage:
//   node tests/run-compare-summary-golden.spec.js
//
// Exit codes:
//   0 — every line matches the fixture.
//   1 — a case differs.

'use strict'

const assert = require('assert')
const fs = require('fs')
const path = require('path')
const { pathToFileURL } = require('url')

const ROOT = path.resolve(__dirname, '..')
const FIXTURE = path.join(
	__dirname,
	'Fixtures',
	'Golden',
	'run-compare-summary.json',
)
const CASES = 500

const STATUSES = [
	'ok',
	'success',
	'error',
	'ERROR',
	'failed',
	'failure',
	'timeout',
	'',
	null,
	undefined,
	3,
]
const DURATIONS = [
	0,
	50,
	99,
	100,
	1000,
	4200,
	15000,
	'2500',
	'slow',
	null,
	undefined,
	Infinity,
]
const NAMES = ['lookup', 'write', 'search', '', undefined]
const MARKS = ['same', 'only-left', 'only-right', 'changed', undefined]

/**
 * A small seeded generator.
 *
 * @param {number} seed The seed.
 * @return {Function} pick(list)
 */
function picker(seed) {
	let state = seed * 0x9e3779b9
	return (list) => {
		state = (state + 0x6d2b79f5) | 0
		let t = Math.imul(state ^ (state >>> 15), 1 | state)
		t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t
		return list[
			Math.floor((((t ^ (t >>> 14)) >>> 0) / 4294967296) * list.length)
		]
	}
}

/**
 * One comparison step, with either side present or not.
 *
 * @param {Function} pick The generator.
 * @return {object} The step row.
 */
function row(pick) {
	const mark = pick(MARKS)
	const out = {}
	if (mark !== undefined) {
		out.mark = mark
	}
	for (const side of ['left', 'right']) {
		const presence = pick(['step', 'step', 'none', 'unnamed'])
		if (presence === 'step') {
			out[side] = { name: pick(NAMES) }
		} else if (presence === 'unnamed') {
			out[side] = {}
		}
	}
	return out
}

/**
 * One case's runs and comparison.
 *
 * @param {number} index The case.
 * @return {object} The case.
 */
function makeCase(index) {
	const pick = picker(index + 1)
	const steps = []
	for (let i = pick([0, 1, 2, 4, 6]); i > 0; i--) {
		steps.push(row(pick))
	}
	return {
		left: { status: pick(STATUSES), durationMs: pick(DURATIONS) },
		right: { status: pick(STATUSES), durationMs: pick(DURATIONS) },
		comparison: pick([{ steps }, { steps }, { steps }, {}, null, undefined]),
	}
}

async function main() {
	const lib = await import(
		pathToFileURL(path.join(ROOT, 'src', 'utils', 'runCompare.js'))
	)
	const actual = []
	for (let index = 0; index < CASES; index++) {
		const c = makeCase(index)
		const calls = []
		const t = (app, text, vars) => {
			calls.push([app, text, vars ?? null])
			return `[${text}|${JSON.stringify(vars ?? null)}]`
		}
		const line = lib.summaryLine(c.left, c.right, c.comparison, t)
		actual.push(JSON.parse(JSON.stringify({ case: index, ...c, line, calls })))
	}

	if (process.env.GOLDEN_RECORD === '1') {
		fs.mkdirSync(path.dirname(FIXTURE), { recursive: true })
		fs.writeFileSync(FIXTURE, JSON.stringify(actual, null, 1) + '\n')
		console.log(`recorded ${actual.length} cases`)
		return
	}

	const expected = JSON.parse(fs.readFileSync(FIXTURE, 'utf8'))
	assert.strictEqual(actual.length, expected.length)
	let failed = 0
	for (let index = 0; index < expected.length; index++) {
		try {
			assert.deepStrictEqual(actual[index], expected[index])
		} catch (e) {
			failed++
			if (failed <= 3) {
				console.log(
					`not ok - case ${index}\n  ${e.message.split('\n').slice(0, 12).join('\n  ')}`,
				)
			}
		}
	}
	console.log(
		failed === 0
			? `ok - ${actual.length} cases match`
			: `${failed} case(s) differ`,
	)
	process.exitCode = failed === 0 ? 0 : 1
}

main().catch((e) => {
	console.log(`not ok - ${e.stack}`)
	process.exitCode = 1
})
