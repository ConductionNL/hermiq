// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The per-department AI switch (oversight-switch-ai-off-per-department).
 *
 * Administrators only; the server answers 403 to anyone else.
 *
 * @spec openspec/changes/oversight-switch-ai-off-per-department/specs/human-approval-gate/spec.md
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/** Hermiq per-department switch base path. */
const DEPARTMENT_CONTROL_BASE = '/apps/hermiq/api/department-control'

/**
 * List the departments with their state, who switched them and when, agent
 * count, last run and the runs refused since the switch-off.
 *
 * @return {Promise<Array<object>>} The department rows.
 * @spec openspec/changes/oversight-switch-ai-off-per-department/specs/human-approval-gate/spec.md#requirement-an-admin-shall-be-able-to-download-evidence-that-ai-was-off-for-a-department
 */
export async function listDepartments() {
	const response = await axios.get(generateUrl(DEPARTMENT_CONTROL_BASE))
	const results = response.data?.results
	return Array.isArray(results) ? results : []
}

/**
 * Switch AI off or on for one department.
 *
 * @param {string} group The department's group id.
 * @param {boolean} off True to switch AI off.
 * @param {string} reason Why, when switching off.
 * @return {Promise<object>} The department as stored.
 * @spec openspec/changes/oversight-switch-ai-off-per-department/specs/human-approval-gate/spec.md#requirement-an-admin-shall-be-able-to-switch-ai-off-for-one-department
 */
export async function toggleDepartment(group, off, reason) {
	const response = await axios.post(
		generateUrl(`${DEPARTMENT_CONTROL_BASE}/${encodeURIComponent(group)}/toggle`),
		{ off: off ? 'true' : 'false', reason: reason || '' },
	)
	return response.data
}

/**
 * The download address of a department's evidence file.
 *
 * @param {string} group The department's group id.
 * @return {string} The URL.
 * @spec openspec/changes/oversight-switch-ai-off-per-department/specs/human-approval-gate/spec.md#requirement-an-admin-shall-be-able-to-download-evidence-that-ai-was-off-for-a-department
 */
export function departmentEvidenceUrl(group) {
	return generateUrl(`${DEPARTMENT_CONTROL_BASE}/${encodeURIComponent(group)}/evidence`)
}
