/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The companion's copy of the component library stylesheet stays inside the
 * companion, and the always-loaded agent leaf injects none. css/companion.css is fetched on every page of every app; copied
 * unscoped, hermiq's (older) library overrode the page's own library rule by
 * rule wherever both had the same scoped selector. Measured on pipelinq's lead
 * page: a list footer kept `margin: auto -16px -16px !important` from here over
 * the app's own 12px bleed, and the tab panel scrolled by 4px.
 */

const assert = require('assert')
const fs = require('fs')
const path = require('path')
const { purge, scopeToCompanion, topLevel, usedNames } = require('../scripts/build-companion-css.js')

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

const SAMPLE = [
	'@charset "UTF-8";',
	':root{--cn-x:1px}',
	'.cn-object-list-widget__add[data-v-1]{margin:auto -16px -16px !important}',
	'/* a comment with { braces } */',
	'.b::after{content:"}{"}',
	'@keyframes k-1{from{opacity:0}to{opacity:1}}',
	'@media (max-width:600px){.c{color:red}}',
	'html :root .d, :root .e{x:y}',
].join('\n')

check('every style rule sits inside @scope (#hermiq-companion-root)', () => {
	const out = scopeToCompanion(SAMPLE)
	const chunks = topLevel(out)
	const scope = chunks.find((chunk) => chunk.startsWith('@scope'))
	assert.ok(scope, 'no @scope block')
	assert.ok(scope.startsWith('@scope (#hermiq-companion-root)'))
	for (const chunk of chunks) {
		if (chunk === scope) {
			continue
		}
		assert.match(
			chunk,
			/^@(charset|keyframes)/,
			`left outside the scope: ${chunk}`,
		)
	}
	assert.ok(scope.includes('.cn-object-list-widget__add[data-v-1]'))
	assert.ok(scope.includes('@media (max-width:600px){.c{color:red}}'))
	assert.ok(
		scope.includes('content:"}{"'),
		'a brace inside a string split the rule',
	)
})

check(
	':root becomes the companion root, so custom properties do not leak to the page',
	() => {
		const out = scopeToCompanion(SAMPLE)
		assert.ok(!/:root\b/.test(out), out)
		assert.ok(out.includes(':scope{--cn-x:1px}'))
		assert.ok(out.includes(':scope .d, :scope .e{x:y}'))
	},
)

check('keyframes and the charset stay at the top level, once', () => {
	const chunks = topLevel(scopeToCompanion(SAMPLE))
	assert.strictEqual(
		chunks.filter((chunk) => chunk.startsWith('@keyframes k-1')).length,
		1,
	)
	assert.strictEqual(chunks[0], '@charset "UTF-8";')
})

const LIBRARY = path.join(
	__dirname,
	'..',
	'node_modules',
	'@conduction',
	'nextcloud-vue',
	'dist',
	'nextcloud-vue.css',
)
if (fs.existsSync(LIBRARY)) {
	check('the installed library stylesheet scopes completely', () => {
		const source = fs.readFileSync(LIBRARY, 'utf8')
		const out = scopeToCompanion(source)
		const chunks = topLevel(out)
		const outside = chunks.filter(
			(chunk) =>
				!chunk.startsWith('@scope')
				&& !/^@(charset|import|namespace|font-face|keyframes|-webkit-keyframes|page|property|layer)/i.test(
					chunk,
				),
		)
		assert.deepStrictEqual(
			outside.map((chunk) => chunk.slice(0, 80)),
			[],
		)
		assert.strictEqual(
			chunks.filter((chunk) => chunk.startsWith('@scope')).length,
			1,
		)
		// Nothing lost: every top-level rule of the source is either hoisted or inside the scope.
		const inScope = topLevel(
			chunks
				.find((chunk) => chunk.startsWith('@scope'))
				.replace(/^@scope \([^)]*\)\s*\{/, '')
				.replace(/\}\s*$/, ''),
		)
		assert.strictEqual(
			inScope.length + chunks.length - 1,
			topLevel(source).length,
		)
	})
}

const LEAF = path.join(__dirname, '..', 'js', 'hermiq-agent-leaf.js')
if (fs.existsSync(LEAF)) {
	check('the always-loaded agent leaf injects no library stylesheet', () => {
		const leaf = fs.readFileSync(LEAF, 'utf8')
		assert.ok(
			!leaf.includes('Main CSS entry point'),
			'the library main stylesheet is inlined in js/hermiq-agent-leaf.js',
		)
		assert.ok(
			!/\.cn-object-list-widget__add\[data-v-/.test(leaf),
			'library component rules are inlined in js/hermiq-agent-leaf.js',
		)
	})
}

const USED = usedNames(['h("div",{class:"cn-ai-panel cn-ai-floating-button--"+pos}) "button-vue"'])
const PURGE_SAMPLE = [
	'@charset "UTF-8";',
	'@keyframes spin{to{transform:rotate(1turn)}}',
	':root{--cn-x:1px}',
	'.cn-ai-panel{display:flex}',
	'.cn-ai-floating-button--bottom-right{right:16px}',
	'.cn-table-container{border:0}',
	'/* a comment, with a comma */\n.cn-look-board .cn-table{x:y}',
	'.cn-table, .button-vue{z:1}',
	'.is-open .cn-unused{a:b}',
	'.fade-enter-active{opacity:0}',
	'@media (max-width:600px){.cn-ai-panel{width:100%}.cn-dashboard{x:y}}',
	'@media print{.cn-dashboard{x:y}}',
].join('\n')
const PURGED = purge(PURGE_SAMPLE, USED)

check('the purge keeps the rules the companion bundles use, and the at-rules', () => {
	for (const kept of ['@charset', '@keyframes spin', ':root{--cn-x', '.cn-ai-panel{display', '.cn-table, .button-vue', '.fade-enter-active']) {
		assert.ok(PURGED.includes(kept), `missing ${kept}`)
	}
})

check('a modifier the bundles build at runtime is kept by its prefix', () => {
	assert.ok(PURGED.includes('.cn-ai-floating-button--bottom-right'))
})

check('the purge drops rules for classes no bundle uses, a comment\'s comma notwithstanding', () => {
	assert.ok(!PURGED.includes('.cn-table-container'))
	assert.ok(!PURGED.includes('.cn-look-board .cn-table'))
	assert.ok(!PURGED.includes('.cn-unused'))
})

check('inside @media the unused rules go, and an emptied block goes with them', () => {
	assert.ok(/@media \(max-width:600px\) \{\.cn-ai-panel\{width:100%\}\}/.test(PURGED))
	assert.ok(!PURGED.includes('@media print'))
})

if (failures.length > 0) {
	console.log(`\n${failures.length} failing`)
	process.exit(1)
}
