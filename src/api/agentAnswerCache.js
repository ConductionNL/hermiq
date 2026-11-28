// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Clear an agent's cached answers (models-prompt-and-answer-cache). Stateless helper.

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * Remove every cached answer of one agent. Only the agent's owner may.
 *
 * @param {string} agentId The agent.
 * @return {Promise<number>} How many cached answers were removed.
 * @spec openspec/changes/models-prompt-and-answer-cache/specs/answer-cache/spec.md#requirement-cached-answers-can-be-cleared-and-expire-req-acache-004
 */
export async function clearAnswerCache(agentId) {
	const { data } = await axios.delete(
		generateUrl(`/apps/hermiq/api/agents/${encodeURIComponent(agentId)}/answer-cache`),
	)
	return Number(data?.removed ?? 0)
}
