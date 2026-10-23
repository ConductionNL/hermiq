// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Plain (non-Pinia) API helper for the conversational intake settings
// (a-conversational-intake-that-files-for-the-citizen): the confidence below which
// the intake hands over to a person rather than filing.
//
// Mirrors src/api/runRetention.js: stateless functions, no custom Pinia store, axios
// from @nextcloud/axios so the CSRF requesttoken travels with the write.

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/** Hermiq intake settings path. */
const INTAKE = '/apps/hermiq/api/settings/intake'

/**
 * Read the abstention threshold in force.
 *
 * @return {Promise<object>} { abstentionThreshold, default }.
 */
export async function getIntakeSettings() {
	const response = await axios.get(generateUrl(INTAKE))
	return response.data
}

/**
 * Set the abstention threshold. Refused with a 422 when it is outside 0 to 1.
 *
 * @param {number} abstentionThreshold The new threshold.
 * @return {Promise<object>} The stored settings.
 */
export async function setIntakeSettings(abstentionThreshold) {
	const response = await axios.put(generateUrl(INTAKE), { abstentionThreshold })
	return response.data
}
