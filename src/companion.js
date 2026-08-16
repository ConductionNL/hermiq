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

import Vue from 'vue'
import { CnAiCompanion } from '@conduction/nextcloud-vue'

/**
 * The Hermiq app's own pages already render a companion via CnAppRoot.
 *
 * Detected from the body class Nextcloud sets for the active app rather than from
 * the URL: a URL check misses `/index.php/apps/hermiq/...` vs `/apps/hermiq/...`
 * and anything mounted under a sub-path, and the body class is what Nextcloud
 * itself considers the current app.
 *
 * @return {boolean} True when this page is one of Hermiq's own.
 */
function hermiqOwnsThisPage() {
	return document.body.classList.contains('app-hermiq')
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

	return (/^\d+$/.test(last ?? '') === true) ? Number(last) : null
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

	if (document.getElementById('hermiq-companion-root') !== null) {
		return
	}

	const root = document.createElement('div')
	root.id = 'hermiq-companion-root'
	document.body.appendChild(root)

	const fileId = openFileId()

	new Vue({
		render: (h) => h(CnAiCompanion, {
			props: {
				chatAppId: 'hermiq',
				position: 'bottom-right',
				// Carried so the assistant can be asked about the document on
				// screen without the user pasting an id. Absent on pages that are
				// not showing a file, which is most of them.
				...(fileId !== null ? { contextFileId: fileId } : {}),
			},
		}),
	}).$mount(root)
}

if (document.readyState === 'loading') {
	document.addEventListener('DOMContentLoaded', mount)
} else {
	mount()
}
