#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// agent-export.spec.js: the export file name and the import file check of the
// agent page's Export and the catalog's Import agent (agents-export-import-and-git-sync).
//
// Usage:
//   node tests/agent-export.spec.js
//
// @spec openspec/changes/agents-export-import-and-git-sync/specs/agent-template-gallery/spec.md#requirement-an-agent-can-be-exported-to-a-file-from-its-page-req-agexp-001

'use strict'

const assert = require('assert')
const path = require('path')
const { pathToFileURL } = require('url')

const ROOT = path.resolve(__dirname, '..')

;(async () => {
	const { exportFileName, packageFromFile } = await import(
		pathToFileURL(path.join(ROOT, 'src/utils/agentExport.js')).href
	)
	const failures = []
	const check = (name, fn) => {
		try {
			fn()
			console.log(`ok - ${name}`)
		} catch (e) {
			failures.push(name)
			console.log(`not ok - ${name}\n  ${e.message}`)
		}
	}

	check('the file is named after the agent', () => {
		assert.strictEqual(exportFileName('Complaint router'), 'complaint-router.hermiq-agent.json')
	})

	check('accents and punctuation do not reach the file name', () => {
		assert.strictEqual(exportFileName('Één klachten-router!'), 'een-klachten-router.hermiq-agent.json')
	})

	check('an agent without a usable name still gets a file name', () => {
		assert.strictEqual(exportFileName(''), 'agent.hermiq-agent.json')
		assert.strictEqual(exportFileName('!!!'), 'agent.hermiq-agent.json')
	})

	check('an exported package is accepted as it is', () => {
		const text = '{"name": "Complaint router", "systemPrompt": "Route complaints."}'
		assert.strictEqual(packageFromFile(text), text)
	})

	check('a file that is not a JSON object is refused with a reason', () => {
		assert.throws(() => packageFromFile('not json'), /not an exported agent/)
		assert.throws(() => packageFromFile('[1, 2]'), /not an exported agent/)
	})

	check('a package without a name is refused with a reason', () => {
		assert.throws(() => packageFromFile('{"systemPrompt": "x"}'), /has no name/)
	})

	if (failures.length > 0) {
		console.log(`\n${failures.length} failing`)
		process.exit(1)
	}
	console.log('\nall passing')
})().catch((e) => {
	console.error(e)
	process.exit(1)
})
