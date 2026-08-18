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
 * @return {Promise<Array<string>>} The stored grant ids.
 */
export async function getAgentGrants(agentId) {
	const response = await axios.get(
		generateUrl(`/apps/hermiq/api/agents/${agentId}`),
	)

	return flattenGrants(response.data?.tools)
}

/**
 * One stored entry as the grant string the backend round-trips.
 *
 * ⚠️ The CONSTRAINTS ARE PART OF THE GRANT, and dropping them widens it. An
 * entry `{id: "openregister.runFlow", args: {flowId: "A"}}` grants that ONE
 * flow; returning the bare id would hand back a grant for every flow, and the
 * next Save would persist that widening. Narrowing is the whole reason the
 * constrained form exists, so it cannot be lost in transit through a screen.
 *
 * @param {*} entry The stored entry — a bare id, or `{id, args}`.
 * @return {string} The grant string, or '' when the entry is unusable.
 */
function grantStringFor(entry) {
	if (typeof entry === 'string') {
		return entry
	}

	const id = entry?.id
	if (typeof id !== 'string' || id === '') {
		return ''
	}

	const args = entry?.args
	if (args === null || typeof args !== 'object' || Object.keys(args).length === 0) {
		return id
	}

	const pairs = Object.entries(args).map(
		([key, value]) => `${key}=${Array.isArray(value) ? `in:${value.join(',')}` : String(value)}`,
	)

	return `${id}?${pairs.join('&')}`
}

/**
 * Every granted tool id, from either stored shape.
 *
 * 🔴 `Agent.tools` is now a STRUCTURE — app → subject → action → tool id — and
 * the previous `Array.isArray(tools) ? tools : []` returned `[]` for it. That is
 * the worst possible failure for this particular function: every agent would
 * have read as having no grants, the matrix would have rendered every box
 * unticked, and the first Save would have written that emptiness back. A
 * silent, total revocation triggered by opening a screen.
 *
 * Both shapes are accepted, and the tool id is READ, never rebuilt from the
 * coordinates it is stored under: `hermiq.listFiles` lives at (hermiq, file,
 * list), and rebuilding gives `hermiq.file.list`, which is not a tool.
 *
 * @param {*} tools The stored `tools` value, in either shape.
 * @return {Array<string>} The grant ids.
 */
export function flattenGrants(tools) {
	if (Array.isArray(tools)) {
		return tools.filter((t) => typeof t === 'string' && t !== '')
	}

	if (tools === null || typeof tools !== 'object') {
		return []
	}

	const ids = []
	for (const subjects of Object.values(tools)) {
		if (subjects === null || typeof subjects !== 'object') { continue }
		for (const actions of Object.values(subjects)) {
			if (actions === null || typeof actions !== 'object') { continue }
			for (const entries of Object.values(actions)) {
				for (const entry of (Array.isArray(entries) ? entries : [entries])) {
					const id = grantStringFor(entry)
					if (id !== '') { ids.push(id) }
				}
			}
		}
	}

	return [...new Set(ids)]
}
