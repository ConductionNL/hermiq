#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// start-fields-golden.spec.js — golden test for startFieldsOf() in
// src/utils/instructionVariables.js.
//
// Builds generated agents whose start fields carry valid, invalid, duplicate
// and missing keys, every field type and unknown ones, labels, options and
// defaults of every JSON type, and more fields than the limit, and compares
// the fields startFieldsOf() keeps with a recorded fixture. GOLDEN_RECORD=1
// rewrites it.
//
// Usage:
//   node tests/start-fields-golden.spec.js
//
// Exit codes:
//   0 — every case matches the fixture.
//   1 — a case differs.

'use strict'

const assert = require('assert')
const fs = require('fs')
const path = require('path')
const { pathToFileURL } = require('url')

const ROOT = path.resolve(__dirname, '..')
const FIXTURE = path.join(__dirname, 'Fixtures', 'Golden', 'start-fields.json')
const CASES = 400

const KEYS = [
	'name',
	'city',
	'b_2',
	'Name',
	'9lives',
	'',
	'a'.repeat(32),
	'a'.repeat(33),
	'name',
	'city',
	null,
	undefined,
	7,
]
const TYPES = [
	'text',
	'paragraph',
	'select',
	'number',
	'date',
	'checkbox',
	'',
	null,
	undefined,
]
const LABELS = ['Your name', '  padded  ', '', '   ', null, undefined, 5]
const OPTIONS = [
	undefined,
	null,
	'a,b',
	[],
	['One', ' two ', '', '   ', 3, null],
	[false, 0],
]
const DEFAULTS = [undefined, null, '', 'x', 0, false, 12, ['a']]
const REQUIRED = [undefined, true, false, 'true', 1]

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
 * One generated start field, with any member left out.
 *
 * @param {Function} pick The generator.
 * @return {*} The entry.
 */
function entry(pick) {
	const shape = pick(['field', 'field', 'field', 'field', 'null', 'string'])
	if (shape === 'null') {
		return null
	}
	if (shape === 'string') {
		return 'name'
	}
	const out = {
		key: pick(KEYS),
		type: pick(TYPES),
		label: pick(LABELS),
		options: pick(OPTIONS),
		required: pick(REQUIRED),
		default: pick(DEFAULTS),
	}
	for (const key of Object.keys(out)) {
		if (out[key] === undefined) {
			delete out[key]
		}
	}
	return out
}

/**
 * One generated agent.
 *
 * @param {number} index The case.
 * @return {*} The agent.
 */
function makeAgent(index) {
	const pick = picker(index + 1)
	const shape = pick(['list', 'list', 'list', 'list', 'null', 'none', 'object'])
	if (shape === 'null') {
		return null
	}
	if (shape === 'none') {
		return {}
	}
	if (shape === 'object') {
		return { startFields: { 0: entry(pick) } }
	}
	const fields = []
	for (let i = pick([0, 1, 2, 3, 5, 12, 14]); i > 0; i--) {
		fields.push(entry(pick))
	}
	return { startFields: fields }
}

async function main() {
	const lib = await import(
		pathToFileURL(path.join(ROOT, 'src', 'utils', 'instructionVariables.js'))
	)
	const actual = []
	for (let index = 0; index < CASES; index++) {
		const agent = makeAgent(index)
		actual.push(
			JSON.parse(
				JSON.stringify({
					case: index,
					agent,
					fields: lib.startFieldsOf(agent),
				}),
			),
		)
	}
	actual.push({ case: 'undefined agent', fields: lib.startFieldsOf(undefined) })
	const many = []
	for (let i = 0; i < 14; i++) {
		many.push({ key: `field_${i}`, type: TYPES[i % 6], label: `Field ${i}` })
	}
	actual.push({
		case: 'more than the limit',
		fields: lib.startFieldsOf({ startFields: many }),
	})

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
