/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The tool steps the chat page draws under a streaming answer. A
 * `hermiq.delegateAgents` call hands several parts to sub-agents at once, so
 * it is drawn as one step per part, each with its target agent and state,
 * where any other tool call is one step.
 */

import { t } from '@nextcloud/l10n'

/**
 * Whether a tool id names the parallel delegation tool, in its dotted form or
 * the underscore form a provider function name takes.
 *
 * @param {string} toolId The tool id from the stream frame.
 * @return {boolean} True for `hermiq.delegateAgents`.
 * @spec openspec/changes/delegation-runs-in-parallel/tasks.md#task-2-1
 */
export function isParallelDelegation(toolId) {
	return /^hermiq[._]+delegateAgents$/.test(String(toolId || ''))
}

/**
 * The tool result as an object: the in-process frame carries it decoded, the
 * replayed frame as a JSON string.
 *
 * @param {object|string|null} result The frame's result.
 * @return {object|null} The decoded result, or null.
 * @spec openspec/changes/delegation-runs-in-parallel/tasks.md#task-2-1
 */
function decodeResult(result) {
	if (typeof result === 'string') {
		try {
			return decodeResult(JSON.parse(result))
		} catch {
			return null
		}
	}
	return result !== null && typeof result === 'object' ? result : null
}

/**
 * The state of one part from its slot in the tool result.
 *
 * @param {object|undefined} slot The part's slot, or undefined while running.
 * @param {boolean} done Whether the tool call has returned.
 * @return {string} running, done, refused, failed or timeout.
 * @spec openspec/changes/delegation-runs-in-parallel/tasks.md#task-2-1
 */
export function partState(slot, done) {
	if (!done) {
		return 'running'
	}
	const code = slot?.error?.code
	if (!code) {
		return slot ? 'done' : 'failed'
	}
	if (code === 'delegation_timeout') {
		return 'timeout'
	}
	if (code === 'delegation_failed') {
		return 'failed'
	}
	return 'refused'
}

/**
 * The label of a part's state.
 *
 * @param {string} state The part's state.
 * @return {string} The translated label.
 * @spec openspec/changes/delegation-runs-in-parallel/tasks.md#task-2-1
 */
export function partStateLabel(state) {
	switch (state) {
	case 'done':
		return t('hermiq', 'Done')
	case 'refused':
		return t('hermiq', 'Refused')
	case 'timeout':
		return t('hermiq', 'Timed out')
	case 'failed':
		return t('hermiq', 'Failed')
	default:
		return t('hermiq', 'Running')
	}
}

/**
 * One step per part of a parallel delegation call.
 *
 * @param {object} tool The streamed call: toolId, arguments, result, done.
 * @param {number} callIndex The call's place among the streamed calls.
 * @param {function(string): string} agentName Resolves an agent id to its name.
 * @return {Array<object>} The part steps.
 * @spec openspec/changes/delegation-runs-in-parallel/tasks.md#task-2-1
 */
function partSteps(tool, callIndex, agentName) {
	const parts = Array.isArray(tool.arguments?.parts) ? tool.arguments.parts : []
	const decoded = decodeResult(tool.result)
	const slots = Array.isArray(decoded?.results) ? decoded.results : []
	return parts.map((part, index) => {
		const slot = slots[index]
		const agentId = String(part?.targetAgentId || slot?.targetAgentId || '')
		const state = partState(slot, tool.done)
		const agent = agentName(agentId) || agentId || t('hermiq', 'Target agent')
		return {
			key: `${callIndex}:${index}`,
			kind: 'part',
			agent,
			state,
			label: t('hermiq', 'Part {index} to {agent}: {state}', {
				index: index + 1,
				agent,
				state: partStateLabel(state),
			}),
		}
	})
}

/**
 * The steps to draw for the streamed tool calls, in call order.
 *
 * @param {Array<object>} tools The streamed calls: toolId, arguments, result, done.
 * @param {function(string): string} [agentName] Resolves an agent id to its name ('' when unknown).
 * @return {Array<object>} One step per call, and one per part of a parallel delegation.
 * @spec openspec/changes/delegation-runs-in-parallel/tasks.md#task-2-1
 */
export function chatToolSteps(tools, agentName = () => '') {
	const steps = []
	;(Array.isArray(tools) ? tools : []).forEach((tool, index) => {
		const parts = isParallelDelegation(tool.toolId) ? partSteps(tool, index, agentName) : []
		if (parts.length > 0) {
			steps.push(...parts)
			return
		}
		steps.push({
			key: `${index}`,
			kind: 'tool',
			state: tool.done ? 'done' : 'running',
			label: tool.done
				? t('hermiq', 'Used tool: {tool}', { tool: tool.toolId })
				: t('hermiq', 'Using tool: {tool}…', { tool: tool.toolId }),
		})
	})
	return steps
}
