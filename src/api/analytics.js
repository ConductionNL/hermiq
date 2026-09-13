// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Plain (non-Pinia) API helper for the run-analytics surface — a thin read-only Hermiq
// controller that computes metrics from OpenRegister's run AuditTrail (tenant-scoped).
// axios from @nextcloud/axios adds the CSRF requesttoken. Mirrors src/api/memory.js.

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/** Hermiq analytics endpoint. */
const ANALYTICS_BASE = '/apps/hermiq/api/analytics'

/** Hermiq cross-agent run list. */
const RUNS_BASE = '/apps/hermiq/api/runs'

/**
 * Get run analytics for the caller's tenant, optionally scoped to one agent.
 *
 * @param {string} [agentId] Optional agent UUID to scope the metrics to.
 * @return {Promise<object>} The metrics payload.
 */
export async function getAnalytics(agentId = '') {
	const response = await axios.get(generateUrl(ANALYTICS_BASE), {
		params: agentId ? { agentId } : {},
	})
	return response.data
}

/**
 * List the caller's runs across every agent, newest first.
 *
 * Shares the tenant boundary with `getAnalytics()`, so this list and the dashboard
 * KPIs above it always describe the same set of runs.
 *
 * @param {object}  [options]         Query options.
 * @param {string}  [options.agentId] Scope to one agent.
 * @param {string}  [options.status]  Filter on an exact run status.
 * @param {number}  [options.limit]   Page size (server clamps to 200).
 * @param {number}  [options.offset]  Rows to skip.
 * @return {Promise<{results: object[], total: number, limit: number, offset: number}>} The page of runs.
 */
export async function listRuns({
	agentId = '',
	status = '',
	limit = 50,
	offset = 0,
} = {}) {
	const params = { limit, offset }
	if (agentId) {
		params.agentId = agentId
	}
	if (status) {
		params.status = status
	}
	const response = await axios.get(generateUrl(RUNS_BASE), { params })
	return response.data
}
