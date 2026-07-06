// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Plain (non-Pinia) API helper for the agent-adjacent surfaces that do NOT
// map onto the createObjectStore object path.
//
// HISTORY (agent-engine-port task 5.2): this file used to carry
// listAgents()/createAgent()/updateAgent() as a documented createObjectStore
// bypass ("OpenRegister agents are a first-class OR resource served at
// /apps/openregister/api/agents ... so they cannot be read through
// createObjectStore"). Since agent-engine-schemas declared `Agent` as a plain
// OR object in the hermiq register, that rationale is void: agent CRUD now
// goes through `useAgentStore` (src/store/store.js) against the generic
// objects path, same as every other Hermiq schema object.
//
// GROUND-TRUTH ADAPTATION (pre-approved, mirrored in src/store/store.js):
// design.md names `/apps/hermiq/api/objects/hermiq/agent` as the
// createObjectStore path, but nc-vue's createObjectStore default baseUrl is
// `/apps/openregister/api/objects` and every existing hermiq schema object
// (schedule, example) uses that default — "same as every other Hermiq schema
// object" wins; no hermiq-side objects proxy is added (it would trip gate-17
// redundant-controller).
//
// What legitimately stays here (stateless helpers, not object reads):
//   - listTools() — the agent-configuration tool catalogue, served by
//     Hermiq's facade-backed endpoint /apps/hermiq/api/agents/tools
//     (agent-engine-port; backed by OR's public ToolRegistryFacade, gate-27).
//   - Run now + run history — thin Hermiq schedule endpoints.
//
// This is deliberately a set of stateless functions (no defineStore) — the
// hard rule is "no custom Pinia stores". axios from @nextcloud/axios adds the
// CSRF requesttoken automatically.

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/** Hermiq agents resource base path (tools catalogue only — CRUD is useAgentStore). */
const AGENTS_BASE = '/apps/hermiq/api/agents'
/** Hermiq schedule action/read base path. */
const HERMIQ_SCHEDULES_BASE = '/apps/hermiq/api/schedules'

/**
 * Normalise the various Hermiq/OpenRegister list envelopes to a plain array.
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
 * List the tools available for agent configuration (from every registered app).
 *
 * Served by Hermiq's /api/agents/tools endpoint (agent-engine-port), which is
 * backed by OR's public ToolRegistryFacade — not an object read, hence a
 * bespoke helper rather than a store call. The envelope may be an array of
 * descriptors or a map keyed by tool id (`{"opencatalogi.cms": {name, …}}`,
 * OR's historical shape), so normalise both and inject the key as `id` (the
 * identifier agents reference a tool by).
 *
 * @return {Promise<Array<object>>} The tool metadata objects (each with an `id`).
 */
export async function listTools() {
	const response = await axios.get(generateUrl(`${AGENTS_BASE}/tools`))
	const results = response.data?.results ?? response.data
	if (Array.isArray(results)) {
		return results
	}
	if (results && typeof results === 'object') {
		return Object.entries(results).map(([id, tool]) => ({ id, ...tool }))
	}
	return []
}

/**
 * Trigger an immediate run of a schedule's agent (thin Hermiq endpoint).
 * Reuses ScheduleService's dispatch path server-side; owner-guarded.
 *
 * @param {string} scheduleId The Schedule object UUID.
 * @return {Promise<object>} The run outcome ({ status, error, nextRun }).
 */
export async function runScheduleNow(scheduleId) {
	const response = await axios.post(generateUrl(`${HERMIQ_SCHEDULES_BASE}/${scheduleId}/run`))
	return response.data
}

/**
 * Read a schedule's run history (owner-scoped OpenRegister AuditTrail entries).
 *
 * @param {string} scheduleId The Schedule object UUID.
 * @return {Promise<Array<object>>} The run records, newest-first.
 */
export async function listRuns(scheduleId) {
	const response = await axios.get(generateUrl(`${HERMIQ_SCHEDULES_BASE}/${scheduleId}/runs`))
	return toList(response.data)
}
