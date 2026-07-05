// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Plain (non-Pinia) API helper for the multi-tenant-ops surface — thin read-only Hermiq
// controllers (per-org quota + EU AI Act audit export). axios from @nextcloud/axios adds
// the CSRF requesttoken. Mirrors src/api/analytics.js.

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/** Hermiq tenant-ops base path. */
const TENANT_OPS_BASE = '/apps/hermiq/api/tenant-ops'

/**
 * Get the caller's organisation quota usage against the configured limits.
 *
 * @return {Promise<object>} The quota status ({ schedules, agents }).
 */
export async function getQuota() {
	const response = await axios.get(generateUrl(`${TENANT_OPS_BASE}/quota`))
	return response.data
}

/**
 * Fetch the per-tenant EU AI Act audit export (the caller's org records).
 *
 * @return {Promise<object>} The export payload ({ export, recordCount, records }).
 */
export async function getAuditExport() {
	const response = await axios.get(generateUrl(`${TENANT_OPS_BASE}/audit-export`))
	return response.data
}
