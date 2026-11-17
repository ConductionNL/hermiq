/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Characterisation of the registry's modal entries: the props schema each
 * modal declares, and that no two entries share one schema object.
 */

import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { runInNewContext } from 'node:vm'
import { describe, expect, it } from 'vitest'

/**
 * The registry's metadata, read the way tests/registry.spec.js reads it: every
 * import becomes a stub, so no component is loaded.
 *
 * @return {object} The registry object.
 */
function readRegistry() {
	const source = readFileSync(resolve(process.cwd(), 'src/registry.js'), 'utf8')
		.replace(/import\s+\{([^}]+)\}\s+from\s+['"][^'"]+['"]/g, (_, names) => names
			.split(',')
			.map((name) => name.trim().split(/\s+as\s+/).pop().trim())
			.filter(Boolean)
			.map((name) => `const ${name} = {}`)
			.join('; '))
		.replace(/import\s+(\w+)\s+from\s+['"][^'"]+['"]/g, (_, name) => `const ${name} = {}`)
		.replace(/export default \{/, 'module.exports = {')
	const sandbox = { module: { exports: {} } }
	runInNewContext(source, sandbox)
	return sandbox.module.exports
}

const registry = readRegistry()

const SHOW_ONLY = { type: 'object', properties: { show: { type: 'boolean' } } }

describe('registry modal props', () => {
	it('declares the props schema of each modal', () => {
		const modals = Object.fromEntries(
			Object.entries(registry)
				.filter(([, entry]) => entry.kind === 'modal')
				.map(([key, entry]) => [key, entry.propsSchema]),
		)
		expect(modals).toEqual({
			'agent-form': SHOW_ONLY,
			'agent-version-history': SHOW_ONLY,
			'agent-availability': SHOW_ONLY,
			'agent-factsheet': SHOW_ONLY,
			'template-import': { type: 'object', properties: { mode: { type: 'string' } } },
			'agent-git': SHOW_ONLY,
			'agent-export': { type: 'object', properties: { show: { type: 'boolean' }, mode: { type: 'string' } } },
			'app-template-collect': { type: 'object', properties: {} },
			EvalDatasetFormModal: SHOW_ONLY,
		})
	})

	it('gives every modal its own schema object', () => {
		const schemas = Object.values(registry)
			.filter((entry) => entry.kind === 'modal')
			.map((entry) => entry.propsSchema)
		expect(new Set(schemas).size).toBe(schemas.length)
	})
})
