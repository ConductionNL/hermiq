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

/**
 * The node types the FLOW ENGINE can actually execute.
 *
 * OpenRegister's engine is the fleet's one flow engine (ADR-065) and it owns the
 * node vocabulary: every app that contributes a node registers it there, so the
 * catalogue is the only authoritative list. The builder used to hard-code five
 * type keys of its own, none of which existed in the catalogue — so its labels
 * fell back to raw type strings, its config panes never matched a node, and a
 * node dropped from its palette carried a type the engine had never heard of.
 *
 * Entries are `{ id, displayName, description, icon }`. There is deliberately no
 * config schema in there yet, which is why per-type config panes are still
 * hand-written rather than rendered from the catalogue.
 *
 * @return {Promise<Array<object>>} The catalogue entries (empty on failure).
 */
export async function getNodeCatalog() {
	const response = await axios.get(generateUrl('/apps/openregister/api/flow/node-catalog'))

	return response.data?.results || []
}
