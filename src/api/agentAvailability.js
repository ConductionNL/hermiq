// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Switch an agent off and on (agents-switch-off-and-stop). Stateless helpers.

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * The URL of an agent's availability endpoint.
 *
 * @param {string} agentId The agent.
 * @return {string} The URL.
 * @spec openspec/changes/agents-switch-off-and-stop/specs/agent-management-ui/spec.md#requirement-an-agent-can-be-switched-off-and-on-without-deleting-it-req-agoff-001
 */
function availabilityUrl(agentId) {
	return generateUrl(
		`/apps/hermiq/api/agents/${encodeURIComponent(agentId)}/availability`,
	)
}

/**
 * The switch state, whether the caller may switch, and the schedule count.
 *
 * @param {string} agentId The agent.
 * @return {Promise<object>} `{ active, changedBy, changedAt, reason, canSwitch, scheduleCount }`.
 * @spec openspec/changes/agents-switch-off-and-stop/specs/agent-management-ui/spec.md#requirement-an-agent-can-be-switched-off-and-on-without-deleting-it-req-agoff-001
 */
export async function getAvailability(agentId) {
	const { data } = await axios.get(availabilityUrl(agentId))
	return data
}

/**
 * Switch the agent off (with a reason) or on.
 *
 * @param {string} agentId The agent.
 * @param {boolean} active On (true) or off (false).
 * @param {string} reason Why; required to switch off.
 * @return {Promise<object>} The new state.
 * @spec openspec/changes/agents-switch-off-and-stop/specs/agent-management-ui/spec.md#requirement-an-agent-can-be-switched-off-and-on-without-deleting-it-req-agoff-001
 */
export async function setAvailability(agentId, active, reason) {
	const { data } = await axios.post(availabilityUrl(agentId), { active, reason })
	return data
}
