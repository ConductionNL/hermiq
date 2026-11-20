/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * SuiteIndependenceTest for the always-loaded companion (companion-everywhere,
 * ADR-087 §5).
 *
 * The companion mounts on every Nextcloud page, office editors included. It
 * must decide WHETHER to mount, and what context to hand the agent, without
 * knowing which office suite is open: attaching per suite would make a
 * capability depend on a named suite, which ADR-087 §5 bans. Comments may name
 * the suites (they explain the URL shapes), code may not.
 *
 * The detector is checked against two fixtures first, so a detector that
 * matches nothing cannot pass the real file by accident.
 */

const assert = require('assert')
const fs = require('fs')
const path = require('path')

/** The office suites a branch must never name. */
const SUITES = [
	'onlyoffice',
	'eurooffice',
	'richdocuments',
	'collabora',
	'officeonline',
	'documentserver',
]

/**
 * The source without comments, keeping string literals.
 *
 * @param {string} source JavaScript source.
 * @return {string} The source with line and block comments removed.
 */
function stripComments(source) {
	let out = ''
	let quote = null
	for (let i = 0; i < source.length; i++) {
		const ch = source[i]
		const next = source[i + 1]
		if (quote !== null) {
			out += ch
			if (ch === '\\') {
				out += next ?? ''
				i++
			} else if (ch === quote) {
				quote = null
			}
			continue
		}

		if (ch === '/' && next === '/') {
			while (i < source.length && source[i] !== '\n') {
				i++
			}
			out += '\n'
			continue
		}

		if (ch === '/' && next === '*') {
			i += 2
			while (i < source.length && !(source[i] === '*' && source[i + 1] === '/')) {
				i++
			}
			i++
			continue
		}

		if (ch === '\'' || ch === '"' || ch === '`') {
			quote = ch
		}
		out += ch
	}

	return out
}

/**
 * The suite names the code (not the comments) mentions.
 *
 * @param {string} source JavaScript source.
 * @return {Array<string>} The suites named in code.
 */
function suitesNamedInCode(source) {
	const code = stripComments(source).toLowerCase()

	return SUITES.filter((suite) => code.includes(suite))
}

const failures = []

/**
 * Run one check and record a failure.
 *
 * @param {string} name The check's name.
 * @param {Function} fn The check.
 * @return {void}
 */
function check(name, fn) {
	try {
		fn()
		console.log(`ok - ${name}`)
	} catch (e) {
		failures.push(name)
		console.log(`not ok - ${name}\n  ${e.message}`)
	}
}

check('the detector finds a branch on a named suite', () => {
	const branching = "if (appId === 'onlyoffice') { mountInto('#editor') }"
	assert.deepStrictEqual(suitesNamedInCode(branching), ['onlyoffice'])
})

check('the detector ignores a suite named only in a comment', () => {
	const commented = "// `/apps/eurooffice/24753` carries the id\n/* richdocuments uses ?fileId */\nconst id = last"
	assert.deepStrictEqual(suitesNamedInCode(commented), [])
})

check('the companion mount names no office suite in code', () => {
	const source = fs.readFileSync(path.join(__dirname, '..', 'src', 'companion.js'), 'utf8')
	assert.deepStrictEqual(
		suitesNamedInCode(source),
		[],
		'src/companion.js branches on a named office suite (ADR-087 §5)',
	)
})

if (failures.length > 0) {
	console.log(`\n${failures.length} suite-independence check(s) failed.`)
	process.exit(1)
}
