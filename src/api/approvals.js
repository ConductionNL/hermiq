// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Plain (non-Pinia) API helper for the human-approval-gate-ui surfaces. These
// endpoints are thin Hermiq controllers (ApprovalController / OrganisationControlController)
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
import { listOrNestedResults as toList } from './listEnvelope.js'

/** Hermiq approvals base path. */
const APPROVALS_BASE = '/apps/hermiq/api/approvals'
/** Hermiq per-organisation kill-switch base path. */
const TENANT_CONTROL_BASE = '/apps/hermiq/api/tenant-control'

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
	const response = await axios.post(
		generateUrl(`${APPROVALS_BASE}/${approvalId}/approve`),
	)
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
/**
 * Answer a question an agent asked in an unattended run (oversight-ask-and-hold).
 * The server dispatches the run again with the answer; a refused answer is 422.
 *
 * @param {string} approvalId The question Approval's id.
 * @param {string} answer The chosen or typed answer.
 * @return {Promise<object>} `{approvalId, status, ran}`.
 * @spec openspec/changes/oversight-ask-and-hold/specs/human-approval-gate/spec.md#requirement-a-question-in-an-unattended-run-goes-to-the-reviewer-and-the-run-continues-with-the-answer-req-askhold-002
 */
export async function answerApproval(approvalId, answer) {
	const response = await axios.post(
		generateUrl(`${APPROVALS_BASE}/${approvalId}/answer`),
		{ answer },
	)
	return response.data
}

/**
 * Release a held draft, as written (no edits) or with edits to its text.
 * A changed recipient is refused by the server with 422.
 *
 * @param {string} approvalId The draft Approval's id.
 * @param {object} [edits] The edited fields: `output`, or `subject`/`body`.
 * @return {Promise<object>} `{approvalId, status, ran}`.
 * @spec openspec/changes/oversight-ask-and-hold/specs/human-approval-gate/spec.md#requirement-written-output-can-be-held-until-a-reviewer-releases-it-req-askhold-003
 */
export async function releaseApproval(approvalId, edits = {}) {
	const response = await axios.post(
		generateUrl(`${APPROVALS_BASE}/${approvalId}/release`),
		{ edits },
	)
	return response.data
}

export async function getKillSwitch(organisation) {
	const response = await axios.get(
		generateUrl(`${TENANT_CONTROL_BASE}/${organisation}`),
	)
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
