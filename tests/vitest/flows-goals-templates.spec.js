/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The flow run comparison page, the goal form, the agent template row actions,
 * the AI feature binding dialog and the session participants dialog, mounted
 * with their API doubled.
 */

import { flushPromises, shallowMount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const bus = []
vi.mock('@nextcloud/event-bus', () => ({ emit: (name, payload) => bus.push([name, payload]) }))
vi.mock('../../src/api/flowRuns.js', () => ({ getFlowRun: vi.fn(), listFlowRuns: vi.fn(), listFlows: vi.fn() }))
vi.mock('../../src/api/chat.js', () => ({ setSessionGoal: vi.fn(), addParticipant: vi.fn(), listParticipants: vi.fn(), removeParticipant: vi.fn(), searchUsers: vi.fn() }))
vi.mock('../../src/api/agentTemplates.js', () => ({ approveAgentTemplate: vi.fn(), exportAgentTemplate: vi.fn(), instantiateAgentTemplate: vi.fn() }))
vi.mock('../../src/api/aiFeatures.js', () => ({ bindAiFeature: vi.fn() }))
vi.mock('../../src/api/modelPolicy.js', () => ({ getEffectiveModelPolicy: vi.fn() }))

const flowRuns = await import('../../src/api/flowRuns.js')
const chat = await import('../../src/api/chat.js')
const templates = await import('../../src/api/agentTemplates.js')
const features = await import('../../src/api/aiFeatures.js')
const policy = await import('../../src/api/modelPolicy.js')
const { default: FlowRunCompare } = await import('../../src/views/FlowRunCompare.vue')
const { default: GoalFormModal } = await import('../../src/modals/GoalFormModal.vue')
const { default: AgentTemplateRowActions } = await import('../../src/widgets/AgentTemplateRowActions.vue')
const { default: AiFeatureBindingModal } = await import('../../src/modals/AiFeatureBindingModal.vue')
const { default: SessionParticipantsModal } = await import('../../src/modals/SessionParticipantsModal.vue')

/**
 * Translate as the identity, filling {placeholders}.
 *
 * @param {string} app The app id.
 * @param {string} text The text.
 * @param {object} vars The placeholders.
 * @return {string} The filled text.
 */
const t = (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m))
globalThis.t = t

/**
 * Mount a component and let its loads settle.
 *
 * @param {object} component The component.
 * @param {object} [props] Its props.
 * @param {object} [mocks] Extra global mocks.
 * @return {Promise<object>} The wrapper.
 */
async function mountIt(component, props = {}, mocks = {}) {
	const w = shallowMount(component, { props, global: { mocks: { t, ...mocks } } })
	await flushPromises()
	return w
}

beforeEach(() => {
	bus.length = 0
	vi.clearAllMocks()
})

describe('FlowRunCompare', () => {
	beforeEach(() => {
		flowRuns.listFlows.mockResolvedValue([{ uuid: 'f1', name: 'Intake' }, { uuid: 'f2' }])
		flowRuns.listFlowRuns.mockResolvedValue([{ uuid: 'r1' }, { uuid: 'r2' }])
	})

	it('loads the flows and preselects the one in the route, with its runs', async () => {
		const w = await mountIt(FlowRunCompare, {}, { $route: { query: { flow: 'f2' } } })
		expect(w.vm.flowOptions).toEqual([{ label: 'Intake', value: 'f1' }, { label: 'f2', value: 'f2' }])
		expect(w.vm.flow).toEqual({ label: 'f2', value: 'f2' })
		expect(flowRuns.listFlowRuns).toHaveBeenCalledWith('f2')
		expect(w.vm.runs).toHaveLength(2)
	})

	it('starts with no flow when the route names none, and clears the runs when the flow is cleared', async () => {
		const w = await mountIt(FlowRunCompare, {}, { $route: { query: {} } })
		expect(w.vm.flow).toBeNull()
		w.vm.flow = { value: 'f1' }
		await flushPromises()
		w.vm.toggle('r1')
		w.vm.flow = null
		await flushPromises()
		expect([w.vm.runs, w.vm.selected, w.vm.result]).toEqual([[], [], null])
	})

	it('compares two selected runs node by node', async () => {
		flowRuns.getFlowRun.mockImplementation(async (uuid) => ({ log: [{ node: 'a', status: uuid === 'r1' ? 'ok' : 'failed' }] }))
		const w = await mountIt(FlowRunCompare, {}, { $route: { query: {} } })
		w.vm.toggle('r1')
		w.vm.toggle('r2')
		w.vm.toggle('r3')
		expect(w.vm.selectionRefused).toBe(true)
		await w.vm.compare()
		expect(flowRuns.getFlowRun.mock.calls.map((c) => c[0])).toEqual(['r1', 'r2'])
		expect(w.vm.result.rows).toEqual([{ node: 'a', left: { node: 'a', status: 'ok', durationMs: null }, right: { node: 'a', status: 'failed', durationMs: null }, differs: true }])
	})

	it('shows a failed read and describes each node', async () => {
		flowRuns.listFlows.mockRejectedValueOnce({ response: { data: { error: 'No flows' } } })
		const w = await mountIt(FlowRunCompare, {}, { $route: { query: {} } })
		expect([w.vm.error, w.vm.loading]).toEqual(['No flows', false])
		expect(w.vm.nodeText(null)).toBe('Not reached')
		expect(w.vm.nodeText({ status: 'ok', durationMs: null })).toBe('ok')
		expect(w.vm.nodeText({ status: 'ok', durationMs: 12 })).toBe('ok, 12 ms')
	})
})

describe('GoalFormModal', () => {
	/**
	 * Mount the form closed, then open it.
	 *
	 * @param {object|null} session The session the goal is for.
	 * @return {Promise<object>} The wrapper.
	 */
	async function openForm(session = { uuid: 's1' }) {
		const w = shallowMount(GoalFormModal, { props: { show: false, session }, global: { mocks: { t } } })
		await w.setProps({ show: true })
		return w
	}

	it('saves only with a statement and the fields its check needs', async () => {
		const w = await openForm()
		expect(w.vm.canSave).toBe(false)
		w.vm.form.statement = 'Ten cases'
		expect(w.vm.canSave).toBe(false)
		Object.assign(w.vm.form, { register: 'r', schema: 's' })
		expect(w.vm.canSave).toBe(true)
		w.vm.form.kind = 'judge'
		expect(w.vm.canSave).toBe(false)
		w.vm.form.question = 'Done?'
		expect(w.vm.canSave).toBe(true)
	})

	it('sends an object count goal with parsed filters and numeric limits', async () => {
		chat.setSessionGoal.mockResolvedValueOnce({ id: 'g1' })
		const w = await openForm()
		Object.assign(w.vm.form, { statement: ' Ten ', register: ' r ', schema: ' s ', filters: '{"status":"open"}', target: '10', intervalMinutes: 'x', maxTurns: '3' })
		await w.vm.save()
		expect(chat.setSessionGoal).toHaveBeenCalledWith('s1', {
			statement: 'Ten',
			check: { kind: 'objectCount', register: 'r', schema: 's', filters: { status: 'open' }, target: 10 },
			intervalMinutes: 60,
			maxTurns: 3,
		})
		expect(w.emitted('saved')).toEqual([[{ id: 'g1' }]])
	})

	it('sends a judge goal, and refuses invalid filters', async () => {
		const w = await openForm()
		Object.assign(w.vm.form, { statement: 'S', kind: 'judge', question: ' Is it done? ' })
		expect(w.vm.check()).toEqual({ kind: 'judge', question: 'Is it done?' })
		Object.assign(w.vm.form, { kind: 'objectCount', register: 'r', schema: 's', filters: '{bad' })
		await w.vm.save()
		expect(w.vm.error).toBe('The filters are not valid JSON.')
		expect(chat.setSessionGoal).not.toHaveBeenCalled()
	})

	it('saves nothing without a session, and shows a refusal', async () => {
		const none = await openForm(null)
		Object.assign(none.vm.form, { statement: 'S', register: 'r', schema: 's' })
		await none.vm.save()
		expect(chat.setSessionGoal).not.toHaveBeenCalled()
		const w = await openForm()
		Object.assign(w.vm.form, { statement: 'S', register: 'r', schema: 's' })
		chat.setSessionGoal.mockRejectedValueOnce({ response: { data: { error: 'Goal already active' } } })
		await w.vm.save()
		expect(w.vm.error).toBe('Goal already active')
		chat.setSessionGoal.mockRejectedValueOnce({})
		await w.vm.save()
		expect([w.vm.error, w.vm.saving]).toEqual(['The goal could not be saved.', false])
	})
})

describe('AgentTemplateRowActions', () => {
	/**
	 * Mount the actions for a row with a router double.
	 *
	 * @param {object} row The template row.
	 * @return {Promise<object>} The wrapper and router.
	 */
	async function mountRow(row = { uuid: 't1' }) {
		const router = { push: vi.fn() }
		const w = await mountIt(AgentTemplateRowActions, { row }, { $router: router })
		return { w, router }
	}

	it('opens the new agent straight away, or first shows what was adjusted', async () => {
		const { w, router } = await mountRow()
		templates.instantiateAgentTemplate.mockResolvedValueOnce({ agent: { uuid: 'a1' } })
		await w.vm.doInstantiate()
		expect(templates.instantiateAgentTemplate).toHaveBeenCalledWith('t1')
		expect(router.push).toHaveBeenCalledWith('/agents/a1')
		templates.instantiateAgentTemplate.mockResolvedValueOnce({ agent: { uuid: 'a2' }, unresolvedSkillRefs: ['k'] })
		await w.vm.doInstantiate()
		expect(w.vm.instantiateNote.agent.uuid).toBe('a2')
		w.vm.openInstantiatedAgent()
		expect(router.push).toHaveBeenLastCalledWith('/agents/a2')
		expect(w.vm.instantiateNote).toBeNull()
		templates.instantiateAgentTemplate.mockResolvedValueOnce({ modelCoerced: true })
		await w.vm.doInstantiate()
		w.vm.openInstantiatedAgent()
		expect(router.push).toHaveBeenCalledTimes(2)
	})

	it('approves, exports and publishes the template by id', async () => {
		const { w } = await mountRow({ id: 't-id' })
		await w.vm.doApprove()
		expect(templates.approveAgentTemplate).toHaveBeenCalledWith('t-id')
		templates.exportAgentTemplate.mockResolvedValueOnce('PKG')
		await w.vm.doExport()
		expect([w.vm.exportedPackage, w.vm.showExport]).toEqual(['PKG', true])
		w.vm.openPublishModal()
		expect(w.vm.showPublish).toBe(true)
		w.vm.onPublished('https://github.com/o/r')
		expect([w.vm.publishNotice, w.vm.showPublish]).toEqual(['Published to https://github.com/o/r', false])
		expect(bus).toHaveLength(2)
	})

	it.each([
		['doInstantiate', 'instantiateAgentTemplate'],
		['doApprove', 'approveAgentTemplate'],
		['doExport', 'exportAgentTemplate'],
	])('%s shows its failure', async (method, call) => {
		const { w } = await mountRow()
		templates[call].mockRejectedValueOnce(new Error(`${call} down`))
		await w.vm[method]()
		expect(w.vm.error).toBe(`${call} down`)
		expect(w.vm.busy).toBe(false)
	})
})

describe('AiFeatureBindingModal', () => {
	beforeEach(() => {
		policy.getEffectiveModelPolicy.mockResolvedValue({ allowed: [{ provider: 'mistral', models: ['small', 'large'] }, { provider: 'local' }] })
	})

	it('starts on the instance default when nothing is bound', async () => {
		const w = await mountIt(AiFeatureBindingModal, { featureId: 'f1' })
		expect([w.vm.useDefault, w.vm.canSave, w.vm.providerOption, w.vm.residencyOption.value]).toEqual([true, true, null, ''])
		await w.vm.save()
		expect(features.bindAiFeature).toHaveBeenCalledWith('f1', { provider: '', model: '', requiredResidency: '' })
		expect(w.emitted('saved')).toHaveLength(1)
	})

	it('restores a binding to a listed model, and drops the model when the provider changes', async () => {
		const w = await mountIt(AiFeatureBindingModal, { featureId: 'f1', current: { provider: 'mistral', model: 'large', requiredResidency: 'eu' } })
		expect([w.vm.useDefault, w.vm.providerOption.value, w.vm.modelOption.value, w.vm.residencyOption.value]).toEqual([false, 'mistral', 'large', 'eu'])
		expect(w.vm.modelOptions.map((o) => o.value)).toEqual(['small', 'large'])
		expect([w.vm.anyModel, w.vm.chosenModel, w.vm.canSave]).toEqual([false, 'large', true])
		w.vm.providerOption = { value: 'local' }
		await flushPromises()
		expect(w.vm.modelOption).toBeNull()
		expect([w.vm.anyModel, w.vm.canSave]).toEqual([true, false])
		w.vm.freeModel = ' llama '
		expect(w.vm.chosenModel).toBe('llama')
		await w.vm.save()
		expect(features.bindAiFeature).toHaveBeenCalledWith('f1', { provider: 'local', model: 'llama', requiredResidency: 'eu' })
	})

	it('fills the free model for a provider that allows any model', async () => {
		const w = await mountIt(AiFeatureBindingModal, { featureId: 'f1', current: { provider: 'local', model: 'mine' } })
		expect(w.vm.freeModel).toBe('mine')
	})

	it('shows a failed policy read and a refused binding', async () => {
		policy.getEffectiveModelPolicy.mockRejectedValueOnce(new Error('Policy down'))
		const w = await mountIt(AiFeatureBindingModal, { featureId: 'f1' })
		expect([w.vm.error, w.vm.allowed]).toEqual(['Policy down', []])
		features.bindAiFeature.mockRejectedValueOnce({ response: { data: { error: 'Not allowed' } } })
		await w.vm.save()
		expect([w.vm.error, w.vm.busy]).toEqual(['Not allowed', false])
		expect(w.emitted('saved')).toBeUndefined()
	})
})

describe('SessionParticipantsModal', () => {
	const session = { uuid: 's1', userId: 'owner' }

	/**
	 * Mount the dialog closed, then open it.
	 *
	 * @return {Promise<object>} The wrapper.
	 */
	async function openDialog() {
		const w = shallowMount(SessionParticipantsModal, { props: { show: false, session }, global: { mocks: { t } } })
		await w.setProps({ show: true })
		await flushPromises()
		return w
	}

	beforeEach(() => {
		chat.listParticipants.mockResolvedValue([{ uid: 'bob' }])
	})

	it('loads the participants when opened', async () => {
		const w = await openDialog()
		expect(chat.listParticipants).toHaveBeenCalledWith('s1')
		expect(w.vm.participants).toEqual([{ uid: 'bob' }])
	})

	it('searches from two characters, leaving out the owner and current participants', async () => {
		chat.searchUsers.mockResolvedValueOnce([{ uid: 'owner' }, { uid: 'bob' }, { uid: 'eve' }])
		const w = await openDialog()
		await w.vm.search('e')
		expect(chat.searchUsers).not.toHaveBeenCalled()
		await w.vm.search('ev')
		expect(w.vm.options).toEqual([{ uid: 'eve' }])
		chat.searchUsers.mockRejectedValueOnce(new Error('x'))
		await w.vm.search('ev')
		expect([w.vm.options, w.vm.searching]).toEqual([[], false])
	})

	it('adds and removes people, reporting the new list', async () => {
		const w = await openDialog()
		chat.addParticipant.mockResolvedValueOnce([{ uid: 'bob' }, { uid: 'eve' }])
		w.vm.selected = { uid: 'eve' }
		await w.vm.add()
		expect(chat.addParticipant).toHaveBeenCalledWith('s1', 'eve')
		expect(w.vm.selected).toBeNull()
		chat.removeParticipant.mockResolvedValueOnce([{ uid: 'eve' }])
		await w.vm.remove({ uid: 'bob' })
		expect(chat.removeParticipant).toHaveBeenCalledWith('s1', 'bob')
		expect(w.emitted('changed')).toEqual([[[{ uid: 'bob' }, { uid: 'eve' }]], [[{ uid: 'eve' }]]])
	})

	it.each([
		[409, 'This session belongs to a Talk room. Invite the person to the room instead.'],
		[400, 'There is no user with that name.'],
		[500, 'The change could not be saved.'],
	])('explains a %s refusal', async (status, message) => {
		const w = await openDialog()
		chat.removeParticipant.mockRejectedValueOnce({ response: { status } })
		await w.vm.remove({ uid: 'bob' })
		expect([w.vm.error, w.vm.busy]).toEqual([message, false])
		chat.listParticipants.mockRejectedValueOnce({ response: { status } })
		await w.vm.load()
		expect(w.vm.error).toBe(message)
	})
})
