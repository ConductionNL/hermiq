// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Plain (non-Pinia) API helper for an agent's curated RELATED FILES surface — a
// Claude-project-style list of existing Nextcloud files the agent can scan and use,
// backed by the Context system (ADR-024) via a thin Hermiq controller
// (AgentFilesController) rather than the generic createObjectStore object path. Distinct
// from chat attachments (per-turn uploads).
//
// Deliberately stateless functions (no defineStore) — the hard rule is "no custom Pinia
// stores". axios from @nextcloud/axios adds the CSRF requesttoken. Mirrors src/api/memory.js.

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/** Hermiq agents API base (related-files endpoints hang off an agent). */
const AGENTS_BASE = '/apps/hermiq/api/agents'

/**
 * Normalise the Hermiq files envelope to a plain array.
 *
 * @param {object} data The response body.
 * @return {Array<object>} The extracted file list (empty array on miss).
 */
function toFiles(data) {
	if (Array.isArray(data?.files)) {
		return data.files
	}
	return []
}

/**
 * List an agent's related files (never creates the bundle on a read).
 *
 * @param {string} agentId The agent UUID.
 * @return {Promise<Array<object>>} The related files ([{ path, name, description }]).
 */
export async function listAgentFiles(agentId) {
	const response = await axios.get(generateUrl(`${AGENTS_BASE}/${agentId}/files`))
	return toFiles(response.data)
}

/**
 * Relate an existing Nextcloud file to an agent (find-or-creates the bundle; deduped by path).
 *
 * @param {string} agentId The agent UUID.
 * @param {object} file The file ref.
 * @param {string} file.path The file path relative to the acting user's Nextcloud folder.
 * @param {string} [file.name] The display name (server derives from the basename anyway).
 * @param {string} [file.description] An optional note on why the file is included.
 * @return {Promise<Array<object>>} The updated related-file list.
 */
export async function addAgentFile(agentId, { path, name, description } = {}) {
	const response = await axios.post(generateUrl(`${AGENTS_BASE}/${agentId}/files`), { path, name, description })
	return toFiles(response.data)
}

/**
 * Remove a related file from an agent by path (idempotent — a missing path still returns 200).
 *
 * @param {string} agentId The agent UUID.
 * @param {string} path The file path to unrelate.
 * @return {Promise<Array<object>>} The updated related-file list.
 */
export async function removeAgentFile(agentId, path) {
	const response = await axios.delete(generateUrl(`${AGENTS_BASE}/${agentId}/files`), { data: { path } })
	return toFiles(response.data)
}
