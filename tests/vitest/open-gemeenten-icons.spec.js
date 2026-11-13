/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The committed OpenGemeenten icon list is the shape CnIconPicker's
 * fromOpenGemeenten() reads: one {name, path, viewBox} per icon, named after
 * its web-component entry, in file order, with the skipped icons left out.
 * A regeneration that breaks that shape fails here instead of in the picker.
 */

import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import { OPEN_GEMEENTEN_ICONS } from '../../src/icons/openGemeentenIcons.js'

const SOURCE = readFileSync(resolve(__dirname, '../../src/icons/openGemeentenIcons.js'), 'utf8')

describe('OPEN_GEMEENTEN_ICONS', () => {
	it('holds exactly the number of icons its header announces', () => {
		const announced = SOURCE.match(/^\/\/ (\d+) icons\. (\d+) skipped: (.+)$/m)

		expect(announced).not.toBeNull()
		expect(OPEN_GEMEENTEN_ICONS).toHaveLength(Number(announced[1]))
	})

	it('leaves the skipped icons and the container element out', () => {
		const skipped = SOURCE.match(/^\/\/ \d+ icons\. \d+ skipped: (.+)$/m)[1].split(', ')
		const names = OPEN_GEMEENTEN_ICONS.map((icon) => icon.name)

		expect(skipped).toEqual(['bezwaar-en-beroep', 'f-1', 'meeuwenoverlast'])
		for (const name of [...skipped, 'container']) {
			expect(names).not.toContain(name)
		}
	})

	it('gives every icon exactly a kebab-case name, an SVG path and a viewBox', () => {
		for (const icon of OPEN_GEMEENTEN_ICONS) {
			expect(Object.keys(icon)).toEqual(['name', 'path', 'viewBox'])
			expect(icon.name).toMatch(/^[a-z0-9]+(-[a-z0-9]+)*$/)
			expect(icon.path).toMatch(/^[Mm][-\d.]/)
			expect(icon.path).not.toMatch(/["<>]/)
			expect(icon.viewBox).toMatch(/^-?\d+(\.\d+)? -?\d+(\.\d+)? \d+(\.\d+)? \d+(\.\d+)?$/)
		}
	})

	it('has unique names in the sorted file order the generator emits', () => {
		const names = OPEN_GEMEENTEN_ICONS.map((icon) => icon.name)
		const entryOrder = [...names].sort((a, b) => {
			const fa = `opengemeenten-icon-${a}.entry.js`
			const fb = `opengemeenten-icon-${b}.entry.js`
			return fa < fb ? -1 : (fa > fb ? 1 : 0)
		})

		expect(new Set(names).size).toBe(names.length)
		expect(names).toEqual(entryOrder)
	})

	it('includes the icons the agent form offers by name', () => {
		const byName = Object.fromEntries(OPEN_GEMEENTEN_ICONS.map((icon) => [icon.name, icon]))

		expect(byName.aanmelden.viewBox).toBe('0 0 48 48')
		expect(byName.zoeken.path.startsWith('M41.35 34.9')).toBe(true)
		expect(OPEN_GEMEENTEN_ICONS[0].name).toBe('aanmelden')
		expect(OPEN_GEMEENTEN_ICONS.at(-1).name).toBe('zwemmen')
	})
})
