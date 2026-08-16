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
const EAGER = 'hermiq-companion.js'
const PANEL = 'hermiq-companion-panel.js'

// The fleet budget for an always-loaded widget. Measured at the time of writing:
// 33 KB eager + 218 KB panel = 251 KB over the wire.
//
// The panel counts against it because `defineAsyncComponent` resolves as soon as
// the component renders — it is off the critical path, not off the page. Moving it
// behind the FAB click needs a change in @conduction/nextcloud-vue itself
// (CnAiCompanion instantiates CnAiChatPanel unconditionally, passing `:visible`
// rather than guarding it with `v-if`), which would drop the per-page cost to the
// eager 33 KB alone.
const WIRE_BUDGET = 400 * 1024

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
function wireSize(name) {
	return zlib.gzipSync(fs.readFileSync(path.join(JS_DIR, name)), { level: 9 }).length
}

for (const f of [EAGER, PANEL]) {
	if (!fs.existsSync(path.join(JS_DIR, f))) {
		console.error(`${f} not found — run \`npm run build\` first.`)
		process.exit(1)
	}
}

const eagerWire = wireSize(EAGER)
const panelWire = wireSize(PANEL)
const totalWire = eagerWire + panelWire
const kb = (n) => (n / 1024).toFixed(1) + ' KB'

console.log(`eager  ${EAGER}: ${kb(eagerWire)} gzipped (${fs.statSync(path.join(JS_DIR, EAGER)).size.toLocaleString()} raw)`)
console.log(`panel  ${PANEL}: ${kb(panelWire)} gzipped (${fs.statSync(path.join(JS_DIR, PANEL)).size.toLocaleString()} raw)`)
console.log(`total per page load: ${kb(totalWire)} (budget ${kb(WIRE_BUDGET)})\n`)

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
	eagerWire <= 100 * 1024,
	`the eager bundle is the hex and its glue, not the panel (${kb(eagerWire)} <= 100.0 KB)`,
)

const eagerSource = fs.readFileSync(path.join(JS_DIR, EAGER), 'utf8')

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
