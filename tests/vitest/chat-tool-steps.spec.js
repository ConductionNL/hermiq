/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The chat page's tool steps: a hermiq.delegateAgents call is drawn as one
 * step per part, with its target agent and state (delegation-runs-in-parallel
 * task 2.1).
 */

import { describe, expect, it } from 'vitest'
import { chatToolSteps, isParallelDelegation, partState } from '../../src/views/chatToolSteps.js'

const names = { 'agent-b': 'Researcher', 'agent-c': 'Writer' }
const agentName = (id) => names[id] || ''

const twoParts = {
	parts: [
		{ targetAgentId: 'agent-b', task: 'Find the sources' },
		{ targetAgentId: 'agent-c', task: 'Draft the summary' },
	],
}

describe('chatToolSteps', () => {
	it('draws one running step per part while the call is open', () => {
		const steps = chatToolSteps(
			[{ toolId: 'hermiq.delegateAgents', arguments: twoParts, result: null, done: false }],
			agentName,
		)

		expect(steps.map((step) => step.label)).toEqual([
			'Part 1 to Researcher: Running',
			'Part 2 to Writer: Running',
		])
		expect(steps.map((step) => step.kind)).toEqual(['part', 'part'])
		expect(new Set(steps.map((step) => step.key)).size).toBe(2)
	})

	it('gives each part its own state from its slot once the call returns', () => {
		const result = {
			results: [
				{ targetAgentId: 'agent-b', result: 'Three sources found.' },
				{ targetAgentId: 'agent-c', error: { code: 'delegation_timeout', message: 'late' } },
			],
		}
		const steps = chatToolSteps(
			[{ toolId: 'hermiq.delegateAgents', arguments: twoParts, result, done: true }],
			agentName,
		)

		expect(steps.map((step) => [step.agent, step.state])).toEqual([
			['Researcher', 'done'],
			['Writer', 'timeout'],
		])
		expect(steps[1].label).toBe('Part 2 to Writer: Timed out')
	})

	it('reads a replayed result sent as a JSON string', () => {
		const result = JSON.stringify({
			results: [
				{ targetAgentId: 'agent-b', error: { code: 'delegation_not_allowed', message: 'no' } },
				{ targetAgentId: 'agent-c', error: { code: 'delegation_failed', message: 'boom' } },
			],
		})
		const steps = chatToolSteps(
			[{ toolId: 'hermiq__delegateAgents', arguments: twoParts, result, done: true }],
			agentName,
		)

		expect(steps.map((step) => step.state)).toEqual(['refused', 'failed'])
	})

	it('falls back to the agent id when the agent is not known', () => {
		const steps = chatToolSteps([
			{ toolId: 'hermiq.delegateAgents', arguments: { parts: [{ targetAgentId: 'agent-x', task: 'x' }] }, done: false },
		])

		expect(steps[0].label).toBe('Part 1 to agent-x: Running')
	})

	it('keeps every other tool call as a single step, in call order', () => {
		const steps = chatToolSteps(
			[
				{ toolId: 'hermiq.searchTools', arguments: {}, done: true },
				{ toolId: 'hermiq.delegateAgents', arguments: twoParts, done: false },
				{ toolId: 'hermiq.delegateAgent', arguments: { targetAgentId: 'agent-b' }, done: false },
			],
			agentName,
		)

		expect(steps.map((step) => step.label)).toEqual([
			'Used tool: hermiq.searchTools',
			'Part 1 to Researcher: Running',
			'Part 2 to Writer: Running',
			'Using tool: hermiq.delegateAgent…',
		])
	})

	it('draws a parallel call without parts as one plain step', () => {
		const steps = chatToolSteps([{ toolId: 'hermiq.delegateAgents', arguments: {}, done: true }])

		expect(steps).toHaveLength(1)
		expect(steps[0].label).toBe('Used tool: hermiq.delegateAgents')
	})
})

describe('isParallelDelegation and partState', () => {
	it('matches only the parallel delegation tool', () => {
		expect(isParallelDelegation('hermiq.delegateAgents')).toBe(true)
		expect(isParallelDelegation('hermiq.delegateAgent')).toBe(false)
		expect(isParallelDelegation(undefined)).toBe(false)
	})

	it('reads a returned call without a slot as failed', () => {
		expect(partState(undefined, true)).toBe('failed')
		expect(partState(undefined, false)).toBe('running')
	})
})
