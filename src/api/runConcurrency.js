// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Plain (non-Pinia) API helper for the limits on parallel scheduled runs
// (operations-parallel-run-workers): how many scheduled runs may go at once on
// the instance and per organisation.
//
// Mirrors src/api/runRetention.js: stateless functions, axios from
// @nextcloud/axios so the CSRF requesttoken travels with the write.

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const RUN_CONCURRENCY = '/apps/hermiq/api/settings/run-concurrency'

/**
 * The limits in force.
 *
 * @return {Promise<{maxParallel: number, maxParallelPerOrganisation: number, maximum: number}>}
 * @spec openspec/specs/agent-schedule/spec.md#requirement-an-administrator-limits-how-many-runs-go-at-once-req-prun-003
 */
export async function getRunConcurrency() {
	const response = await axios.get(generateUrl(RUN_CONCURRENCY))
	return response.data
}

/**
 * Store new limits.
 *
 * @param {number} maxParallel The limit for the instance.
 * @param {number} maxParallelPerOrganisation The limit per organisation.
 * @return {Promise<{maxParallel: number, maxParallelPerOrganisation: number, maximum: number}>}
 * @spec openspec/specs/agent-schedule/spec.md#requirement-an-administrator-limits-how-many-runs-go-at-once-req-prun-003
 */
export async function setRunConcurrency(maxParallel, maxParallelPerOrganisation) {
	const response = await axios.put(generateUrl(RUN_CONCURRENCY), {
		maxParallel,
		maxParallelPerOrganisation,
	})
	return response.data
}
