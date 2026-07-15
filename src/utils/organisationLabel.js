// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Shared organisation-id → label lookup (inapp-settings-section Task 2).
//
// Both TenantOps.vue's Model policy section and GuardrailPolicySettings.vue's
// guardrail-policy list need to resolve a policy's `organisation` id to a
// human label against the caller's loadState-provided `managed_organisations`
// list. Before this change, both files carried their own copy of this
// ~4-line lookup (`policyOrgLabel()`); this util is the single place it now
// lives so neither file duplicates the logic.

/**
 * Human label for an organisation id, looked up against the caller's
 * manageable organisations (typically `loadState('hermiq', 'managed_organisations', [])`).
 *
 * @param {string} orgId The organisation identifier.
 * @param {Array<{id: string, label?: string}>} organisations The caller's manageable organisations.
 * @return {string} The organisation's label, or the raw id when not found.
 */
export function organisationLabel(orgId, organisations) {
	const list = Array.isArray(organisations) ? organisations : []
	const org = list.find((candidate) => candidate && candidate.id === orgId)
	return org ? (org.label || org.id) : orgId
}
