// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Plain (non-Pinia) API helper for the AI run retention settings
// (what-the-model-reads-and-what-is-kept): how long run records are kept, and the
// report saying when the cleanup job last enforced it and how much it removed.
//
// Mirrors src/api/aiFeatures.js: stateless functions, no custom Pinia store, and
// axios from @nextcloud/axios so the CSRF requesttoken travels with the write.

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/** Hermiq run-retention settings path. */
const RUN_RETENTION = '/apps/hermiq/api/settings/run-retention'

/**
 * Read the instance retention and the last cleanup.
 *
 * @return {Promise<object>} { defaultDays, minimumDays, maximumDays, lastCleanup: { ran, at, removed } }.
 */
export async function getRunRetention() {
	const response = await axios.get(generateUrl(RUN_RETENTION))
	return response.data
}

/**
 * Set the instance retention. Refused with a 422 naming the permitted range when
 * the value falls outside it.
 *
 * @param {number} defaultDays The new retention, in days.
 * @return {Promise<object>} { defaultDays, lastCleanup }.
 */
export async function setRunRetention(defaultDays) {
	const response = await axios.put(generateUrl(RUN_RETENTION), { defaultDays })
	return response.data
}
