// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Plain (non-Pinia) API helper for the schedule endpoints of
// scheduling-overview-and-start-choice: reading plain words into a rule (D3),
// the access-filtered schedule list (D1) and a run by hand without a schedule
// (D2). Stateless functions, mirroring src/api/budgets.js; axios from
// @nextcloud/axios adds the CSRF requesttoken.

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * Read a plain English or Dutch description of when a schedule runs.
 *
 * @param {string} text What the person typed.
 * @param {string} locale The readback language (`en`, `nl`, ...).
 * @return {Promise<object>} `{kind, cronExpr|intervalMinutes|runAt, readback, nextRuns, source}`.
 * @throws {Error} The server's 422 (with its example) when the text cannot be read.
 * @spec openspec/changes/scheduling-overview-and-start-choice/specs/agent-schedule/spec.md#requirement-a-schedule-can-be-set-in-plain-words-req-sched-003
 */
export async function parseScheduleWhen(text, locale) {
	const response = await axios.post(
		generateUrl('/apps/hermiq/api/schedules/parse-when'),
		{ text, locale },
	)
	return response.data
}

/**
 * The schedules the caller may see, filtered.
 *
 * @param {object} [filters] `agentId`, `enabled` (boolean) and `failed` (boolean).
 * @return {Promise<Array<object>>} The schedule rows.
 * @spec openspec/changes/scheduling-overview-and-start-choice/specs/agent-schedule/spec.md#requirement-every-schedule-a-person-may-see-is-on-one-page-req-sched-001
 */
export async function listSchedules(filters = {}) {
	const response = await axios.get(generateUrl('/apps/hermiq/api/schedules'), {
		params: filters,
	})
	return Array.isArray(response.data?.results) ? response.data.results : []
}

/**
 * Run an agent by hand with a prompt, without a schedule.
 *
 * @param {string} agentId The agent uuid.
 * @param {string} prompt What the agent should do.
 * @return {Promise<object>} The run result (`status`, `runId`, `trigger`).
 * @spec openspec/changes/scheduling-overview-and-start-choice/specs/agent-schedule/spec.md#requirement-an-agents-page-offers-one-choice-of-how-it-starts-req-sched-002
 */
export async function runAgentByHand(agentId, prompt) {
	const response = await axios.post(
		generateUrl('/apps/hermiq/api/agents/{id}/run', { id: agentId }),
		{ prompt },
	)
	return response.data
}
