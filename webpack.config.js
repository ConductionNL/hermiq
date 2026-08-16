// SPDX-License-Identifier: EUPL-1.2
const path = require('path')
const fs = require('fs')
const webpack = require('webpack')
const webpackConfig = require('@nextcloud/webpack-vue-config')
const { VueLoaderPlugin } = require('vue-loader')
const NodePolyfillPlugin = require('node-polyfill-webpack-plugin')

const buildMode = process.env.NODE_ENV
const isDev = buildMode === 'development'
webpackConfig.devtool = isDev ? 'cheap-source-map' : 'source-map'

webpackConfig.stats = {
	colors: true,
	modules: false,
}

const appId = 'hermiq'

// Each Nextcloud Dashboard widget needs its own webpack entry-point so the
// widget's JS can be attached via `Util::addScript()` from PHP. Add a new
// line here for every widget you create alongside `lib/Dashboard/<Foo>Widget.php`.
webpackConfig.entry = {
	main: {
		import: path.join(__dirname, 'src', 'main.js'),
		filename: appId + '-main.js',
	},
	adminSettings: {
		import: path.join(__dirname, 'src', 'settings.js'),
		filename: appId + '-settings.js',
	},
	// Agent render leaf (agent-object-leaf, ADR-019): the always-loaded bundle
	// attached on every page via `\OCP\Util::addInitScript('hermiq', 'hermiq-agent-leaf')`
	// so `registerIntegration('hermiq-agent', …)` runs wherever an OpenBuild app
	// renders the OpenRegister integration registry.
	'agent-leaf': {
		import: path.join(__dirname, 'src', 'integration-leaf.js'),
		filename: appId + '-agent-leaf.js',
	},
	// AI companion attached to EVERY page (companion-everywhere). The office
	// editors are third-party apps -- onlyoffice, eurooffice, richdocuments --
	// so there is no CnAppRoot of ours to switch the companion on inside. This
	// bundle is the only seam that reaches them.
	companion: {
		import: path.join(__dirname, 'src', 'companion.js'),
		filename: appId + '-companion.js',
	},
}

// Use local source when available (monorepo dev), otherwise fall back to npm package.
// LOCAL_LIB_PATH repoints the alias at another checkout of the library's `src`
// (e.g. the reconciled feat/vue-3 worktree) so a Vue 3 library change can be built
// and validated here before it is published. The default sibling `../nextcloud-vue`
// is the Vue 2 BETA submodule — do NOT use it for this Vue 3 build; set
// LOCAL_LIB_PATH to a Vue 3 source, or USE_LOCAL_LIB=false to consume the npm 2.x.
const localLib = process.env.LOCAL_LIB_PATH
	? path.resolve(process.env.LOCAL_LIB_PATH)
	: path.resolve(__dirname, '../nextcloud-vue/src')
let useLocalLib =
	process.env.USE_LOCAL_LIB === 'false'
		? false
		: Boolean(process.env.LOCAL_LIB_PATH) && fs.existsSync(localLib)

// Requiring LOCAL_LIB_PATH already makes this opt-in — the bare sibling is never
// picked up by accident, which is the failure other apps in the fleet had. What
// was missing is any check on WHICH source the developer pointed at: an explicit
// LOCAL_LIB_PATH was trusted on the strength of being explicit.
//
// So the target must also satisfy this app's declared range, and the check fails
// CLOSED. Note a version-agnostic check would not have helped here: a "is it the
// Vue 3 line?" test passes for a sibling that is Vue 3 and still the wrong
// version — the sibling declares `vue: ^3.5.0` while being 2.0.5 against a
// declared ^2.3.0.
if (useLocalLib) {
	let localVersion = 'unreadable'
	let satisfied = false
	try {
		// eslint-disable-next-line n/no-extraneous-require
		const semver = require('semver')
		const required =
			require('./package.json').dependencies['@conduction/nextcloud-vue']
		localVersion = String(
			JSON.parse(
				fs.readFileSync(
					path.resolve(localLib, '..', 'package.json'),
					'utf8',
				),
			).version || '',
		)
		satisfied = semver.satisfies(localVersion, required, {
			includePrerelease: true,
		})
	} catch (e) {
		satisfied = false
	}

	if (!satisfied) {
		// eslint-disable-next-line no-console
		console.warn(
			`[hermiq] IGNORING LOCAL_LIB_PATH @conduction/nextcloud-vue@${localVersion} — `
				+ "it does not satisfy this app's declared range. Building against the npm dist.",
		)
		useLocalLib = false
	}
}

// `@nextcloud/webpack-vue-config` hardcodes `output.publicPath` to
// `/apps/<appName>/js/` (its webpack.config.js line 31). A custom_apps install is
// served from `/custom_apps/<appName>/js/`, so every runtime-resolved chunk URL
// 404s — and Nextcloud routes a 404 to index.php, so the browser receives HTML
// where it expected JavaScript: "Refused to execute script ... MIME type
// ('text/html')". Any `import()` in this app was therefore unloadable, which is
// why the companion could not be split until now.
//
// `'auto'` makes webpack derive the base from the executing script's own URL at
// runtime, so it is correct under both layouts. pipelinq and openbuild already set
// it for exactly this reason.
webpackConfig.output = {
	...(webpackConfig.output || {}),
	publicPath: 'auto',
}

// Extend the base resolve config (preserves defaults from @nextcloud/webpack-vue-config)
webpackConfig.resolve = webpackConfig.resolve || {}
// NOTE: deliberately NO `resolve.modules = [<app>/node_modules, 'node_modules']`.
// Pinning the app's top-level node_modules first defeats npm's nested resolution,
// so a package that legitimately needs its OWN nested copy of a dependency gets
// the hoisted one instead. Concretely: @nextcloud/dialogs is built against
// @vueuse/core <=12 (it imports `toValue`, removed in v13) and npm nests
// @vueuse/core@11 under it, while @nextcloud/vue needs @vueuse/components@14 and
// its v14-only symbols. Forcing top-level resolution gave the dialogs chunk
// @vueuse/core@14 -> "export 'toValue' was not found". Standard node resolution
// (nested first) lets each consumer get the version it was built against.
webpackConfig.resolve.alias = {
	...(webpackConfig.resolve.alias || {}),
	'@': path.resolve(__dirname, 'src'),
	...(useLocalLib ? { '@conduction/nextcloud-vue': localLib } : {}),
	// PURE VUE 3 — @vue/compat removed. hermiq's source is compat-construct-free
	// (no Vue.observable / $set / $listeners / $children / beforeDestroy / filters),
	// so the compat runtime is unnecessary AND actively harmful when consuming the
	// PUBLISHED @conduction/nextcloud-vue: that dist is pre-compiled against real
	// Vue 3, and pairing pre-compiled Vue-3 components with a compat runtime made
	// CnAppRoot render nothing at all — silently, with zero console errors.
	// One ABSOLUTE file so the app and the library share ONE Vue copy (dual copies
	// = two currentRenderingInstance states → CnAppRoot null crash).
	vue$: path.resolve(
		__dirname,
		'node_modules/vue/dist/vue.runtime.esm-bundler.js',
	),
	pinia$: path.resolve(__dirname, 'node_modules/pinia'),
	// Dedupe vue-router to ONE copy (absolute file): the aliased lib worktree ships
	// its own vue-router, and a per-importer resolve gives @nextcloud/vue's
	// RouterLink a different router instance than app.use(router) provided.
	'vue-router$': path.resolve(
		__dirname,
		'node_modules/vue-router/dist/vue-router.mjs',
	),
	// @nextcloud/vue v9 is ESM-only (exports '.' -> ./dist/index.mjs, no main/module),
	// so a directory alias can't resolve it — point at the explicit entry file.
	'@nextcloud/vue$': path.resolve(
		__dirname,
		'node_modules/@nextcloud/vue/dist/index.mjs',
	),
	// @nextcloud/dialogs v6 ships its stylesheet at dist/style.css via the exports
	// map; the aliased lib imports '@nextcloud/dialogs/style.css' — resolve it here.
	'@nextcloud/dialogs/style.css$': path.resolve(
		__dirname,
		'node_modules/@nextcloud/dialogs/dist/style.css',
	),
	'@nextcloud/dialogs': path.resolve(__dirname, 'node_modules/@nextcloud/dialogs'),
	// Force the lib's transitive @nextcloud/axios import to resolve to
	// the app's installed copy. Without the `$` exact-match suffix,
	// webpack would walk up to the lib's own node_modules and load a
	// second axios instance, breaking shared interceptors / CSRF tokens.
	'@nextcloud/axios$': path.resolve(__dirname, 'node_modules/@nextcloud/axios'),
}

// Add SCSS rule to the existing module rules
webpackConfig.module.rules.push({
	test: /\.scss$/,
	use: ['style-loader', 'css-loader', 'sass-loader'],
})

// PUBLISHED @conduction/nextcloud-vue dist: rollup emits each SFC as three
// modules — `X.vue2.js` (the options object, default-exported), `X.vue3.js`
// (the compiled render function), and a wrapper (`X.vue.js` or `X.vue2.js`,
// naming alternates) whose ONLY job is the side-effectful glue
// `script.render = render; script.__scopeId = ...`. The barrel index.js
// imports that wrapper for side effects and re-exports the render-less
// options module. The lib's package.json `sideEffects` allowlist only covers
// `**/*.vue` — which does NOT glob-match the compiled `*.vue.js` files — so
// webpack tree-shakes the wrapper import away and every Cn component ships
// WITHOUT its render function. Vue 3 then silently renders a comment node for
// the whole component tree (a template-less options component is "missing
// render"; prod runtime emits no warning). Force side-effect evaluation for
// the entire published dist so the render-attach glue survives.
webpackConfig.module.rules.push({
	test: /[\\/]node_modules[\\/]@conduction[\\/]nextcloud-vue[\\/]dist[\\/]/,
	sideEffects: true,
})

// `@nextcloud/vue@9` declares NO `sideEffects` field, so webpack must assume every
// module in it may have side effects and cannot drop a single unused re-export from
// its barrel. Everything importing `{ NcButton } from '@nextcloud/vue'` therefore
// ships the whole library — measured in the companion's chunk: 2,157,453 bytes
// across 281 modules of @nextcloud/vue, for the ten components the AI chat actually
// uses, dragging in @vuepic/vue-datepicker, emoji-mart, @ckpack/vue-color and
// date-fns behind it.
//
// This is scoped to the BARREL FILE ONLY, deliberately. Marking the whole package
// side-effect-free would also license webpack to drop a component module whose
// import exists for its side effect — a stylesheet, a directive registration — and
// that failure is silent: the component still renders, unstyled. `index.mjs` is
// pure re-exports, so dropping unused ones from it is safe, and the component
// modules that survive keep their own side effects.
webpackConfig.module.rules.push({
	test: /[\\/]node_modules[\\/]@nextcloud[\\/]vue[\\/]dist[\\/]index\.mjs$/,
	sideEffects: false,
})

// Replace plugins to avoid duplicate VueLoaderPlugin (base config also registers one).
// CRITICAL: re-add the appName / appVersion DefinePlugin entries — without them
// every @nextcloud/vue widget mount logs `[ERROR] @nextcloud/vue: The library
// was used without setting / replacing the appName`. The base config sets these
// defines, but we lose them when we replace `webpackConfig.plugins` wholesale.
// See ADR-004 (Build / bundling) in hydra/openspec/architecture/.
webpackConfig.plugins = [
	new VueLoaderPlugin(),
	new NodePolyfillPlugin({ additionalAliases: ['process'] }),
	new webpack.DefinePlugin({ appName: JSON.stringify(appId) }),
	new webpack.DefinePlugin({
		appVersion: JSON.stringify(process.env.npm_package_version),
	}),
	// Vue 3 esm-bundler feature flags (tree-shaking hints). __VUE_OPTIONS_API__
	// MUST stay true — the app and @nextcloud/vue are Options-API based, and
	// @vue/compat requires it during the straddle.
	new webpack.DefinePlugin({
		__VUE_OPTIONS_API__: 'true',
		__VUE_PROD_DEVTOOLS__: 'false',
		__VUE_PROD_HYDRATION_MISMATCH_DETAILS__: 'false',
	}),
]

// Share Vue + @nextcloud/vue + pinia + icons + @conduction/nextcloud-vue across
// every entry-point so each widget bundle no longer inlines its own ~3 MB
// framework copy. Stable filenames (no contenthash in the JS name) mean each
// widget's `Util::addScript` PHP call can reference the chunk directly without
// a manifest. The shared chunks load once on the page and stay cached across
// navigations between this app's pages.
//
// Each widget's PHP `load()` MUST attach the shared chunks before the per-widget
// bundle. Order in PHP:
//   1. <appId>-shared-vendor   (Vue, pinia, icons)
//   2. <appId>-shared-nc-vue   (@nextcloud/vue, @conduction/nextcloud-vue)
//   3. <appId>-<widget>Widget  (your widget code)
// `Util::addScript` dedupes by (app, file) so eagerly loading every widget
// still emits each shared chunk exactly once.
//
// EXCEPTION — the `agent-leaf` AND `companion` entries are SELF-CONTAINED. Unlike the widgets, they are
// injected GLOBALLY on every page via `\OCP\Util::addInitScript('hermiq',
// 'hermiq-agent-leaf')` (Application.php), with NO PHP `load()` to attach the
// shared chunks first. If splitChunks hoisted its `registerIntegration` /
// @nextcloud/vue / @conduction/nextcloud-vue / Vue into the shared chunks, the
// built entry would become a DEFERRED bundle doing `__webpack_require__.e(...)`
// for chunks that are never on the page — so the deferred body never runs,
// `registerIntegration('hermiq-agent', …)` never fires, and the Agent tab never
// renders (the failure is silent). We therefore exclude the `agent-leaf` chunk
// from every cacheGroup by making `chunks` a predicate: it inlines its own
// framework copy (~a few hundred KB, correct for a globally-injected script),
// while `main` + `adminSettings` still share the extracted chunks as before.
//
// `companion` is injected the SAME way (addInitScript, no PHP load()) and was
// initially left in the cacheGroups, which put it in exactly the position this
// paragraph warns about. Measured: webpack reported the `companion` ENTRYPOINT
// at 14.1 MiB across three files, of which PHP attaches one — the other two,
// hermiq-shared-nc-vue.js and hermiq-shared-vendor.js, are simply not on a Files
// or office-editor page. The emitted bundle is not a deferred stub (no
// `__webpack_require__.e`), so nothing announces the problem: it just requires
// modules that were hoisted into a chunk nobody loaded.
//
// Worth recording, because it is the trap under the trap: the FIRST measurement
// of this bundle read `ls -l js/hermiq-companion.js` and reported 1.79 MB. That
// is one chunk of a three-chunk entrypoint. The number was off by 8x and looked
// authoritative — measure the ENTRYPOINT, which is what actually has to arrive.
// The globally-injected entries and everything they can reach at runtime.
//
// Testing `chunk.name` alone is not enough, and this was measured. nc-vue calls
// `defineAsyncComponent` internally, so the companion's module graph contains
// async chunks — and an async chunk's `name` is neither 'companion' nor
// 'agent-leaf', so a name test let splitChunks hoist their nc-vue modules straight
// back into `hermiq-shared-nc-vue.js`. The emitted bundle then contained
// `n.e("hermiq-shared-nc-vue")` on paths the FAB and chat panel happen not to
// take: it rendered, it opened, it fetched nothing extra, and it was still one
// interaction away from `ChunkLoadError` against a file no page attaches.
//
// `chunk.runtime` names the ENTRY a chunk belongs to and is carried by async
// chunks too, so it covers the whole reachable graph rather than the entry file.
const SELF_CONTAINED_RUNTIMES = ['agent-leaf', 'companion']

/**
 * May this chunk take part in the shared cacheGroups?
 *
 * @param {object} chunk The webpack chunk.
 * @return {boolean} False for anything reachable from a globally-injected entry.
 */
function isSharedChunkEligible(chunk) {
	if (SELF_CONTAINED_RUNTIMES.includes(chunk.name)) {
		return false
	}

	const runtime = chunk.runtime
	const runtimes = typeof runtime === 'string'
		? [runtime]
		: (runtime ? Array.from(runtime) : [])

	return runtimes.some((r) => SELF_CONTAINED_RUNTIMES.includes(r)) === false
}

webpackConfig.optimization = {
	...(webpackConfig.optimization || {}),
	splitChunks: {
		...(webpackConfig.optimization?.splitChunks || {}),
		chunks: (chunk) => isSharedChunkEligible(chunk),
		cacheGroups: {
			default: false,
			defaultVendors: false,
			ncVue: {
				name: appId + '-shared-nc-vue',
				// Matches both node_modules entries AND the monorepo-dev alias
				// `../nextcloud-vue/src/...` which webpack resolves outside
				// node_modules when @conduction/nextcloud-vue is aliased to it.
				test: /[\\/]node_modules[\\/](@nextcloud[\\/]vue|@conduction[\\/]nextcloud-vue)[\\/]|[\\/]nextcloud-vue[\\/]src[\\/]/,
				priority: 30,
				reuseExistingChunk: true,
				enforce: true,
				filename: appId + '-shared-nc-vue.js',
			},
			vendor: {
				name: appId + '-shared-vendor',
				test: /[\\/]node_modules[\\/](vue|pinia|vue-material-design-icons|@vueuse|core-js)[\\/]/,
				priority: 20,
				reuseExistingChunk: true,
				enforce: true,
				filename: appId + '-shared-vendor.js',
			},
		},
	},
}

module.exports = webpackConfig
