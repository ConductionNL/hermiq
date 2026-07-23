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
const useLocalLib = process.env.USE_LOCAL_LIB === 'false'
	? false
	: (Boolean(process.env.LOCAL_LIB_PATH) && fs.existsSync(localLib))

// Extend the base resolve config (preserves defaults from @nextcloud/webpack-vue-config)
webpackConfig.resolve = webpackConfig.resolve || {}
webpackConfig.resolve.modules = [path.resolve(__dirname, 'node_modules'), 'node_modules']
webpackConfig.resolve.alias = {
	...(webpackConfig.resolve.alias || {}),
	'@': path.resolve(__dirname, 'src'),
	...(useLocalLib ? { '@conduction/nextcloud-vue': localLib } : {}),
	// VUE 3 STRADDLE: route the runtime `vue` import to @vue/compat (MODE 3),
	// so hermiq's un-migrated Vue-2 components keep rendering while the app is
	// incrementally de-compat'd. vue-loader 17 still finds the real compiler via
	// @vue/compiler-sfc. One ABSOLUTE file so the app + aliased lib source share
	// ONE copy (dual copies = two currentRenderingInstance states → CnAppRoot crash).
	vue$: path.resolve(__dirname, 'node_modules/@vue/compat/dist/vue.runtime.esm-bundler.js'),
	'@vue/compat$': path.resolve(__dirname, 'node_modules/@vue/compat/dist/vue.runtime.esm-bundler.js'),
	pinia$: path.resolve(__dirname, 'node_modules/pinia'),
	// Dedupe vue-router to ONE copy (absolute file): the aliased lib worktree ships
	// its own vue-router, and a per-importer resolve gives @nextcloud/vue's
	// RouterLink a different router instance than app.use(router) provided.
	'vue-router$': path.resolve(__dirname, 'node_modules/vue-router/dist/vue-router.mjs'),
	// @nextcloud/vue v9 is ESM-only (exports '.' -> ./dist/index.mjs, no main/module),
	// so a directory alias can't resolve it — point at the explicit entry file.
	'@nextcloud/vue$': path.resolve(__dirname, 'node_modules/@nextcloud/vue/dist/index.mjs'),
	// @nextcloud/dialogs v6 ships its stylesheet at dist/style.css via the exports
	// map; the aliased lib imports '@nextcloud/dialogs/style.css' — resolve it here.
	'@nextcloud/dialogs/style.css$': path.resolve(__dirname, 'node_modules/@nextcloud/dialogs/dist/style.css'),
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
	new webpack.DefinePlugin({ appVersion: JSON.stringify(process.env.npm_package_version) }),
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
webpackConfig.optimization = {
	...(webpackConfig.optimization || {}),
	splitChunks: {
		...(webpackConfig.optimization?.splitChunks || {}),
		chunks: 'all',
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
