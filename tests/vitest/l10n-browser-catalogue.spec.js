/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The generated browser catalogue for the source locale (en) carries only the
 * entries that differ from their key; every other locale, and l10n/en.json
 * itself, stays complete.
 *
 * @spec PERF-HANDOFF.md (all apps: drop identity entries from the English catalogue at build time)
 */

import fs from 'fs'
import path from 'path'
import { createRequire } from 'module'
import { describe, expect, it } from 'vitest'

const require = createRequire(import.meta.url)
const {
	browserEntries,
	renderJs,
	SOURCE_LOCALE,
} = require('../../scripts/build-l10n-js.js')

const ROOT = path.resolve(__dirname, '..', '..')

describe('browserEntries', () => {
	it('drops entries equal to their key for the source locale', () => {
		const out = browserEntries(SOURCE_LOCALE, {
			Save: 'Save',
			'Import failed:': 'Import failed: ',
			'reload now': 'Reload now',
		})
		expect(out).toEqual({
			'Import failed:': 'Import failed: ',
			'reload now': 'Reload now',
		})
	})

	it('keeps plural entries, which are arrays and never equal their key', () => {
		const plural = { '_%n card_::_%n cards_': ['%n card', '%n cards'] }
		expect(browserEntries(SOURCE_LOCALE, plural)).toEqual(plural)
	})

	it('keeps every entry for any other locale, identity ones included', () => {
		const nl = { Save: 'Opslaan', OK: 'OK' }
		expect(browserEntries('nl', nl)).toEqual(nl)
	})

	it('returns an empty catalogue when every source entry is an identity', () => {
		expect(browserEntries(SOURCE_LOCALE, { A: 'A', B: 'B' })).toEqual({})
		expect(renderJs('hermiq', {}, 'nplurals=2; plural=(n != 1);')).toContain(
			'OC.L10N.register(',
		)
	})
})

describe('committed catalogues', () => {
	it('l10n/en.js holds exactly the non-identity entries of l10n/en.json', () => {
		const json = JSON.parse(
			fs.readFileSync(path.join(ROOT, 'l10n', 'en.json'), 'utf8'),
		)
		const js = fs.readFileSync(path.join(ROOT, 'l10n', 'en.js'), 'utf8')
		const body = js.match(
			/OC\.L10N\.register\(\s*"[^"]+"\s*,\s*(\{.*\})\s*,\s*"nplurals/s,
		)
		expect(body).not.toBeNull()
		const served = JSON.parse(body[1])
		const expected = Object.fromEntries(
			Object.entries(json.translations).filter(
				([key, value]) => key !== value,
			),
		)
		expect(served).toEqual(expected)
		expect(Object.keys(json.translations).length).toBeGreaterThan(
			Object.keys(served).length,
		)
	})
})
