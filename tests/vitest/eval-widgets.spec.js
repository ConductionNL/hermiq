/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Characterisation of the two eval widgets' shared behaviour: the pass-rate,
 * delta and date labels, the agent picker and its baseline mode, the cost
 * note, and how each widget loads.
 */

import { describe, expect, it, vi } from 'vitest'

vi.mock('../../src/store/store.js', () => ({
	useAgentStore: () => ({}),
	useEvalRunStore: () => ({}),
	useEvalDatasetStore: () => ({}),
	useSkillStore: () => ({}),
}))

vi.mock('../../src/api/skills.js', () => ({ listSkills: vi.fn() }))
vi.mock('../../src/api/evals.js', () => ({ runEval: vi.fn() }))

const skillsApi = await import('../../src/api/skills.js')
const { default: EvalRunPanelWidget } = await import('../../src/widgets/EvalRunPanelWidget.vue')
const { default: SkillEvalEvidence } = await import('../../src/widgets/SkillEvalEvidence.vue')

/**
 * Translate as the identity, filling {placeholders}.
 *
 * @param {string} app The app id.
 * @param {string} text The text.
 * @param {object} vars The placeholders.
 * @return {string} The filled text.
 */
function t(app, text, vars = {}) {
	return text.replace(/\{(\w+)\}/g, (match, key) => String(vars[key] ?? match))
}

describe.each([
	['EvalRunPanelWidget', EvalRunPanelWidget],
	['SkillEvalEvidence', SkillEvalEvidence],
])('%s labels', (name, widget) => {
	it('labels pass rates, deltas and dates', () => {
		const m = widget.methods
		expect(m.passRateLabel.call({}, 0.456)).toBe('46%')
		expect(m.passRateLabel.call({}, null)).toBe('—')
		expect(m.deltaLabel.call({}, 0.3)).toBe('+30 pp')
		expect(m.deltaLabel.call({}, -0.104)).toBe('−10 pp')
		expect(m.deltaLabel.call({}, 0.001)).toBe('±0 pp')
		expect(m.deltaLabel.call({}, '0.3')).toBe('—')
		expect(m.formatDate.call({}, '')).toBe('—')
		expect(m.formatDate.call({}, 'not a date')).toBe('not a date')
		expect(m.formatDate.call({}, '2026-01-02T03:04:05Z')).toBe(new Date('2026-01-02T03:04:05Z').toLocaleString())
	})

	it('offers the agents and reads the selected agent\'s baseline mode', () => {
		const agents = [{ uuid: 'a1', name: 'One', evalBaselineMode: 'per-skill' }, { id: 'a2' }]
		const c = widget.computed
		expect(c.agentOptions.call({ agents })).toEqual([{ label: 'One', value: 'a1' }, { label: 'a2', value: 'a2' }])
		expect(c.selectedAgentMode.call({ agents, selectedAgent: { value: 'a1' } })).toBe('per-skill')
		expect(c.selectedAgentMode.call({ agents, selectedAgent: { value: 'a2' } })).toBe('joint')
		expect(c.selectedAgentMode.call({ agents, selectedAgent: null })).toBe('joint')
	})
})

describe('cost notes', () => {
	it('EvalRunPanelWidget counts its linked skills', () => {
		const note = EvalRunPanelWidget.computed.costNote
		expect(note.call({ t, selectedAgentMode: 'per-skill', linkedSkillIds: ['s1', 's2'] })).toContain('every case runs 3 times')
		expect(note.call({ t, selectedAgentMode: 'joint', linkedSkillIds: ['s1'] })).toContain('Joint baseline: every case runs twice')
	})

	it('SkillEvalEvidence counts the selected dataset\'s skills', () => {
		const note = SkillEvalEvidence.computed.costNote
		expect(note.call({ t, selectedAgentMode: 'per-skill', selectedDatasetSkillCount: 1 })).toContain('about 2x the token cost')
		expect(note.call({ t, selectedAgentMode: 'joint', selectedDatasetSkillCount: 4 })).toContain('Joint baseline')
	})
})

describe('EvalRunPanelWidget load', () => {
	/**
	 * A widget context whose stores answer with the given values.
	 *
	 * @param {object} answers agents, runs, dataset, skills (values or Errors).
	 * @return {object} The context.
	 */
	function context(answers) {
		const answer = (value) => (value instanceof Error ? Promise.reject(value) : Promise.resolve(value))
		skillsApi.listSkills.mockImplementation(() => answer(answers.skills))
		return {
			t,
			datasetId: 'ds-1',
			error: 'old',
			agentStore: { fetchCollection: () => answer(answers.agents) },
			runStore: { fetchCollection: () => answer(answers.runs) },
			datasetStore: { fetchObject: (type, id) => (id === 'ds-1' ? answer(answers.dataset) : answer(null)) },
		}
	}

	it('keeps this dataset\'s ten latest runs, newest first', async () => {
		const runs = Array.from({ length: 12 }, (v, i) => ({ id: i, datasetId: 'ds-1', startedAt: `2026-01-${String(i + 1).padStart(2, '0')}` }))
		runs.push({ id: 'other', datasetId: 'ds-2', startedAt: '2027-01-01' }, { id: 'undated', datasetId: 'ds-1' })
		const ctx = context({ agents: [{ id: 'a' }], runs, dataset: { skillRefs: ['s'] }, skills: [{ id: 's' }] })
		await EvalRunPanelWidget.methods.load.call(ctx)

		expect(ctx.error).toBe('')
		expect(ctx.agents).toEqual([{ id: 'a' }])
		expect(ctx.dataset).toEqual({ skillRefs: ['s'] })
		expect(ctx.skills).toEqual([{ id: 's' }])
		expect(ctx.runs.map((r) => r.id)).toEqual([11, 10, 9, 8, 7, 6, 5, 4, 3, 2])
	})

	it('treats failed or odd answers as empty', async () => {
		const ctx = context({ agents: new Error('x'), runs: 'odd', dataset: new Error('y'), skills: { not: 'a list' } })
		await EvalRunPanelWidget.methods.load.call(ctx)

		expect(ctx.error).toBe('')
		expect(ctx.agents).toEqual([])
		expect(ctx.runs).toEqual([])
		expect(ctx.dataset).toBe(null)
		expect(ctx.skills).toEqual([])
	})

	it('reports an error the stores throw outright', async () => {
		const ctx = context({ agents: [], runs: [], dataset: null, skills: [] })
		ctx.agentStore.fetchCollection = () => {
			throw Object.assign(new Error('boom'), { response: { data: { error: 'Server said no' } } })
		}
		await EvalRunPanelWidget.methods.load.call(ctx)
		expect(ctx.error).toBe('Server said no')

		ctx.agentStore.fetchCollection = () => {
			throw new Error('plain')
		}
		await EvalRunPanelWidget.methods.load.call(ctx)
		expect(ctx.error).toBe('plain')

		ctx.agentStore.fetchCollection = () => {
			throw {}
		}
		await EvalRunPanelWidget.methods.load.call(ctx)
		expect(ctx.error).toBe('Unknown error')
	})
})

describe('SkillEvalEvidence load', () => {
	it('loads the skill, datasets, agents and runs, and reports a failure', async () => {
		const ctx = {
			t,
			skillId: 'sk-1',
			skillStore: { fetchObject: async () => ({ id: 'sk-1' }) },
			datasetStore: { fetchCollection: async () => 'odd' },
			agentStore: { fetchCollection: async () => { throw new Error('x') } },
			runStore: { fetchCollection: async () => [{ id: 'r' }] },
		}
		await SkillEvalEvidence.methods.load.call(ctx)
		expect(ctx.loading).toBe(false)
		expect(ctx.error).toBe('')
		expect(ctx.skill).toEqual({ id: 'sk-1' })
		expect(ctx.datasets).toEqual([])
		expect(ctx.agents).toEqual([])
		expect(ctx.runs).toEqual([{ id: 'r' }])

		ctx.skillStore.fetchObject = async () => { throw new Error('gone') }
		await SkillEvalEvidence.methods.load.call(ctx)
		expect(ctx.error).toBe('gone')
		ctx.skillStore.fetchObject = async () => { throw {} }
		await SkillEvalEvidence.methods.load.call(ctx)
		expect(ctx.error).toBe('Could not load the skill.')
		expect(ctx.loading).toBe(false)
	})
})
