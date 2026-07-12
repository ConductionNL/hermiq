// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Plain (non-Pinia) API helper for the agent webhook-secret lifecycle
// (agent-webhook-trigger). AgentWebhook objects are thin Hermiq-controller
// endpoints (AgentWebhookController), NOT the generic createObjectStore object
// path: the plaintext secret must be shaped into the create/rotate response
// exactly once and never re-exposed via a generic object read — mirrors
// src/api/budgets.js / src/api/approvals.js.
//
// Deliberately a set of stateless functions (no defineStore) — the hard rule is
// "no custom Pinia stores". axios from @nextcloud/axios adds the CSRF requesttoken.

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/** Hermiq agents base path. */
const AGENTS_BASE = '/apps/hermiq/api/agents'

/**
 * Read the webhook status for an agent ({configured:false} when unconfigured).
 *
 * @param {string} agentId The agent UUID.
 * @return {Promise<object>} The status payload.
 */
export async function getWebhookStatus(agentId) {
	const response = await axios.get(generateUrl(`${AGENTS_BASE}/${agentId}/webhook-secret`))
	return response.data
}

/**
 * Create a webhook secret for an agent that has none. The plaintext secret is
 * present ONLY in this response.
 *
 * @param {string} agentId The agent UUID.
 * @return {Promise<object>} The created status payload plus a one-time `secret`.
 */
export async function createWebhookSecret(agentId) {
	const response = await axios.post(generateUrl(`${AGENTS_BASE}/${agentId}/webhook-secret`))
	return response.data
}

/**
 * Rotate an agent's webhook secret, invalidating the previous one immediately.
 * The new plaintext secret is present ONLY in this response.
 *
 * @param {string} agentId The agent UUID.
 * @return {Promise<object>} The updated status payload plus a one-time `secret`.
 */
export async function rotateWebhookSecret(agentId) {
	const response = await axios.post(generateUrl(`${AGENTS_BASE}/${agentId}/webhook-secret/rotate`))
	return response.data
}

/**
 * Revoke an agent's webhook secret (disables it without deleting its configuration).
 *
 * @param {string} agentId The agent UUID.
 * @return {Promise<object>} The updated status payload (never a secret).
 */
export async function revokeWebhookSecret(agentId) {
	const response = await axios.post(generateUrl(`${AGENTS_BASE}/${agentId}/webhook-secret/revoke`))
	return response.data
}

/**
 * Update only the approval-gate fields (requiresApproval/reviewer/reviewerType).
 *
 * @param {string} agentId The agent UUID.
 * @param {object} payload The fields to update.
 * @return {Promise<object>} The updated status payload.
 */
export async function patchWebhookSecret(agentId, payload) {
	const response = await axios.patch(generateUrl(`${AGENTS_BASE}/${agentId}/webhook-secret`), payload)
	return response.data
}
