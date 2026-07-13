// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Plain (non-Pinia) API helper for the agent-tool-governance-and-disclosure surface — the
// grant editor (grant-annotated derived catalog read + Agent.tools write) and the per-agent
// EU AI Act art.12/14 tool-invocation oversight read. axios from @nextcloud/axios adds the
// CSRF requesttoken. Mirrors src/api/analytics.js / src/api/tenantOps.js.
//
// @spec openspec/changes/agent-tool-governance-and-disclosure/tasks.md#task-6
// @spec openspec/changes/agent-tool-governance-and-disclosure/tasks.md#task-7

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/** Hermiq agents base path. */
const AGENTS_BASE = '/apps/hermiq/api/agents'

/**
 * Fetch the grant-annotated derived tool catalogue for an agent's grant editor.
 *
 * @param {string} agentId The agent UUID.
 * @return {Promise<object>} { agentId, disclosureThreshold, resolvedCount, disclosureActive, tools }.
 */
export async function getToolCatalog(agentId) {
	const response = await axios.get(generateUrl(`${AGENTS_BASE}/${agentId}/tool-catalog`))
	return response.data
}

/**
 * Persist the Agent.tools grant array (owner-only; single write-path via ObjectService).
 *
 * @param {string} agentId The agent UUID.
 * @param {Array<string>} grants The new grant strings (exact ids / {app}.{schema}.* / verb subsets / :write).
 * @return {Promise<object>} { agentId, tools }.
 */
export async function updateToolGrants(agentId, grants) {
	const response = await axios.put(generateUrl(`${AGENTS_BASE}/${agentId}/tool-grants`), { grants })
	return response.data
}

/**
 * Fetch the per-agent tool-invocation oversight rows (tenant-scoped).
 *
 * @param {string} agentId The agent UUID.
 * @param {object} [params] Optional { from, to } ISO 8601 bounds.
 * @return {Promise<object>} { agentId, available, source, retention, rows }.
 */
export async function getToolInvocations(agentId, params = {}) {
	const response = await axios.get(generateUrl(`${AGENTS_BASE}/${agentId}/tool-invocations`), { params })
	return response.data
}

/**
 * Build the CSV/JSON export URL for the oversight rows (opened in a new tab / download).
 *
 * @param {string} agentId The agent UUID.
 * @param {string} format Either 'csv' or 'json'.
 * @return {string} The absolute export URL.
 */
export function toolInvocationsExportUrl(agentId, format) {
	return generateUrl(`${AGENTS_BASE}/${agentId}/tool-invocations?format=${format}`)
}
