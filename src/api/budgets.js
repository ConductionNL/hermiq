// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Plain (non-Pinia) API helper for the budget-guardrails surface (cost-guardrails).
// Budget objects are thin Hermiq-controller endpoints (BudgetController), NOT the
// generic createObjectStore object path: write access is admin/owner-gated
// server-side (mirrors TenantControlController), which the generic OR objects path
// does not express — so we hit the resource directly, mirroring src/api/approvals.js.
//
// Deliberately a set of stateless functions (no defineStore) — the hard rule is
// "no custom Pinia stores". axios from @nextcloud/axios adds the CSRF requesttoken.

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/** Hermiq budgets base path. */
const BUDGETS_BASE = '/apps/hermiq/api/budgets'
/** Hermiq agents base path (budget-estimate lives here per design.md). */
const AGENTS_BASE = '/apps/hermiq/api/agents'

/**
 * Normalise the various Hermiq list envelopes to a plain array.
 *
 * @param {object} data The response body.
 * @return {Array<object>} The extracted list (empty array on miss).
 */
function toList(data) {
	if (Array.isArray(data?.budgets)) {
		return data.budgets
	}
	if (Array.isArray(data)) {
		return data
	}
	return []
}

/**
 * List budgets (organisation-scope and agent-scope) for an organisation.
 *
 * @param {string} organisation The organisation identifier.
 * @return {Promise<Array<object>>} The budget records.
 */
export async function listBudgets(organisation) {
	const response = await axios.get(generateUrl(BUDGETS_BASE), { params: { organisation } })
	return toList(response.data)
}

/**
 * Current-period usage vs. limit for one scope (organisation, or organisation+agent).
 *
 * @param {string} organisation The organisation identifier.
 * @param {string} [agentId] Optional agent UUID (agent-scoped budget).
 * @return {Promise<object>} The status payload ({ tokens, eur, softThresholdReached, hardCapReached, configured }).
 */
export async function getBudgetStatus(organisation, agentId) {
	const response = await axios.get(generateUrl(`${BUDGETS_BASE}/status`), {
		params: { organisation, agentId: agentId || '' },
	})
	return response.data
}

/**
 * Create a budget for an organisation (admin/owner-guarded server-side).
 *
 * @param {object} payload The budget fields (organisation, scope, agentId, period, tokenLimit, eurLimit, softThresholdPercent).
 * @return {Promise<object>} The created budget.
 */
export async function createBudget(payload) {
	const response = await axios.post(generateUrl(BUDGETS_BASE), payload)
	return response.data
}

/**
 * Update a budget (admin/owner-guarded server-side).
 *
 * @param {string} budgetId The Budget object UUID.
 * @param {object} payload The fields to update.
 * @return {Promise<object>} The updated budget.
 */
export async function updateBudget(budgetId, payload) {
	const response = await axios.put(generateUrl(`${BUDGETS_BASE}/${budgetId}`), payload)
	return response.data
}

/**
 * Delete a budget (admin/owner-guarded server-side).
 *
 * @param {string} budgetId The Budget object UUID.
 * @return {Promise<object>} The deletion outcome.
 */
export async function deleteBudget(budgetId) {
	const response = await axios.delete(generateUrl(`${BUDGETS_BASE}/${budgetId}`))
	return response.data
}

/**
 * Pre-run rough cost estimate for one agent, derived from its trailing run history.
 * Never a fabricated figure — `available:false` when the agent has no recorded runs.
 *
 * @param {string} agentId The agent UUID.
 * @return {Promise<object>} The estimate payload ({ available, sampleSize, avgTotalTokens, avgCostEur, label }).
 */
export async function getBudgetEstimate(agentId) {
	const response = await axios.get(generateUrl(`${AGENTS_BASE}/${agentId}/budget-estimate`))
	return response.data
}
