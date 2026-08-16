/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Guards the shape and size of the always-loaded companion bundle.
 *
 * `hermiq-companion.js` is attached with `\OCP\Util::addInitScript` (Application.php),
 * so it is parsed on EVERY Nextcloud page in the instance — the Files list, another
 * app's settings, a third-party office editor. Nothing about that cost is visible
 * when reading `src/companion.js`, and every way of getting it wrong is silent.
 *
 * The three failure modes this file exists to catch, all of them measured:
 *
 *   1. THE BUNDLE MUST BE SELF-CONTAINED. `addInitScript` attaches exactly one
 *      file; there is no PHP `load()` to attach shared chunks first. When the
 *      `companion` entry was left inside the splitChunks cacheGroups, webpack
 *      reported the ENTRYPOINT at 14.1 MiB across three files, of which the page
 *      received one — and the emitted bundle was not a deferred stub, so nothing
 *      announced it. It simply required modules hoisted into a chunk nobody
 *      loaded.
 *
 *   2. THE IMPORT MUST STAY DEEP. `import { CnAiCompanion } from
 *      '@conduction/nextcloud-vue'` pulls the whole component library, because
 *      webpack.config.js forces `sideEffects: true` over the published dist (it
 *      must — the SFC wrapper attaches the render function as a side effect, and
 *      dropping it renders every component as an empty comment node).
 *
 *   3. AN ASYNC BOUNDARY IS NOT A FREE WIN. `defineAsyncComponent` cut the eager
 *      bundle to 89 KB and then failed at runtime twice over: the splitChunks
 *      predicate keys on `chunk.name` and so does not exclude the async chunk,
 *      which put nc-vue back into `hermiq-shared-nc-vue.js`; and webpack's
 *      publicPath is `/apps/hermiq/js/` while the app is served from
 *      `/custom_apps/hermiq/js/`, so the request 404s to Nextcloud's HTML error
 *      page. Both must be fixed before a lazy panel is possible.
 *
 * Run after a production build.
 */

const fs = require('fs')
const path = require('path')

const JS_DIR = path.join(__dirname, '..', 'js')
const BUNDLE = path.join(JS_DIR, 'hermiq-companion.js')

// Measured 3,254,643 bytes at the time of writing. The ceiling is deliberately
// close: this is a per-page cost, and the point is that a change which inflates
// it has to be noticed and argued for, not that some round number is safe.
//
// It is NOT a target. 3.1 MiB on every page is still a lot, and the honest reason
// it is tolerated is that `hermiq-agent-leaf.js` already ships 10.3 MiB the same
// way. Lowering both is real work that failure mode 3 above describes.
const MAX_BYTES = 3_400_000

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

if (!fs.existsSync(BUNDLE)) {
	console.error('hermiq-companion.js not found — run `npm run build` first.')
	process.exit(1)
}

const bytes = fs.statSync(BUNDLE).size
const source = fs.readFileSync(BUNDLE, 'utf8')

console.log(`companion bundle: ${bytes.toLocaleString()} bytes (ceiling ${MAX_BYTES.toLocaleString()})`)

assert(
	bytes <= MAX_BYTES,
	`eager bundle is within the ceiling (${bytes.toLocaleString()} <= ${MAX_BYTES.toLocaleString()})`,
)

// A self-contained bundle never asks for another chunk. `__webpack_require__.e`
// is webpack's chunk loader; its presence means this bundle defers to a file the
// page has not been given.
assert(
	/__webpack_require__\.e\(/.test(source) === false
		&& /\.e\(["'](hermiq-shared|hermiq-hermiq)/.test(source) === false,
	'bundle loads no additional chunks (nothing attaches them on a foreign page)',
)

// The shared chunks are the specific ones that are not on a foreign page.
assert(
	source.includes('hermiq-shared-nc-vue') === false
		&& source.includes('hermiq-shared-vendor') === false,
	'bundle does not reference hermiq-shared-nc-vue / hermiq-shared-vendor',
)

// Nothing else may be emitted for this entry: one file is what PHP attaches.
const companionAssets = fs
	.readdirSync(JS_DIR)
	.filter((f) => f.startsWith('hermiq-companion') || f.includes('companion-panel'))
	.filter((f) => f.endsWith('.js'))

assert(
	companionAssets.length === 1 && companionAssets[0] === 'hermiq-companion.js',
	`exactly one companion JS asset is emitted (found: ${companionAssets.join(', ') || 'none'})`,
)

if (failed > 0) {
	console.error(`\n${failed} companion-bundle check(s) failed.`)
	process.exit(1)
}

console.log('\nAll companion-bundle checks passed.')
