// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Plain (non-Pinia) API helper for the AI-feature governance register
// (ai-feature-governance-register). These endpoints are thin Hermiq controllers
// (AiFeatureController) that do NOT map onto the generic createObjectStore object
// path — the DPO-ack and enable/disable actions are action-auth-gated server-side
// (ADR-023) and enable drives a declarative OpenRegister lifecycle transition — so
// we hit the resources directly.
//
// Deliberately a set of stateless functions (no defineStore) — the hard rule is
// "no custom Pinia stores". axios from @nextcloud/axios adds the CSRF requesttoken.
//
// Mirrors src/api/approvals.js.

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/** Hermiq AI-features base path. */
const AI_FEATURES_BASE = '/apps/hermiq/api/ai-features'

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
 * List the tenant's AI-feature governance objects (risk-classified).
 *
 * @return {Promise<Array<object>>} The AiFeature records.
 */
export async function listAiFeatures() {
	const response = await axios.get(generateUrl(AI_FEATURES_BASE))
	return toList(response.data)
}

/**
 * Record the DPO acknowledgement for a feature (action-auth-gated).
 *
 * @param {string} slug The AiFeature slug.
 * @return {Promise<object>} The stamped feature ({ uuid, slug, dpoAckBy, dpoAckAt, … }).
 */
export async function acknowledgeAiFeature(slug) {
	const response = await axios.post(generateUrl(`${AI_FEATURES_BASE}/${slug}/acknowledge`))
	return response.data
}

/**
 * Enable a feature — refused (409) until the DPO has acknowledged it (action-auth-gated).
 *
 * @param {string} id The AiFeature UUID.
 * @return {Promise<object>} The transition outcome ({ id, lifecycle }).
 */
export async function enableAiFeature(id) {
	const response = await axios.post(generateUrl(`${AI_FEATURES_BASE}/${id}/enable`))
	return response.data
}

/**
 * Disable a feature (unguarded transition; action-auth-gated).
 *
 * @param {string} id The AiFeature UUID.
 * @return {Promise<object>} The transition outcome ({ id, lifecycle }).
 */
export async function disableAiFeature(id) {
	const response = await axios.post(generateUrl(`${AI_FEATURES_BASE}/${id}/disable`))
	return response.data
}
