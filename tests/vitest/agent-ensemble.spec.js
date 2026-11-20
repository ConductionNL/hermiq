/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * models-several-models-per-turn (REQ-MENS-001): the ensemble setting on the
 * agent form. The helpers shape the setting, the editor switches it on with
 * two rows and states the cost, the form saves it only when it is on or was
 * stored, and the agent's Configuration card names it.
 */

import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

vi.mock('@conduction/nextcloud-vue', () => ({
	CnIconPicker: { name: 'CnIconPicker', props: ['modelValue'], render: () => h('div') },
	fromOpenGemeenten: () => [],
	createObjectStore: () => () => ({}),
}))
vi.mock('../../src/store/store.js', () => ({
	useAgentStore: () => ({ registerObjectType: () => {}, saveObject: vi.fn(), fetchObject: vi.fn(async () => null), errors: {} }),
}))
vi.mock('../../src/api/toolOversight.js', () => ({ updateToolGrants: vi.fn() }))
vi.mock('../../src/api/agents.js', () => ({ listTools: async () => [], setAppAssistant: async () => ({}) }))
vi.mock('../../src/api/chat.js', () => ({ searchGroups: async () => [], searchUsers: async () => [] }))
vi.mock('../../src/api/actingUser.js', () => ({ getActingUserStatus: vi.fn() }))
vi.mock('../../src/api/modelPolicy.js', () => ({
	getEffectiveModelPolicy: async () => ({ allowed: [{ provider: 'ollama', models: ['qwen2.5', 'llama3.1'] }, { provider: 'anthropic', models: [] }] }),
}))

vi.stubGlobal('t', (app, text) => text)

const {
	MAX_REFERENCES,
	addReference,
	ensembleOf,
	ensemblePayload,
	ensembleSummary,
	removeReference,
	sendsEnsemble,
	setReference,
	switchEnsemble,
} = await import('../../src/utils/agentEnsemble.js')
const { default: EnsembleEditor } = await import('../../src/components/EnsembleEditor.vue')
const { default: AgentFormModal } = await import('../../src/modals/AgentFormModal.vue')

const translate = (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (m, k) => String(vars[k] ?? m))
const plural = (app, one, many, count, vars) => translate(app, count === 1 ? one : many, vars)

/**
 * A stub for a Nextcloud component that renders its slots and carries its label.
 *
 * @param {string} name The component name.
 * @return {object} The stub.
 */
function stub(name) {
	return defineComponent({
		name,
		inheritAttrs: false,
		props: ['modelValue', 'options', 'disabled'],
		emits: ['update:modelValue', 'click'],
		render() {
			const key = this.$attrs.inputLabel ?? this.$attrs['aria-label'] ?? this.$attrs['data-testid'] ?? ''
			return h('div', { class: `stub-${name}`, 'data-key': key }, Object.values(this.$slots).map((slot) => slot()))
		},
	})
}

const stubs = Object.fromEntries(
	['NcModal', 'NcButton', 'NcCheckboxRadioSwitch', 'NcLoadingIcon', 'NcNoteCard', 'NcSelect', 'NcTextArea', 'NcTextField', 'PromptPlaceholderTools', 'StartFieldsEditor']
		.map((name) => [name, stub(name)]),
)

const STORED = { enabled: true, referenceModels: [{ provider: 'anthropic', model: 'claude-opus-4-8' }, { provider: 'ollama', model: 'qwen2.5' }] }

describe('the ensemble helpers', () => {
	it('reads a stored ensemble into fresh rows, and nothing as off', () => {
		const form = ensembleOf(STORED)
		expect(form).toEqual(STORED)
		expect(form.referenceModels[0]).not.toBe(STORED.referenceModels[0])
		expect(ensembleOf(undefined)).toEqual({ enabled: false, referenceModels: [] })
		expect(ensembleOf({ enabled: 'yes', referenceModels: 'x' })).toEqual({ enabled: false, referenceModels: [] })
	})

	it('switches on with two rows to fill in, and off keeping the picks', () => {
		const on = switchEnsemble(ensembleOf(undefined), true)
		expect(on).toEqual({ enabled: true, referenceModels: [{ provider: '', model: '' }, { provider: '', model: '' }] })
		expect(switchEnsemble(ensembleOf(STORED), false)).toEqual({ ...STORED, enabled: false })
	})

	it('adds up to three rows, removes one, and clears the model of a new provider', () => {
		let ensemble = addReference(ensembleOf(STORED))
		expect(ensemble.referenceModels).toHaveLength(3)
		expect(addReference(ensemble).referenceModels).toHaveLength(MAX_REFERENCES)
		ensemble = removeReference(ensemble, 0)
		expect(ensemble.referenceModels[0]).toEqual({ provider: 'ollama', model: 'qwen2.5' })
		expect(setReference(ensemble, 0, 'provider', 'anthropic').referenceModels[0]).toEqual({ provider: 'anthropic', model: '' })
		expect(setReference(ensemble, 0, 'provider', 'ollama').referenceModels[0]).toEqual({ provider: 'ollama', model: 'qwen2.5' })
		expect(setReference(ensemble, 0, 'model', 'llama3.1').referenceModels[0]).toEqual({ provider: 'ollama', model: 'llama3.1' })
	})

	it('saves trimmed rows without empty ones, keeping a half-filled one for the server to refuse', () => {
		const payload = ensemblePayload({
			enabled: true,
			referenceModels: [{ provider: ' ollama ', model: 'qwen2.5 ' }, { provider: '', model: '' }, { provider: 'openai', model: '' }],
		})
		expect(payload).toEqual({ enabled: true, referenceModels: [{ provider: 'ollama', model: 'qwen2.5' }, { provider: 'openai', model: '' }] })
	})

	it('sends the field only when it is on or was stored', () => {
		expect(sendsEnsemble({ enabled: false, referenceModels: [] }, undefined)).toBe(false)
		expect(sendsEnsemble({ enabled: true, referenceModels: [] }, undefined)).toBe(true)
		expect(sendsEnsemble({ enabled: false, referenceModels: [] }, STORED)).toBe(true)
	})

	it('names the ensemble on the agent card', () => {
		expect(ensembleSummary(STORED, plural, translate)).toBe('Ensemble: 2 reference models')
		expect(ensembleSummary({ enabled: true, referenceModels: [STORED.referenceModels[0]] }, plural, translate)).toBe('Ensemble: 1 reference model')
		expect(ensembleSummary({ ...STORED, enabled: false }, plural, translate)).toBe('Off')
		expect(ensembleSummary(undefined, plural, translate)).toBe('Off')
	})
})

describe('the ensemble editor', () => {
	/**
	 * Mount the editor over an ensemble.
	 *
	 * @param {object} ensemble The ensemble.
	 * @return {object} The wrapper.
	 */
	function editor(ensemble) {
		return mount(EnsembleEditor, {
			props: { modelValue: ensemble, providerOptions: [{ label: 'ollama', value: 'ollama' }], modelsFor: (p) => (p === 'ollama' ? ['qwen2.5'] : []) },
			global: { stubs, mocks: { t: translate } },
		})
	}

	it('shows only the switch while off, and switching on emits two empty rows', async () => {
		const wrapper = editor(ensembleOf(undefined))
		expect(wrapper.text()).not.toContain('Each answer costs up to four model calls.')
		wrapper.findAllComponents({ name: 'NcCheckboxRadioSwitch' })[0].vm.$emit('update:modelValue', true)
		expect(wrapper.emitted('update:modelValue')[0][0]).toEqual({
			enabled: true,
			referenceModels: [{ provider: '', model: '' }, { provider: '', model: '' }],
		})
	})

	it('states the cost and offers a provider and model per reference', async () => {
		const wrapper = editor(ensembleOf(STORED))
		expect(wrapper.find('[data-testid="agent-form-ensemble-cost"]').text()).toBe('Each answer costs up to four model calls.')
		const selects = wrapper.findAllComponents({ name: 'NcSelect' })
		expect(selects.map((s) => s.attributes('data-key'))).toEqual(['Reference provider 1', 'Reference model 1', 'Reference provider 2', 'Reference model 2'])
		expect(selects[3].props('options')).toEqual([{ label: 'qwen2.5', value: 'qwen2.5' }])
		selects[3].vm.$emit('update:modelValue', { label: 'other', value: 'other' })
		expect(wrapper.emitted('update:modelValue')[0][0].referenceModels[1]).toEqual({ provider: 'ollama', model: 'other' })
	})

	it('says when fewer than two references are picked', () => {
		const wrapper = editor({ enabled: true, referenceModels: [{ provider: 'ollama', model: 'qwen2.5' }] })
		expect(wrapper.find('[data-testid="agent-form-ensemble-too-few"]').exists()).toBe(true)
	})
})

describe('the agent form', () => {
	/**
	 * Mount the open form.
	 *
	 * @param {object|null} agent The agent being edited.
	 * @return {Promise<object>} The wrapper.
	 */
	async function form(agent) {
		const wrapper = mount(AgentFormModal, {
			props: { show: true, agent },
			global: { stubs, mocks: { t: translate, n: plural } },
		})
		await flushPromises()
		return wrapper
	}

	it('saves an agent that never had an ensemble without the field', async () => {
		const wrapper = await form({ id: 'agent-1', name: 'Woo-triage', provider: 'ollama', model: 'qwen2.5' })
		expect(wrapper.vm.buildPayload()).not.toHaveProperty('ensemble')
	})

	it('saves the switched-on ensemble in order', async () => {
		const wrapper = await form({ id: 'agent-1', name: 'Beleidsadviseur', provider: 'ollama', model: 'qwen2.5' })
		wrapper.findComponent(EnsembleEditor).vm.$emit('update:modelValue', ensembleOf(STORED))
		await flushPromises()
		expect(wrapper.vm.buildPayload().ensemble).toEqual(STORED)
	})

	it('saves a stored ensemble switched off, so the switch sticks', async () => {
		const wrapper = await form({ id: 'agent-1', name: 'Beleidsadviseur', ensemble: STORED })
		wrapper.findComponent(EnsembleEditor).vm.$emit('update:modelValue', switchEnsemble(ensembleOf(STORED), false))
		await flushPromises()
		expect(wrapper.vm.buildPayload().ensemble).toEqual({ ...STORED, enabled: false })
	})

	it('offers the policy models for a reference provider, else the known ones', async () => {
		const wrapper = await form(null)
		expect(wrapper.vm.ensembleModelsFor('ollama')).toEqual(['qwen2.5', 'llama3.1'])
		expect(wrapper.vm.ensembleModelsFor('anthropic').length).toBeGreaterThan(0)
	})
})
