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

/**
 * Get run analytics for the caller's tenant, optionally scoped to one agent.
 *
 * @param {string} [agentId] Optional agent UUID to scope the metrics to.
 * @return {Promise<object>} The metrics payload.
 */
export async function getAnalytics(agentId = '') {
	const response = await axios.get(generateUrl(ANALYTICS_BASE), { params: agentId ? { agentId } : {} })
	return response.data
}
