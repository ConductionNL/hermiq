#!/usr/bin/env node
/**
 * Golden test for the three grant and context readers: flattenGrants(),
 * parseVerbAndSubject() and buildAgentContext() (through its allowlist).
 *
 * Generated inputs of every shape run through the readers and the outputs are
 * compared with a recorded fixture. GOLDEN_RECORD=1 rewrites the fixture; a run
 * in record mode compares nothing.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

const fs = require('fs')
const path = require('path')
const vm = require('vm')

const ROOT = path.join(__dirname, '..')
const FIXTURE = path.join(__dirname, 'Fixtures', 'Golden', 'grant-reading.json')

/**
 * Load an ES module's functions into a plain context, imports and async requests stripped.
 *
 * @param {string} rel The module path from the repo root.
 * @param {Array<string>} names The functions to return.
 * @return {object} The named functions.
 */
function load(rel, names) {
	const source = fs
		.readFileSync(path.join(ROOT, rel), 'utf8')
		.split('\n')
		.filter((line) => line.startsWith('import ') === false)
		.join('\n')
		.replace(/export async function[\s\S]*?\n}\n/g, '')
		.replace(/^export /gm, '')
	const context = { module: { exports: {} } }
	vm.createContext(context)
	vm.runInContext(source + `\nmodule.exports = { ${names.join(', ')} }`, context)
	return context.module.exports
}

const { flattenGrants } = load('src/api/toolTaxonomy.js', ['flattenGrants'])
const { parseVerbAndSubject } = load('src/utils/toolTaxonomy.js', ['parseVerbAndSubject'])
const { buildAgentContext } = load('src/utils/agentContext.js', ['buildAgentContext'])

let seed = 1
/**
 * A seeded integer in [0, n).
 *
 * @param {number} n The bound.
 * @return {number} The integer.
 */
function rand(n) {
	seed = (seed * 1103515245 + 12345) % 2147483648
	return seed % n
}
/**
 * One value from a pool.
 *
 * @param {Array} pool The pool.
 * @return {*} The value.
 */
function pick(pool) {
	return pool[rand(pool.length)]
}

const entries = [
	'hermiq.listFiles', '', 7, null, { id: 'openregister.object.read' }, { id: '' }, { id: 3 },
	{ id: 'flow.run', args: { flow: 'f1' } }, { id: 'flow.run', args: { flow: ['a', 'b'], n: 2 } },
	{ id: 'mail.send', args: {} }, { id: 'mail.send', args: null },
]
/**
 * A stored `tools` value in one of its shapes.
 *
 * @return {*} The value.
 */
function tools() {
	const kind = rand(6)
	if (kind === 0) {
		return pick([null, 'x', 3, undefined])
	}
	if (kind === 1) {
		return [pick(entries), pick(entries), 'hermiq.listFiles']
	}
	const out = {}
	for (let a = 0; a < 1 + rand(3); a++) {
		const subjects = pick([null, 'none', {}, {}, {}])
		if (subjects !== null && typeof subjects === 'object') {
			for (let s = 0; s < 1 + rand(3); s++) {
				const actions = pick([null, 5, {}, {}, {}])
				if (actions !== null && typeof actions === 'object') {
					for (let c = 0; c < 1 + rand(3); c++) {
						actions['a' + c] = pick([pick(entries), [pick(entries), pick(entries)], []])
					}
				}
				subjects['s' + s] = actions
			}
		}
		out['app' + a] = subjects
	}
	return out
}

const ids = [
	'hermiq.listFiles', 'hermiq.readFile', 'hermiq_webFetch', 'openregister.object.read', 'openregister_objects_create',
	'calendar.events.list', 'deck.boards', 'hermiq.searchContacts', 'pipelineForecast', 'x', '', 'a.b.c.d',
	'hermiq.createCalendarEvent', 'notes_list_all', 'mail_send', 'hermiq.recallMemory', 'courses.list', 'boxes_create',
	'hermiq.listCategories', 'tool', 'hermiq.UPPER', 'app_deleteThings',
]
/**
 * A taxonomy row in one of its shapes.
 *
 * @return {object|undefined} The row.
 */
function taxonomy() {
	return pick([
		undefined, {}, { right: 'read' }, { subject: 'file', action: 'list' }, { subject: '', action: 'list' },
		{ subject: 'file', action: '' }, { subject: 3, action: 'read' }, { subject: 'event', action: 4, right: 'write' },
		{ subject: 'note', action: 'archive' },
	])
}

/**
 * A schema carrying an allowlist in one of its shapes and places.
 *
 * @return {*} The schema.
 */
function schema() {
	const spec = pick([
		undefined, null, 'title', 5, [], ['title', '', 'missing', 7, null],
		[{ property: 'title', maxLength: 3 }, { property: '' }, { name: 'x' }, 'body'],
		{ title: { maxLength: 2 }, body: null, '': {}, count: 'caps' },
		{ title: {}, empty: {}, nul: {}, zero: { maxLength: 0 }, emoji: { maxLength: 1 } },
	])
	const kind = rand(4)
	if (kind === 0) {
		return spec === undefined ? {} : { 'x-openregister-agent-context': spec }
	}
	if (kind === 1) {
		return { configuration: { 'x-openregister-agent-context': spec } }
	}
	if (kind === 2) {
		return { 'x-openregister-agent-context': spec, configuration: { 'x-openregister-agent-context': ['body'] } }
	}
	return pick([null, 'schema', undefined])
}

const data = { title: 'Hello world', body: 'Body text', empty: '', nul: null, count: 0, zero: 'zz', emoji: '😀😀x' }
const cases = []
for (let i = 0; i < 400; i++) {
	const stored = tools()
	const id = pick(ids)
	const row = taxonomy()
	const sch = schema()
	cases.push({
		flatten: JSON.parse(JSON.stringify(flattenGrants(stored))),
		parse: [id, JSON.parse(JSON.stringify(row === undefined ? parseVerbAndSubject(id) : parseVerbAndSubject(id, row)))],
		context: JSON.parse(JSON.stringify(buildAgentContext(pick([data, data, data, null, 'x']), sch))),
	})
}

const text = JSON.stringify(cases, null, 1) + '\n'
if (process.env.GOLDEN_RECORD === '1') {
	fs.mkdirSync(path.dirname(FIXTURE), { recursive: true })
	fs.writeFileSync(FIXTURE, text)
	console.log('grant-reading golden: fixture recorded')
	process.exit(0)
}

const expected = JSON.parse(fs.readFileSync(FIXTURE, 'utf8'))
let failures = 0
expected.forEach((one, index) => {
	if (JSON.stringify(one) !== JSON.stringify(cases[index])) {
		failures++
		if (failures <= 5) {
			console.error(`case ${index} differs:\n  want ${JSON.stringify(one)}\n  got  ${JSON.stringify(cases[index])}`)
		}
	}
})
if (expected.length !== cases.length || failures > 0) {
	console.error(`grant-reading golden: ${failures} case(s) differ`)
	process.exit(1)
}
console.log(`grant-reading golden: all ${cases.length} cases match`)
