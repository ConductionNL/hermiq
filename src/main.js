// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

import {
	CnPageRenderer,
	defaultPageTypes,
	registerBuiltinDashboardWidgets,
	registerIcons,
	registerTranslations,
} from '@conduction/nextcloud-vue'
import {
	loadTranslations,
	translatePlural as n,
	register,
	translate as t,
} from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { createApp } from 'vue'
import { createRouter, createWebHistory } from 'vue-router'
import App from './App.vue'
import enTranslations from '../l10n/en.json'
import customComponents from './customComponents.js'
import appIcons from './icons.js'
import bundledManifest from './manifest.json'
import pinia from './pinia.js'
// v2 five-kind registry — the replacement for customComponents.
// Both props coexist during the v1 → v2 transition.
// Once fully migrated to v2, remove the customComponents import and prop.
import registry from './registry.js'

// Must stay first: sets __webpack_public_path__ before any dynamic import()
// (the CnIconPicker MDI catalogue, the toast-ui markdown editor) triggers
// lazy-chunk loading from the wrong path.
import './publicPath.js'
// Library CSS — must be explicit import (webpack tree-shakes side-effect imports from aliased packages)
import '@conduction/nextcloud-vue/css/index.css'
// NOTE: gridstack's stylesheet is deliberately NOT imported here. The library owns it:
// @conduction/nextcloud-vue's src/css/index.css @imports 'gridstack/dist/gridstack.min.css'
// so postcss-import inlines it into the sheet the line above already pulls in. An app-level
// import would duplicate those rules in every consumer and rot when the library changes its
// internals. NOTE: this requires a @conduction/nextcloud-vue release that CONTAINS that
// @import — the installed 2.1.0-vue3.9 predates it, so until the bump the rules are supplied
// by a local patch to node_modules (which `npm ci` reverts).
// Global (unscoped) app styles
import './assets/app.css'

// Vue 3 (ADR-066): global t/n install via app.config.globalProperties after
// createApp (below); pinia + router install via app.use. @vue/compat has been
// REMOVED — hermiq's source carries no Vue-2 constructs, and the compat runtime
// breaks the published (pre-compiled Vue-3) @conduction/nextcloud-vue dist.

// Register library-side icon set + lib translations once at bootstrap.
registerIcons(appIcons)
// Populate the shared dashboard widget catalog. The library's widgets
// self-register as an import side effect, but webpack drops a bare side-effect
// import from a package whose exports it can tree-shake — so without this
// explicit no-op call EVERY registry widget type (stat, gauge, chart,
// flow-runs, …) resolves to nothing and its tile renders "Widget not
// available". Silent: no console error, and a custom widget in the same grid
// still renders, so the dashboard looks half-built rather than mis-wired.
registerBuiltinDashboardWidgets()
try {
	registerTranslations()
} catch (e) {
	// Non-fatal — lib translations fall back to English source.
	// eslint-disable-next-line no-console
	console.warn('[hermiq] registerTranslations failed; falling back to English', e)
}

// Register English translations from the bundled en.json. loadTranslations()
// short-circuits for the 'en' locale (it assumes the key IS the English text),
// but this template uses slugged keys like 'app-availability.title', so we must
// register en.json explicitly to get readable strings instead of raw slugs.
register('hermiq', enTranslations.translations)

// Fire-and-forget translation load. Some Nextcloud installs (including
// standard dev containers) only allow the JS/CSS allowlist through
// Apache and rewrite everything else to index.php — there's no route
// for /custom_apps/<app>/l10n/<locale>.json so the request 404s.
// `loadTranslations` rejects on 404, so wrapping the Vue mount inside
// its callback would silently fail boot when translations can't load.
// Strings just fall back to their English source on miss; boot MUST
// not depend on this resolving.
/**
 *
 */
function tryLoadTranslations() {
	try {
		const result = loadTranslations('hermiq', () => {})
		if (result && typeof result.then === 'function') {
			result.then(
				() => {},
				() => {},
			)
		}
	} catch {
		// no-op
	}
}

// Shallow-clone CnPageRenderer because the lib's barrel exports are
// non-extensible (webpack ESM module records). Vue 2's `Vue.extend()`
// adds an internal `_Ctor` cache to the component definition; mutating
// a non-extensible export throws "Cannot add property _Ctor, object is
// not extensible". Cloning gives Vue Router an extensible
// component-options object without altering the lib's internals.
const RoutePageRenderer = { ...CnPageRenderer }

/**
 * Build the vue-router config from the manifest. Each manifest page becomes
 * one route; the route's `name` IS `page.id` (per the lib's manifest contract).
 * Routes whose path declares a `:` parameter receive `props: true` so the
 * built-in detail / index components can read the route param without each
 * consumer wiring it manually.
 *
 * @param {object} manifest The bundled manifest (with `pages[]`).
 * @return {Array<object>} vue-router 3 routes config.
 */
function routesFromManifest(manifest) {
	const routes = manifest.pages.map((page) => ({
		name: page.id,
		path: page.route,
		component: RoutePageRenderer,
		props: page.route.includes(':'),
	}))
	// Catch-all: redirect unknown paths to the first page (the dashboard).
	// vue-router 4 replaces the `*` wildcard with a named param matcher.
	routes.push({ path: '/:pathMatch(.*)*', redirect: '/' })
	return routes
}

/**
 * The router base for THIS page load.
 *
 * ⚠️ `generateUrl('/apps/hermiq')` alone is not enough. Nextcloud serves the
 * app under BOTH `/apps/hermiq/...` and `/index.php/apps/hermiq/...`, but
 * `generateUrl()` returns only the form the instance is configured for. A
 * visitor arriving on the other form — a bookmark, an emailed deep link, an
 * integration that hardcodes `/index.php` — has a pathname the router cannot
 * strip its base from. No route matches, the catch-all takes over, and they
 * land on the dashboard with no error at all: the deep link is silently
 * swallowed.
 *
 * Measured on a live instance for learniq, across all 282 of its routes:
 * `/apps/learniq/courses` resolved to Courses, `/index.php/apps/learniq/courses`
 * resolved to the dashboard. Every route behaved the same way, so this is not
 * one broken page but every deep link in that URL form.
 *
 * Deriving the base from the pathname makes both forms resolve, because the
 * base then always matches the URL the visitor actually arrived on.
 *
 * @return {string} The base path vue-router should strip from the URL.
 */
function routerBase() {
	const match = window.location.pathname.match(/^(.*\/apps\/hermiq)(?:\/|$)/)
	return match ? match[1] : generateUrl('/apps/hermiq')
}

const router = createRouter({
	history: createWebHistory(routerBase()),
	routes: routesFromManifest(bundledManifest),
})

tryLoadTranslations()

// Pass shallow copies of the registry maps to App.vue. The lib exports
// `defaultPageTypes` (and consumers' `customComponents`) as frozen
// module objects in some bundle shapes — Vue 2's `Vue.extend()` mutates
// component definitions to attach an internal `_Ctor` cache, which
// throws "Cannot add property _Ctor, object is not extensible" against
// a frozen source map. Cloning here yields extensible objects without
// changing the values the lib resolves at render time.
const pageTypesProp = { ...defaultPageTypes }
const customComponentsProp = { ...customComponents }
// Shallow-clone the v2 registry for the same reason as above.
// Once the app fully migrates to v2, the customComponentsProp and
// customComponents prop can be removed.
const registryProp = { ...registry }

const app = createApp(App, {
	manifest: bundledManifest,
	customComponents: customComponentsProp,
	pageTypes: pageTypesProp,
	registry: registryProp,
})
// Vue 3: global helpers replace Vue.mixin({ methods: { t, n } }).
app.config.globalProperties.t = t
app.config.globalProperties.n = n
app.use(pinia)
app.use(router)
app.mount('#content')
