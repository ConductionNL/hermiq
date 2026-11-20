/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A person's own agent preferences (personal-preferences), read and written
 * through the per-user preferences endpoint (`GET`/`PUT /api/preferences/{key}`,
 * PreferencesController). Each person's values are their own; an empty value
 * clears the preference.
 *
 * @spec openspec/specs/personal-preferences/spec.md#requirement-a-persons-agent-preferences-are-kept-per-person-and-set-in-hermiqs-settings-dialog-req-pref-001
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * The preference keys the Voorkeuren section sets. PreferencesController keeps
 * only lowercase letters, digits and hyphens, so these are the stored names.
 */
export const PREFERENCE_KEYS = Object.freeze({
	answerLanguage: 'answer-language',
	defaultAgent: 'default-agent',
	askBeforeSend: 'ask-before-send',
	notifyRunDone: 'notify-run-done',
})

/**
 * The endpoint of one preference.
 *
 * @param {string} key The preference key.
 * @return {string} The URL.
 * @spec openspec/specs/personal-preferences/spec.md#requirement-a-persons-agent-preferences-are-kept-per-person-and-set-in-hermiqs-settings-dialog-req-pref-001
 */
function preferenceUrl(key) {
	return generateUrl('/apps/hermiq/api/preferences/' + encodeURIComponent(key))
}

/**
 * Read one of the signed-in person's preferences.
 *
 * @param {string} key The preference key.
 * @return {Promise<string>} The stored value, or `''` when none is set.
 * @spec openspec/specs/personal-preferences/spec.md#requirement-a-persons-agent-preferences-are-kept-per-person-and-set-in-hermiqs-settings-dialog-req-pref-001
 */
export async function getPreference(key) {
	const { data } = await axios.get(preferenceUrl(key))
	const value = data && data.value
	return typeof value === 'string' ? value : ''
}

/**
 * Store one of the signed-in person's preferences; `''` clears it.
 *
 * @param {string} key The preference key.
 * @param {string} value The value.
 * @return {Promise<string>} The value the server now holds, or `''`.
 * @spec openspec/specs/personal-preferences/spec.md#requirement-a-persons-agent-preferences-are-kept-per-person-and-set-in-hermiqs-settings-dialog-req-pref-001
 */
export async function setPreference(key, value) {
	const { data } = await axios.put(preferenceUrl(key), { value: value || '' })
	const stored = data && data.value
	return typeof stored === 'string' ? stored : ''
}
