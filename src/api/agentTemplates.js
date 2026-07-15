// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Plain (non-Pinia) API helper for the agent-template-gallery surface. Thin Hermiq
// controller (AgentTemplateController) that manages tenant-scoped AgentTemplate objects
// through OpenRegister — not the generic createObjectStore object path — so we hit the
// resources directly.
//
// Deliberately stateless functions (no defineStore) — the hard rule is "no custom Pinia
// stores". axios from @nextcloud/axios adds the CSRF requesttoken. Mirrors src/api/skills.js.

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/** Hermiq agent-templates API base. */
const TEMPLATES_BASE = '/apps/hermiq/api/agent-templates'

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
 * List the tenant's agent templates.
 *
 * @return {Promise<Array<object>>} The AgentTemplate objects.
 */
export async function listAgentTemplates() {
	const response = await axios.get(generateUrl(TEMPLATES_BASE))
	return toList(response.data)
}

/**
 * Get a single agent template.
 *
 * @param {string} id The AgentTemplate UUID.
 * @return {Promise<object>} The template.
 */
export async function getAgentTemplate(id) {
	const response = await axios.get(generateUrl(`${TEMPLATES_BASE}/${id}`))
	return response.data
}

/**
 * Author a new template directly (always active/local, never scanned).
 *
 * @param {object} payload The template fields.
 * @return {Promise<object>} The created template.
 */
export async function createAgentTemplate(payload) {
	const response = await axios.post(generateUrl(TEMPLATES_BASE), payload)
	return response.data
}

/**
 * Update an existing template's fields.
 *
 * @param {string} id The AgentTemplate UUID.
 * @param {object} payload The field updates.
 * @return {Promise<object>} The updated template.
 */
export async function updateAgentTemplate(id, payload) {
	const response = await axios.put(generateUrl(`${TEMPLATES_BASE}/${id}`), payload)
	return response.data
}

/**
 * Delete a template.
 *
 * @param {string} id The AgentTemplate UUID.
 * @return {Promise<object>} The (empty) response body.
 */
export async function deleteAgentTemplate(id) {
	const response = await axios.delete(generateUrl(`${TEMPLATES_BASE}/${id}`))
	return response.data
}

/**
 * Export an existing Agent to a portable template package (no secrets/tenant fields).
 *
 * @param {string} agentId The Agent UUID.
 * @return {Promise<string>} The JSON package string.
 */
export async function exportAgentToTemplate(agentId) {
	const response = await axios.get(generateUrl(`${TEMPLATES_BASE}/from-agent/${agentId}/export`))
	return response.data?.package || ''
}

/**
 * Export an existing template's own fields to a shareable JSON package (the read-only
 * counterpart to importAgentTemplate — hand a locally-authored template to another org/hub).
 *
 * @param {string} id The AgentTemplate UUID.
 * @return {Promise<string>} The JSON package string.
 */
export async function exportAgentTemplate(id) {
	const response = await axios.get(generateUrl(`${TEMPLATES_BASE}/${id}/export`))
	return response.data?.package || ''
}

/**
 * Import a package into a new template. `source='org'|'hub'` lands quarantined +
 * content-scanned; `source='local'` is saved active with no scan.
 *
 * @param {string} pkg The JSON package string.
 * @param {string} source The import source ('local' | 'org' | 'hub').
 * @return {Promise<object>} The imported template.
 */
export async function importAgentTemplate(pkg, source = 'org') {
	const response = await axios.post(generateUrl(`${TEMPLATES_BASE}/import`), { package: pkg, source })
	return response.data
}

/**
 * Approve a quarantined template — the review gate → active.
 *
 * @param {string} id The AgentTemplate UUID.
 * @param {boolean} force Override a dangerous scan verdict (a conscious reviewer decision).
 * @return {Promise<object>} The updated template.
 */
export async function approveAgentTemplate(id, force = false) {
	const response = await axios.post(generateUrl(`${TEMPLATES_BASE}/${id}/approve`), { force })
	return response.data
}

/**
 * "Use this template" — instantiate a template into a real Agent in the caller's
 * organisation. The response reports any model coercion and unresolved skill refs, and
 * carries the suggestedSchedule hint verbatim (no Schedule is ever created here).
 *
 * @param {string} id The AgentTemplate UUID.
 * @param {object} overrides Caller-supplied field overrides for the created Agent.
 * @return {Promise<object>} The instantiate result.
 */
export async function instantiateAgentTemplate(id, overrides = {}) {
	const response = await axios.post(generateUrl(`${TEMPLATES_BASE}/${id}/instantiate`), { overrides })
	return response.data
}

/**
 * Search GitHub for `topic:hermiq-agent-template` repos (agent-template-github-store).
 * Anonymous by default; upgraded through the OpenRegister credential broker when a
 * `github`-provider `credentialId` is supplied (raises the anonymous rate limit,
 * reaches private repos). Degrades to `{ outcome: 'github_rate_limited'|'github_unreachable',
 * cards: [] }` rather than throwing — the caller never sees a 5xx for a third-party outage.
 *
 * @param {string} query Optional free-text term narrowing the search.
 * @param {string|null} credentialId Optional broker `github` credential UUID.
 * @return {Promise<object>} `{ outcome, cards, brokerCredentialAvailable, brokerUsed, rateLimited }`.
 */
export async function searchGithubTemplates(query = '', credentialId = null) {
	const params = {}
	if (query) {
		params.q = query
	}
	if (credentialId) {
		params.credentialId = credentialId
	}
	const response = await axios.get(generateUrl(`${TEMPLATES_BASE}/github/search`), { params })
	return response.data
}

/**
 * Install a discovered GitHub template. Fetches the repo's portable package file and
 * imports it through the SAME quarantine + content-scan path a pasted hub package
 * already goes through (`source: 'hub'`) — the created template always lands
 * `state: "quarantined"`.
 *
 * @param {object} target The install target.
 * @param {string} target.owner The repo owner.
 * @param {string} target.repo The repo name.
 * @param {string|null} [target.ref] Optional git ref (branch/tag/sha).
 * @param {string|null} [target.credentialId] Optional broker `github` credential UUID.
 * @return {Promise<object>} The created (quarantined) template.
 */
export async function installGithubTemplate({ owner, repo, ref = null, credentialId = null }) {
	const response = await axios.post(generateUrl(`${TEMPLATES_BASE}/github/install`), { owner, repo, ref, credentialId })
	return response.data
}

/**
 * Publish an existing template to a new GitHub repository tagged
 * `topic:hermiq-agent-template`. Requires a broker `github` credential — the GitHub
 * token never reaches Hermiq. On success the template's `githubOwner`/`githubRepo`/
 * `publishedAt` provenance fields are updated.
 *
 * @param {string} id The AgentTemplate UUID.
 * @param {object} target The publish target.
 * @param {string} target.owner The target GitHub owner (user or organisation).
 * @param {string} target.repo The target repository name.
 * @param {string} [target.visibility] Repository visibility (`public`|`private`, defaults `private`).
 * @param {string} target.credentialId The broker `github` credential UUID (required).
 * @return {Promise<object>} `{ repoUrl, commitSha }`.
 */
export async function publishAgentTemplateToGithub(id, { owner, repo, visibility = 'private', credentialId }) {
	const response = await axios.post(generateUrl(`${TEMPLATES_BASE}/${id}/publish-github`), { owner, repo, visibility, credentialId })
	return response.data
}
