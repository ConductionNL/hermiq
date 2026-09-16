#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// connections-page.spec.js — the Integrations page, its declaration and the
// built-in formatters it renders with (adopt-connection-registry).
//
// Usage:
//   node tests/connections-page.spec.js
//
// Exit codes:
//   0 — every assertion holds.
//   1 — one or more assertions failed.
//
// Everything asserted here fails SILENTLY in the browser. A menu entry without
// its `query` lists every app's rows as though they were hermiq's. A header
// action naming a handler nobody passes to CnAppRoot does nothing when clicked.
// A local formatter under a built-in's name wins over the built-in, so a copy
// that predates a status renders that status as its raw word. A page without
// `requiresApp` renders an empty table where it should say integriq is missing.
//
// @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-the-integrations-page-lists-hermiqs-rows-from-integriq-req-hermiq-conn-002

'use strict'

const assert = require('assert')
const fs = require('fs')
const path = require('path')
const { pathToFileURL } = require('url')

const ROOT = path.resolve(__dirname, '..')
const read = (...parts) => fs.readFileSync(path.join(ROOT, ...parts), 'utf8')
const readJson = (...parts) => JSON.parse(read(...parts))

const failures = []

/**
 * Run one named check and record its failure instead of stopping.
 *
 * @param {string} name What the check proves.
 * @param {function(): void} fn The assertions.
 */
function check(name, fn) {
	try {
		fn()
		console.log(`  ok   ${name}`)
	} catch (e) {
		failures.push(name)
		console.log(
			`  FAIL ${name}\n       ${e.message.split('\n').join('\n       ')}`,
		)
	}
}

/**
 * Load the ESM module under test and run every check.
 */
async function main() {
	const registry = await import(
		pathToFileURL(path.join(ROOT, 'src', 'services', 'connectionRegistry.js'))
			.href
	)
	// The built-ins import @nextcloud/l10n, whose auth dependency reads
	// `window` at load. Plain node has none, and an untranslated call returns
	// the English source string, which is what the check compares.
	globalThis.window ??= globalThis
	const builtIns = await import(
		pathToFileURL(
			require.resolve('@conduction/nextcloud-vue/dist/esm/utils/builtInFormatters.js'),
		).href
	)
	const libraryNl = JSON.parse(
		fs.readFileSync(
			require.resolve('@conduction/nextcloud-vue/l10n/nl.json'),
			'utf8',
		),
	).translations

	const manifest = readJson('src', 'manifest.json')
	const en = readJson('l10n', 'en.json').translations
	const nl = readJson('l10n', 'nl.json').translations
	const appVue = read('src', 'App.vue')
	const customComponentsJs = read('src', 'customComponents.js')

	const page = manifest.pages.find((p) => p.id === 'Integrations')
	const menuEntry = manifest.menu.find((m) => m.route === 'Integrations')

	console.log('[connections-page.spec]')

	check(
		"the page is an admin index page over integriq's app_connection schema",
		() => {
			assert.ok(page, 'no page with id Integrations')
			assert.strictEqual(page.type, 'index')
			assert.strictEqual(page.route, '/settings/integrations')
			assert.strictEqual(page.permission, 'admin')
			assert.strictEqual(page.config.register, 'integriq')
			assert.strictEqual(page.config.schema, 'app_connection')
		},
	)

	check('the page names Integriq as the app it needs', () => {
		assert.deepStrictEqual(page.requiresApp, {
			id: 'integriq',
			name: 'Integriq',
		})
	})

	check(
		'the status and settings columns render through the contract formatters',
		() => {
			const byKey = Object.fromEntries(
				page.config.columns.map((c) => [c.key, c]),
			)
			assert.deepStrictEqual(Object.keys(byKey), [
				'title',
				'status',
				'statusMessage',
				'checkedAt',
				'settingsUrl',
			])
			assert.strictEqual(byKey.status.formatter, 'connectionStatus')
			assert.strictEqual(
				byKey.settingsUrl.formatter,
				'connectionSettingsLabel',
			)
			assert.strictEqual(byKey.settingsUrl.widget, 'link')
			assert.strictEqual(byKey.settingsUrl.widgetProps.href, '{settingsUrl}')
		},
	)

	check('the page sorts on the declared order and groups on status', () => {
		assert.deepStrictEqual(page.config.defaultSort, {
			field: 'order',
			direction: 'asc',
		})
		assert.strictEqual(page.config.folderSidebar.source, 'field')
		assert.strictEqual(page.config.folderSidebar.field, 'status')
	})

	check('no generic Add button, and Add integration goes to integriq', () => {
		assert.strictEqual(page.config.showAdd, false)
		const add = page.config.headerActions.find((a) => a.id === 'add-integration')
		assert.ok(add, 'no add-integration header action')
		assert.strictEqual(add.label, 'Add integration')
		assert.strictEqual(add.handler, 'openIntegriqConnections')
		assert.strictEqual(
			registry.INTEGRIQ_CONNECTIONS_PATH,
			'/apps/integriq/connections?app=hermiq&link=1',
		)

		let assigned = null
		const handlers = registry.createConnectionHandlers({
			generateUrl: (p) => `/index.php${p}`,
			assign: (url) => {
				assigned = url
			},
		})
		handlers.openIntegriqConnections()
		assert.strictEqual(
			assigned,
			'/index.php/apps/integriq/connections?app=hermiq&link=1',
		)
	})

	check('the handler reaches CnAppRoot', () => {
		assert.match(customComponentsJs, /\.\.\.createConnectionHandlers\(/)
	})

	check("the menu entry presets the list to hermiq's own rows", () => {
		assert.ok(menuEntry, 'no menu entry routes to Integrations')
		assert.deepStrictEqual(menuEntry.query, { app: 'hermiq' })
	})

	check(
		'the menu entry only renders when integriq is installed, in the settings foldout',
		() => {
			assert.deepStrictEqual(menuEntry.visibleIf, { appInstalled: 'integriq' })
			assert.strictEqual(menuEntry.section, 'settings')
		},
	)

	check('a switched-off connection reads Switched off, from the built-in', () => {
		// CnAppRoot provides `{ ...BUILT_IN_FORMATTERS, ...props.formatters }`,
		// so this builds the registry the same way. A local copy passed to
		// CnAppRoot under either name wins, and one that predates `disabled`
		// shows the raw word.
		const local = /createConnectionFormatters\(/.test(appVue)
			? registry.createConnectionFormatters((source) => source)
			: {}
		const formatters = { ...builtIns.BUILT_IN_FORMATTERS, ...local }
		assert.strictEqual(formatters.connectionStatus('disabled'), 'Switched off')
		assert.strictEqual(libraryNl['Switched off'], 'Uitgeschakeld')
		assert.doesNotMatch(appVue, /:formatters=/)
	})

	check('the page strings are in the English and Dutch catalogues', () => {
		for (const source of [
			'Add integration',
			'Integrations',
			'Last checked',
			'Status message',
			'All connections',
		]) {
			assert.strictEqual(en[source], source, `en.json lacks "${source}"`)
			assert.ok(nl[source], `nl.json lacks "${source}"`)
			assert.notStrictEqual(
				nl[source],
				source,
				`nl.json leaves "${source}" in English`,
			)
		}
	})

	if (failures.length > 0) {
		console.log(`[connections-page.spec] FAIL: ${failures.length} check(s)`)
		process.exit(1)
	}
	console.log('[connections-page.spec] PASS')
}

main().catch((e) => {
	console.error(e)
	process.exit(1)
})
