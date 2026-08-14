// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Plain (non-Pinia) API helper for the per-SCHEDULE OUTBOUND webhook signing
// secret lifecycle (delivery-channels). Mirrors src/api/webhooks.js (the
// per-AGENT INBOUND trigger secret) exactly, but scoped to /api/schedules/{id}
// and backed by ScheduleWebhookSecretController — distinct endpoints because
// the two secrets are unrelated (different direction, different owner shape).
//
// Deliberately a set of stateless functions (no defineStore) — the hard rule is
// "no custom Pinia stores". axios from @nextcloud/axios adds the CSRF requesttoken.

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/** Hermiq schedules base path. */
const SCHEDULES_BASE = '/apps/hermiq/api/schedules'

/**
 * Read the webhook signing-secret status for a schedule ({configured:false}
 * when unconfigured). Never includes the plaintext secret.
 *
 * @param {string} scheduleId The schedule UUID.
 * @return {Promise<object>} The status payload.
 */
export async function getScheduleWebhookSecretStatus(scheduleId) {
	const response = await axios.get(
		generateUrl(`${SCHEDULES_BASE}/${scheduleId}/webhook-secret`),
	)
	return response.data
}

/**
 * Mint a webhook signing secret for a schedule that has none. The plaintext
 * secret is present ONLY in this response.
 *
 * @param {string} scheduleId The schedule UUID.
 * @return {Promise<object>} The created status payload plus a one-time `secret`.
 */
export async function mintScheduleWebhookSecret(scheduleId) {
	const response = await axios.post(
		generateUrl(`${SCHEDULES_BASE}/${scheduleId}/webhook-secret`),
	)
	return response.data
}

/**
 * Rotate a schedule's webhook signing secret, invalidating the previous one
 * immediately. The new plaintext secret is present ONLY in this response.
 *
 * @param {string} scheduleId The schedule UUID.
 * @return {Promise<object>} The updated status payload plus a one-time `secret`.
 */
export async function rotateScheduleWebhookSecret(scheduleId) {
	const response = await axios.post(
		generateUrl(`${SCHEDULES_BASE}/${scheduleId}/webhook-secret/rotate`),
	)
	return response.data
}

/**
 * Revoke a schedule's webhook signing secret (idempotent; never a secret in the response).
 *
 * @param {string} scheduleId The schedule UUID.
 * @return {Promise<object>} The updated status payload (never a secret).
 */
export async function revokeScheduleWebhookSecret(scheduleId) {
	const response = await axios.post(
		generateUrl(`${SCHEDULES_BASE}/${scheduleId}/webhook-secret/revoke`),
	)
	return response.data
}
