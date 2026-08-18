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

import { createApp, defineAsyncComponent, h } from 'vue'

// 🔴 The component library's compiled stylesheet. Without it `.cn-ai-companion`
// has NO rules at all: the element measures 0x0 with `position: static`, and
// the panel inside it falls back to NcAppSidebar's bare default — docked to the
// left edge at full height instead of the floating bottom-right companion. It
// still opened, still worked, still passed every DOM assertion; it just looked
// like a different, older component.
//
// `css/index.css` (which main.js imports) does NOT carry these rules — the
// cn-ai-* styles live only in the compiled bundle, so importing the wrong one
// looks like a fix and changes nothing.
//
// Checked before shipping it to EVERY page in the instance: the file has zero
// bare element selectors (no `body`, `a`, `table`, `button` rules), so it
// cannot restyle a host app.
import '@conduction/nextcloud-vue/dist/nextcloud-vue.css'

// TWO deliberate choices here, both of which exist because this bundle loads on
// EVERY Nextcloud page in the instance.
//
// 1. A DEEP import, not `import { CnAiCompanion } from '@conduction/nextcloud-vue'`.
//    The barrel pulls the entire component library, and not by accident:
//    webpack.config.js forces `sideEffects: true` across the whole published
//    nc-vue dist (it has to — the compiled SFC wrapper attaches the render
//    function as a side effect, and tree-shaking it away renders every component
//    as an empty comment node, silently). With side effects on, nothing in an
//    imported module graph can be dropped, so naming one export off the barrel
//    costs all of them. Reaching past the barrel keeps the graph to what this
//    component actually needs.
//
// 2. An ASYNC component, so the companion's own code is a separate chunk fetched
//    after the page is interactive rather than parsed inline on every page load.
//    The eager cost is Vue plus this file.
//
// Both are load-bearing and neither is obvious from reading the diff, which is
// why the build guard in package.json asserts the emitted size: a re-barrelled
// import or a lost async boundary shows up only as a fatter bundle, and a fat
// bundle on every page is exactly the failure this file is shaped to avoid.
const CnAiCompanion = defineAsyncComponent(
	() =>
		import(
			/* webpackChunkName: "hermiq-companion-panel" */
			'@conduction/nextcloud-vue/dist/esm/components/CnAiCompanion/CnAiCompanion.vue.js'
		),
)

/**
 * The Hermiq app's own pages already render a companion via CnAppRoot.
 *
 * 🔴 Checked from the URL **and** the body class, because the body class alone
 * was wrong. The original version tested only `app-hermiq`, on the reasoning
 * that it is "what Nextcloud itself considers the current app" — but on this
 * instance Hermiq's own pages carry an EMPTY body class, so the guard never
 * fired and a second companion mounted on top of CnAppRoot's. The two launchers
 * landed at the identical coordinates, which is why it looked like one button
 * and not like a bug.
 *
 * The URL objection in the original comment was that `/index.php/apps/hermiq/…`
 * and a sub-path install would be missed — both are handled by testing for the
 * segment anywhere in the path rather than anchoring at the start.
 *
 * @return {boolean} True when this page is one of Hermiq's own.
 */
function hermiqOwnsThisPage() {
	if (document.body.classList.contains('app-hermiq') === true) {
		return true
	}

	return window.location.pathname.includes('/apps/hermiq')
}

/**
 * A file-viewing page can tell the companion which document is open.
 *
 * Read from the query string because that is what every office connector uses:
 * `/apps/onlyoffice/{fileId}` puts it in the path, the others use `?fileId=`.
 * Returning null is normal and means "no document context", not an error.
 *
 * @return {number|null} The open file's id, or null.
 */
function openFileId() {
	const fromQuery = new URLSearchParams(window.location.search).get('fileId')
	if (fromQuery !== null && /^\d+$/.test(fromQuery) === true) {
		return Number(fromQuery)
	}

	// `/apps/onlyoffice/24753` and `/apps/eurooffice/24753` carry it as the last
	// path segment.
	const segments = window.location.pathname.split('/').filter((s) => s !== '')
	const last = segments[segments.length - 1]

	return /^\d+$/.test(last ?? '') === true ? Number(last) : null
}

/**
 * Mount the companion into its own container appended to the body.
 *
 * A dedicated container rather than an existing element: the host page belongs to
 * another app, and writing into its DOM is how one app breaks another's layout.
 *
 * @return {void}
 */
function mount() {
	if (hermiqOwnsThisPage() === true) {
		return
	}

	// NEVER mount inside an iframe. The Files app opens an office document by
	// embedding `/apps/eurooffice/<fileId>` — a FULL Nextcloud page, same-origin —
	// inside itself, so this script runs twice on one screen and the user sees two
	// hexes, the inner one clipped by the frame's edge.
	//
	// The guard is "am I framed", not a URL match, because the duplicate is caused
	// by the embedding rather than by any particular app: anything Nextcloud frames
	// this way reproduces it. The OUTER page is the one to keep — it owns the whole
	// viewport, so its button is never clipped, and its context describes the page
	// the user is actually looking at.
	if (window.self !== window.top) {
		return
	}

	if (document.getElementById('hermiq-companion-root') !== null) {
		return
	}

	const root = document.createElement('div')
	root.id = 'hermiq-companion-root'

	// 🔴 Above Nextcloud's header, which sits at z-index 2000.
	//
	// Without this the panel renders UNDERNEATH the header and its close button
	// — which lands at y 8–42, inside the 50px header — is covered by
	// `.header-start`. `elementFromPoint` at the button's own centre returned
	// the header, not the button, so the panel could be opened and never
	// closed. It looked correct in the DOM the whole time: present, visible,
	// correctly sized. Only a real click found it.
	//
	// 2001, not something larger: a genuine modal (Nextcloud uses far higher)
	// must still win over an assistant panel.
	root.style.position = 'relative'
	root.style.zIndex = '2001'

	document.body.appendChild(root)

	const fileId = openFileId()

	// ⚠️ Vue 3 `createApp`, not Vue 2 `new Vue().$mount()`. This app is on Vue
	// 3.5, where the default export is not a constructor — the Vue 2 form threw
	// `TypeError: r.default is not a constructor` at load, leaving an empty
	// mount point and no companion on any page. `main.js` and
	// `integration-leaf.js` were already on createApp; this file alone was not.
	//
	// Props go at the top level in Vue 3's `h()`, not nested under `props`.
	createApp({
		render: () =>
			h(CnAiCompanion, {
				chatAppId: 'hermiq',
				position: 'bottom-right',
				// Carried so the assistant can be asked about the document on
				// screen without the user pasting an id. Absent on pages that are
				// not showing a file, which is most of them.
				...(fileId !== null ? { contextFileId: fileId } : {}),
			}),
	}).mount(root)
}

if (document.readyState === 'loading') {
	document.addEventListener('DOMContentLoaded', mount)
} else {
	mount()
}
