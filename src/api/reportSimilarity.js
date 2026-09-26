// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Plain (non-Pinia) API helper for the report-grouping settings
// (identical-reports-collapse-into-one): the two thresholds and the comparison
// window per report type.
//
// Mirrors src/api/runRetention.js: stateless functions, no custom Pinia store, axios
// from @nextcloud/axios so the CSRF requesttoken travels with the write.

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/** Hermiq report-similarity settings path. */
const SETTINGS = '/apps/hermiq/api/settings/report-similarity'

/**
 * Read both thresholds and the per-type windows.
 *
 * @return {Promise<object>} { upper, lower, windows, defaultWindowMinutes }.
 */
export async function getReportSimilaritySettings() {
	const response = await axios.get(generateUrl(SETTINGS))
	return response.data
}

/**
 * Set the thresholds, the windows, or both. Refused with a 422 naming what was
 * wrong: a score outside 0 to 1, or a lower threshold that does not sit below the
 * upper one, which would leave no middle band for a near-duplicate to fall into.
 *
 * @param {object} settings The settings to write ({ upper, lower, windows }).
 * @return {Promise<object>} The stored settings.
 */
export async function setReportSimilaritySettings(settings) {
	const response = await axios.put(generateUrl(SETTINGS), settings)
	return response.data
}
