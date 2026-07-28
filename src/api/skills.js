// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Plain (non-Pinia) API helper for the skills-catalog surface. Thin Hermiq controllers
// (SkillController) that manage tenant-scoped Skill objects through OpenRegister — not the
// generic createObjectStore object path — so we hit the resources directly.
//
// Deliberately stateless functions (no defineStore) — the hard rule is "no custom Pinia
// stores". axios from @nextcloud/axios adds the CSRF requesttoken. Mirrors
// src/api/memory.js.

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/** Hermiq skills API base. */
const SKILLS_BASE = '/apps/hermiq/api/skills'

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
 * List the tenant's skills.
 *
 * @return {Promise<Array<object>>} The Skill objects.
 */
export async function listSkills() {
	const response = await axios.get(generateUrl(SKILLS_BASE))
	return toList(response.data)
}

/**
 * Import an agentskills.io package into a new Skill.
 *
 * @param {string} pkg The agentskills.io package string.
 * @return {Promise<object>} The imported Skill.
 */
export async function importSkill(pkg) {
	const response = await axios.post(generateUrl(SKILLS_BASE), { package: pkg })
	return response.data
}

/**
 * Export a Skill back to an agentskills.io package.
 *
 * @param {string} id The Skill UUID.
 * @return {Promise<string>} The package string.
 */
export async function exportSkill(id) {
	const response = await axios.get(generateUrl(`${SKILLS_BASE}/${id}/export`))
	return response.data?.package || ''
}

/**
 * Install a Skill onto an agent (records the agent on installedOn).
 *
 * @param {string} id The Skill UUID.
 * @param {string} agentId The agent UUID.
 * @return {Promise<object>} The updated Skill.
 */
export async function installSkill(id, agentId) {
	const response = await axios.post(generateUrl(`${SKILLS_BASE}/${id}/install`), { agentId })
	return response.data
}

/**
 * Detach a Skill from an agent (removes the agent from installedOn and the skill from
 * the agent's skillInstalls — the mirror of installSkill).
 *
 * @param {string} id The Skill UUID.
 * @param {string} agentId The agent UUID.
 * @return {Promise<object>} The updated Skill.
 */
export async function uninstallSkill(id, agentId) {
	const response = await axios.delete(generateUrl(`${SKILLS_BASE}/${id}/install/${agentId}`))
	return response.data
}

/**
 * Update a Skill via the guarded hermiq merge path (skill-maturity). The server
 * applies the computed-maturity write guard: client-supplied `maturityLevel` /
 * `levelEvidence.l1`–`l4` are ignored and stored values carried forward, while
 * `targetLevel` and ordinary fields stay editable — the reason edits go through
 * this endpoint instead of the generic OpenRegister object PUT.
 *
 * @param {string} id The Skill UUID.
 * @param {object} skill The full merge payload (stored fields spread first).
 * @return {Promise<object>} The updated Skill.
 */
export async function updateSkill(id, skill) {
	const response = await axios.put(generateUrl(`${SKILLS_BASE}/${id}`), skill)
	return response.data
}

/**
 * Qualify a skill (skill-maturity): recompute its L1–L7 maturity from content +
 * evidence, persist it, and return the seven-level scorecard. Owner-guarded
 * server-side (404 for a skill the caller does not own).
 *
 * @param {string} id The Skill UUID.
 * @return {Promise<object>} `{ skillId, maturityLevel, targetLevel, scorecard }`.
 */
export async function qualifySkill(id) {
	const response = await axios.post(generateUrl(`${SKILLS_BASE}/${id}/qualify`))
	return response.data
}

/**
 * Attest a skill's L4 (personalization) level (skill-maturity) — a curator act
 * gated by the `skill.attest-maturity` action (ADR-023); 403 when the caller's
 * groups are not mapped to it.
 *
 * @param {string} id The Skill UUID.
 * @param {string} note Optional curator note on what the personalization covers.
 * @return {Promise<object>} The refreshed `{ skillId, maturityLevel, targetLevel, scorecard }`.
 */
export async function attestSkillL4(id, note = '') {
	const response = await axios.post(generateUrl(`${SKILLS_BASE}/${id}/attest-l4`), { note })
	return response.data
}

/**
 * Install a skill from an external source (another org / hub) into quarantine
 * (skills-marketplace). The skill is NOT usable until it passes the review gate.
 *
 * @param {string} pkg The agentskills.io package string.
 * @param {string} source The source ('org' | 'hub').
 * @return {Promise<object>} The quarantined Skill.
 */
export async function installFromSource(pkg, source = 'hub') {
	const response = await axios.post(generateUrl(`${SKILLS_BASE}/install-from-source`), { package: pkg, source })
	return response.data
}

/**
 * Approve a quarantined skill — the review gate → active (skills-marketplace).
 *
 * @param {string} id The Skill UUID.
 * @return {Promise<object>} The updated Skill.
 */
export async function approveSkill(id) {
	const response = await axios.post(generateUrl(`${SKILLS_BASE}/${id}/approve`))
	return response.data
}

/**
 * Publish a skill to an external hub via OpenConnector (skills-marketplace).
 *
 * @param {string} id The Skill UUID.
 * @param {string} hubId The target hub id.
 * @return {Promise<object>} The publish result (or a structured seam error).
 */
export async function publishSkill(id, hubId = 'default') {
	const response = await axios.post(generateUrl(`${SKILLS_BASE}/${id}/publish`), { hubId })
	return response.data
}

/**
 * Search GitHub for `topic:hermiq-skill` repos (hermiq-github-store), the skill
 * counterpart of `searchGithubTemplates` in `agentTemplates.js`. Anonymous by
 * default; upgraded through the OpenRegister credential broker when a
 * `github`-provider `credentialId` is supplied. Degrades to
 * `{ outcome: 'github_rate_limited'|'github_unreachable', cards: [] }` rather than
 * throwing — never a 5xx for a third-party outage. Every returned card carries
 * `kind: 'skill'`.
 *
 * @param {string} query Optional free-text term narrowing the search.
 * @param {string|null} credentialId Optional broker `github` credential UUID.
 * @return {Promise<object>} `{ outcome, cards, brokerCredentialAvailable, brokerUsed, rateLimited }`.
 */
export async function searchGithubSkills(query = '', credentialId = null) {
	const params = {}
	if (query) {
		params.q = query
	}
	if (credentialId) {
		params.credentialId = credentialId
	}
	const response = await axios.get(generateUrl(`${SKILLS_BASE}/github/search`), { params })
	return response.data
}

/**
 * Install a discovered GitHub skill. Fetches the repo's agentskills.io package
 * file and installs it through the SAME quarantine + content-scan path a
 * pasted/hub-sourced skill already goes through (`installFromSource(source:
 * 'hub')`, unchanged) — the created skill always lands `state: "quarantined"`.
 *
 * @param {object} target The install target.
 * @param {string} target.owner The repo owner.
 * @param {string} target.repo The repo name.
 * @param {string|null} [target.ref] Optional git ref (branch/tag/sha).
 * @param {string|null} [target.credentialId] Optional broker `github` credential UUID.
 * @return {Promise<object>} The created (quarantined) skill.
 */
export async function installGithubSkill({ owner, repo, ref = null, credentialId = null }) {
	const response = await axios.post(generateUrl(`${SKILLS_BASE}/github/install`), { owner, repo, ref, credentialId })
	return response.data
}

/**
 * Publish an existing skill to a new GitHub repository tagged
 * `topic:hermiq-skill`, in agentskills.io format (hermiq-github-store — the new
 * primary publish path; `publishSkill` above via OpenConnector remains
 * secondary). Requires a broker `github` credential — the GitHub token never
 * reaches Hermiq. On success the skill's `githubOwner`/`githubRepo`/
 * `publishedAt` provenance fields are updated.
 *
 * @param {string} id The Skill UUID.
 * @param {object} target The publish target.
 * @param {string} target.owner The target GitHub owner (user or organisation).
 * @param {string} target.repo The target repository name.
 * @param {string} [target.visibility] Repository visibility (`public`|`private`, defaults `private`).
 * @param {string} target.credentialId The broker `github` credential UUID (required).
 * @return {Promise<object>} `{ repoUrl, commitSha }`.
 */
export async function publishSkillToGithub(id, { owner, repo, visibility = 'private', credentialId }) {
	const response = await axios.post(generateUrl(`${SKILLS_BASE}/${id}/github/publish`), { owner, repo, visibility, credentialId })
	return response.data
}

/**
 * Propose a consolidation draft manually (skill-self-improvement, trigger (c)).
 * Owner-guarded; the kill-switch/budget/one-open-draft gates apply exactly as in
 * the background job — an existing open draft is returned as a pointer (200).
 *
 * @param {string} id The Skill UUID.
 * @return {Promise<object>} `{ status, draft }`.
 */
export async function proposeSkillImprovement(id) {
	const response = await axios.post(generateUrl(`${SKILLS_BASE}/${id}/propose-improvement`))
	return response.data
}

/**
 * List a skill's consolidation drafts, newest first (skill-self-improvement).
 *
 * @param {string} id The Skill UUID.
 * @return {Promise<Array<object>>} The SkillDraft objects.
 */
export async function listSkillDrafts(id) {
	const response = await axios.get(generateUrl(`${SKILLS_BASE}/${id}/drafts`))
	return toList(response.data)
}

/**
 * Edit-then-accept content update on a draft (skill-self-improvement) — the
 * SkillDetail-only surface. The edit INVALIDATES the stored scan/eval evidence
 * and re-runs pre-qualification; the linked Approval is not approvable from any
 * surface until it passes.
 *
 * @param {string} draftId The SkillDraft UUID.
 * @param {object} content The replacement content.
 * @param {string} [content.frontmatter] Replacement frontmatter (omit = keep).
 * @param {string} [content.body] Replacement body (omit = keep).
 * @param {Array<object>} [content.files] Replacement files (omit = keep).
 * @return {Promise<object>} The re-qualified draft.
 */
export async function updateSkillDraftContent(draftId, content) {
	const response = await axios.post(generateUrl(`/apps/hermiq/api/skill-drafts/${draftId}/content`), content)
	return response.data
}

/**
 * Accept a draft (skill-self-improvement) — decides by transitioning the draft's
 * linked Approval to `approved`; the apply step fires on THAT transition (the
 * identical path a generic-inbox approval runs) and writes the new skill version.
 * Gated by the `skill.review-draft` action (ADR-023).
 *
 * @param {string} draftId The SkillDraft UUID.
 * @return {Promise<object>} `{ status, versionId, draft }`.
 */
export async function acceptSkillDraft(draftId) {
	const response = await axios.post(generateUrl(`/apps/hermiq/api/skill-drafts/${draftId}/accept`))
	return response.data
}

/**
 * Reject a draft (skill-self-improvement), optionally marking specific driving
 * learnings entries as bad — marked refs never drive the skill's next proposal
 * (recorded on the DRAFT, never as an edit to learnings.md).
 *
 * @param {string} draftId The SkillDraft UUID.
 * @param {string} [note] Optional rejection note.
 * @param {Array<string>} [rejectedLearningRefs] Learnings entry refs marked bad.
 * @return {Promise<object>} `{ status, draft }`.
 */
export async function rejectSkillDraft(draftId, note = '', rejectedLearningRefs = []) {
	const response = await axios.post(
		generateUrl(`/apps/hermiq/api/skill-drafts/${draftId}/reject`),
		{ note, rejectedLearningRefs },
	)
	return response.data
}

/**
 * List a skill's version history (skill-self-improvement): the skill's
 * OpenRegister AuditTrail create/update entries, newest first.
 *
 * @param {string} id The Skill UUID.
 * @return {Promise<Array<object>>} The versions `{ id, timestamp, user, action, changedFields }`.
 */
export async function listSkillVersions(id) {
	const response = await axios.get(generateUrl(`${SKILLS_BASE}/${id}/versions`))
	return toList(response.data)
}

/**
 * Field-level diff between two skill versions, limited to the versioned content
 * plane (frontmatter/body/files) — a state/provenance change never appears.
 *
 * @param {string} id The Skill UUID.
 * @param {string} from The "old" version id.
 * @param {string} to The "new" version id.
 * @return {Promise<object>} Map of field → `{ old, new }`.
 */
export async function diffSkillVersions(id, from, to) {
	const response = await axios.get(generateUrl(`${SKILLS_BASE}/${id}/versions/diff`), { params: { from, to } })
	return response.data?.diff || {}
}

/**
 * Roll the skill back to a previous version's content — as a brand-NEW version
 * (history never mutated; non-versioned fields keep their current values).
 * Always an explicit human action.
 *
 * @param {string} id The Skill UUID.
 * @param {string} versionId The target version id.
 * @return {Promise<object>} `{ status, versionId }`.
 */
export async function rollbackSkill(id, versionId) {
	const response = await axios.post(generateUrl(`${SKILLS_BASE}/${id}/rollback`), { versionId })
	return response.data
}

/**
 * One-click republish to the skill's OWN provenance-stamped GitHub repository
 * (skill-self-improvement) — the same publish action authorization as first
 * publish, never automatic; ships learnings.md, strips learning-candidates.md,
 * restamps publishedAt (clearing the behind-badge).
 *
 * @param {string} id The Skill UUID.
 * @param {string} credentialId The broker `github` credential UUID (required).
 * @return {Promise<object>} `{ repoUrl, commitSha }`.
 */
export async function republishSkill(id, credentialId) {
	const response = await axios.post(generateUrl(`${SKILLS_BASE}/${id}/republish`), { credentialId })
	return response.data
}
