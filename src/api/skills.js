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
