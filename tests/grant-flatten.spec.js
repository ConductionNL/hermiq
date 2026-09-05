#!/usr/bin/env node
/**
 * Reading an agent's grants out of either stored shape.
 *
 * 🔴 This test exists because the function it covers is the one place where a
 * wrong answer REVOKES GRANTS SILENTLY. The matrix reads the stored grants,
 * renders them, and writes back what it read plus the user's edits — so a read
 * that returns less than was stored does not display an error, it deletes the
 * difference the next time anyone presses Save.
 *
 * Two ways that has nearly happened, both covered below:
 *
 * 1. `Array.isArray(tools) ? tools : []` returned `[]` for the new structured
 *    shape — every agent would have read as ungranted.
 * 2. Flattening to bare tool ids dropped argument constraints, turning a grant
 *    for ONE flow into a grant for every flow — a widening, written back as if
 *    the user had asked for it.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

const assert = require('assert')
const fs = require('fs')
const path = require('path')
const vm = require('vm')

const source = fs.readFileSync(
	path.join(__dirname, '..', 'src', 'api', 'toolTaxonomy.js'),
	'utf8',
)

// Only the pure helpers are under test; the axios-backed calls are not. The
// imports and the exported request functions are stripped so this runs under
// plain `node`, like every other spec here.
const pure = source
	.split('\n')
	.filter((line) => line.startsWith('import ') === false)
	.join('\n')
	.replace(/export async function[\s\S]*?\n}\n/g, '')
	.replace(/^export /gm, '')

const context = { module: { exports: {} }, console }
vm.createContext(context)
vm.runInContext(pure + '\nmodule.exports = { flattenGrants }', context)
const { flattenGrants } = context.module.exports

let failures = 0

/**
 * Assert one flatten, reporting rather than throwing so every case is measured.
 *
 * @param {string} label What is being checked.
 * @param {*} stored The stored `tools` value.
 * @param {Array<string>} want The expected grant strings.
 * @return {void}
 */
function check(label, stored, want) {
	// ⚠️ Copied into THIS realm before comparing. The function under test builds
	// its array inside the vm context, so it is a different realm's `Array` —
	// `deepStrictEqual` compares prototypes and reported four identical-looking
	// lists as unequal, printing `want` and `got` as the same text. The legacy
	// cases passed only because `filter()` on a host array returns a host array.
	const got = [...flattenGrants(stored)].map(String)
	try {
		assert.deepStrictEqual(got.slice().sort(), want.slice().sort())
		console.log(`  ok   ${label}`)
	} catch {
		failures++
		console.log(`  FAIL ${label}`)
		console.log(`       want ${JSON.stringify(want)}`)
		console.log(`       got  ${JSON.stringify(got)}`)
	}
}

console.log('\nThe legacy string[] still reads')
check(
	'a flat list passes through',
	['pipelinq.lead.search', 'hermiq.listFiles'],
	['pipelinq.lead.search', 'hermiq.listFiles'],
)
check('empties and non-strings are dropped', ['a.b.c', '', null, 7, {}], ['a.b.c'])

console.log('\nThe structured shape reads — the case that returned [] before')
check(
	'app → subject → action → id',
	{
		pipelinq: {
			lead: { search: ['pipelinq.lead.search'], get: ['pipelinq.lead.get'] },
		},
		hermiq: { file: { list: ['hermiq.listFiles'] } },
	},
	['pipelinq.lead.search', 'pipelinq.lead.get', 'hermiq.listFiles'],
)

// ⚠️ The id is READ, not rebuilt. `hermiq.listFiles` sits at (hermiq, file,
// list); rebuilding from those coordinates gives `hermiq.file.list`, which is
// not a tool and would dispatch to nothing.
check(
	'a hand-written id is read, not rebuilt from its coordinates',
	{ hermiq: { file: { list: ['hermiq.listFiles'] } } },
	['hermiq.listFiles'],
)

check(
	'a bare id is accepted where a list is expected',
	{ hermiq: { file: { list: 'hermiq.listFiles' } } },
	['hermiq.listFiles'],
)

console.log('\n🔴 Constraints are part of the grant and must survive')
check(
	'a pinned argument round-trips',
	{
		openregister: {
			runFlow: {
				runFlow: [{ id: 'openregister.runFlow', args: { flowId: 'A' } }],
			},
		},
	},
	['openregister.runFlow?flowId=A'],
)

check(
	'a closed value set round-trips',
	{
		hermiq: {
			readFile: {
				readFile: [{ id: 'hermiq.readFile', args: { path: ['/a', '/b'] } }],
			},
		},
	},
	['hermiq.readFile?path=in:/a,/b'],
)

check(
	'two constrained grants for one tool both survive',
	{
		openregister: {
			runFlow: {
				runFlow: [
					{ id: 'openregister.runFlow', args: { flowId: 'A' } },
					{ id: 'openregister.runFlow', args: { flowId: 'B' } },
				],
			},
		},
	},
	['openregister.runFlow?flowId=A', 'openregister.runFlow?flowId=B'],
)

check(
	'a bare grant beside a constrained one keeps both',
	{
		openregister: {
			runFlow: {
				runFlow: [
					'openregister.runFlow',
					{ id: 'openregister.runFlow', args: { flowId: 'A' } },
				],
			},
		},
	},
	['openregister.runFlow', 'openregister.runFlow?flowId=A'],
)

console.log('\nNothing stored means nothing granted')
for (const empty of [null, undefined, {}, [], 0, 'x']) {
	const got = flattenGrants(empty)
	if (Array.isArray(got) === false || got.length !== 0) {
		failures++
		console.log(`  FAIL ${JSON.stringify(empty)} should flatten to []`)
	}
}
console.log('  ok   null/undefined/{}/[]/0/"x" all flatten to []')

console.log('\nMalformed structures are dropped, never guessed at')
check(
	'an entry with no id is dropped',
	{
		pipelinq: { lead: { search: [{ args: { x: 1 } }, 'pipelinq.lead.search'] } },
	},
	['pipelinq.lead.search'],
)
check(
	'a non-object level is skipped',
	{ pipelinq: 'nope', hermiq: { file: null } },
	[],
)

if (failures > 0) {
	console.error(`\n${failures} case(s) wrong\n`)
	process.exit(1)
}

console.log('\nAll grant flattening correct\n')
