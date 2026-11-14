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
import { listOrResults as toList } from './listEnvelope.js'

/** Hermiq agents API base (memory endpoints hang off an agent). */
const AGENTS_BASE = '/apps/hermiq/api/agents'

/**
 * The request config naming a project layer, or none for the shared memory.
 *
 * @param {string} [project] The project key, or '' for the shared memory.
 * @return {Array<object>} `[]`, or `[{ params: { project } }]`.
 */
function projectConfig(project) {
	return project ? [{ params: { project } }] : []
}

/**
 * Get an agent's Memory object (tenant-scoped; created empty if none exists):
 * the shared memory, or one project's (memory-project-scopes).
 *
 * @param {string} agentId The agent UUID.
 * @param {string} [project] The project key; omitted or '' for the shared memory.
 * @return {Promise<object>} The Memory payload ({ entries, charBudget, needsConsolidation, … }).
 * @spec openspec/changes/memory-project-scopes/specs/agent-memory/spec.md#requirement-an-owner-sees-and-moves-entries-between-layers-req-pmem-004
 */
export async function getMemory(agentId, project = '') {
	const response = await axios.get(
		generateUrl(`${AGENTS_BASE}/${agentId}/memory`),
		...projectConfig(project),
	)
	return response.data
}

/**
 * The projects an agent keeps a memory for.
 *
 * @param {string} agentId The agent UUID.
 * @return {Promise<Array<{projectKey: string, projectName: string}>>} The projects, by name.
 * @spec openspec/changes/memory-project-scopes/specs/agent-memory/spec.md#requirement-an-agent-has-one-shared-memory-and-a-memory-per-project-req-pmem-001
 */
export async function listMemoryProjects(agentId) {
	const response = await axios.get(
		generateUrl(`${AGENTS_BASE}/${agentId}/memory/projects`),
	)
	const projects = response.data?.projects
	return Array.isArray(projects) ? projects : []
}

/**
 * Move one entry between the shared memory and a project memory (owner only).
 *
 * @param {string} agentId The agent UUID.
 * @param {string} entryId The entry id.
 * @param {string} from The project the entry is in, or '' for the shared memory.
 * @param {string} to The project to move it to, or '' for the shared memory.
 * @return {Promise<object>} The memory the entry is now in.
 * @spec openspec/changes/memory-project-scopes/specs/agent-memory/spec.md#requirement-an-owner-sees-and-moves-entries-between-layers-req-pmem-004
 */
export async function moveMemoryEntry(agentId, entryId, from, to) {
	const response = await axios.post(
		generateUrl(`${AGENTS_BASE}/${agentId}/memory/entries/${entryId}/move`),
		{ from: from || '', to: to || '' },
	)
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
 * Correct one memory entry (owner only). The old entry is kept as forgotten and
 * the new text becomes a new entry.
 *
 * @param {string} agentId The agent UUID.
 * @param {string} entryId The entry id.
 * @param {string} text The corrected text.
 * @param {string} [project] The project memory the entry is in; '' for the shared memory.
 * @return {Promise<object>} The updated memory.
 * @spec openspec/specs/agent-memory/spec.md#requirement-an-owner-can-correct-a-remembered-fact-req-memedit-001
 */
export async function correctMemoryEntry(agentId, entryId, text, project = '') {
	const response = await axios.put(
		generateUrl(`${AGENTS_BASE}/${agentId}/memory/entries/${entryId}`),
		{ text },
		...projectConfig(project),
	)
	return response.data
}

/**
 * Make the agent forget one memory entry (owner only, a soft delete).
 *
 * @param {string} agentId The agent UUID.
 * @param {string} entryId The entry id.
 * @param {string} [project] The project memory the entry is in; '' for the shared memory.
 * @return {Promise<object>} The updated memory.
 * @spec openspec/specs/agent-memory/spec.md#requirement-an-owner-can-make-an-agent-forget-a-fact-req-memedit-002
 */
export async function forgetMemoryEntry(agentId, entryId, project = '') {
	const response = await axios.delete(
		generateUrl(`${AGENTS_BASE}/${agentId}/memory/entries/${entryId}`),
		...projectConfig(project),
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
