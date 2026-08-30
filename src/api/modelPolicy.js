// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Plain (non-Pinia) API helper for the tenant model-policy surface
// (tenant-model-policy). Policies are thin Hermiq-controller endpoints
// (TenantModelPolicyController), NOT the generic createObjectStore object path:
// write access is instance-admin/org-owner-gated server-side (mirrors
// src/api/budgets.js), which the generic OR objects path does not express.
//
// Deliberately a set of stateless functions (no defineStore) — the hard rule is
// "no custom Pinia stores". axios from @nextcloud/axios adds the CSRF requesttoken.

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/** Hermiq model-policy base path. */
const BASE = '/apps/hermiq/api/model-policy'

/**
 * The caller's effective model policy: own organisation's policy, else the
 * instance-wide default, else the fail-closed fallback.
 *
 * @return {Promise<object>} `{ source, allowed: [{provider, models}], defaultModel }`.
 */
export async function getEffectiveModelPolicy() {
	const response = await axios.get(generateUrl(`${BASE}/effective`))
	return response.data
}

/**
 * List the caller-visible ModelPolicy objects (all for an instance admin,
 * own organisations' otherwise).
 *
 * @return {Promise<Array<object>>} The policy records.
 */
export async function listModelPolicies() {
	const response = await axios.get(generateUrl(BASE))
	return Array.isArray(response.data?.policies) ? response.data.policies : []
}

/**
 * Create a ModelPolicy (instance-admin/org-owner-guarded server-side).
 *
 * @param {object} payload The policy fields (organisation, allowed, defaultModel).
 * @return {Promise<object>} The created policy.
 */
export async function createModelPolicy(payload) {
	const response = await axios.post(generateUrl(BASE), payload)
	return response.data
}

/**
 * Update a ModelPolicy (instance-admin/org-owner-guarded server-side).
 *
 * @param {string} policyId The ModelPolicy object UUID.
 * @param {object} payload The fields to update.
 * @return {Promise<object>} The updated policy.
 */
export async function updateModelPolicy(policyId, payload) {
	const response = await axios.put(generateUrl(`${BASE}/${policyId}`), payload)
	return response.data
}
