// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Plain (non-Pinia) API helper for the compliance-control-packs surface — thin
// ComplianceController endpoints (org-scoped dashboard, auditor's-pack export, and
// per-agent AI factsheet). axios from @nextcloud/axios adds the CSRF requesttoken.
// Mirrors src/api/tenantOps.js.

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/** Hermiq compliance base path. */
const COMPLIANCE_BASE = '/apps/hermiq/api/compliance'

/**
 * Get the caller's organisation's compliance dashboard: per-framework coverage and
 * the gap list.
 *
 * @return {Promise<object>} The dashboard payload (`{ frameworks, gaps }`).
 */
export async function getComplianceDashboard() {
	const response = await axios.get(generateUrl(`${COMPLIANCE_BASE}/dashboard`))
	return response.data
}

/**
 * Fetch the auditor's-pack export (the unmodified Art. 12 audit export nested
 * alongside the computed compliance coverage data).
 *
 * @return {Promise<object>} The export payload (`{ auditTrail, complianceCoverage, generatedAt }`).
 */
export async function getComplianceExport() {
	const response = await axios.get(generateUrl(`${COMPLIANCE_BASE}/export`))
	return response.data
}

/**
 * Fetch a single agent's AI factsheet.
 *
 * @param {string} agentId The agent UUID.
 * @return {Promise<object>} The factsheet (`{ agent, aiFeature, approvals, incidents, lastReviewedAt }`).
 */
export async function getAgentFactsheet(agentId) {
	const response = await axios.get(generateUrl(`${COMPLIANCE_BASE}/factsheet/${agentId}`))
	return response.data
}
