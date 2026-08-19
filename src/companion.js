/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Hermiq AI companion, mounted on EVERY Nextcloud page.
 *
 * The chat lived only inside Hermiq's own Vue app, where `CnAppRoot` renders it
 * from `:aiCompanion="true"`. That put the assistant one navigation away from the
 * document a user was actually looking at: to ask about the file open in the
 * editor, they had to leave the editor.
 *
 * The office editors are THIRD-PARTY apps — `onlyoffice`, `eurooffice`,
 * `richdocuments` — so there is no `CnAppRoot` to switch on and no template of ours
 * to edit. A standalone bundle attached with `\OCP\Util::addInitScript` is the only
 * seam that reaches them, and it is the same pattern this app already uses for the
 * agent render leaf.
 *
 * Deliberately NOT mounted on Hermiq's own pages: `CnAppRoot` already renders a
 * companion there, and two would fight over the same corner of the screen.
 *
 * @spec openspec/changes/companion-everywhere/specs/companion-everywhere/spec.md
 */

// The CnAiCompanion import below is DEEP, not
// `import { CnAiCompanion } from '@conduction/nextcloud-vue'`, and it is
// deliberately STATIC. Both are load-bearing, and neither survives being
// "tidied up", so the reasoning lives here rather than in a commit message.
//
// DEEP, because this bundle loads on EVERY Nextcloud page and its size is the
// whole design constraint. The barrel pulls the entire component library, and not
// by accident: webpack.config.js forces `sideEffects: true` across the published
// nc-vue dist — it has to, because the compiled SFC wrapper attaches the render
// function as a side effect, and tree-shaking that away renders every component
// as an empty comment node, silently. With side effects on, nothing in an
// imported module graph can be dropped, so naming one export off the barrel costs
// all of them. Measured on the emitted entrypoint: barrel + shared chunks =
// 14.1 MiB across three files, of which PHP attaches one. Deep + self-contained
// (see the splitChunks predicate in webpack.config.js) = 3.1 MiB in ONE file.
//
// ⚠️ STATIC, because `defineAsyncComponent(() => import(...))` was tried and
// measured: it cuts the eager bundle to 89 KB and then fails at runtime, twice
// over. The splitChunks predicate has to exclude the async chunk by RUNTIME
// rather than name, or the panel's nc-vue modules are hoisted straight back into
// `hermiq-shared-nc-vue.js` — the chunk that is not on a Files page, which is the
// entire problem this file exists to avoid. And webpack's `publicPath` resolves
// to `/apps/hermiq/js/` while the app is served from `/custom_apps/hermiq/js/`,
// so every async request 404s to Nextcloud's HTML error page ("Refused to execute
// script ... MIME type ('text/html')"). That one has a known remedy this app is
// missing — `output.publicPath = 'auto'`, which @nextcloud/webpack-vue-config
// hardcodes wrongly for a custom_apps install, and which pipelinq and openbuild
// already set. Setting it changes chunk resolution for `main` and `adminSettings`
// too, so it needs those pages re-verified; it is not part of this fix.
import { createApp, defineAsyncComponent, h } from 'vue'

// 🔴 The component library's compiled stylesheet. Without it `.cn-ai-companion`
// has NO rules at all: the element measures 0x0 with `position: static`, and the
// panel inside it falls back to NcAppSidebar's bare default — docked to the left
// edge at full height instead of the floating bottom-right companion. It still
// opened, still worked, still passed every DOM assertion; it just looked like a
// different, older component.
//
// `css/index.css` (which main.js imports) does NOT carry these rules — the
// cn-ai-* styles live only in the compiled bundle, so importing the wrong one
// looks like a fix and changes nothing.
//
// Checked before shipping it to EVERY page in the instance: the file has zero
// bare element selectors (no `body`, `a`, `table`, `button` rules), so it cannot
// restyle a host app.
import '@conduction/nextcloud-vue/dist/nextcloud-vue.css'

const CnAiCompanion = defineAsyncComponent(
	() =>
		import(
			/* webpackChunkName: "companion-panel" */
			'@conduction/nextcloud-vue/dist/esm/components/CnAiCompanion/CnAiCompanion.vue.js'
		),
)

/**
 * The Hermiq app's own pages already render a companion via CnAppRoot.
 *
 * Detected from the URL. An earlier version of this file read
 * `document.body.classList.contains('app-hermiq')` on the reasoning that "the body
 * class is what Nextcloud itself considers the current app" — measured on
 * Nextcloud 34, `document.body.className` is the EMPTY STRING on every page, and
 * nothing else in the DOM names the active app either (no `OC.appName`, no
 * `#content` class, no meta tag). The check could never fire, so the companion
 * would have mounted a second time on top of the one CnAppRoot already renders.
 *
 * The regex covers what the body-class approach was reaching for: `/apps/hermiq`
 * and `/index.php/apps/hermiq`, the bare app root and any sub-path, and it will
 * not match a longer app id that merely starts with `hermiq`.
 *
 * @return {boolean} True when this page is one of Hermiq's own.
 */
function hermiqOwnsThisPage() {
	return /(^|\/)apps\/hermiq(\/|$)/.test(window.location.pathname)
}

/**
 * Whether a Nextcloud user is signed in on this page.
 *
 * 🔴 The login page is a Nextcloud page like any other, so without this the
 * companion mounted there: a floating assistant button in front of the login
 * form, a 1.9 MB bundle downloaded before anyone has authenticated, and two
 * calls to `/api/conversations` and `/api/agents` that both answered 401.
 *
 * ⚠️ Reads `head[data-user]` DIRECTLY rather than calling `getCurrentUser()`
 * from `@nextcloud/auth`. The helper was tried first and returned null on a
 * signed-in Files page — it resolves once, at module-evaluation time, and this
 * script is an init script that runs before the value it caches is readable.
 * The result was a guard that suppressed the companion EVERYWHERE, which is a
 * worse bug than the one it fixes and is invisible unless you check the
 * positive case. Measured on a live page: `head[data-user]` is `"admin"` while
 * `getCurrentUser()` is null.
 *
 * Checked rather than inferred from the URL, because `/login` is not the only
 * unauthenticated surface — public shares, the setup wizard and error pages all
 * reach this script.
 *
 * @return {boolean} True when a user is signed in.
 */
function hasSession() {
	// `OC.currentUser` carries the same uid and was considered as a second
	// reading, but it is deprecated since Nextcloud 19 and the lint rule says
	// so; one honest source beats a fallback the project has already retired.
	return (document.head?.getAttribute('data-user') ?? '') !== ''
}

/**
 * A file-viewing page can tell the companion which document is open.
 *
 * Returning null is normal and means "no document context", not an error — most
 * pages are not showing a file.
 *
 * @return {number|null} The open file's id, or null.
 */
function openFileId() {
	const fromQuery = new URLSearchParams(window.location.search).get('fileId')
	if (fromQuery !== null && /^\d+$/.test(fromQuery) === true) {
		return Number(fromQuery)
	}

	// `/apps/onlyoffice/24753` and `/apps/eurooffice/24753` carry it as the last
	// path segment; `richdocuments` uses the query string handled above.
	const segments = window.location.pathname.split('/').filter((s) => s !== '')
	const last = segments[segments.length - 1]

	return /^\d+$/.test(last ?? '') === true ? Number(last) : null
}

/**
 * The Nextcloud app whose page this is, from the URL.
 *
 * There is no DOM signal for it on Nextcloud 34 — no `OC.appName`, no body
 * class, no meta tag (all three were checked). The path is what remains, and it
 * is reliable: every app page is served under `/apps/<id>/`.
 *
 * @return {string} The app id, or 'unknown' when the path names none.
 */
function currentAppId() {
	const match = window.location.pathname.match(/(?:^|\/)apps\/([^/]+)/)

	return match ? match[1] : 'unknown'
}

/**
 * Mount the companion into its own container appended to the body.
 *
 * A dedicated container rather than an existing element: the host page belongs to
 * another app, and writing into its DOM is how one app breaks another's layout.
 *
 * `createApp`, not `new Vue` — this app is pure Vue 3 (webpack aliases `vue$` to
 * `vue.runtime.esm-bundler.js`). The Vue 2 constructor form threw
 * `TypeError: r.default is not a constructor` on first load, which is also the
 * proof that this bundle had never been run in a browser before.
 *
 * @return {void}
 */
function mount() {
	if (hermiqOwnsThisPage() === true) {
		return
	}

	// Nobody signed in — see hasSession(). The companion is a per-user
	// assistant, so there is nothing for it to be on an anonymous page.
	if (hasSession() === false) {
		return
	}

	// 🔴 Never inside a frame. The Files app opens a document by embedding a
	// FULL Nextcloud page, same-origin, inside itself, so this script runs
	// again in the inner document — and the library's singleton guard is
	// `window`-scoped, which a frame boundary defeats by construction: the
	// inner document has its own `window`, so it cannot see that the outer page
	// already has a companion.
	//
	// The inner mount is invisible (the frame clips it), which is exactly why it
	// survived: it costs a health probe and a second copy of the panel's state
	// while showing nothing. Found by the e2e assertion that no
	// `#hermiq-companion-root` exists inside the frame.
	if (window.self !== window.top) {
		return
	}

	if (document.getElementById('hermiq-companion-root') !== null) {
		return
	}

	const root = document.createElement('div')
	root.id = 'hermiq-companion-root'
	// 🔴 ABOVE Nextcloud's header, which sits at z-index 2000. The panel's close
	// button lands at y 8-42 — inside the 50px header — and with the root at
	// `z-index: auto` the header won that overlap: `elementFromPoint` at the
	// button's own centre returned `.header-start`, so the panel could be opened
	// and never closed. It looked correct in the DOM the whole time: present,
	// visible, correctly sized. Only a real click found it.
	//
	// 2001, not something larger: a genuine modal (Nextcloud uses far higher)
	// must still win over an assistant panel.
	root.style.position = 'relative'
	root.style.zIndex = '2001'

	document.body.appendChild(root)

	const fileId = openFileId()

	createApp({
		render: () =>
			h(CnAiCompanion, {
				chatAppId: 'hermiq',
				position: 'bottom-right',
				// WHAT THE USER IS LOOKING AT — without this the agent is blind.
				//
				// nc-vue resolves the page context by injection from a CnAppRoot
				// ancestor. Mounted standalone on a page belonging to another app
				// there is no such ancestor, so it falls back to a default whose
				// `appId` is the literal string 'unknown'. Measured: asked to change
				// a word in the document on screen, the agent replied "I don't have
				// a clear app context (it's showing as unknown)" and refused —
				// correctly, since it genuinely could not tell what "this document"
				// meant.
				//
				// This mount is the one place that DOES know, so it says so.
				context: {
					appId: currentAppId(),
					pageKind: fileId !== null ? 'file' : 'custom',
					route: { path: window.location.pathname },
					...(fileId !== null ? { fileId } : {}),
				},
			}),
	}).mount(root)
}

if (document.readyState === 'loading') {
	document.addEventListener('DOMContentLoaded', mount)
} else {
	mount()
}
