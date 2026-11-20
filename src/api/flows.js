// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The event starts of one agent (scheduling-overview-and-start-choice D2):
// the OpenRegister flows whose `hermiq.agent-step` runs this agent, and a
// draft flow with an object trigger and this agent's step for the person to
// finish on the canvas. Flows live in OpenRegister and are read and written
// with the person's own session; OpenRegister decides who may create one.

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/** The node type of a hermiq agent step in an OpenRegister flow. */
export const AGENT_STEP = 'hermiq.agent-step'

/** The node type of an object trigger in an OpenRegister flow. */
export const OBJECT_TRIGGER = 'openregister.trigger-object'

/**
 * Whether a flow has a step that runs this agent.
 *
 * @param {object} flow An OpenRegister flow.
 * @param {string} agentId The agent uuid.
 * @return {boolean}
 * @spec openspec/changes/scheduling-overview-and-start-choice/specs/agent-schedule/spec.md#requirement-an-agents-page-offers-one-choice-of-how-it-starts-req-sched-002
 */
export function runsAgent(flow, agentId) {
	const nodes = Array.isArray(flow?.nodes) ? flow.nodes : []
	return nodes.some((node) => node?.type === AGENT_STEP && node?.config?.agentId === agentId)
}

/**
 * The hermiq flows whose agent step runs this agent.
 *
 * @param {string} agentId The agent uuid.
 * @return {Promise<Array<object>>} The flows.
 * @spec openspec/changes/scheduling-overview-and-start-choice/specs/agent-schedule/spec.md#requirement-an-agents-page-offers-one-choice-of-how-it-starts-req-sched-002
 */
export async function listAgentFlows(agentId) {
	const response = await axios.get(generateUrl('/apps/openregister/api/flows'), {
		params: { app: 'hermiq' },
	})
	const data = response.data
	const flows = Array.isArray(data) ? data : data?.results || []
	return flows.filter((flow) => runsAgent(flow, agentId))
}

/**
 * The draft flow "Add event" creates: an object trigger the person still
 * points at a register and schema, then this agent's step, then the end.
 * Off until the person switches it on. An object trigger without a register
 * and schema subscribes to nothing, so the draft cannot fire half-made.
 *
 * @param {string} agentId The agent uuid.
 * @param {string} name The flow's name.
 * @return {object} The flow body.
 * @spec openspec/changes/scheduling-overview-and-start-choice/specs/agent-schedule/spec.md#requirement-an-agents-page-offers-one-choice-of-how-it-starts-req-sched-002
 */
export function eventDraft(agentId, name) {
	return {
		name,
		app: 'hermiq',
		enabled: false,
		nodes: [
			{
				id: 'trigger',
				type: OBJECT_TRIGGER,
				position: { x: 0, y: 0 },
				config: { event: 'object.created', register: '', schema: '' },
			},
			{
				id: 'agent',
				type: AGENT_STEP,
				position: { x: 240, y: 0 },
				config: { agentId, prompt: '' },
			},
			{
				id: 'end',
				type: 'openregister.end',
				position: { x: 480, y: 0 },
				config: {},
			},
		],
		edges: [
			{ id: 'trigger-agent', from: 'trigger', to: 'agent' },
			{ id: 'agent-end', from: 'agent', to: 'end' },
		],
	}
}

/**
 * Create the draft event flow for this agent.
 *
 * @param {string} agentId The agent uuid.
 * @param {string} name The flow's name.
 * @return {Promise<object>} The created flow.
 * @spec openspec/changes/scheduling-overview-and-start-choice/specs/agent-schedule/spec.md#requirement-an-agents-page-offers-one-choice-of-how-it-starts-req-sched-002
 */
export async function createAgentEventDraft(agentId, name) {
	const response = await axios.post(
		generateUrl('/apps/openregister/api/flows'),
		eventDraft(agentId, name),
	)
	return response.data
}
