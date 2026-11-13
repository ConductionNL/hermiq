/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Characterisation of the row, register, memory, binding and skill actions that run
 * behind a busy or loading flag: the flag goes up and the error clears before the call,
 * a failure lands in the error field as the server's message, the thrown
 * message or the translated fallback, and the flag drops again afterwards.
 * Every write to the component is recorded in order.
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'

const api = vi.hoisted(() => ({}))
const emitted = vi.hoisted(() => [])

/**
 * A mocked API module whose every export records its call into the trace.
 *
 * @param {Array<string>} names The exported function names.
 * @return {object} The module.
 */
function apiModule(names) {
	return Object.fromEntries(names.map((name) => [name, (...args) => api.call(name, args)]))
}

vi.mock('@conduction/nextcloud-vue', () => ({
	CnDataTable: { name: 'CnDataTable', render: () => null },
	CnMarkdownEditor: { name: 'CnMarkdownEditor', render: () => null },
	createObjectStore: () => () => ({}),
}))
vi.mock('@nextcloud/event-bus', () => ({
	emit: (name, payload) => emitted.push([name, payload]),
	subscribe: () => {},
	unsubscribe: () => {},
}))
vi.mock('../../src/api/agentTemplates.js', () => apiModule(['approveAgentTemplate', 'exportAgentTemplate', 'instantiateAgentTemplate']))
vi.mock('../../src/api/skills.js', () => apiModule([
	'acceptSkillDraft', 'approveSkill', 'attestSkillL4', 'diffSkillVersions', 'exportSkill', 'importSkill',
	'installFromSource', 'listSkillDrafts', 'listSkillVersions', 'proposeSkillImprovement', 'publishSkill',
	'qualifySkill', 'rejectSkillDraft', 'republishSkill', 'rollbackSkill', 'updateSkill', 'updateSkillDraftContent',
]))
vi.mock('../../src/api/aiFeatures.js', () => apiModule([
	'acknowledgeAiFeature', 'bindAiFeature', 'disableAiFeature', 'enableAiFeature',
	'listAiFeatures', 'listFeatureResidency', 'publishAiFeature', 'withdrawAiFeature',
]))
vi.mock('../../src/api/memory.js', () => apiModule(['addMemory', 'consolidateMemory', 'correctMemoryEntry', 'forgetMemoryEntry', 'getMemory']))
vi.mock('../../src/api/modelPolicy.js', () => apiModule(['getEffectiveModelPolicy']))

const AgentTemplateRowActions = (await import('../../src/widgets/AgentTemplateRowActions.vue')).default
const SkillRowActions = (await import('../../src/widgets/SkillRowActions.vue')).default
const AiFeatureRegister = (await import('../../src/views/AiFeatureRegister.vue')).default
const AgentMemoryPanel = (await import('../../src/components/AgentMemoryPanel.vue')).default
const AiFeatureBindingModal = (await import('../../src/modals/AiFeatureBindingModal.vue')).default
const SkillDraftReview = (await import('../../src/widgets/SkillDraftReview.vue')).default
const SkillVersionHistory = (await import('../../src/widgets/SkillVersionHistory.vue')).default
const SkillMaturityScorecard = (await import('../../src/widgets/SkillMaturityScorecard.vue')).default
const SkillFormModal = (await import('../../src/modals/SkillFormModal.vue')).default

let trace

/**
 * A stand-in component: its state, its methods bound to it, and a trace of
 * every field write and API call.
 *
 * @param {object} component The component definition.
 * @param {object} state The initial data.
 * @return {object} The proxy to call methods on.
 */
function instance(component, state) {
	const target = {
		...state,
		t: (app, text, vars) => (vars ? `${text} ${JSON.stringify(vars)}` : text),
		$emit: (event) => trace.push(`$emit ${event}`),
	}
	const vm = new Proxy(target, {
		get(obj, key) {
			if (key in obj) {
				return obj[key]
			}
			const method = component.methods[key]
			return method ? method.bind(vm) : undefined
		},
		set(obj, key, value) {
			trace.push(`${String(key)}=${JSON.stringify(value)}`)
			obj[key] = value
			return true
		},
	})
	return vm
}

beforeEach(() => {
	trace = []
	api.stepResult = { id: 'saved' }
	emitted.length = 0
	api.result = { ok: 1 }
	api.failure = null
	api.call = (name, args) => {
		trace.push(`${name}(${JSON.stringify(args)})`)
		return api.failure ? Promise.reject(api.failure) : Promise.resolve(api.result)
	}
})

const feature = { uuid: 'f-1', slug: 'summary' }

/**
 * A stand-in for a method the action calls on its own component.
 *
 * @param {string} name The method name.
 * @return {Function} A recorder.
 */
function step(name) {
	return async (...args) => {
		trace.push(args.length > 0 ? `${name}(${JSON.stringify(args)})` : `${name}()`)
		return api.stepResult
	}
}

/**
 * A stand-in object store that records its fetches.
 *
 * @param {string} name The store name.
 * @return {object} The store.
 */
function store(name) {
	const fetch = (method) => (...args) => {
		trace.push(`${name}.${method}(${JSON.stringify(args)})`)
		return api.failure ? Promise.reject(api.failure) : Promise.resolve(api.result)
	}
	return { fetchObject: fetch('fetchObject'), fetchCollection: fetch('fetchCollection') }
}

const load = { load: step('load') }
const draft = { pendingDraft: { uuid: 'd-1' }, skillId: 'sk-1', ...load }

const cases = [
	['AgentTemplateRowActions.doApprove', AgentTemplateRowActions, 'doApprove', [], { row: { uuid: 'tpl-1' } }],
	['SkillRowActions.doApprove', SkillRowActions, 'doApprove', [], { row: { uuid: 'sk-1' } }],
	['SkillRowActions.doQualify', SkillRowActions, 'doQualify', [], { row: { id: 'sk-2' } }],
	['SkillRowActions.doPublish', SkillRowActions, 'doPublish', [], { row: { uuid: 'sk-3' } }],
	['AiFeatureRegister.doWithdraw', AiFeatureRegister, 'doWithdraw', [feature], load],
	['AiFeatureRegister.doAcknowledge', AiFeatureRegister, 'doAcknowledge', [feature], load],
	['AiFeatureRegister.doEnable', AiFeatureRegister, 'doEnable', [feature], load],
	['AiFeatureRegister.doDisable', AiFeatureRegister, 'doDisable', [feature], load],
	['AgentMemoryPanel.addFact', AgentMemoryPanel, 'addFact', [], { agentId: 'a-1', newEntry: '  likes tea  ' }],
	['AgentMemoryPanel.consolidate', AgentMemoryPanel, 'consolidate', [], { agentId: 'a-1' }],
	['AgentMemoryPanel.saveCorrection', AgentMemoryPanel, 'saveCorrection', [{ id: 'e-1' }], { agentId: 'a-1', editText: ' fixed ' }],
	['AgentMemoryPanel.confirmForget', AgentMemoryPanel, 'confirmForget', [], { agentId: 'a-1', forgetTarget: { id: 'e-2' } }],
	['AiFeatureBindingModal.save', AiFeatureBindingModal, 'save', [], {
		featureId: 'f-9', useDefault: false, providerOption: { value: 'claude' }, chosenModel: 'm-1', residencyOption: { value: 'eu' },
	}],
	['SkillDraftReview.load', SkillDraftReview, 'load', [], { loading: false, skillId: 'sk-1', skillStore: store('skillStore') }],
	['SkillDraftReview.doPropose', SkillDraftReview, 'doPropose', [], draft],
	['SkillDraftReview.doAccept', SkillDraftReview, 'doAccept', [], draft],
	['SkillDraftReview.doEditContent', SkillDraftReview, 'doEditContent', ['# body'], { showEditor: true, ...draft }],
	['SkillDraftReview.doReject', SkillDraftReview, 'doReject', [{ note: 'no', rejectedLearningRefs: ['l-1'] }], { showReject: true, ...draft }],
	['SkillVersionHistory.load', SkillVersionHistory, 'load', [], {
		loading: false, skillId: 'sk-1', skillStore: store('skillStore'), datasetStore: store('datasetStore'), runStore: store('runStore'),
	}],
	['SkillVersionHistory.showDiff', SkillVersionHistory, 'showDiff', ['v-2'], { skillId: 'sk-1', versions: [{ id: 'v-3' }] }],
	['SkillVersionHistory.doRollback', SkillVersionHistory, 'doRollback', ['v-2'], { skillId: 'sk-1', ...load }],
	['SkillVersionHistory.doRepublish', SkillVersionHistory, 'doRepublish', [], { skillId: 'sk-1', republishCredentialId: ' c-1 ', ...load }],
	['SkillMaturityScorecard.doQualify', SkillMaturityScorecard, 'doQualify', [], { qualifying: false, skillId: 'sk-1', fetchSkill: step('fetchSkill') }],
	['SkillMaturityScorecard.doAttest', SkillMaturityScorecard, 'doAttest', [], {
		attesting: false, attestError: 'stale', attestNote: ' seen ', skillId: 'sk-1', fetchSkill: step('fetchSkill'),
	}],
	['SkillFormModal.importPastedPackage', SkillFormModal, 'importPastedPackage', [], {
		pasteBusy: false, pasteError: 'stale', pasteText: ' ---\nname: x ', applyImportedSkill: step('applyImportedSkill'),
	}],
	['SkillFormModal.save (new)', SkillFormModal, 'save', [], {
		saving: false, form: { name: 'Skill' }, effectiveSkill: null, saveNew: step('saveNew'), handleClose: step('handleClose'),
	}],
	['SkillFormModal.save (edit)', SkillFormModal, 'save', [], {
		saving: false, form: { name: 'Skill' }, effectiveSkill: { id: 4 }, saveEdit: step('saveEdit'), handleClose: step('handleClose'),
	}],
]

/**
 * Run one action and return what it did.
 *
 * @param {Array} entry One row of `cases`.
 * @return {Promise<object>} The trace and the emitted events.
 */
async function run([, component, method, args, state]) {
	const vm = instance(component, { busy: false, error: 'stale', ...state })
	await vm[method](...args)
	return { trace, emitted: [...emitted] }
}

describe.each(cases)('%s', (name, ...rest) => {
	it('succeeds behind the busy flag', async () => {
		expect(await run([name, ...rest])).toMatchSnapshot()
	})

	it('reports the server error', async () => {
		api.failure = { response: { data: { error: 'Denied by server' } }, message: 'Request failed' }
		expect(await run([name, ...rest])).toMatchSnapshot()
	})

	it('reports the thrown message', async () => {
		api.failure = new Error('Network down')
		expect(await run([name, ...rest])).toMatchSnapshot()
	})

	it('falls back to the translated message', async () => {
		api.failure = {}
		expect(await run([name, ...rest])).toMatchSnapshot()
	})
})

describe('SkillRowActions.doPublish', () => {
	it('reports a structured publish refusal without throwing', async () => {
		api.result = { error: { message: 'Hub unavailable' } }
		expect(await run(cases[3])).toMatchSnapshot()
		api.result = { error: {} }
		trace = []
		expect(await run(cases[3])).toMatchSnapshot()
	})
})

describe('AgentMemoryPanel guards', () => {
	it.each([
		['addFact', [], { agentId: 'a-1', newEntry: '   ' }],
		['addFact', [], { agentId: '', newEntry: 'x' }],
		['consolidate', [], { agentId: '' }],
		['saveCorrection', [{ id: 'e-1' }], { agentId: 'a-1', editText: '' }],
		['confirmForget', [], { agentId: 'a-1', forgetTarget: null }],
	])('%s does nothing without its input', async (method, args, state) => {
		const vm = instance(AgentMemoryPanel, { busy: false, error: 'stale', ...state })
		await vm[method](...args)
		expect(trace).toEqual([])
	})
})

describe('step failures inside the action', () => {
	it.each([
		['SkillDraftReview.doPropose', 'load'],
		['SkillMaturityScorecard.doQualify', 'fetchSkill'],
		['SkillFormModal.save (new)', 'saveNew'],
	])('%s reports a failing %s', async (name, method) => {
		const entry = cases.find(([label]) => label === name)
		const state = { ...entry[4], [method]: async () => { trace.push(method); throw new Error(`${method} broke`) } }
		expect(await run([name, entry[1], entry[2], entry[3], state])).toMatchSnapshot()
	})
})

describe('SkillFormModal guards', () => {
	it('saves nothing without a name', async () => {
		const vm = instance(SkillFormModal, { saving: false, error: '', form: { name: '  ' } })
		await vm.save()
		expect(trace).toEqual([])
	})

	it('stops on an edit the server refused', async () => {
		api.stepResult = null
		const entry = cases.find(([label]) => label === 'SkillFormModal.save (edit)')
		expect(await run(entry)).toMatchSnapshot()
	})

	it.each([['   '], ['name: x']])('refuses the pasted package %j', async (pasteText) => {
		const vm = instance(SkillFormModal, { pasteBusy: false, pasteError: '', pasteText, applyImportedSkill: step('applyImportedSkill') })
		await vm.importPastedPackage()
		expect(trace).toMatchSnapshot()
	})
})

describe('AiFeatureBindingModal.save', () => {
	it('binds the instance default when no provider is chosen', async () => {
		const vm = instance(AiFeatureBindingModal, {
			busy: false, error: '', featureId: 'f-9', useDefault: true, providerOption: null, chosenModel: 'x', residencyOption: null,
		})
		await vm.save()
		expect(trace).toMatchSnapshot()
	})
})
