#!/usr/bin/env node
/**
 * The tool-id parser behind the grant matrix.
 *
 * 🔴 This test exists because the parser was wrong on 35 of the 87 undeclared
 * tools in the live catalogue while every other instrument was green — the
 * build, the linter, and a 9-test Playwright suite that opened the panel and
 * typed into it. None of them could see it: the matrix rendered, the checkboxes
 * ticked, and the rows were simply labelled with the wrong nouns. A parse that
 * produces a plausible-looking label is indistinguishable from a correct one
 * unless something asserts WHICH label.
 *
 * The fixtures below are real ids taken from the running instance, not names
 * chosen to suit the rule.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

const assert = require('assert')
const fs = require('fs')
const path = require('path')
const vm = require('vm')

// The module under test is an ES module and this suite runs as CommonJS under
// plain `node`, matching every other spec in this directory. Rather than add a
// build step for one file, its exports are evaluated directly.
const source = fs.readFileSync(
	path.join(__dirname, '..', 'src', 'utils', 'toolTaxonomy.js'),
	'utf8',
)
const context = { module: { exports: {} }, exports: {} }
vm.createContext(context)
vm.runInContext(
	source.replace(/^export (function|const) /gm, '$1 ')
		+ '\nmodule.exports = { parseVerbAndSubject, splitOnVerbSegment, singularise, joinKey,'
		+ ' VERB_ALIASES, CANONICAL_VERBS, SPECIAL_VERBS }',
	context,
)
const { parseVerbAndSubject, singularise, joinKey, VERB_ALIASES, CANONICAL_VERBS } =
	context.module.exports

let failures = 0

/**
 * Assert one parse, reporting rather than throwing so every case is measured.
 *
 * @param {string} id       The tool id.
 * @param {object} taxonomy The taxonomy row.
 * @param {object} want     The expected verb/subject/specialLabel.
 * @return {void}
 */
function check(id, taxonomy, want) {
	const got = parseVerbAndSubject(id, taxonomy)
	try {
		assert.deepStrictEqual(
			{ verb: got.verb, subject: got.subject, specialLabel: got.specialLabel },
			want,
		)
		console.log(
			`  ok   ${id}  ->  ${got.subject} / ${got.specialLabel ?? got.verb}`,
		)
	} catch {
		failures++
		console.log(`  FAIL ${id}`)
		console.log(`       want ${JSON.stringify(want)}`)
		console.log(
			`       got  ${JSON.stringify({ verb: got.verb, subject: got.subject, specialLabel: got.specialLabel })}`,
		)
	}
}

console.log('\nA declared subject and action are used verbatim')
// Never parsed. These are 90 of the 177 tools on the live instance.
check(
	'docudesk_generatedDocument_search',
	{ subject: 'generatedDocument', action: 'search' },
	{ verb: 'list', subject: 'generatedDocument', specialLabel: null },
)
check(
	'pipelinq_lead_get',
	{ subject: 'lead', action: 'get' },
	{ verb: 'read', subject: 'lead', specialLabel: null },
)
// A declared subject is NOT singularised — it is already what the producer
// meant, and "status" would become "statu".
check(
	'x_y_z',
	{ subject: 'status', action: 'get' },
	{ verb: 'read', subject: 'status', specialLabel: null },
)

console.log('\nTwo-segment snake ids keep their verb (the OpenRegister core)')
// 🔴 These 30 previously lost the verb entirely: `parts.length === 2` was read
// as `app_name`, so "list" was taken for an app prefix and thrown away, and all
// 30 collapsed onto the coarse `right` field.
check(
	'list_registers',
	{},
	{ verb: 'list', subject: 'register', specialLabel: null },
)
check('get_register', {}, { verb: 'read', subject: 'register', specialLabel: null })
check(
	'create_register',
	{},
	{ verb: 'create', subject: 'register', specialLabel: null },
)
check(
	'update_register',
	{},
	{ verb: 'update', subject: 'register', specialLabel: null },
)
check(
	'delete_register',
	{},
	{ verb: 'delete', subject: 'register', specialLabel: null },
)
check('search_objects', {}, { verb: 'list', subject: 'object', specialLabel: null })
check('list_schemas', {}, { verb: 'list', subject: 'schema', specialLabel: null })
check(
	'list_applications',
	{},
	{ verb: 'list', subject: 'application', specialLabel: null },
)
check('list_agents', {}, { verb: 'list', subject: 'agent', specialLabel: null })

console.log('\nThree-segment snake ids are not read backwards')
// 🔴 These 5 were INVERTED: subject "create", special verb "page".
check('cms_create_page', {}, { verb: 'create', subject: 'page', specialLabel: null })
check('cms_list_pages', {}, { verb: 'list', subject: 'page', specialLabel: null })
check('cms_create_menu', {}, { verb: 'create', subject: 'menu', specialLabel: null })
check('cms_list_menus', {}, { verb: 'list', subject: 'menu', specialLabel: null })
check(
	'cms_add_menu_item',
	{},
	{ verb: 'create', subject: 'menu_item', specialLabel: null },
)

console.log('\nThe schema-derived shape still parses verb-last when undeclared')
// A known verb in FINAL position with two segments ahead of it is the only
// case that is unambiguously `app_subject_verb`. Without this the fallback
// would break every undeclared tool that does follow the schema shape.
check(
	'myapp_invoice_create',
	{},
	{ verb: 'create', subject: 'invoice', specialLabel: null },
)
check(
	'myapp_purchase_order_delete',
	{},
	{ verb: 'delete', subject: 'purchase_order', specialLabel: null },
)

console.log('\ncamelCase ids are unaffected by the segment rule')
// ⚠️ The regression risk of locating the verb by segment: `listFiles` must NOT
// match as a verb segment, or the subject would be empty.
check('hermiq_listFiles', {}, { verb: 'list', subject: 'file', specialLabel: null })
check('hermiq_readFile', {}, { verb: 'read', subject: 'file', specialLabel: null })
check(
	'hermiq_sendEmail',
	{},
	{ verb: 'special', subject: 'email', specialLabel: 'send' },
)
check(
	'hermiq_delegateAgent',
	{},
	{ verb: 'special', subject: 'agent', specialLabel: 'delegate' },
)
check(
	'opencatalogi_searchCatalog',
	{},
	{ verb: 'list', subject: 'catalog', specialLabel: null },
)
check(
	'pipelinq_logContactmoment',
	{},
	{ verb: 'special', subject: 'contactmoment', specialLabel: 'log' },
)

console.log('\nA name with no verb in it names itself')
// ⚠️ "web" and "pipeline" are not verbs. Splitting on any leading lowercase run
// invented the subjects "fetch" and "forecast", naming things that do not exist.
check(
	'hermiq_webSearch',
	{},
	{ verb: 'special', subject: 'webSearch', specialLabel: 'webSearch' },
)
check(
	'hermiq_webFetch',
	{},
	{ verb: 'special', subject: 'webFetch', specialLabel: 'webFetch' },
)
check(
	'pipelinq_pipelineForecast',
	{},
	{
		verb: 'special',
		subject: 'pipelineForecast',
		specialLabel: 'pipelineForecast',
	},
)

console.log('\nDots and underscores are one separator')
check(
	'pipelinq.lead.search',
	{ subject: 'lead', action: 'search' },
	{ verb: 'list', subject: 'lead', specialLabel: null },
)
assert.strictEqual(joinKey('pipelinq.lead.search'), 'pipelinq_lead_search')
assert.strictEqual(joinKey(null), '')

console.log('\nSingularising is lossy and stays narrow')
assert.strictEqual(singularise('registers'), 'register')
assert.strictEqual(singularise('policies'), 'policy')
assert.strictEqual(singularise('boxes'), 'box')
// ⚠️ Both previously regressed: "courses" -> "cours" under an `s|x|z|ch|sh`
// group, and "address" would lose its final letter under a bare `s$`.
assert.strictEqual(singularise('courses'), 'course')
assert.strictEqual(singularise('address'), 'address')
console.log('  ok   registers/policies/boxes/courses/address')

console.log('\nEvery canonical verb is reachable from some alias')
// A verb with no alias silently produces an empty column no tool can ever tick.
for (const verb of CANONICAL_VERBS) {
	const reachable = Object.values(VERB_ALIASES).includes(verb)
	if (reachable === false) {
		failures++
		console.log(`  FAIL canonical column "${verb}" has no alias mapping to it`)
	}
}
console.log('  ok   all five canonical columns are reachable')

if (failures > 0) {
	console.error(`\n${failures} parse(s) wrong\n`)
	process.exit(1)
}

console.log('\nAll tool-id parses correct\n')
