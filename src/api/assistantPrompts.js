// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Plain (non-Pinia) API helper for the assistant prompt library
// (the-declared-tool-surface-and-the-prompt-library). The prompts the assistant
// offers on a record are objects an administrator reads and edits: a gemeente that
// cannot read the prompt cannot defend the output.
//
// Mirrors src/api/aiFeatures.js: stateless functions, no custom Pinia store, axios
// from @nextcloud/axios so the CSRF requesttoken travels with each write.

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/** Hermiq assistant-prompt base path. */
const PROMPTS = '/apps/hermiq/api/assistant-prompts'

/**
 * Read the library, in the administrator's order. With a scope, only the prompts
 * offered on that record type, which is what a case surface asks for.
 *
 * @param {string} [scope] The record type, or omitted for the whole library.
 * @return {Promise<Array<object>>} The prompts.
 */
export async function listAssistantPrompts(scope = '') {
	const url = scope
		? `${PROMPTS}?scope=${encodeURIComponent(scope)}`
		: PROMPTS
	const response = await axios.get(generateUrl(url))
	return response.data?.results || []
}

/**
 * Create or update one prompt (action-auth-gated).
 *
 * @param {string|null} id The prompt uuid, or null to create.
 * @param {object} payload The fields to write ({ label, prompt, usageScope, order, enabled }).
 * @return {Promise<object>} The stored prompt.
 */
export async function saveAssistantPrompt(id, payload) {
	if (id) {
		const response = await axios.put(generateUrl(`${PROMPTS}/${id}`), payload)
		return response.data
	}

	const response = await axios.post(generateUrl(PROMPTS), payload)
	return response.data
}

/**
 * Disable every prompt, wholesale or within one scope, in one act. There is
 * deliberately no counterpart: re-enabling is per prompt, through
 * saveAssistantPrompt.
 *
 * @param {string} [scope] The scope to disable, or omitted for every prompt.
 * @return {Promise<object>} { disabled, scope }.
 */
export async function disableAllAssistantPrompts(scope = '') {
	const response = await axios.post(
		generateUrl(`${PROMPTS}/disable-all`),
		scope ? { scope } : {},
	)
	return response.data
}
