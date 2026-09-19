#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// check-single-dexie.js: the Dexie singleton guard, fleet edition.
//
// WHY THIS EXISTS, AND WHY HERMIQ'S COPY IS STRICTER THAN THE OTHERS
//
//   Dexie refuses to initialise twice in one page: a second copy at a
//   different version throws "Two different versions of Dexie loaded in the
//   same app" at module init, before any SPA mounts. Hermiq is the app that
//   makes this everyone's problem, because its companion panel and its agent
//   leaf load next to ANOTHER app's bundles, on that app's pages. So hermiq's
//   Dexie is never alone on a page, and a drift here is not a hermiq bug, it
//   is an instance-wide outage.
//
//   That is measured, not hypothetical. On 2026-09-18 hermiq 0.2.14 was
//   deployed carrying dexie 4.4.6 while buildiq and dossiq shipped 4.4.5.
//   Every page of both apps, and every app built with buildiq, rendered
//   blank: no error in the UI, nothing in the Nextcloud log, just an empty
//   body and one console throw. The per-repo guard the fleet already has
//   (procest scripts/check-single-dexie.js) could not have caught it: hermiq
//   agreed with its own lockfile perfectly. Agreement with the FLEET is the
//   property that was missing, so this copy checks that too.
//
// WHAT IT CHECKS
//
//   1. every built chunk that embeds a Dexie copy embeds the SAME version;
//   2. that version is the one package-lock.json resolves, so a stale chunk
//      from an earlier build cannot ship unnoticed;
//   3. that version is FLEET_DEXIE, the version the other apps on the
//      instance ship.
//
//   Check 3 will fail the day the fleet moves to a new Dexie, and that is the
//   intent: hermiq moves WITH the fleet, in the same week, not ahead of it.
//   When the fleet bumps (the dependabot PRs land together), bump the
//   constant below in the same pass. A failure here is never fixed by
//   deleting the check.
//
// HOW IT DETECTS A COPY
//
//   Dexie's own duplicate check ships in every copy of the library, so a
//   built chunk embeds Dexie exactly when it contains the error string
//   "Two different versions of Dexie". Inside such a chunk the version
//   literal survives minification as `semVer:"x.y.z"` (Dexie.semVer).
//
// WHEN IT RUNS
//
//   As `postbuild`, so it runs wherever `npm run build` runs: locally, in
//   code quality CI, and in the release build that packages js/ into the App
//   Store tarball. Standalone via `npm run check:dexie`. When js/ does not
//   exist yet it skips loudly instead of failing.
//
// Exit codes:
//   0: zero or one Dexie version across js/, matching the lockfile and the fleet
//   1: two or more versions, or a version the lockfile or the fleet disagrees with

const fs = require('fs')
const path = require('path')

// The Dexie version every other Conduction app on an instance ships today.
// Verified 2026-09-19 against buildiq, dossiq, openregister, opencatalogi and
// integriq, all resolving 4.4.5, and against @conduction/nextcloud-vue's peer
// range ^4.0.8. Move this only together with them.
const FLEET_DEXIE = '4.4.5'

const repoRoot = path.join(__dirname, '..')
const jsDir = path.join(repoRoot, 'js')

const SENTINEL = 'Two different versions of Dexie'
const SEMVER_RE = /semVer\s*[:=]\s*["']([0-9][0-9A-Za-z.+-]*)["']/g

if (!fs.existsSync(jsDir)) {
	console.log(
		'i dexie singleton: js/ not built yet, skipping (run npm run build first)',
	)
	process.exit(0)
}

let expected = null
try {
	const lock = JSON.parse(
		fs.readFileSync(path.join(repoRoot, 'package-lock.json'), 'utf8'),
	)
	expected =
		(lock.packages
			&& lock.packages['node_modules/dexie']
			&& lock.packages['node_modules/dexie'].version)
		|| null
} catch (e) {
	console.log(
		`i dexie singleton: could not read package-lock.json (${e.message}); checking chunk agreement only`,
	)
}

if (expected && expected !== FLEET_DEXIE) {
	console.error(
		`x dexie singleton: package-lock.json resolves dexie ${expected}, but the fleet ships ${FLEET_DEXIE}. Hermiq's bundles load on every other app's pages, so this drift blanks those apps. Pin dexie back, or bump FLEET_DEXIE in the same pass that bumps the rest of the fleet.`,
	)
	process.exit(1)
}

const findings = []
for (const name of fs.readdirSync(jsDir).sort()) {
	if (!name.endsWith('.js')) {
		continue
	}
	const text = fs.readFileSync(path.join(jsDir, name), 'utf8')
	if (!text.includes(SENTINEL)) {
		continue
	}
	const versions = new Set()
	for (const m of text.matchAll(SEMVER_RE)) {
		versions.add(m[1])
	}
	if (versions.size === 0) {
		// A chunk carries Dexie's error string but no recognisable version
		// literal. The marker contract changed, so the guard can no longer
		// see, and a guard that cannot see must say so rather than pass.
		console.error(
			`x dexie singleton: js/${name} embeds Dexie (sentinel found) but no semVer literal matched; update SEMVER_RE in ${path.basename(__filename)}`,
		)
		process.exit(1)
	}
	for (const v of versions) {
		findings.push({ file: name, version: v })
	}
}

if (findings.length === 0) {
	console.log('+ dexie singleton: no built chunk embeds Dexie')
	process.exit(0)
}

const distinct = [...new Set(findings.map((f) => f.version))].sort()

for (const f of findings) {
	console.log(`  js/${f.file}: dexie ${f.version}`)
}

if (distinct.length > 1) {
	console.error(
		`x dexie singleton: ${distinct.length} different Dexie versions in one chunk set (${distinct.join(', ')}). Loading any two of these chunks in one page throws at module init and the SPA never mounts. Rebuild from a clean js/ with a single resolved dexie.`,
	)
	process.exit(1)
}

if (expected && distinct[0] !== expected) {
	console.error(
		`x dexie singleton: built chunks carry dexie ${distinct[0]} but package-lock.json resolves ${expected}. A chunk is stale, or a dependency vendors its own copy. Rebuild from a clean js/.`,
	)
	process.exit(1)
}

if (distinct[0] !== FLEET_DEXIE) {
	console.error(
		`x dexie singleton: built chunks carry dexie ${distinct[0]} but the fleet ships ${FLEET_DEXIE}. Hermiq's panel loads on the other apps' pages; two versions in one page blank them all.`,
	)
	process.exit(1)
}

console.log(
	`+ dexie singleton: one Dexie version (${distinct[0]}) across ${findings.length} chunk(s), matching the lockfile and the fleet`,
)
