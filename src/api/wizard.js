// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Plain (non-Pinia) API helper for the first-run configuration wizard
// (src/views/SetupWizard.vue). Wraps the SetupController probe endpoints plus the
// generic PreferencesController get/set used to persist the wizard's completed flag
// and chosen defaults. axios from @nextcloud/axios adds the CSRF requesttoken.
//
// (Named wizard.js, not setup.js: the repo .gitignore has a `**/setup*` rule for
// setup scripts that would otherwise swallow this source file.)

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/** Hermiq setup + preferences bases. */
const SETUP_BASE = '/apps/hermiq/api/setup'
const PREF_BASE = '/apps/hermiq/api/preferences'

/**
 * Probe an LLM endpoint server-side and return the models it advertises.
 *
 * @param {string} endpoint The LLM base URL (empty → server default).
 * @return {Promise<{reachable: boolean, models: string[], error?: string}>} The probe result.
 */
export async function llmTest(endpoint = '') {
	const response = await axios.get(generateUrl(`${SETUP_BASE}/llm-test`), { params: { endpoint } })
	return response.data
}

/**
 * List the OpenRegister organisations the current user owns.
 *
 * @return {Promise<Array<{uuid: string, name: string}>>} The organisations.
 */
export async function listOrganisations() {
	const response = await axios.get(generateUrl(`${SETUP_BASE}/organisations`))
	return Array.isArray(response.data?.results) ? response.data.results : []
}

/**
 * Read a per-user preference.
 *
 * @param {string} key The preference key.
 * @return {Promise<string|null>} The stored value, or null.
 */
export async function getPreference(key) {
	const response = await axios.get(generateUrl(`${PREF_BASE}/${key}`))
	return response.data?.value ?? null
}

/**
 * Write a per-user preference (empty value clears it).
 *
 * @param {string} key The preference key.
 * @param {string} value The value to store.
 * @return {Promise<string|null>} The stored value, or null.
 */
export async function setPreference(key, value) {
	const response = await axios.put(generateUrl(`${PREF_BASE}/${key}`), { value })
	return response.data?.value ?? null
}
