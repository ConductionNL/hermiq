// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Thin fetch helpers for the admin LLM provider configuration
// (SPECTR-NEXTCLOUD-PLAN.md §8 move 1). Backs src/modals/LlmProviderModal.vue.
// GET returns the config with credentials masked to `*Set` booleans; PATCH
// merges a partial config and validates the provider server-side.

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * Read the current Hermiq LLM provider configuration (credentials masked).
 *
 * @return {Promise<object>} The masked `hermiq.llm` config.
 */
export async function getLlmSettings() {
	const { data } = await axios.get(generateUrl('/apps/hermiq/api/settings/llm'))
	return data
}

/**
 * Patch the Hermiq LLM provider configuration (merge semantics).
 *
 * @param {object} payload A partial `hermiq.llm` config (e.g. { chatProvider, openaiConfig }).
 * @return {Promise<object>} `{ success, config }` with the merged masked config.
 */
export async function patchLlmSettings(payload) {
	const { data } = await axios.patch(
		generateUrl('/apps/hermiq/api/settings/llm'),
		{ llm: payload },
	)
	return data
}
