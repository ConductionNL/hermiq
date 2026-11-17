/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Budget guard for the always-loaded AI companion.
 *
 * `hermiq-companion.js` is attached with `\OCP\Util::addInitScript`
 * (Application.php), so it is fetched on EVERY Nextcloud page in the instance —
 * the Files list, another app's settings, a third-party office editor. A hexagonal
 * button and a chat panel must not cost what an application costs.
 *
 * ⚠️ THE BUDGET IS MEASURED ON THE WIRE, NOT ON DISK. Nextcloud serves these
 * gzipped, and the difference is not a rounding error: the panel chunk is 918 KB
 * on disk and 218 KB over the wire. Asserting the disk size would fail a bundle
 * that is comfortably within budget, and — worse in the other direction — would
 * let someone "fix" a red build by shipping something less compressible.
 * Verified against the running instance with
 * `curl -H 'Accept-Encoding: gzip' -w '%{size_download}'`.
 *
 * The other failure modes here, all of them measured and all of them silent:
 *
 *   1. THE EAGER BUNDLE MUST BE SELF-CONTAINED. `addInitScript` attaches exactly
 *      one file and there is no PHP `load()` to attach shared chunks first. With
 *      the `companion` entry left inside the splitChunks cacheGroups, webpack
 *      reported the ENTRYPOINT at 14.1 MiB across three files, of which a page
 *      receives one. The emitted bundle was not a deferred stub, so nothing
 *      announced it — it simply required modules hoisted into a chunk nobody
 *      loaded.
 *
 *   2. THE SPLIT MUST SURVIVE. The panel is a separate chunk only because
 *      `src/companion.js` imports it through `defineAsyncComponent`. Collapsing
 *      that back to a static import re-inlines ~900 KB into the eager file, and
 *      the page still works — it is just four times heavier for everyone.
 *
 *   3. THE `@nextcloud/vue` BARREL MUST STAY SHAKEABLE. That package declares no
 *      `sideEffects` field, so without the rule in webpack.config.js webpack
 *      cannot drop one unused re-export and the panel ships all 281 modules of it
 *      (3.17 MB on disk instead of 918 KB) plus vue-datepicker, emoji-mart and
 *      @ckpack/vue-color behind it.
 *
 * Run after a production build: `npm run build && npm run check:companion-bundle`.
 */

const fs = require('fs')
const path = require('path')
const zlib = require('zlib')

const JS_DIR = path.join(__dirname, '..', 'js')
const CSS_DIR = path.join(__dirname, '..', 'css')
const EAGER = 'hermiq-companion.js'
const PANEL = 'hermiq-companion-panel.js'
const STYLESHEET = 'companion.css'

// The fleet budget for an always-loaded widget. Measured at the time of writing:
// 33 KB eager + 218 KB panel = 251 KB over the wire.
//
// The panel counts against it because `defineAsyncComponent` resolves as soon as
// the component renders — it is off the critical path, not off the page. Moving it
// behind the FAB click needs a change in @conduction/nextcloud-vue itself
// (CnAiCompanion instantiates CnAiChatPanel unconditionally, passing `:visible`
// rather than guarding it with `v-if`), which would drop the per-page cost to the
// eager 33 KB alone.
//
// 🔴 THE STYLESHEET COUNTS TOO, and it is measured here for a reason. It used to be
// INSIDE the eager bundle: `src/companion.js` imported nextcloud-vue.css and
// style-loader inlined all 801 KB of it as a JavaScript string, which is how the
// eager half reached 356 KB and the total reached 531 KB against this 400 KB budget.
// It is now a real file that `companion.js` requests at mount time.
//
// If this check measured only the two JS files it would now report 208 KB and call
// that a 323 KB saving. It is not: 142 KB of it moved to a file the browser still
// fetches. Counting it keeps the number honest. The real wins are the ones a byte
// count cannot show — the stylesheet is cached across pages instead of re-parsed as
// JavaScript on every one, and a page that gets no companion (the login screen, a
// public share, a framed document, Hermiq's own pages) now fetches none of it.
const WIRE_BUDGET = 400 * 1024

// The eager half is the hex button and its glue. This is the number that caught the
// regression: the original design measured 33 KB here, and an inlined stylesheet took
// it to 356 KB while every other assertion in this file still passed.
const EAGER_BUDGET = 100 * 1024

let failed = 0

/**
 * Report one assertion.
 *
 * @param {boolean} ok Whether the assertion held.
 * @param {string} message What was being asserted.
 * @return {void}
 */
function assert(ok, message) {
	if (ok) {
		console.log('  PASS  ' + message)
		return
	}

	console.error('  FAIL  ' + message)
	failed++
}

/**
 * Gzipped size of an emitted asset, which is what the browser actually fetches.
 *
 * @param {string} name File name inside js/.
 * @return {number} Size in bytes after gzip.
 */
function wireSize(file) {
	return zlib.gzipSync(fs.readFileSync(file), { level: 9 }).length
}

const EAGER_PATH = path.join(JS_DIR, EAGER)
const PANEL_PATH = path.join(JS_DIR, PANEL)
const STYLESHEET_PATH = path.join(CSS_DIR, STYLESHEET)

for (const f of [EAGER_PATH, PANEL_PATH]) {
	if (!fs.existsSync(f)) {
		console.error(`${path.basename(f)} not found — run \`npm run build\` first.`)
		process.exit(1)
	}
}

// Absence is a FAILURE, not a skip. `companion.js` requests this file by name at
// mount time, so a build that did not emit it ships a companion whose rules 404 —
// a 0x0 statically-positioned button that renders, reports no error, and cannot be
// seen. Treating a missing file as "nothing to measure" would pass that build.
if (!fs.existsSync(STYLESHEET_PATH)) {
	console.error(
		`css/${STYLESHEET} not found — run \`npm run companion-css:build\`.`,
	)
	console.error(
		'companion.js requests this file at mount time; without it the companion renders unstyled.',
	)
	process.exit(1)
}

const eagerWire = wireSize(EAGER_PATH)
const panelWire = wireSize(PANEL_PATH)
const cssWire = wireSize(STYLESHEET_PATH)
const totalWire = eagerWire + panelWire + cssWire
const kb = (n) => (n / 1024).toFixed(1) + ' KB'

console.log(
	`eager  ${EAGER}: ${kb(eagerWire)} gzipped (${fs.statSync(EAGER_PATH).size.toLocaleString()} raw)`,
)
console.log(
	`panel  ${PANEL}: ${kb(panelWire)} gzipped (${fs.statSync(PANEL_PATH).size.toLocaleString()} raw)`,
)
console.log(
	`style  ${STYLESHEET}: ${kb(cssWire)} gzipped (${fs.statSync(STYLESHEET_PATH).size.toLocaleString()} raw)`,
)
console.log(`total per page load: ${kb(totalWire)} (budget ${kb(WIRE_BUDGET)})`)
console.log(`  of which fetched ONLY where the companion mounts: ${kb(cssWire)}\n`)

assert(
	totalWire <= WIRE_BUDGET,
	`per-page wire cost is within budget (${kb(totalWire)} <= ${kb(WIRE_BUDGET)})`,
)

// The panel must remain a SEPARATE chunk. If it collapses back into the eager
// file the total above can still pass, so assert the split itself.
assert(
	fs.existsSync(path.join(JS_DIR, PANEL)),
	'the chat panel is still split into its own chunk (defineAsyncComponent intact)',
)

assert(
	eagerWire <= EAGER_BUDGET,
	`the eager bundle is the hex and its glue, not the panel (${kb(eagerWire)} <= ${kb(EAGER_BUDGET)})`,
)

const eagerSource = fs.readFileSync(EAGER_PATH, 'utf8')

// 🔴 THE STYLESHEET MUST NOT BE BACK INSIDE THE SCRIPT. Re-adding
// `import '@conduction/nextcloud-vue/dist/nextcloud-vue.css'` to src/companion.js
// looks harmless and silently inlines 801 KB into a file every page fetches. The
// size assertion above would catch it today, but only while the eager budget stays
// far below the inlined size — so name the actual mistake as well as its symptom.
//
// `.cn-ai-companion{` is a rule that exists only in the library stylesheet, so
// finding it inside the JavaScript means the CSS is in there.
assert(
	eagerSource.includes('.cn-ai-companion{') === false,
	'the component library stylesheet is NOT inlined into the always-loaded script',
)

// A self-contained entry never expects a chunk the page was not given. Async
// chunks it fetches ITSELF are fine — publicPath is 'auto', so they resolve.
assert(
	eagerSource.includes('hermiq-shared-nc-vue') === false
		&& eagerSource.includes('hermiq-shared-vendor') === false,
	'eager bundle does not reference hermiq-shared-nc-vue / hermiq-shared-vendor',
)

// Guards failure mode 3: if the barrel rule is dropped, the panel roughly triples.
assert(
	panelWire <= 300 * 1024,
	`the @nextcloud/vue barrel is still being tree-shaken (panel ${kb(panelWire)} <= 300.0 KB)`,
)

if (failed > 0) {
	console.error(`\n${failed} companion-bundle check(s) failed.`)
	process.exit(1)
}

console.log('\nAll companion-bundle checks passed.')
