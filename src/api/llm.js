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

/**
 * Where each provider runs, as administrators declared it
 * (a-provider-and-a-place-per-ai-feature).
 *
 * @return {Promise<object>} `{ residencies: {provider: {residency, location}}, allowed }`.
 */
export async function getProviderResidency() {
	const { data } = await axios.get(
		generateUrl('/apps/hermiq/api/settings/provider-residency'),
	)
	return data
}

/**
 * Declare where one provider runs.
 *
 * @param {string} provider The provider id.
 * @param {object} declaration `{ residency, location }`.
 * @return {Promise<object>} The stored declaration.
 */
export async function declareProviderResidency(provider, declaration) {
	const { data } = await axios.put(
		generateUrl(
			`/apps/hermiq/api/settings/provider-residency/${encodeURIComponent(provider)}`,
		),
		declaration,
	)
	return data
}

/**
 * What each provider does with the data it is sent (models-no-training-guarantee).
 *
 * @return {Promise<object>} `{ dataUse: {provider: {dataUse, termsReference, declaredBy, declaredAt}}, allowed }`.
 */
export async function getProviderDataUse() {
	const { data } = await axios.get(
		generateUrl('/apps/hermiq/api/settings/provider-data-use'),
	)
	return data
}

/**
 * Declare what one provider does with the data it is sent.
 *
 * @param {string} provider The provider id.
 * @param {object} declaration `{ dataUse, termsReference }`.
 * @return {Promise<object>} The stored declaration.
 */
export async function declareProviderDataUse(provider, declaration) {
	const { data } = await axios.put(
		generateUrl(
			`/apps/hermiq/api/settings/provider-data-use/${encodeURIComponent(provider)}`,
		),
		declaration,
	)
	return data
}
