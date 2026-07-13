// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Plain (non-Pinia) API helper for the guardrail-policy surface
// (agent-guardrails). Policies are thin Hermiq-controller endpoints
// (GuardrailPolicyController), NOT the generic createObjectStore object path:
// write access is instance-admin/org-owner-gated server-side (mirrors
// src/api/modelPolicy.js), which the generic OR objects path does not express.
//
// Deliberately a set of stateless functions (no defineStore) — the hard rule is
// "no custom Pinia stores". axios from @nextcloud/axios adds the CSRF requesttoken.

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/** Hermiq guardrail-policy base path. */
const BASE = '/apps/hermiq/api/guardrail-policies'

/**
 * The caller's effective guardrail policy: own organisation's policy (if
 * enabled), else the instance-wide default (if enabled), else the fully-open
 * fallback.
 *
 * @return {Promise<object>} `{ source, inputFilters, outputFilters, toolPolicy, enabled }`.
 */
export async function getEffectiveGuardrailPolicy() {
	const response = await axios.get(generateUrl(`${BASE}/effective`))
	return response.data
}

/**
 * List the caller-visible GuardrailPolicy objects (all for an instance admin,
 * own organisations' otherwise).
 *
 * @return {Promise<Array<object>>} The policy records.
 */
export async function listGuardrailPolicies() {
	const response = await axios.get(generateUrl(BASE))
	return Array.isArray(response.data?.policies) ? response.data.policies : []
}

/**
 * Create a GuardrailPolicy (instance-admin/org-owner-guarded server-side).
 *
 * @param {object} payload The policy fields (organisation, inputFilters, outputFilters, toolPolicy, enabled).
 * @return {Promise<object>} The created policy.
 */
export async function createGuardrailPolicy(payload) {
	const response = await axios.post(generateUrl(BASE), payload)
	return response.data
}

/**
 * Update a GuardrailPolicy (instance-admin/org-owner-guarded server-side).
 *
 * @param {string} policyId The GuardrailPolicy object UUID.
 * @param {object} payload The fields to update.
 * @return {Promise<object>} The updated policy.
 */
export async function updateGuardrailPolicy(policyId, payload) {
	const response = await axios.put(generateUrl(`${BASE}/${policyId}`), payload)
	return response.data
}
