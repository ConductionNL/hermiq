#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// registry.spec.js — structural validation for src/registry.js.
//
// Usage:
//   node tests/registry.spec.js
//
// Exit codes:
//   0 — registry has all five kinds represented and each entry has the
//       required metadata for its kind.
//   1 — one or more kinds are missing, or an entry is missing required
//       metadata.
//
// This is a Node CJS script (no transpilation), consistent with the existing
// validate-manifest.js / validate-register.js pattern. It uses the
// module-shimming technique from validate-manifest.js: Vue SFCs cannot be
// required without a Vue loader, so the script validates the JS metadata
// (kind, defaultSize, etc.) without instantiating the Vue components.

'use strict'

const fs = require('fs')
const path = require('path')
const vm = require('vm')

const REPO_ROOT = path.resolve(__dirname, '..')
const REGISTRY_PATH = path.join(REPO_ROOT, 'src', 'registry.js')

const REQUIRED_KINDS = ['widget', 'modal', 'page', 'form-field', 'cell-renderer']

// Required metadata fields per kind (mirrors CnAppRoot registry validation).
const KIND_REQUIRED_META = {
	widget: ['defaultSize', 'minSize', 'maxSize', 'allowedSlots', 'propsSchema'],
	modal: ['propsSchema'],
	page: [],
	'form-field': ['appliesTo'],
	'cell-renderer': ['appliesTo'],
}

/**
 * Parse the registry.js ES module source into a plain JS object.
 *
 * Strategy: replace ES module syntax with CJS-compatible stubs so we can
 * evaluate the metadata portion without requiring a Webpack/Babel pipeline.
 * Vue SFC imports are stubbed with a plain object { __vueStub: true }.
 * The `export default { ... }` is extracted and the object literal is eval'd
 * as a module.exports assignment.
 *
 * @param {string} srcPath Absolute path to registry.js
 * @return {object} Parsed registry object (component values are stubs)
 */
function parseRegistry(srcPath) {
	let src = fs.readFileSync(srcPath, 'utf8')

	// 1. Replace all import statements with a stub assignment so the
	//    required-but-unavailable .vue files don't cause require() to throw.
	//    Handles both single-line and inline forms:
	//      import Foo from './foo.vue'
	//    Becomes:
	//      const Foo = { __vueStub: true }
	src = src.replace(/import\s+(\w+)\s+from\s+['"][^'"]+['"]/g, (_, name) => {
		return `const ${name} = { __vueStub: true }`
	})

	// 2. Replace `export default {` with `module.exports = {`
	src = src.replace(/export default \{/, 'module.exports = {')

	// 3. Evaluate in a sandboxed context.
	const sandbox = {
		module: { exports: {} },
		require: () => ({}), // stub any stray require() calls
		console,
	}
	try {
		vm.runInNewContext(src, sandbox)
	} catch (err) {
		throw new Error(`Failed to evaluate registry.js: ${err.message}`)
	}
	return sandbox.module.exports
}

function main() {
	if (!fs.existsSync(REGISTRY_PATH)) {
		console.error(`[registry.spec] registry not found: ${REGISTRY_PATH}`)
		process.exit(1)
	}

	console.log(`[registry.spec] registry: ${REGISTRY_PATH}`)

	let registry
	try {
		registry = parseRegistry(REGISTRY_PATH)
	} catch (err) {
		console.error(`[registry.spec] parse error: ${err.message}`)
		process.exit(1)
	}

	const entries = Object.entries(registry)
	console.log(`[registry.spec] entries: ${entries.length}`)

	const errors = []
	const foundKinds = new Set()

	for (const [key, entry] of entries) {
		if (!entry || typeof entry !== 'object') {
			errors.push(`entry "${key}": must be an object`)
			continue
		}

		const kind = entry.kind
		if (typeof kind !== 'string' || kind.length === 0) {
			errors.push(`entry "${key}": missing required "kind" field`)
			continue
		}

		if (!REQUIRED_KINDS.includes(kind)) {
			errors.push(`entry "${key}": unknown kind "${kind}" (must be one of: ${REQUIRED_KINDS.join(', ')})`)
			continue
		}

		foundKinds.add(kind)

		// Validate component is present (but we only check it's set, since the
		// value is a stubbed Vue object during this parse step)
		if (!entry.component) {
			errors.push(`entry "${key}" (kind=${kind}): missing required "component" field`)
		}

		// Validate kind-specific required metadata fields
		const requiredMeta = KIND_REQUIRED_META[kind] || []
		for (const field of requiredMeta) {
			if (entry[field] === undefined) {
				errors.push(`entry "${key}" (kind=${kind}): missing required metadata field "${field}"`)
			}
		}

		// Extra: form-field appliesTo must have format or property
		if (kind === 'form-field' && entry.appliesTo && typeof entry.appliesTo === 'object') {
			if (!entry.appliesTo.format && !entry.appliesTo.property) {
				errors.push(`entry "${key}" (kind=form-field): appliesTo must have "format" or "property"`)
			}
		}

		// Extra: cell-renderer appliesTo must have schema AND property
		if (kind === 'cell-renderer' && entry.appliesTo && typeof entry.appliesTo === 'object') {
			if (!entry.appliesTo.schema || !entry.appliesTo.property) {
				errors.push(`entry "${key}" (kind=cell-renderer): appliesTo must have "schema" and "property"`)
			}
		}
	}

	// Check all five kinds are represented
	for (const kind of REQUIRED_KINDS) {
		if (!foundKinds.has(kind)) {
			errors.push(`missing kind: no entry with kind="${kind}" found in registry`)
		}
	}

	if (errors.length === 0) {
		console.log(`[registry.spec] all five kinds present: ${[...foundKinds].sort().join(', ')}`)
		console.log('[registry.spec] registry validation: PASS (0 errors)')
		process.exit(0)
	}

	console.error('[registry.spec] registry validation: FAIL')
	for (const err of errors) console.error(`  - ${err}`)
	process.exit(1)
}

main()
