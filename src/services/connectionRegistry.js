// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The Integrations page's Add integration handler.
 *
 * The rows on that page are integriq's `app_connection` objects (hydra change
 * connection-registry, design D8). The page's `connectionStatus` and
 * `connectionSettingsLabel` formatters are nextcloud-vue built-ins, so hermiq
 * registers no copy of its own: a local formatter under either name would win
 * over the built-in and fall behind it the next time a status is added.
 *
 * Pure: the URL builder and the navigation are passed in, so the module runs
 * under plain node with nothing mocked.
 *
 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-the-integrations-page-lists-hermiqs-rows-from-integriq-req-hermiq-conn-002
 */

/**
 * Where Add integration lands: integriq's Connections overview, preset to this
 * app and opening the link-a-source dialog (hydra connection-registry D9).
 */
export const INTEGRIQ_CONNECTIONS_PATH =
	'/apps/integriq/connections?app=hermiq&link=1'

/**
 * Build the Add integration header-action handler.
 *
 * A function handler, because a header action's `navigate` keyword only pushes
 * a route inside this app's router, which cannot leave the app.
 *
 * @param {{generateUrl: function(string): string, assign: function(string): void}} deps Builds the instance URL and navigates to it.
 * @return {{openIntegriqConnections: function(): void}} The handler, keyed by its manifest name.
 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-the-integrations-page-lists-hermiqs-rows-from-integriq-req-hermiq-conn-002
 */
export function createConnectionHandlers({ generateUrl, assign }) {
	return {
		/**
		 * Open integriq's Connections overview on the link-a-source dialog.
		 *
		 * @return {void}
		 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-the-integrations-page-lists-hermiqs-rows-from-integriq-req-hermiq-conn-002
		 */
		openIntegriqConnections() {
			assign(generateUrl(INTEGRIQ_CONNECTIONS_PATH))
		},
	}
}
