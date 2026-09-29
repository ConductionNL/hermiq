// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

// Flow runs, read from OpenRegister with the person's own session
// (observability-compare-two-runs). OpenRegister's organisation scoping decides
// what can be read; hermiq neither copies nor stores flow runs.
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * The flows the person may read.
 *
 * @return {Promise<Array<object>>} The flows.
 *
 * @spec openspec/specs/run-replay-and-dry-run/spec.md#requirement-a-person-can-compare-two-runs-of-a-flow-node-by-node-req-rcmp-003
 */
export async function listFlows() {
	const response = await axios.get(generateUrl('/apps/openregister/api/flows'))
	const data = response.data
	return Array.isArray(data) ? data : data?.results || []
}

/**
 * The latest runs of one flow.
 *
 * @param {string} flowId The flow id.
 * @return {Promise<Array<object>>} The runs, newest first as OpenRegister returns them.
 *
 * @spec openspec/specs/run-replay-and-dry-run/spec.md#requirement-a-person-can-compare-two-runs-of-a-flow-node-by-node-req-rcmp-003
 */
export async function listFlowRuns(flowId) {
	const response = await axios.get(
		generateUrl('/apps/openregister/api/flow-runs'),
		{
			params: { flowId, limit: 50 },
		},
	)
	return response.data?.results || []
}

/**
 * One flow run, with its log.
 *
 * @param {string} uuid The run uuid.
 * @return {Promise<object>} The run.
 *
 * @spec openspec/specs/run-replay-and-dry-run/spec.md#requirement-a-person-can-compare-two-runs-of-a-flow-node-by-node-req-rcmp-003
 */
export async function getFlowRun(uuid) {
	const response = await axios.get(
		generateUrl(`/apps/openregister/api/flow-runs/${encodeURIComponent(uuid)}`),
	)
	return response.data
}
