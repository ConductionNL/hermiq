// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Plain (non-Pinia) API helper for the multi-tenant-ops + agent-lifecycle-governance
// surface — thin Hermiq TenantOpsController endpoints (per-org quota, EU AI Act audit
// export, periodic access review + attestation + reassignment, incident records, and
// the retention-period setting). axios from @nextcloud/axios adds the CSRF requesttoken.
// Mirrors src/api/analytics.js.

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

/**
 * Get the caller's organisation's periodic access-review list (agent-lifecycle-governance).
 *
 * @return {Promise<object>} The access-review payload ({ agents: [...] }).
 */
export async function getAccessReview() {
	const response = await axios.get(generateUrl(`${TENANT_OPS_BASE}/access-review`))
	return response.data
}

/**
 * Record a "reviewed" attestation for one agent.
 *
 * @param {string} uuid The agent UUID.
 * @return {Promise<object>} The updated access-review row.
 */
export async function attestReviewed(uuid) {
	const response = await axios.post(
		generateUrl(`${TENANT_OPS_BASE}/access-review/${uuid}/attest`),
	)
	return response.data
}

/**
 * Reassign a flagged agent's actingUser to a new active user.
 *
 * @param {string} uuid The agent UUID.
 * @param {string} actingUser The target Nextcloud user id.
 * @return {Promise<object>} The updated access-review row.
 */
export async function reassignAgent(uuid, actingUser) {
	const response = await axios.post(
		generateUrl(`${TENANT_OPS_BASE}/access-review/${uuid}/reassign`),
		{ actingUser },
	)
	return response.data
}

/**
 * Get the caller's organisation's incident records (agent-lifecycle-governance).
 *
 * @return {Promise<object>} The incident list ({ incidents: [...] }).
 */
export async function getIncidents() {
	const response = await axios.get(generateUrl(`${TENANT_OPS_BASE}/incidents`))
	return response.data
}

/**
 * Open a new incident record.
 *
 * @param {object} payload The incident fields ({ description, impact, actionsTaken, linkedAgentId, linkedRunIds }).
 * @return {Promise<object>} The created incident.
 */
export async function createIncident(payload) {
	const response = await axios.post(
		generateUrl(`${TENANT_OPS_BASE}/incidents`),
		payload,
	)
	return response.data
}

/**
 * Get the caller's organisation's currently configured retention period.
 *
 * @return {Promise<object>} `{ retentionMonths }`.
 */
export async function getRetention() {
	const response = await axios.get(generateUrl(`${TENANT_OPS_BASE}/retention`))
	return response.data
}

/**
 * Configure the caller's organisation's retention period (rejected below 6 months).
 *
 * @param {number} months The new retention period, in months.
 * @return {Promise<object>} `{ retentionMonths }`.
 */
export async function setRetention(months) {
	const response = await axios.put(generateUrl(`${TENANT_OPS_BASE}/retention`), {
		retentionMonths: months,
	})
	return response.data
}
