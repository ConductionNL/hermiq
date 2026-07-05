// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Plain (non-Pinia) API helper for the human-approval-gate-ui surfaces. These
// endpoints are thin Hermiq controllers (ApprovalController / TenantControlController)
// that do NOT map onto the generic createObjectStore object path — the reviewer
// inbox is reviewer-scoped (not owner-scoped) and the decision/kill-switch actions
// are guarded server-side — so we hit the resources directly.
//
// Deliberately a set of stateless functions (no defineStore) — the hard rule is
// "no custom Pinia stores"; Schedule CRUD still goes through the createObjectStore
// in src/store/store.js. axios from @nextcloud/axios adds the CSRF requesttoken.
//
// Mirrors src/api/agents.js.

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/** Hermiq approvals base path. */
const APPROVALS_BASE = '/apps/hermiq/api/approvals'
/** Hermiq per-organisation kill-switch base path. */
const TENANT_CONTROL_BASE = '/apps/hermiq/api/tenant-control'

/**
 * Normalise the various Hermiq list envelopes to a plain array.
 * Handles `{ results: [] }`, `{ data: { results: [] } }`, and a bare array.
 *
 * @param {object} data The response body.
 * @return {Array<object>} The extracted list (empty array on miss).
 */
function toList(data) {
	if (Array.isArray(data)) {
		return data
	}
	if (Array.isArray(data?.results)) {
		return data.results
	}
	if (Array.isArray(data?.data?.results)) {
		return data.data.results
	}
	return []
}

/**
 * List the pending approvals routed to the current user as reviewer.
 *
 * @return {Promise<Array<object>>} The pending-approval records.
 */
export async function listPendingApprovals() {
	const response = await axios.get(generateUrl(APPROVALS_BASE))
	return toList(response.data)
}

/**
 * Approve a pending approval and execute the gated run (reviewer/admin-guarded).
 *
 * @param {string} approvalId The Approval object UUID.
 * @return {Promise<object>} The decision outcome ({ approvalId, status, ran }).
 */
export async function approveApproval(approvalId) {
	const response = await axios.post(generateUrl(`${APPROVALS_BASE}/${approvalId}/approve`))
	return response.data
}

/**
 * Deny a pending approval — the gated run never executes (reviewer/admin-guarded).
 *
 * @param {string} approvalId The Approval object UUID.
 * @param {string} reason An optional free-text denial reason.
 * @return {Promise<object>} The decision outcome ({ approvalId, status }).
 */
export async function denyApproval(approvalId, reason) {
	const response = await axios.post(
		generateUrl(`${APPROVALS_BASE}/${approvalId}/deny`),
		{ reason: reason || '' },
	)
	return response.data
}

/**
 * Read the kill-switch state for an organisation (subadmin/instance-admin-guarded).
 *
 * @param {string} organisation The organisation identifier (NC group id).
 * @return {Promise<object>} The control state ({ organisation, engaged, reason, … }).
 */
export async function getKillSwitch(organisation) {
	const response = await axios.get(generateUrl(`${TENANT_CONTROL_BASE}/${organisation}`))
	return response.data
}

/**
 * Engage or disengage the kill-switch for an organisation.
 *
 * @param {string} organisation The organisation identifier (NC group id).
 * @param {boolean} engaged The new engaged state.
 * @param {string} reason An optional free-text reason.
 * @return {Promise<object>} The new control state.
 */
export async function toggleKillSwitch(organisation, engaged, reason) {
	const response = await axios.post(
		generateUrl(`${TENANT_CONTROL_BASE}/${organisation}/toggle`),
		{ engaged: engaged ? 'true' : 'false', reason: reason || '' },
	)
	return response.data
}
