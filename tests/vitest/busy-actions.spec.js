/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Characterisation of the row, register, memory and binding actions that run
 * behind a busy flag: the flag goes up and the error clears before the call,
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
	createObjectStore: () => () => ({}),
}))
vi.mock('@nextcloud/event-bus', () => ({
	emit: (name, payload) => emitted.push([name, payload]),
	subscribe: () => {},
	unsubscribe: () => {},
}))
vi.mock('../../src/api/agentTemplates.js', () => apiModule(['approveAgentTemplate', 'exportAgentTemplate', 'instantiateAgentTemplate']))
vi.mock('../../src/api/skills.js', () => apiModule(['approveSkill', 'exportSkill', 'publishSkill', 'qualifySkill']))
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
	emitted.length = 0
	api.result = { ok: 1 }
	api.failure = null
	api.call = (name, args) => {
		trace.push(`${name}(${JSON.stringify(args)})`)
		return api.failure ? Promise.reject(api.failure) : Promise.resolve(api.result)
	}
})

const feature = { uuid: 'f-1', slug: 'summary' }

const cases = [
	['AgentTemplateRowActions.doApprove', AgentTemplateRowActions, 'doApprove', [], { row: { uuid: 'tpl-1' } }],
	['SkillRowActions.doApprove', SkillRowActions, 'doApprove', [], { row: { uuid: 'sk-1' } }],
	['SkillRowActions.doQualify', SkillRowActions, 'doQualify', [], { row: { id: 'sk-2' } }],
	['SkillRowActions.doPublish', SkillRowActions, 'doPublish', [], { row: { uuid: 'sk-3' } }],
	['AiFeatureRegister.doWithdraw', AiFeatureRegister, 'doWithdraw', [feature], {}],
	['AiFeatureRegister.doAcknowledge', AiFeatureRegister, 'doAcknowledge', [feature], {}],
	['AiFeatureRegister.doEnable', AiFeatureRegister, 'doEnable', [feature], {}],
	['AiFeatureRegister.doDisable', AiFeatureRegister, 'doDisable', [feature], {}],
	['AgentMemoryPanel.addFact', AgentMemoryPanel, 'addFact', [], { agentId: 'a-1', newEntry: '  likes tea  ' }],
	['AgentMemoryPanel.consolidate', AgentMemoryPanel, 'consolidate', [], { agentId: 'a-1' }],
	['AgentMemoryPanel.saveCorrection', AgentMemoryPanel, 'saveCorrection', [{ id: 'e-1' }], { agentId: 'a-1', editText: ' fixed ' }],
	['AgentMemoryPanel.confirmForget', AgentMemoryPanel, 'confirmForget', [], { agentId: 'a-1', forgetTarget: { id: 'e-2' } }],
	['AiFeatureBindingModal.save', AiFeatureBindingModal, 'save', [], {
		featureId: 'f-9', useDefault: false, providerOption: { value: 'claude' }, chosenModel: 'm-1', residencyOption: { value: 'eu' },
	}],
]

/**
 * Run one action and return what it did.
 *
 * @param {Array} entry One row of `cases`.
 * @return {Promise<object>} The trace and the emitted events.
 */
async function run([, component, method, args, state]) {
	if (component === AiFeatureRegister) {
		component.methods.load = vi.fn(async function() {
			trace.push('load()')
		})
	}
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

describe('AiFeatureBindingModal.save', () => {
	it('binds the instance default when no provider is chosen', async () => {
		const vm = instance(AiFeatureBindingModal, {
			busy: false, error: '', featureId: 'f-9', useDefault: true, providerOption: null, chosenModel: 'x', residencyOption: null,
		})
		await vm.save()
		expect(trace).toMatchSnapshot()
	})
})
