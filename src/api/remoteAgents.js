// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Plain (non-Pinia) API helper for the remote agents an admin registers
// (flows-hand-off-and-wait D5): each is reached only through an integriq source,
// whose credential lives in the broker, never in hermiq. RemoteAgentController
// refuses anyone who is not an admin.
//
// @spec openspec/changes/flows-hand-off-and-wait/specs/remote-agents/spec.md#requirement-an-admin-registers-a-remote-agent-through-an-integriq-source-req-a2a-001

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/** Hermiq remote agents path. */
const REMOTE_AGENTS = '/apps/hermiq/api/remote-agents'

/**
 * Read the registered remote agents.
 *
 * @return {Promise<Array<object>>} The remote agents.
 * @spec openspec/changes/flows-hand-off-and-wait/specs/remote-agents/spec.md#requirement-an-admin-registers-a-remote-agent-through-an-integriq-source-req-a2a-001
 */
export async function listRemoteAgents() {
	const response = await axios.get(generateUrl(REMOTE_AGENTS))
	return Array.isArray(response.data?.results) ? response.data.results : []
}

/**
 * Register a remote agent: the server fetches its card once through the source.
 *
 * @param {{name: string, slug: string, description: string, source: string}} fields The fields.
 * @return {Promise<object>} The stored remote agent, switched off.
 * @spec openspec/changes/flows-hand-off-and-wait/specs/remote-agents/spec.md#requirement-an-admin-registers-a-remote-agent-through-an-integriq-source-req-a2a-001
 */
export async function registerRemoteAgent(fields) {
	const response = await axios.post(generateUrl(REMOTE_AGENTS), fields)
	return response.data
}

/**
 * Change a remote agent's name, description or whether agents may use it.
 *
 * @param {string} id The remote agent.
 * @param {{name?: string, description?: string, enabled?: boolean}} fields The fields to change.
 * @return {Promise<object>} The stored remote agent.
 * @spec openspec/changes/flows-hand-off-and-wait/specs/remote-agents/spec.md#requirement-an-admin-registers-a-remote-agent-through-an-integriq-source-req-a2a-001
 */
export async function updateRemoteAgent(id, fields) {
	const response = await axios.put(generateUrl(`${REMOTE_AGENTS}/${encodeURIComponent(id)}`), fields)
	return response.data
}

/**
 * Remove a remote agent.
 *
 * @param {string} id The remote agent.
 * @return {Promise<void>}
 * @spec openspec/changes/flows-hand-off-and-wait/specs/remote-agents/spec.md#requirement-an-admin-registers-a-remote-agent-through-an-integriq-source-req-a2a-001
 */
export async function deleteRemoteAgent(id) {
	await axios.delete(generateUrl(`${REMOTE_AGENTS}/${encodeURIComponent(id)}`))
}

/**
 * The integriq sources a remote agent can be reached through, read from
 * OpenRegister with the admin's own session.
 *
 * @return {Promise<Array<{id: string, label: string}>>} The sources.
 * @spec openspec/changes/flows-hand-off-and-wait/specs/remote-agents/spec.md#requirement-an-admin-registers-a-remote-agent-through-an-integriq-source-req-a2a-001
 */
export async function listIntegriqSources() {
	const response = await axios.get(generateUrl('/apps/openregister/api/objects/integriq/source'), { params: { _limit: 200 } })
	const rows = Array.isArray(response.data?.results) ? response.data.results : []
	return rows
		.map((row) => {
			const id = String(row.id ?? row['@self']?.id ?? row.uuid ?? '')
			return { id, label: String(row.name ?? row.title ?? id) }
		})
		.filter((source) => source.id !== '')
}
