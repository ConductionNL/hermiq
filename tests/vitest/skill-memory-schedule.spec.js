/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The skill version history and draft review widgets, the agent memory panel,
 * and the schedule form, mounted with their API and stores doubled.
 */

import { flushPromises, shallowMount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const bus = []
vi.mock('@nextcloud/event-bus', () => ({
	emit: (name, payload) => bus.push(['emit', name, payload]),
	subscribe: (name, fn) => bus.push(['subscribe', name, fn]),
	unsubscribe: (name, fn) => bus.push(['unsubscribe', name, fn]),
}))
vi.mock('../../src/api/skills.js', () => ({
	diffSkillVersions: vi.fn(),
	listSkillVersions: vi.fn(),
	republishSkill: vi.fn(),
	rollbackSkill: vi.fn(),
	acceptSkillDraft: vi.fn(),
	listSkillDrafts: vi.fn(),
	proposeSkillImprovement: vi.fn(),
	rejectSkillDraft: vi.fn(),
	updateSkillDraftContent: vi.fn(),
}))
vi.mock('../../src/api/memory.js', () => ({ addMemory: vi.fn(), consolidateMemory: vi.fn(), correctMemoryEntry: vi.fn(), forgetMemoryEntry: vi.fn(), getMemory: vi.fn() }))
vi.mock('../../src/api/budgets.js', () => ({ getBudgetEstimate: vi.fn() }))
const skillStore = { registerObjectType: vi.fn(), fetchObject: vi.fn() }
const datasetStore = { registerObjectType: vi.fn(), fetchCollection: vi.fn() }
const runStore = { registerObjectType: vi.fn(), fetchCollection: vi.fn() }
const scheduleStore = { registerObjectType: vi.fn(), saveObject: vi.fn(), errors: {} }
vi.mock('../../src/store/store.js', () => ({
	useSkillStore: () => skillStore,
	useEvalDatasetStore: () => datasetStore,
	useEvalRunStore: () => runStore,
	useScheduleStore: () => scheduleStore,
}))

const skills = await import('../../src/api/skills.js')
const memoryApi = await import('../../src/api/memory.js')
const budgets = await import('../../src/api/budgets.js')
const { default: SkillVersionHistory } = await import('../../src/widgets/SkillVersionHistory.vue')
const { default: SkillDraftReview } = await import('../../src/widgets/SkillDraftReview.vue')
const { default: AgentMemoryPanel } = await import('../../src/components/AgentMemoryPanel.vue')
const { default: ScheduleFormModal } = await import('../../src/modals/ScheduleFormModal.vue')

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
 * Mount a component with the route of skill k1.
 *
 * @param {object} component The component.
 * @param {object} [props] Its props.
 * @return {Promise<object>} The wrapper.
 */
async function mountIt(component, props = {}) {
	const w = shallowMount(component, { props, global: { mocks: { t, $route: { params: { id: 'k1' } } } } })
	await flushPromises()
	return w
}

beforeEach(() => {
	bus.length = 0
	vi.clearAllMocks()
	scheduleStore.errors = {}
})

describe('SkillVersionHistory', () => {
	beforeEach(() => {
		skillStore.fetchObject.mockResolvedValue({ id: 'k1', githubRepo: 'r', publishedAt: '2026-01-01', lastAcceptedVersionAt: '2026-02-01' })
		skills.listSkillVersions.mockResolvedValue([{ id: 'v2' }, { id: 'v1' }])
		datasetStore.fetchCollection.mockResolvedValue({ results: [{ uuid: 'd1', skillRefs: ['k1'] }, { uuid: 'd2', skillRefs: ['other'] }] })
		runStore.fetchCollection.mockResolvedValue([
			{ datasetId: 'd2', regressionGateResult: 'failed', endedAt: '2026-03-01' },
			{ datasetId: 'd1', regressionGateResult: 'failed', endedAt: '2026-01-15' },
		])
	})

	it('loads the skill, its versions and the linked eval evidence', async () => {
		const w = await mountIt(SkillVersionHistory)
		expect(w.vm.versions).toEqual([{ id: 'v2' }, { id: 'v1' }])
		expect(w.vm.datasets).toHaveLength(2)
		expect(w.vm.publishedCopyBehind).toBe(true)
		expect(w.vm.rollbackSuggestion).toBe(false)
		expect(bus[0][1]).toBe('cn:page:refresh')
	})

	it('suggests a rollback only after a failed regression gate on a linked dataset since the last accept', async () => {
		runStore.fetchCollection.mockResolvedValueOnce({ results: [{ datasetId: 'd1', regressionGateResult: 'failed', endedAt: '2026-03-01' }] })
		const w = await mountIt(SkillVersionHistory)
		expect(w.vm.rollbackSuggestion).toBe(true)
		w.vm.skill = { lastAcceptedVersionAt: '' }
		expect(w.vm.rollbackSuggestion).toBe(false)
		expect(w.vm.publishedCopyBehind).toBe(false)
	})

	it('treats unreadable evidence as none, and shows a failed load', async () => {
		datasetStore.fetchCollection.mockRejectedValueOnce(new Error('x'))
		runStore.fetchCollection.mockResolvedValueOnce(null)
		const w = await mountIt(SkillVersionHistory)
		expect([w.vm.datasets, w.vm.evalRuns]).toEqual([[], []])
		skills.listSkillVersions.mockRejectedValueOnce({})
		await w.vm.load()
		expect(w.vm.error).toBe('Could not load the version history.')
	})

	it('diffs a version against the newest one', async () => {
		skills.diffSkillVersions.mockResolvedValueOnce({ body: { from: 'a', to: 'b' } })
		const w = await mountIt(SkillVersionHistory)
		await w.vm.showDiff('v1')
		expect(skills.diffSkillVersions).toHaveBeenCalledWith('k1', 'v1', 'v2')
		expect([w.vm.diffVersionId, w.vm.diff]).toEqual(['v1', { body: { from: 'a', to: 'b' } }])
		skills.diffSkillVersions.mockRejectedValueOnce(new Error('Diff down'))
		await w.vm.showDiff('v1')
		expect(w.vm.error).toBe('Diff down')
	})

	it('rolls back and republishes, reloading afterwards', async () => {
		const w = await mountIt(SkillVersionHistory)
		w.vm.diffVersionId = 'v1'
		await w.vm.doRollback('v1')
		expect(skills.rollbackSkill).toHaveBeenCalledWith('k1', 'v1')
		expect(w.vm.diffVersionId).toBe('')
		w.vm.republishCredentialId = ' c1 '
		await w.vm.doRepublish()
		expect(skills.republishSkill).toHaveBeenCalledWith('k1', 'c1')
		skills.rollbackSkill.mockRejectedValueOnce({})
		await w.vm.doRollback('v1')
		expect(w.vm.error).toBe('Rollback failed.')
		skills.republishSkill.mockRejectedValueOnce({})
		await w.vm.doRepublish()
		expect(w.vm.error).toBe('Republish failed.')
		expect(w.vm.busy).toBe(false)
	})

	it('formats values and dates for the diff table', async () => {
		const w = await mountIt(SkillVersionHistory)
		expect([null, 'a', { b: 1 }].map(w.vm.stringify)).toEqual(['—', 'a', '{\n  "b": 1\n}'])
		expect([w.vm.formatDate(''), w.vm.formatDate('nope')]).toEqual(['—', 'nope'])
		w.unmount()
		expect(bus.at(-1)[0]).toBe('unsubscribe')
	})
})

describe('SkillDraftReview', () => {
	const pending = {
		uuid: 'd1', status: 'awaiting-approval', proposedBody: 'keep\nnew line\n',
		provenance: { learningRefs: ['l1', '', 3] },
		evalEvidence: { delta: -0.05, draftPassRate: 0.7, activePassRate: 0.75 },
	}

	beforeEach(() => {
		skillStore.fetchObject.mockResolvedValue({ body: 'keep\nold line' })
		skills.listSkillDrafts.mockResolvedValue([{ status: 'proposed', uuid: 'q' }, pending])
	})

	it('shows the pending draft as a line diff with its evidence and learnings', async () => {
		const w = await mountIt(SkillDraftReview)
		expect(w.vm.pendingDraft.uuid).toBe('d1')
		expect(w.vm.qualifyingDraft.uuid).toBe('q')
		expect(w.vm.learningRefs).toEqual(['l1'])
		expect(w.vm.diffLines).toEqual([{ kind: 'removed', text: 'old line' }, { kind: 'added', text: 'new line' }])
		expect(w.vm.evalDeltaLabel).toBe('Eval: draft 70% vs active 75% (delta -5%)')
		w.vm.drafts = [{ ...pending, evalEvidence: { delta: 0.1 } }]
		expect(w.vm.evalDeltaLabel).toBe('Eval: draft 0% vs active 0% (delta +10%)')
	})

	it('has nothing to show without a draft or a body', async () => {
		skills.listSkillDrafts.mockResolvedValueOnce([])
		skillStore.fetchObject.mockResolvedValueOnce(null)
		const w = await mountIt(SkillDraftReview)
		expect([w.vm.pendingDraft, w.vm.qualifyingDraft, w.vm.activeBody, w.vm.proposedBody, w.vm.learningRefs, w.vm.evalDeltaLabel, w.vm.diffLines])
			.toEqual([null, null, '', '', [], '', []])
	})

	it('proposes, accepts, edits and rejects, refreshing the page after a decision', async () => {
		const w = await mountIt(SkillDraftReview)
		await w.vm.doPropose()
		expect(skills.proposeSkillImprovement).toHaveBeenCalledWith('k1')
		await w.vm.doAccept()
		expect(skills.acceptSkillDraft).toHaveBeenCalledWith('d1')
		w.vm.openEditor()
		expect(w.vm.showEditor).toBe(true)
		await w.vm.doEditContent({ body: 'x' })
		expect(skills.updateSkillDraftContent).toHaveBeenCalledWith('d1', { body: 'x' })
		expect(w.vm.showEditor).toBe(false)
		w.vm.showReject = true
		await w.vm.doReject({ note: 'no', rejectedLearningRefs: ['l1'] })
		expect(skills.rejectSkillDraft).toHaveBeenCalledWith('d1', 'no', ['l1'])
		expect(w.vm.showReject).toBe(false)
		expect(bus.filter((e) => e[0] === 'emit')).toHaveLength(2)
	})

	it.each([
		['doPropose', 'proposeSkillImprovement', 'Propose failed.'],
		['doAccept', 'acceptSkillDraft', 'Accept failed.'],
		['doEditContent', 'updateSkillDraftContent', 'Edit failed.'],
		['doReject', 'rejectSkillDraft', 'Reject failed.'],
	])('%s shows its failure', async (method, call, message) => {
		const w = await mountIt(SkillDraftReview)
		skills[call].mockRejectedValueOnce({})
		await w.vm[method]({ note: '', rejectedLearningRefs: [] })
		expect(w.vm.error).toBe(message)
		expect(w.vm.busy).toBe(false)
	})

	it('shows a failed load', async () => {
		skills.listSkillDrafts.mockRejectedValueOnce({ response: { data: { error: 'Gone' } } })
		const w = await mountIt(SkillDraftReview)
		expect(w.vm.error).toBe('Gone')
	})
})

describe('AgentMemoryPanel', () => {
	const memory = { entries: [{ id: 'e1', text: 'abcd' }, { id: 'e2', text: 'xy', deletedAt: '2026-01-01' }], charBudget: 8 }

	beforeEach(() => {
		memoryApi.getMemory.mockResolvedValue(memory)
	})

	it('loads nothing without an agent', async () => {
		const w = await mountIt(AgentMemoryPanel)
		expect(memoryApi.getMemory).not.toHaveBeenCalled()
		expect(w.vm.entries).toEqual([])
		expect(w.vm.budgetPct).toBe(0)
	})

	it('counts active entries against the budget, hiding forgotten ones unless asked', async () => {
		const w = await mountIt(AgentMemoryPanel, { agentId: 'a1' })
		expect(w.vm.activeEntries.map((e) => e.id)).toEqual(['e1'])
		expect(w.vm.forgottenCount).toBe(1)
		expect(w.vm.charCount).toBe(4)
		expect(w.vm.budgetPct).toBe(50)
		expect(w.vm.visibleEntries).toHaveLength(1)
		w.vm.showForgotten = true
		expect(w.vm.visibleEntries).toHaveLength(2)
		w.vm.memory = { entries: [{ text: 'x'.repeat(20000) }], charBudget: 0 }
		expect([w.vm.charBudget, w.vm.budgetPct]).toEqual([8000, 100])
	})

	it('falls back to an empty memory and shows a failed load', async () => {
		memoryApi.getMemory.mockResolvedValueOnce(null)
		const w = await mountIt(AgentMemoryPanel, { agentId: 'a1' })
		expect(w.vm.entries).toEqual([])
		memoryApi.getMemory.mockRejectedValueOnce(new Error('Memory down'))
		await w.vm.load()
		expect(w.vm.error).toBe('Memory down')
	})

	it('adds a trimmed fact, and nothing blank', async () => {
		memoryApi.addMemory.mockResolvedValueOnce({ entries: [{ id: 'n', text: 'fact' }] })
		const w = await mountIt(AgentMemoryPanel, { agentId: 'a1' })
		w.vm.newEntry = '  '
		await w.vm.addFact()
		expect(memoryApi.addMemory).not.toHaveBeenCalled()
		w.vm.newEntry = ' fact '
		await w.vm.addFact()
		expect(memoryApi.addMemory).toHaveBeenCalledWith('a1', 'fact')
		expect([w.vm.newEntry, w.vm.entries]).toEqual(['', [{ id: 'n', text: 'fact' }]])
	})

	it('consolidates, corrects and forgets entries', async () => {
		memoryApi.consolidateMemory.mockResolvedValueOnce({ entries: [] })
		memoryApi.correctMemoryEntry.mockResolvedValueOnce({ entries: [{ id: 'e1', text: 'new' }] })
		memoryApi.forgetMemoryEntry.mockResolvedValueOnce({ entries: [] })
		const w = await mountIt(AgentMemoryPanel, { agentId: 'a1' })
		await w.vm.consolidate()
		expect(memoryApi.consolidateMemory).toHaveBeenCalledWith('a1')
		w.vm.startCorrection({ id: 'e1', text: 'old' })
		expect([w.vm.editingId, w.vm.editText]).toEqual(['e1', 'old'])
		w.vm.editText = ' new '
		await w.vm.saveCorrection({ id: 'e1' })
		expect(memoryApi.correctMemoryEntry).toHaveBeenCalledWith('a1', 'e1', 'new')
		expect(w.vm.editingId).toBe('')
		await w.vm.confirmForget()
		expect(memoryApi.forgetMemoryEntry).not.toHaveBeenCalled()
		w.vm.forgetTarget = { id: 'e1' }
		await w.vm.confirmForget()
		expect(memoryApi.forgetMemoryEntry).toHaveBeenCalledWith('a1', 'e1')
		expect(w.vm.forgetTarget).toBeNull()
	})

	it('keeps the editor open with a blank correction, and shows each failure', async () => {
		const w = await mountIt(AgentMemoryPanel, { agentId: 'a1' })
		w.vm.startCorrection({ id: 'e1', text: 'old' })
		w.vm.editText = ' '
		await w.vm.saveCorrection({ id: 'e1' })
		expect(memoryApi.correctMemoryEntry).not.toHaveBeenCalled()
		for (const [call, run] of [
			['addMemory', () => { w.vm.newEntry = 'x'; return w.vm.addFact() }],
			['consolidateMemory', () => w.vm.consolidate()],
			['correctMemoryEntry', () => { w.vm.editText = 'x'; return w.vm.saveCorrection({ id: 'e1' }) }],
			['forgetMemoryEntry', () => { w.vm.forgetTarget = { id: 'e1' }; return w.vm.confirmForget() }],
		]) {
			memoryApi[call].mockRejectedValueOnce(new Error(`${call} failed`))
			await run()
			expect(w.vm.error).toBe(`${call} failed`)
		}
		expect(w.vm.forgetTarget).toEqual({ id: 'e1' })
	})

	it('reloads when the agent changes and formats dates', async () => {
		const w = await mountIt(AgentMemoryPanel, { agentId: 'a1' })
		await w.setProps({ agentId: 'a2' })
		await flushPromises()
		expect(memoryApi.getMemory).toHaveBeenLastCalledWith('a2')
		memoryApi.consolidateMemory.mockResolvedValueOnce(memory)
		await w.vm.consolidate()
		expect([w.vm.formatDate(''), w.vm.formatDate('x')]).toEqual(['—', 'x'])
		expect(w.vm.isForgotten(null)).toBe(false)
		await w.setProps({ agentId: '' })
		await flushPromises()
		await w.vm.consolidate()
		expect(memoryApi.consolidateMemory).toHaveBeenCalledTimes(1)
	})
})

describe('ScheduleFormModal', () => {
	/**
	 * Mount the schedule form, then open it.
	 *
	 * @param {object|null} schedule The schedule being edited.
	 * @return {Promise<object>} The wrapper.
	 */
	async function openForm(schedule = null) {
		budgets.getBudgetEstimate.mockResolvedValue({ tokens: 100 })
		const w = shallowMount(ScheduleFormModal, { props: { show: false, agentId: 'a1', schedule }, global: { mocks: { t } } })
		await w.setProps({ show: true })
		await flushPromises()
		return w
	}

	it('opens blank for a new schedule with the run estimate', async () => {
		const w = await openForm()
		expect(w.vm.heading).toBe('Attach schedule')
		expect(w.vm.form).toMatchObject({ kind: 'cron', cronExpr: '0 8 * * *', deliver: 'none', enabled: true })
		expect(w.vm.estimate).toEqual({ tokens: 100 })
		expect(w.vm.kindOption.value).toBe('cron')
		expect(w.vm.deliverOption.value).toBe('none')
		expect(w.vm.reviewerTypeOption.value).toBe('user')
	})

	it('opens an existing schedule with its values and defaults', async () => {
		const w = await openForm({ id: 's1', name: 'Daily', kind: 'interval', intervalMinutes: 30, enabled: false, repeat: { times: 4 }, requiresApproval: true, reviewer: 'boss', reviewerType: 'group', retryEnabled: true })
		expect(w.vm.heading).toBe('Edit schedule')
		expect(w.vm.form).toMatchObject({ name: 'Daily', kind: 'interval', intervalMinutes: 30, enabled: false, repeatTimes: 4, requiresApproval: true, reviewerType: 'group', retryEnabled: true, retryMaxAttempts: 3 })
	})

	it('maps the select options onto the form, with fallbacks for a cleared select', async () => {
		const w = await openForm()
		w.vm.kindOption = { value: 'once' }
		w.vm.deliverOption = { value: 'talk' }
		w.vm.reviewerTypeOption = { value: 'group' }
		expect([w.vm.form.kind, w.vm.form.deliver, w.vm.form.reviewerType]).toEqual(['once', 'talk', 'group'])
		w.vm.kindOption = null
		w.vm.deliverOption = null
		w.vm.reviewerTypeOption = null
		expect([w.vm.form.kind, w.vm.form.deliver, w.vm.form.reviewerType]).toEqual(['once', 'none', 'user'])
		w.vm.form.kind = 'unknown'
		expect(w.vm.kindOption.value).toBe('once')
	})

	it('builds the payload per trigger kind with only the options that apply', async () => {
		const w = await openForm({ id: 's1' })
		Object.assign(w.vm.form, { name: 'N', kind: 'interval', intervalMinutes: '0', deliver: 'webhook', deliverTarget: 'https://x', repeatTimes: '2', requiresApproval: true, reviewer: '', retryEnabled: true, retryMaxAttempts: 'x', retryBackoffBaseSeconds: 5, circuitBreakerThreshold: 0 })
		expect(w.vm.buildPayload()).toEqual({
			name: 'N', agentId: 'a1', kind: 'interval', prompt: '', deliver: 'webhook', enabled: true,
			intervalMinutes: 1, deliverTarget: 'https://x', repeat: { times: 2, completed: 0 },
			requiresApproval: true, reviewer: '', reviewerType: 'user',
			retryEnabled: true, retryMaxAttempts: 3, retryBackoffBaseSeconds: 5, circuitBreakerThreshold: 3, id: 's1',
		})
		Object.assign(w.vm.form, { kind: 'once', runAt: '2026-05-01T10:00:00Z', deliver: 'notification', requiresApproval: false, retryEnabled: false, repeatTimes: '' })
		const once = w.vm.buildPayload()
		expect(once.runAt).toBe('2026-05-01T10:00:00.000Z')
		expect(once).not.toHaveProperty('deliverTarget')
		expect(once).not.toHaveProperty('repeat')
		expect(once).not.toHaveProperty('reviewer')
		expect(once).not.toHaveProperty('retryEnabled')
		w.vm.form.kind = 'cron'
		expect(w.vm.buildPayload().cronExpr).toBe('0 8 * * *')
		w.vm.form.repeatTimes = '1'
		expect(w.vm.buildPayload().repeat).toEqual({ times: 1, completed: 0 })
	})

	it('saves and closes, or shows why it could not', async () => {
		const w = await openForm()
		scheduleStore.saveObject.mockResolvedValueOnce({ id: 's9' })
		await w.vm.save()
		expect(w.emitted('saved')).toEqual([[{ id: 's9' }]])
		expect(w.emitted('close')).toHaveLength(1)
		scheduleStore.saveObject.mockResolvedValueOnce(null)
		scheduleStore.errors = { schedule: { message: 'Bad cron' } }
		await w.vm.save()
		expect(w.vm.error).toBe('Bad cron')
		scheduleStore.saveObject.mockResolvedValueOnce(null)
		scheduleStore.errors = {}
		await w.vm.save()
		expect(w.vm.error).toBe('Could not save schedule')
		scheduleStore.saveObject.mockRejectedValueOnce({})
		await w.vm.save()
		expect(w.vm.error).toBe('Unknown error')
		expect(w.emitted('saved')).toHaveLength(1)
		expect(w.vm.saving).toBe(false)
	})

	it('has no estimate when it cannot be fetched', async () => {
		const w = shallowMount(ScheduleFormModal, { props: { show: false, agentId: 'a1' }, global: { mocks: { t } } })
		budgets.getBudgetEstimate.mockRejectedValueOnce(new Error('x'))
		await w.setProps({ show: true })
		await flushPromises()
		expect(w.vm.estimate).toBeNull()
	})
})
