/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The eval run panel, the skill eval evidence panel and the skill maturity
 * scorecard, mounted with their API and stores doubled: what they load, what
 * they derive from it, and how their actions report back.
 */

import { flushPromises, shallowMount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const toasts = []
vi.mock('@nextcloud/dialogs', () => ({
	showError: (m) => toasts.push(['error', m]),
	showSuccess: (m) => toasts.push(['success', m]),
}))
vi.mock('../../src/api/evals.js', () => ({ runEval: vi.fn() }))
vi.mock('../../src/api/skills.js', () => ({ listSkills: vi.fn(), attestSkillL4: vi.fn(), qualifySkill: vi.fn() }))
const agentStore = { registerObjectType: vi.fn(), fetchCollection: vi.fn() }
const runStore = { registerObjectType: vi.fn(), fetchCollection: vi.fn() }
const datasetStore = { registerObjectType: vi.fn(), fetchObject: vi.fn(), fetchCollection: vi.fn() }
const skillStore = { registerObjectType: vi.fn(), fetchObject: vi.fn() }
vi.mock('../../src/store/store.js', () => ({
	useAgentStore: () => agentStore,
	useEvalRunStore: () => runStore,
	useEvalDatasetStore: () => datasetStore,
	useSkillStore: () => skillStore,
}))

const evals = await import('../../src/api/evals.js')
const skills = await import('../../src/api/skills.js')
const { default: EvalRunPanelWidget } = await import('../../src/widgets/EvalRunPanelWidget.vue')
const { default: SkillEvalEvidence } = await import('../../src/widgets/SkillEvalEvidence.vue')
const { default: SkillMaturityScorecard } = await import('../../src/widgets/SkillMaturityScorecard.vue')

/**
 * Translate as the identity, filling {placeholders}.
 *
 * @param {string} app The app id.
 * @param {string} text The text.
 * @param {object} vars The placeholders.
 * @return {string} The filled text.
 */
const t = (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m))

/**
 * Mount a widget on the route of object x1 and let it load.
 *
 * @param {object} component The widget.
 * @return {Promise<object>} The wrapper.
 */
async function mountIt(component) {
	const w = shallowMount(component, { global: { mocks: { t, $route: { params: { id: 'x1' } } } } })
	await flushPromises()
	return w
}

beforeEach(() => {
	toasts.length = 0
	vi.clearAllMocks()
	agentStore.fetchCollection.mockResolvedValue([{ uuid: 'a1', name: 'Bot', evalBaselineMode: 'per-skill' }])
	runStore.fetchCollection.mockResolvedValue([])
	skills.listSkills.mockResolvedValue([{ uuid: 'k1', name: 'Summarise' }])
})

describe('EvalRunPanelWidget mounted', () => {
	beforeEach(() => {
		datasetStore.fetchObject.mockResolvedValue({ id: 'x1', skillRefs: ['k1', ''] })
	})

	it('loads the dataset with its linked skills and offers the agents', async () => {
		const w = await mountIt(EvalRunPanelWidget)
		expect(datasetStore.fetchObject).toHaveBeenCalledWith('evaldataset', 'x1')
		expect([w.vm.linkedSkillIds, w.vm.hasLinkedSkills]).toEqual([['k1'], true])
		expect(w.vm.agentOptions).toEqual([{ label: 'Bot', value: 'a1' }])
		w.vm.selectedAgent = { value: 'a1' }
		expect(w.vm.selectedAgentMode).toBe('per-skill')
		expect(w.vm.costNote).toContain('every case runs 2 times')
	})

	it('runs the eval as a paired baseline only when skills are linked', async () => {
		evals.runEval.mockResolvedValue({ passRate: 0.8 })
		const w = await mountIt(EvalRunPanelWidget)
		await w.vm.run()
		expect(evals.runEval).not.toHaveBeenCalled()
		w.vm.selectedAgent = { value: 'a1' }
		w.vm.baseline = true
		await w.vm.run()
		expect(evals.runEval).toHaveBeenCalledWith('x1', 'a1', { baseline: true })
		expect(toasts).toEqual([['success', 'Eval run complete: 80% passed.']])
		w.vm.dataset = { skillRefs: [] }
		await w.vm.run()
		expect(evals.runEval).toHaveBeenLastCalledWith('x1', 'a1', { baseline: false })
	})

	it('reports a failed run', async () => {
		const w = await mountIt(EvalRunPanelWidget)
		w.vm.selectedAgent = { value: 'a1' }
		evals.runEval.mockRejectedValueOnce({ response: { data: { error: 'Budget used up' } } })
		await w.vm.run()
		evals.runEval.mockRejectedValueOnce({})
		await w.vm.run()
		expect(toasts).toEqual([['error', 'Budget used up'], ['error', 'The eval run failed.']])
		expect(w.vm.running).toBe(false)
	})

	it('expands one run at a time and lays out its without-skill halves', async () => {
		const w = await mountIt(EvalRunPanelWidget)
		w.vm.toggleExpanded('r1')
		expect(w.vm.expandedRunId).toBe('r1')
		w.vm.toggleExpanded('r1')
		expect(w.vm.expandedRunId).toBeNull()
		expect(w.vm.withoutHalves({ attributionMode: 'per-skill', skillResults: [{ skillId: 'k1', baselineResults: [1] }, { skillId: 'k9' }] })).toEqual([
			{ key: 'wo-k1', title: 'Without Summarise — per case', results: [1] },
			{ key: 'wo-k9', title: 'Without k9 — per case', results: [] },
		])
		expect(w.vm.withoutHalves({ baselineResults: [2] })).toEqual([{ key: 'wo-joint', title: 'Without skills — per case', results: [2] }])
		expect(w.vm.withoutHalves({ attributionMode: 'per-skill' })).toEqual([])
	})

	it('labels baselines, modes, cases, regressions and statuses', async () => {
		const w = await mountIt(EvalRunPanelWidget)
		expect([w.vm.baselineCellLabel({ attributionMode: 'per-skill' }), w.vm.baselineCellLabel({ baselinePassRate: 0.5 })]).toEqual(['per-skill', '50%'])
		expect(w.vm.pairedModeLabel('per-skill')).toBe('Paired baseline — per-skill marginals')
		expect(w.vm.pairedModeLabel('joint')).toBe('Paired baseline — joint contribution of the linked set')
		expect(w.vm.caseOutcomeLabel({ passed: true, prompt: 'Short' })).toBe('passed — Short')
		expect(w.vm.caseOutcomeLabel({ passed: false, prompt: 'x'.repeat(70) })).toBe(`failed — ${'x'.repeat(60)}…`)
		expect(w.vm.caseOutcomeLabel({ passed: false })).toBe('failed — ')
		expect(['failed', 'passed', null].map(w.vm.regressionLabel)).toEqual(['Regressed', 'No regression', 'First run'])
		expect(['failed', 'passed', null].map(w.vm.regressionBadgeClass)).toEqual([
			'eval-run-panel-widget__badge--error', 'eval-run-panel-widget__badge--ok', 'eval-run-panel-widget__badge--neutral',
		])
		expect(['completed', 'failed', 'blocked_killswitch', 'blocked_budget', 'odd'].map(w.vm.statusLabel))
			.toEqual(['Completed', 'Failed', 'Blocked (kill switch)', 'Blocked (budget)', 'odd'])
	})
})

describe('SkillEvalEvidence mounted', () => {
	beforeEach(() => {
		skillStore.fetchObject.mockResolvedValue({ levelEvidence: { l5: { passRate: 0.9, lastValidated: '2026-01-01', mode: 'joint' } } })
		datasetStore.fetchCollection.mockResolvedValue([
			{ uuid: 'd1', name: 'Core', skillRefs: ['x1', 'k2'] },
			{ id: 'd2', skillRefs: ['x1'] },
			{ uuid: 'd3', skillRefs: ['other'] },
		])
	})

	it('shows the stored evidence and the datasets this skill is linked to', async () => {
		const w = await mountIt(SkillEvalEvidence)
		expect([w.vm.hasEvidence, w.vm.deltaTermLabel]).toEqual([true, 'Baseline delta (joint, linked set)'])
		expect(w.vm.datasetOptions).toEqual([{ label: 'Core', value: 'd1' }, { label: 'd2', value: 'd2' }])
		expect(w.vm.emptyStateText).toContain('Run a paired eval below')
		w.vm.selectedDataset = { value: 'd1' }
		expect(w.vm.selectedDatasetSkillCount).toBe(2)
		w.vm.selectedDataset = null
		expect(w.vm.selectedDatasetSkillCount).toBe(1)
	})

	it('names the delta per mode and asks to link a dataset when none is', async () => {
		datasetStore.fetchCollection.mockResolvedValueOnce([])
		const w = await mountIt(SkillEvalEvidence)
		expect(w.vm.emptyStateText).toContain('Link this skill to an evaluation dataset')
		w.vm.skill = { levelEvidence: { l5: { mode: 'per-skill' } } }
		expect([w.vm.deltaTermLabel, w.vm.hasEvidence]).toEqual(['Baseline delta (per-skill marginal)', false])
		w.vm.skill = { levelEvidence: { l5: 'x' } }
		expect([w.vm.l5, w.vm.deltaTermLabel]).toEqual([{}, 'Baseline delta'])
	})

	it('trends the last completed paired runs that measured this skill, oldest first', async () => {
		runStore.fetchCollection.mockResolvedValueOnce([
			{ id: 'r2', baselineMode: true, status: 'completed', startedAt: '2026-02-01', skillResults: [{ skillId: 'x1', passRateWith: 0.7 }] },
			{ uuid: 'r1', baselineMode: true, status: 'completed', startedAt: '2026-01-01', skillResults: [{ skillId: 'x1', passRateWith: 0.6 }] },
			{ id: 'r3', baselineMode: true, status: 'failed', skillResults: [{ skillId: 'x1' }] },
			{ id: 'r4', baselineMode: false, status: 'completed', skillResults: [{ skillId: 'x1' }] },
			{ id: 'r5', baselineMode: true, status: 'completed', skillResults: [{ skillId: 'other' }] },
			{ id: 'r6', baselineMode: true, status: 'completed' },
		])
		const w = await mountIt(SkillEvalEvidence)
		expect(w.vm.trend).toEqual([
			{ id: 'r1', startedAt: '2026-01-01', passRateWith: 0.6 },
			{ id: 'r2', startedAt: '2026-02-01', passRateWith: 0.7 },
		])
	})

	it('runs a paired eval only with a dataset and an agent, and reports the outcome', async () => {
		const w = await mountIt(SkillEvalEvidence)
		await w.vm.runPaired()
		expect(evals.runEval).not.toHaveBeenCalled()
		w.vm.selectedDataset = { value: 'd1' }
		w.vm.selectedAgent = { value: 'a1' }
		evals.runEval.mockResolvedValueOnce({ passRate: 1 })
		await w.vm.runPaired()
		expect(evals.runEval).toHaveBeenCalledWith('d1', 'a1', { baseline: true })
		evals.runEval.mockRejectedValueOnce({ response: { data: { error: 'No budget' } } })
		await w.vm.runPaired()
		evals.runEval.mockRejectedValueOnce({})
		await w.vm.runPaired()
		expect(toasts).toEqual([['success', 'Paired eval complete: 100% passed.'], ['error', 'No budget'], ['error', 'The paired eval failed.']])
		expect(w.vm.selectedAgentMode).toBe('per-skill')
		expect(w.vm.costNote).toContain('times')
	})
})

describe('SkillMaturityScorecard mounted', () => {
	const evidence = {
		l1: { passed: true, checkedAt: '2026-01-01T00:00:00Z' },
		l2: { passed: false },
		l4: { attestedBy: 'dpo', attestedAt: '2026-01-02T00:00:00Z', note: 'Reviewed' },
		l5: { lastValidated: '2026-01-03T00:00:00Z', passRate: 0.9 },
		l6: { lastConsolidatedAt: '2026-01-04T00:00:00Z' },
		l7: { lastExecutedAt: '2026-01-05T00:00:00Z' },
	}

	beforeEach(() => {
		skillStore.fetchObject.mockResolvedValue({ maturityLevel: 5, targetLevel: 6, levelEvidence: evidence })
	})

	it('lays out the seven levels from the stored evidence', async () => {
		const w = await mountIt(SkillMaturityScorecard)
		expect(skillStore.fetchObject).toHaveBeenCalledWith('agentskill', 'x1')
		expect([w.vm.maturityLevel, w.vm.targetLabel]).toEqual([5, 'Target: L6'])
		const rows = w.vm.rows
		expect(rows.map((r) => r.passed)).toEqual([true, false, true, true, true, false, false])
		expect(rows[0].evidence).toMatch(/^Checked /)
		expect(rows[1].evidence).toBe('')
		expect(rows[3].evidence).toMatch(/^Attested by dpo on .* — Reviewed$/)
		expect(rows[4].evidence).toMatch(/^Pass rate 0.9, baseline delta —, validated /)
		expect(rows[5].evidence).toMatch(/^0 learnings, consolidated /)
		expect(rows[6].evidence).toMatch(/^Chain executed /)
	})

	it('prefers a fresh scorecard over the stored evidence', async () => {
		skills.qualifySkill.mockResolvedValueOnce({ scorecard: [{ level: 2, passed: true, reasons: [] }, { level: 6, passed: false, reasons: ['No learnings'] }] })
		const w = await mountIt(SkillMaturityScorecard)
		await w.vm.doQualify()
		expect(skills.qualifySkill).toHaveBeenCalledWith('x1')
		expect(w.vm.rows[1].passed).toBe(true)
		expect(w.vm.rows[5].reasons).toEqual(['No learnings'])
	})

	it('records an attestation with a trimmed note', async () => {
		skills.attestSkillL4.mockResolvedValueOnce({})
		const w = await mountIt(SkillMaturityScorecard)
		w.vm.attestNote = ' ok '
		await w.vm.doAttest()
		expect(skills.attestSkillL4).toHaveBeenCalledWith('x1', 'ok')
		expect([w.vm.attestNote, w.vm.scorecard]).toEqual(['', []])
	})

	it('reports load, qualify and attest failures', async () => {
		skillStore.fetchObject.mockRejectedValueOnce({ response: { data: { error: 'Gone' } } })
		const w = await mountIt(SkillMaturityScorecard)
		expect([w.vm.skill, w.vm.error, w.vm.maturityLevel, w.vm.targetLabel]).toEqual([null, 'Gone', 0, 'No target level set'])
		skills.qualifySkill.mockRejectedValueOnce(new Error('Qualify down'))
		await w.vm.doQualify()
		expect(w.vm.error).toBe('Qualify down')
		skills.attestSkillL4.mockRejectedValueOnce({})
		await w.vm.doAttest()
		expect([w.vm.attestError, w.vm.attesting]).toEqual(['Unknown error', false])
		skillStore.fetchObject.mockRejectedValueOnce({})
		await w.vm.fetchSkill()
		expect(w.vm.error).toBe('Could not load the skill.')
	})

	it('treats an unset level as passed by the maturity level, and formats a bad date as empty', async () => {
		skillStore.fetchObject.mockResolvedValueOnce({ maturityLevel: 3 })
		const w = await mountIt(SkillMaturityScorecard)
		expect(w.vm.rows.map((r) => r.passed)).toEqual([true, true, true, false, false, false, false])
		expect(w.vm.rows.every((r) => r.evidence === '')).toBe(true)
		expect(w.vm.formatDate('nope')).toBe('')
	})
})
