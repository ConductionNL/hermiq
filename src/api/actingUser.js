// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Whether an agent's declared acting user still resolves
// (agent-acting-identity-picker REQ-AGID-003). The server answers: the named
// user must exist and be enabled. Kept out of api/agents.js so the many tests
// that double that module need not learn a new export.

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * The agent's declared acting user and whether it resolves.
 *
 * @param {string} agentId The Agent UUID.
 * @return {Promise<{actingUser: string, resolves: boolean}>} The answer.
 * @spec openspec/changes/agent-acting-identity-picker/specs/agent-identity/spec.md#requirement-the-edit-page-shows-a-declared-account-that-no-longer-resolves-req-agid-003
 */
export async function getActingUserStatus(agentId) {
	const response = await axios.get(
		generateUrl(`/apps/hermiq/api/agents/${encodeURIComponent(agentId)}/acting-user`),
	)
	return {
		actingUser: typeof response.data?.actingUser === 'string' ? response.data.actingUser : '',
		resolves: response.data?.resolves !== false,
	}
}
