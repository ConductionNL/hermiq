// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Hermiq agent render leaf — JS registration (agent-object-leaf, ADR-019 +
 * ADR-066 mount hand-off).
 *
 * This is the always-loaded bundle Application.php attaches on EVERY Nextcloud
 * page via `\OCP\Util::addInitScript('hermiq', 'hermiq-agent-leaf')`. It registers
 * the `hermiq-agent` integration under the SAME id as the server-side
 * `LeafDescriptor` (RegisterAgentLeafListener), so an Agent tab + widget appears
 * on any OpenRegister object in any OpenBuild app that renders the integration
 * registry.
 *
 * `registerIntegration()` is the load-order-safe entry point: when OpenRegister's
 * bundle has not installed the real registry yet, the call is queued on a stub and
 * replayed on install (ADR-019 cross-Vue-bundle trap, openregister#1958). This
 * bundle MUST NOT call `installIntegrationRegistry()` — that would clobber OR's
 * singleton.
 *
 * RENDER MODE — `mount` (openregister#2127, ADR-066): Hermiq is Vue 3 while the
 * OpenBuild/OpenRegister host is Vue 2.7. A Vue-3 SFC handed to the host is
 * interpreted under the host's own (incompatible) Vue runtime and renders blank
 * (hermiq#44). Instead the leaf ships a `mount(el, props)` / `unmount(el)` pair:
 * the host hands us a bare, host-owned DOM element and we root Hermiq's OWN Vue 3
 * app at it, so each side runs its own framework across the neutral DOM boundary.
 * No `tab`/`widget` SFC is registered — a mount-mode descriptor renders through
 * the pair, not through the host's dynamic-component machinery.
 *
 * Render-only: the chat surface reuses the tool-free `converse` endpoint; the
 * widget reads OR's audit trail and POSTs the single governed run-on-object
 * command. No agent logic lives here.
 *
 * @spec openspec/changes/hermiq-agent-leaf/specs/agent-object-leaf/spec.md#requirement-agent-integration-leaf-registration
 * @spec openspec/changes/hydra-console-agent-leaves/specs/agent-object-leaf/spec.md#requirement-agent-integration-leaf-registration
 */

import { createApp } from 'vue'
import { registerIntegration } from '@conduction/nextcloud-vue'
import { translate as t } from '@nextcloud/l10n'
import CnAgentChatTab from './components/CnAgentChatTab/CnAgentChatTab.vue'
import CnAgentRunsWidget from './components/CnAgentRunsWidget/CnAgentRunsWidget.vue'

/**
 * Per-element registry of the Vue 3 app instances this leaf has mounted, so
 * `unmount(el)` can find and destroy the right one. Keyed by the host-owned DOM
 * element — NOT by leaf id — because the same leaf may be mounted into several
 * elements on one page at once (e.g. a sidebar chat tab AND a detail-page run
 * widget), each its own instance (openregister#2127 design.md, "keyed by el").
 *
 * @type {Map<Element, import('vue').App>}
 */
const mountedApps = new Map()

/**
 * Surfaces that render the per-object run-history widget rather than the chat.
 * The host forwards `surface` on the mount props (CnLeafMountHost): the object
 * sidebar tab carries `single-entity` (hermiq#44's blank surface → the chat),
 * while the detail-page and dashboard widget grids carry these.
 *
 * @type {string[]}
 */
const WIDGET_SURFACES = ['detail-page', 'app-dashboard', 'user-dashboard']

/**
 * The render surfaces this leaf targets — the SAME set, in the same order, as the
 * PHP half declares (RegisterAgentLeafListener::SURFACES).
 *
 * Declared EXPLICITLY rather than by omission (hydra-console-agent-leaves). This
 * half previously shipped no `surfaces` key at all while contributing a
 * dashboard-sized widget, and the PHP half declared only detail-page/single-entity
 * — so the two halves disagreed about whether the widget could be placed on a
 * dashboard, and a silent half is exactly what let them drift without the
 * cross-layer parity gate noticing. Every member is drawn from OpenRegister's
 * authoritative `LeafDescriptor::VALID_SURFACES` vocabulary.
 */
const SURFACES = ['user-dashboard', 'app-dashboard', 'detail-page', 'single-entity']

/**
 * Pick the root component for a mount, off the host-forwarded `surface`.
 *
 * @param {string} [surface] The render surface the host is mounting into.
 * @return {object} The Vue component to root at the element.
 */
function componentForSurface(surface) {
	return WIDGET_SURFACES.includes(surface) ? CnAgentRunsWidget : CnAgentChatTab
}

/**
 * Mount hand-off (renderMode 'mount'). The host hands us a bare, host-owned
 * element already in the DOM; we root Hermiq's OWN Vue 3 app at it with the
 * forwarded object context as root props, so the leaf renders under its own
 * framework even though the host runs Vue 2.7. Idempotent per element.
 *
 * @param {Element} el    Host-owned container element to root the app at.
 * @param {object}  props Forwarded context: { register, schema, objectId, surface, integrationContext, … }.
 * @return {void}
 */
function mount(el, props) {
	if (el === undefined || el === null || mountedApps.has(el) === true) {
		return
	}
	const app = createApp(componentForSurface(props && props.surface), {
		...(props || {}),
	})
	app.mount(el)
	mountedApps.set(el, app)
}

/**
 * Teardown hand-off. Destroy the Vue 3 app instance rooted at `el` and release
 * the map entry so a mount/unmount cycle leaks no instance. Guarded against a
 * double-unmount and an unknown element.
 *
 * @param {Element} el The container element previously passed to `mount`.
 * @return {void}
 */
function unmount(el) {
	const app = mountedApps.get(el)
	if (app === undefined) {
		return
	}
	mountedApps.delete(el)
	app.unmount()
}

registerIntegration({
	id: 'hermiq-agent',
	label: t('hermiq', 'Agent'),
	appName: t('hermiq', 'Hermiq'),
	icon: 'Creation',
	requiredApp: 'hermiq',
	order: 60,
	group: 'workflow',
	referenceType: 'hermiq-agent',
	surfaces: SURFACES,
	// Vue 3 leaf under a Vue 2.7 host: render via the DOM mount hand-off, not an
	// SFC the host would interpret under its own runtime (openregister#2127).
	// `mount`/`unmount` travel as a pair; no `tab`/`widget` in mount mode.
	renderMode: 'mount',
	mount,
	unmount,
	defaultSize: { w: 4, h: 4 },
})
