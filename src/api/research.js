// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Thin fetch helpers for a deep research run (tools-deep-research-report task 6).
// A run is a research handoff: POST /api/research starts one in the background,
// GET /api/research/{id} reads its status, plan and progress, and
// POST /api/research/{id}/stop asks it to end at its next step. The server only
// answers for the caller's own runs.

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * Start a research run on a question in a conversation.
 *
 * @param {object} run The run to start.
 * @param {string} run.agentId The agent that researches.
 * @param {string} run.conversationId The conversation the plan and report are posted in.
 * @param {string} run.question The question.
 * @param {string} [run.depth] `thorough` (the caps) or `quick` (half the caps).
 * @return {Promise<object>} The run: `{id, status, question, depth, plan, progress, caps, stopRequested}`.
 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-a-user-can-start-deep-research-and-sees-the-plan-req-research-001
 */
export async function startResearch({ agentId, conversationId, question, depth = 'thorough' }) {
	const { data } = await axios.post(generateUrl('/apps/hermiq/api/research'), {
		agentId,
		conversationId,
		question,
		depth,
	})
	return data
}

/**
 * Read one of the caller's research runs.
 *
 * @param {string} id The run's handoff id.
 * @return {Promise<object>} The run.
 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-a-user-can-start-deep-research-and-sees-the-plan-req-research-001
 */
export async function getResearch(id) {
	const { data } = await axios.get(
		generateUrl(`/apps/hermiq/api/research/${encodeURIComponent(id)}`),
	)
	return data
}

/**
 * Ask one of the caller's research runs to stop at its next step.
 *
 * @param {string} id The run's handoff id.
 * @return {Promise<object>} `{id, stopRequested: true}`.
 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-a-research-run-stops-at-its-caps-the-budget-the-kill-switch-or-the-user-req-research-005
 */
export async function stopResearch(id) {
	const { data } = await axios.post(
		generateUrl(`/apps/hermiq/api/research/${encodeURIComponent(id)}/stop`),
	)
	return data
}
