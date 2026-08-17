/**
 * The tool taxonomy: which cluster and verb each tool belongs to.
 *
 * Separate from `toolOversight.js` deliberately. That module answers what one
 * AGENT holds; this one answers where a tool BELONGS, which is the same for
 * every agent on the instance. Joining them client-side keeps either endpoint
 * from growing a dependency on the other.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * Fetch every tool with its owning app, subject group and CRUD verb.
 *
 * @return {Promise<Array<{name: string, app: string, group: string, right: string}>>} The taxonomy rows.
 */
export async function getToolTaxonomy() {
	const response = await axios.get(generateUrl('/apps/hermiq/api/agents/tools'))

	return response.data?.results ?? []
}

/**
 * The agent's grant strings, exactly as stored.
 *
 * 🔴 This is the AUTHORITATIVE list, and reading it from anywhere else destroys
 * grants. The obvious-looking alternative — reconstructing it from the
 * catalogue's `grantedBy` annotations — silently drops every grant the
 * catalogue cannot attribute: measured on a live agent, all 8 of its grants
 * came back `granted: true` with `grantedBy: null`, so a reconstruction kept
 * none of them and the next save wrote them away.
 *
 * @param {string} agentId The agent UUID.
 * @return {Promise<Array<string>>} The stored grant strings.
 */
export async function getAgentGrants(agentId) {
	const response = await axios.get(generateUrl(`/apps/hermiq/api/agents/${agentId}`))

	const tools = response.data?.tools
	return Array.isArray(tools) ? tools : []
}
