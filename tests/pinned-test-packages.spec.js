#!/usr/bin/env node
/**
 * The component-test packages are pinned to exact versions, and the lockfile
 * holds exactly those versions.
 *
 * npm 10 ignores .npmrc's min-release-age, so a caret range lets a plain
 * `npm install` pull a release that is a day old. An exact pin moves only
 * when someone changes it on purpose, after checking its release date.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

const fs = require('fs')
const path = require('path')

const ROOT = path.join(__dirname, '..')
const PINNED = ['vitest', 'vite', 'jsdom', '@vitejs/plugin-vue']

const pkg = JSON.parse(fs.readFileSync(path.join(ROOT, 'package.json'), 'utf8'))
const lock = JSON.parse(
	fs.readFileSync(path.join(ROOT, 'package-lock.json'), 'utf8'),
)

let failures = 0
for (const name of PINNED) {
	const wanted = (pkg.devDependencies || {})[name]
	const locked = (lock.packages[`node_modules/${name}`] || {}).version
	if (typeof wanted !== 'string' || /^\d+\.\d+\.\d+$/.test(wanted) === false) {
		failures++
		console.log(
			`  FAIL ${name}: devDependencies has ${JSON.stringify(wanted)}, not an exact version`,
		)
		continue
	}
	if (locked !== wanted) {
		failures++
		console.log(
			`  FAIL ${name}: package.json pins ${wanted}, the lockfile holds ${locked}`,
		)
		continue
	}
	console.log(`  ok   ${name} ${wanted}`)
}

if (failures > 0) {
	console.error(`\n${failures} test package(s) not pinned\n`)
	process.exit(1)
}

console.log('\nComponent-test packages pinned\n')
