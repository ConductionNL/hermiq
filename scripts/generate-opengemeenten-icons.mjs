#!/usr/bin/env node
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Extract the OpenGemeenten icon set into a plain `{name, path, viewBox}` list.
 *
 * WHY THIS EXISTS
 * ---------------
 * `@opengemeenten/iconset-web-component` ships the set as Stencil web
 * components — 231 lazily-loaded chunks, one custom element each. There is no
 * exported list of icons and no SVG files in the package, so the shape
 * `CnIconPicker`'s `fromOpenGemeenten()` adapter wants (`{name, path}`) has to
 * be recovered from the built entry files, each of which renders exactly one
 * `h('path', { d: … })`.
 *
 * Importing the web components directly would not do: the picker renders a
 * `<path>` from a `d` string, and pulling in 231 lazy chunks to read one
 * attribute out of each would ship the whole Stencil runtime for a list of
 * strings.
 *
 * THE OUTPUT IS COMMITTED. A generated file in the tree is a thing that can go
 * stale, so the trade is deliberate: extracting at build time would make every
 * `npm run build` depend on another package's internal file layout, and that
 * breaks silently on a minor release. Committed, a version bump changes the
 * generated file in a diff a person reads.
 *
 * Re-run with `npm run icons:opengemeenten` after bumping the package.
 *
 * LICENCE: the set is CC0-1.0 — both the package's `license` field and its
 * bundled LICENSE.txt say so, verified 2026-08-10. (OpenCatalogi's
 * `menuItemIconCatalogues.js` claims the npm package is CC BY-NC-ND and ships
 * ten hand-drawn placeholders because of it; that claim does not match the
 * artefact.)
 */

import { readdirSync, readFileSync, writeFileSync } from 'node:fs'
import { join, dirname } from 'node:path'
import { fileURLToPath } from 'node:url'

const HERE = dirname(fileURLToPath(import.meta.url))
const ESM_DIR = join(HERE, '..', 'node_modules', '@opengemeenten', 'iconset-web-component', 'dist', 'esm')
const OUT = join(HERE, '..', 'src', 'icons', 'openGemeentenIcons.js')

const ENTRY = /^opengemeenten-icon-(.+)\.entry\.js$/
// Stencil emits `h("path", { d: "…" })`; the viewBox rides on the sibling svg.
const PATH_D = /h\(\s*"path"\s*,\s*\{\s*d:\s*"([^"]+)"/
const VIEW_BOX = /viewBox:\s*"([^"]+)"/

const icons = []
const skipped = []

for (const file of readdirSync(ESM_DIR).sort()) {
	const match = file.match(ENTRY)
	if (match === null) {
		continue
	}

	const name = match[1]
	// The container is chrome, not an icon.
	if (name === 'container') {
		continue
	}

	const source = readFileSync(join(ESM_DIR, file), 'utf8')
	const d = source.match(PATH_D)
	if (d === null) {
		// A multi-path or mask-based icon this crude reader cannot flatten.
		// Reported rather than dropped silently — a set that quietly loses
		// icons on a version bump is exactly what a generated file hides.
		skipped.push(name)
		continue
	}

	const viewBox = source.match(VIEW_BOX)
	icons.push({
		name,
		path: d[1],
		viewBox: viewBox === null ? '0 0 48 48' : viewBox[1],
	})
}

const header = `// SPDX-FileCopyrightText: 2026 Conduction B.V.
// SPDX-License-Identifier: EUPL-1.2
//
// GENERATED — do not edit by hand.
// Run \`npm run icons:opengemeenten\` to rebuild from
// @opengemeenten/iconset-web-component (CC0-1.0).
//
// ${icons.length} icons.${skipped.length > 0 ? ` ${skipped.length} skipped: ${skipped.join(', ')}` : ''}

/**
 * The OpenGemeenten icon set, as \`CnIconPicker\`'s \`fromOpenGemeenten()\` wants it.
 *
 * @type {Array<{name: string, path: string, viewBox: string}>}
 */
export const OPEN_GEMEENTEN_ICONS = `

writeFileSync(OUT, header + JSON.stringify(icons, null, '\t') + '\n')

process.stdout.write(`Wrote ${icons.length} OpenGemeenten icons to ${OUT}\n`)
if (skipped.length > 0) {
	process.stdout.write(`Skipped ${skipped.length} (no single flat path): ${skipped.join(', ')}\n`)
}
