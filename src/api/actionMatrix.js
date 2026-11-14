// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The ADR-023 action matrix endpoints (action-matrix-screen), administrators
// only. axios from @nextcloud/axios adds the CSRF requesttoken.
//
// @spec openspec/changes/action-matrix-screen/specs/action-authorization/spec.md

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/** The action matrix base path. */
const ACTIONS_BASE = '/apps/hermiq/api/actions'

/**
 * Every action with its label, groups and changed mark, and the groups that exist.
 *
 * @return {Promise<{actions: Array<object>, groups: Array<string>}>} The matrix.
 */
export async function listActions() {
	const response = await axios.get(generateUrl(ACTIONS_BASE))
	return response.data
}

/**
 * Replace the groups allowed to run one action.
 *
 * @param {string} action The action.
 * @param {Array<string>} groups The groups; `admin` is always kept by the server.
 * @return {Promise<object>} The stored row.
 */
export async function saveActionGroups(action, groups) {
	const response = await axios.put(generateUrl(`${ACTIONS_BASE}/{action}`, { action }), { groups })
	return response.data
}
