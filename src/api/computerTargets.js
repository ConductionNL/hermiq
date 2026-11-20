// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Plain (non-Pinia) API helper for the admin-only computer targets
// (tools-browser-and-computer-use, REQ-COMPUTER-001): the machines an agent may
// operate, each reached only through an integriq source. ComputerTargetController
// refuses everyone but an admin. A test screenshot is returned to show, never stored.
//
// @spec openspec/changes/tools-browser-and-computer-use/specs/computer-use/spec.md#requirement-an-admin-registers-a-machine-reached-only-through-integriq-req-computer-001

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/** The admin computer targets API base. */
const TARGETS_BASE = '/apps/hermiq/api/settings/computer-targets'

/**
 * The registered computer targets.
 *
 * @return {Promise<Array<object>>} The targets: { id, name, description, source, os, applications, enabled }.
 * @spec openspec/changes/tools-browser-and-computer-use/specs/computer-use/spec.md#requirement-an-admin-registers-a-machine-reached-only-through-integriq-req-computer-001
 */
export async function listComputerTargets() {
	const response = await axios.get(generateUrl(TARGETS_BASE))
	return Array.isArray(response.data?.results) ? response.data.results : []
}

/**
 * Register a computer target, or change one when it has an id.
 *
 * @param {object} target { id?, name, description, source, applications, enabled }.
 * @return {Promise<object>} The saved target.
 * @spec openspec/changes/tools-browser-and-computer-use/specs/computer-use/spec.md#requirement-an-admin-registers-a-machine-reached-only-through-integriq-req-computer-001
 */
export async function saveComputerTarget(target) {
	const { id, ...fields } = target
	const response = id
		? await axios.put(generateUrl(`${TARGETS_BASE}/${id}`), fields)
		: await axios.post(generateUrl(TARGETS_BASE), fields)
	return response.data
}

/**
 * Remove a computer target.
 *
 * @param {string} id The target UUID.
 * @return {Promise<void>}
 * @spec openspec/changes/tools-browser-and-computer-use/specs/computer-use/spec.md#requirement-an-admin-registers-a-machine-reached-only-through-integriq-req-computer-001
 */
export async function deleteComputerTarget(id) {
	await axios.delete(generateUrl(`${TARGETS_BASE}/${id}`))
}

/**
 * Take a test screenshot of a target through its integriq source.
 *
 * @param {string} id The target UUID.
 * @return {Promise<object>} { image (base64), mimeType, width, height }.
 * @spec openspec/changes/tools-browser-and-computer-use/specs/computer-use/spec.md#requirement-an-admin-registers-a-machine-reached-only-through-integriq-req-computer-001
 */
export async function takeTestScreenshot(id) {
	const response = await axios.post(generateUrl(`${TARGETS_BASE}/${id}/screenshot`))
	return response.data
}

/**
 * The integriq sources a target can be reached through, read from OpenRegister
 * with the admin's own session.
 *
 * @return {Promise<Array<{id: string, label: string}>>} The sources.
 * @spec openspec/changes/tools-browser-and-computer-use/specs/computer-use/spec.md#requirement-an-admin-registers-a-machine-reached-only-through-integriq-req-computer-001
 */
export async function listIntegriqSources() {
	const response = await axios.get(generateUrl('/apps/openregister/api/objects/integriq/source'), { params: { _limit: 200 } })
	const rows = Array.isArray(response.data?.results) ? response.data.results : []
	return rows.map((row) => {
		const id = String(row.id ?? row['@self']?.id ?? row.uuid ?? '')
		return { id, label: String(row.name ?? row.title ?? id) }
	}).filter((source) => source.id !== '')
}
