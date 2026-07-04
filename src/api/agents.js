// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Plain (non-Pinia) API helper for the agent-management-ui surfaces that do NOT
// map onto the createObjectStore object path:
//
//   - OpenRegister agents are a first-class OR resource (their own
//     AgentsController + AgentMapper, RBAC-filtered), served at
//     /apps/openregister/api/agents — NOT the generic
//     /apps/openregister/api/objects/{register}/{schema} path, so they cannot be
//     read through createObjectStore. We hit the resource directly.
//   - Run now + run history are thin Hermiq endpoints.
//
// This is deliberately a set of stateless functions (no defineStore) — the hard
// rule is "no custom Pinia stores"; schedule CRUD still goes through the
// createObjectStore in src/store/store.js. axios from @nextcloud/axios adds the
// CSRF requesttoken automatically.

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/** OpenRegister agents resource base path. */
const AGENTS_BASE = '/apps/openregister/api/agents'
/** Hermiq schedule action/read base path. */
const HERMIQ_SCHEDULES_BASE = '/apps/hermiq/api/schedules'

/**
 * Normalise the various OpenRegister/Hermiq list envelopes to a plain array.
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
 * List the agents the current user may see (RBAC-filtered server-side).
 *
 * @return {Promise<Array<object>>} The agent objects.
 */
export async function listAgents() {
	const response = await axios.get(generateUrl(AGENTS_BASE))
	return toList(response.data)
}

/**
 * List the tools available for agent configuration (from every registered app).
 *
 * @return {Promise<Array<object>>} The tool metadata objects.
 */
export async function listTools() {
	const response = await axios.get(generateUrl(`${AGENTS_BASE}/tools`))
	return toList(response.data)
}

/**
 * Create an agent in OpenRegister.
 *
 * @param {object} payload The agent fields (name, provider, model, prompt, tools).
 * @return {Promise<object>} The created agent.
 */
export async function createAgent(payload) {
	const response = await axios.post(generateUrl(AGENTS_BASE), payload)
	return response.data
}

/**
 * Update an existing agent in OpenRegister.
 *
 * @param {number|string} id The agent's numeric id.
 * @param {object} payload The agent fields to persist.
 * @return {Promise<object>} The updated agent.
 */
export async function updateAgent(id, payload) {
	const response = await axios.put(generateUrl(`${AGENTS_BASE}/${id}`), payload)
	return response.data
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
