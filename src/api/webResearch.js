// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Thin fetch helpers for the admin web-research backend configuration
// (web-research-tool). Backs src/modals/WebResearchSettingsModal.vue. GET returns
// the config with the search credential masked to a `searchCredentialConfigured`
// boolean (the raw id is never returned — a stricter contract than the LLM
// settings endpoint); PATCH merges a partial config and validates the provider
// server-side.

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * Read the current Hermiq web-research configuration (credential masked).
 *
 * @return {Promise<object>} The masked `hermiq.webResearch` config.
 */
export async function getWebResearchSettings() {
	const { data } = await axios.get(
		generateUrl('/apps/hermiq/api/settings/web-research'),
	)
	return data
}

/**
 * Patch the Hermiq web-research configuration (merge semantics).
 *
 * @param {object} payload A partial `hermiq.webResearch` config (e.g. { searchProvider, searchEndpoint }).
 * @return {Promise<object>} `{ success, config }` with the merged masked config.
 */
export async function patchWebResearchSettings(payload) {
	const { data } = await axios.patch(
		generateUrl('/apps/hermiq/api/settings/web-research'),
		{ webResearch: payload },
	)
	return data
}
