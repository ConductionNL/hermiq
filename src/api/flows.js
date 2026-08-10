// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The flow surface hermiq's flow editor talks to — OpenRegister's, entirely.
//
// There is ONE flow store in the fleet and OpenRegister owns it
// (flow-engine-unification, `specs/flow-storage/spec.md`):
//
//   "A flow definition SHALL NOT be stored as an OpenRegister object […] No
//    other app SHALL own a flow store, a flow controller, or a flow execution
//    service."
//
// A flow authored here is a row in that store with `app = 'hermiq'` — not a
// second entity: no hermiq table, no hermiq schema, no hermiq controller. Every
// function here is a thin call to `/apps/openregister/api/flow…`, which is also
// what the ENGINE reads — so what the editor shows and what a trigger runs
// cannot drift apart.
//
// THIS FILE IS TEMPORARY. It is a per-app HTTP client for a shared abstraction,
// which is the same duplication one layer up: openconnector and procest consume
// the same engine and would each need it. It moves to `createFlowStore` in
// @conduction/nextcloud-vue — next to the `createObjectStore` objects already
// have — in the `cn-flow-store-and-canvas-rename` change.
//
// This replaces `api/graph.js`, which posted to `/apps/hermiq/api/graph/run`.
// That route was never registered in `appinfo/routes.php`, so the builder's
// Run button had been posting into a 404 the whole time — a bespoke execution
// service that the spec forbids and that did not exist either way.

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/** The app every flow authored here is scoped to (`flows.app`). */
export const FLOW_APP = 'hermiq'

/**
 * Flows owned by hermiq.
 *
 * Scoped with `app=hermiq` rather than filtered client-side: the store is
 * shared with every other app's flows, and an unfiltered list would put
 * openconnector's and openregister's flows in hermiq's flow list.
 *
 * @param {number} limit How many to read.
 *
 * @return {Promise<Array<object>>} The flows.
 */
export async function listFlows(limit = 100) {
	const url = generateUrl('/apps/openregister/api/flows')
	const response = await axios.get(url, { params: { app: FLOW_APP, limit } })

	return response.data?.results || []
}

/**
 * One flow by uuid.
 *
 * @param {string} id The flow uuid.
 *
 * @return {Promise<object>} The flow.
 */
export async function getFlow(id) {
	const url = generateUrl('/apps/openregister/api/flows/{id}', { id })
	const response = await axios.get(url)

	return response.data
}

/**
 * Create a flow.
 *
 * `app` is stamped here rather than left to the caller: a flow that reaches the
 * store without it defaults to `openregister` and disappears from hermiq's own
 * list the moment it is saved.
 *
 * @param {object} flow The flow document.
 *
 * @return {Promise<object>} The stored flow.
 */
export async function createFlow(flow) {
	const url = generateUrl('/apps/openregister/api/flows')
	const response = await axios.post(url, { ...flow, app: FLOW_APP })

	return response.data
}

/**
 * Update a flow.
 *
 * The endpoint is a PARTIAL update — `FlowService` only touches keys that are
 * actually present — so send the keys the editor owns and leave the rest
 * (`owner`, `organisation`, `created`) to the store.
 *
 * @param {string} id   The flow uuid.
 * @param {object} flow The changed fields.
 *
 * @return {Promise<object>} The stored flow.
 */
export async function updateFlow(id, flow) {
	const url = generateUrl('/apps/openregister/api/flows/{id}', { id })
	const response = await axios.put(url, flow)

	return response.data
}

/**
 * Queue a run of a saved flow.
 *
 * Returns the queued FlowRun, NOT a finished trace: the engine runs flows
 * asynchronously by default (`executionMode`), so the run has a `uuid` and a
 * `status` long before it has a `log`. Callers poll `getFlowRun`.
 *
 * @param {string} id      The flow uuid.
 * @param {object} subject `{uuid, register, schema}` the run walks against.
 * @param {object} context Extra context seeded onto the run.
 *
 * @return {Promise<object>} The queued run.
 */
export async function runFlow(id, subject = {}, context = {}) {
	const url = generateUrl('/apps/openregister/api/flows/{id}/run', { id })
	const response = await axios.post(url, { subject, context })

	return response.data
}

/**
 * Read a run back — status, marking, and the step log the canvas renders.
 *
 * @param {string} uuid The run uuid.
 *
 * @return {Promise<object>} The run.
 */
export async function getFlowRun(uuid) {
	const url = generateUrl('/apps/openregister/api/flow-runs/{uuid}', { uuid })
	const response = await axios.get(url)

	return response.data
}

/**
 * This flow's runs, most recent first.
 *
 * Capped, and the cap is the point: a flow scheduled every five minutes
 * produces 288 runs a day, so "all of them" is not a list anyone reads and is
 * not what an operator opening the panel is asking for. They want the last few
 * and whether they went well.
 *
 * @param {string} flowId The flow uuid.
 * @param {number} limit  How many runs to fetch.
 *
 * @return {Promise<Array<object>>} The runs.
 */
export async function listFlowRuns(flowId, limit = 25) {
	const url = generateUrl('/apps/openregister/api/flow-runs')
	const response = await axios.get(url, { params: { flowId, limit } })

	return response.data?.results || []
}

/**
 * Preflight a flow document against the live node registry WITHOUT saving it.
 *
 * This is the editor's correctness check and it is the engine's own: it builds
 * the same Petri-net definition `FlowDefinitionBuilder` builds at run time, so
 * a document that validates here is a document that runs. It is what turns the
 * dialect rules (a node is a place and carries no `type`/`config`; an edge
 * carries the step) from prose into an error message on the canvas.
 *
 * It also catches what structure alone cannot: `FlowNodePreflight` calls each
 * step's own `validateConfig()`, so an edge written in another node's dialect —
 * which resolves, runs, and reports COMPLETED while doing nothing — is blocking
 * rather than invisible.
 *
 * @param {object} flow The flow document to check.
 *
 * @return {Promise<{valid: boolean, blocking: Array<object>, warnings: Array<object>, message: string}>} The verdict.
 */
export async function validateFlow(flow) {
	const url = generateUrl('/apps/openregister/api/flow/validate')

	// A 400 is a VERDICT here ("this body does not describe a flow"), not a
	// transport failure, and it carries the same report shape. Letting axios
	// throw it would turn the editor's clearest error message into an
	// unhandled rejection.
	let data = {}
	try {
		const response = await axios.post(url, { flow })
		data = response.data || {}
	} catch (e) {
		data = e?.response?.data
		if (!data) {
			throw e
		}
	}

	return {
		valid: data.valid === true,
		blocking: data.blocking || [],
		warnings: data.warnings || [],
		message: data.message || '',
	}
}

/**
 * The node types the engine can execute.
 *
 * OpenRegister's engine owns the vocabulary (ADR-065): every app that
 * contributes a node registers it there, so the catalogue is the only
 * authoritative list.
 *
 * These entries describe a **node**, and the endpoint is called `node-catalog`
 * because that is what it returns. This docblock used to say the opposite —
 * that a step "lives on an EDGE", and that reading the endpoint's name as
 * "types a node can have" was the mistake that made flows unrunnable, citing
 * `FlowDefinitionBuilder::extractPlaces()` as throwing on a node carrying
 * `type`. There is no `extractPlaces()` in the engine, and the builder rejects
 * the opposite thing: `assertNotPreInversion()` throws when an EDGE carries a
 * non-empty `type`, with the message "an edge is sequence and a NODE is the
 * action". `flow-engine/spec.md` agrees — "each node MUST carry the `type` and
 * `config` of the step it performs".
 *
 * The comment was describing the pre-inversion dialect as though it were the
 * canonical one, which is how the editor came to write behaviour onto edges.
 *
 * @return {Promise<Array<object>>} The catalogue entries (`{id, displayName, description}`).
 */
export async function getNodeCatalog() {
	const url = generateUrl('/apps/openregister/api/flow/node-catalog')
	const response = await axios.get(url)

	return response.data?.results || []
}
