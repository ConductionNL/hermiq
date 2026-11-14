/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The companion init script must not branch on which office suite is present
 * (ADR-087 §5): no capability may come to depend on a named suite. The names
 * may appear in comments that explain the design, never in the code itself.
 *
 * @spec openspec/changes/companion-everywhere/specs/companion-everywhere/spec.md
 */

import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'

const SOURCE = resolve(dirname(fileURLToPath(import.meta.url)), '../../src/companion.js')

const SUITES = ['onlyoffice', 'eurooffice', 'richdocuments', 'collabora']

/**
 * The script with its block and line comments removed.
 *
 * @param {string} text The source.
 * @return {string} The code alone.
 */
function withoutComments(text) {
	return text
		.replace(/\/\*[\s\S]*?\*\//g, '')
		.split('\n')
		.map((line) => line.replace(/(^|[^:])\/\/.*$/, '$1'))
		.join('\n')
}

describe('companion suite independence', () => {
	const code = withoutComments(readFileSync(SOURCE, 'utf8')).toLowerCase()

	it.each(SUITES)('names no office suite in its code: %s', (suite) => {
		expect(code).not.toContain(suite)
	})

	it('still reads the comments it strips, so the check is not vacuous', () => {
		expect(readFileSync(SOURCE, 'utf8').toLowerCase()).toContain('richdocuments')
		expect(code).toContain('openfileid')
	})
})
