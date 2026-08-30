// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Plain (non-Pinia) API helper for the agent-memory surface. These are thin Hermiq
// controllers (MemoryController) that manage an agent's Memory / UserProfile objects
// tenant-scoped through OpenRegister — not the generic createObjectStore object path —
// so we hit the resources directly.
//
// The Session / SessionTurn readers (listSessions, recall) went with the Sessions page:
// nothing else called them, so they were exports pointing at endpoints no UI reaches.
// MemoryController::sessions() and ::recall() and their routes are still there — see
// the note in the change that dropped the page.
//
// Deliberately stateless functions (no defineStore) — the hard rule is "no custom Pinia
// stores". axios from @nextcloud/axios adds the CSRF requesttoken. Mirrors
// src/api/approvals.js.

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/** Hermiq agents API base (memory endpoints hang off an agent). */
const AGENTS_BASE = '/apps/hermiq/api/agents'

/**
 * Normalise the Hermiq list envelope to a plain array.
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
	return []
}

/**
 * Get an agent's Memory object (tenant-scoped; created empty if none exists).
 *
 * @param {string} agentId The agent UUID.
 * @return {Promise<object>} The Memory payload ({ entries, charBudget, needsConsolidation, … }).
 */
export async function getMemory(agentId) {
	const response = await axios.get(generateUrl(`${AGENTS_BASE}/${agentId}/memory`))
	return response.data
}

/**
 * Append a fact to an agent's Memory (char-budget-aware; flags consolidation over budget).
 *
 * @param {string} agentId The agent UUID.
 * @param {string} text The fact to remember.
 * @return {Promise<object>} The updated Memory payload.
 */
export async function addMemory(agentId, text) {
	const response = await axios.post(
		generateUrl(`${AGENTS_BASE}/${agentId}/memory`),
		{ text },
	)
	return response.data
}

/**
 * Consolidate an agent's Memory. With no entries the server de-duplicates the current set.
 *
 * @param {string} agentId The agent UUID.
 * @param {Array<object>|null} entries Optional consolidated entries ([{ text, createdAt }]).
 * @return {Promise<object>} The consolidated Memory payload.
 */
export async function consolidateMemory(agentId, entries = null) {
	const body = Array.isArray(entries) ? { entries } : {}
	const response = await axios.post(
		generateUrl(`${AGENTS_BASE}/${agentId}/memory/consolidate`),
		body,
	)
	return response.data
}

/**
 * List an agent's UserProfiles (tenant-scoped).
 *
 * @param {string} agentId The agent UUID.
 * @return {Promise<Array<object>>} The UserProfile objects.
 */
export async function listUserProfiles(agentId) {
	const response = await axios.get(
		generateUrl(`${AGENTS_BASE}/${agentId}/user-profiles`),
	)
	return toList(response.data)
}
