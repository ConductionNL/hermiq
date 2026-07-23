// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Plain (non-Pinia) API helper for running an authored agent graph.
//
// The graph DEFINITION is an OpenRegister object and is read/written through
// `useAgentFlowStore` (src/store/store.js) on the generic objects path, like
// every other hermiq schema object. Only the EXECUTION is a bespoke Hermiq
// endpoint: OpenRegister can store a graph but has no notion of walking one,
// so `POST /apps/hermiq/api/graph/run` is the thin action surface over
// GraphExecutor. Keeping it here (rather than proxying object reads) avoids
// the redundant-controller gate.

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * Run an agent graph against a subject object.
 *
 * Executes the supplied (unsaved-or-saved) graph definition immediately, so the
 * builder can test a graph before persisting it. The response carries the final
 * state plus an ordered `trace` of every node the executor visited, which the
 * builder renders per-node.
 *
 * @param {object} graph          The graph definition ({nodes, edges, limits}).
 * @param {string} subjectUuid    UUID of the object the graph runs against.
 * @param {string} subjectRegister Register slug of the subject object.
 * @param {string} subjectSchema  Schema slug of the subject object.
 *
 * @return {Promise<object>} `{ subjectUuid, state, trace }`.
 */
export async function runGraph(graph, subjectUuid, subjectRegister, subjectSchema) {
	const response = await axios.post(generateUrl('/apps/hermiq/api/graph/run'), {
		graph,
		subjectUuid,
		subjectRegister,
		subjectSchema,
	})

	return response.data
}
