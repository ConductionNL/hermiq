/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * AgentFormModal's pickers: the heading for a new or edited agent, the voice
 * engine options, the provider list from the model policy (or the known
 * providers without one), the model reset when a provider change leaves the
 * model outside the allowlist, the model hint, and the delegation allowlist
 * that never offers the agent itself.
 */

import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { KNOWN_MODELS } from '../../src/llm/knownModels.js'

vi.mock('@conduction/nextcloud-vue', () => ({
	CnIconPicker: { name: 'CnIconPicker', render: () => null },
	fromOpenGemeenten: () => [],
	createObjectStore: () => () => ({}),
}))

vi.mock('../../src/store/store.js', () => ({
	useAgentStore: () => ({
		registerObjectType: () => {},
		saveObject: vi.fn(),
		fetchObject: vi.fn(async () => null),
		errors: {},
	}),
}))

vi.mock('../../src/api/toolOversight.js', () => ({ updateToolGrants: vi.fn() }))
vi.mock('../../src/api/agents.js', () => ({ listTools: async () => [], setAppAssistant: async () => ({}) }))
vi.mock('../../src/api/chat.js', () => ({ searchGroups: async () => [], searchUsers: async () => [] }))
vi.mock('../../src/api/modelPolicy.js', () => ({ getEffectiveModelPolicy: async () => null }))

// Some labels use the Nextcloud global `t`, others the component's `this.t`.
vi.stubGlobal('t', (app, text) => text)

const { default: AgentFormModal } = await import('../../src/modals/AgentFormModal.vue')

/**
 * Mount the modal without its template.
 *
 * @param {object} props The props.
 * @return {Promise<object>} The component instance.
 */
async function modal(props = {}) {
	const wrapper = mount({ ...AgentFormModal, render: () => null }, {
		props: { show: false, ...props },
		global: { mocks: { t: (app, text) => text } },
	})
	await flushPromises()
	return wrapper.vm
}

const policy = {
	defaultModel: 'gpt-4o',
	allowed: [
		{ provider: 'openai', models: ['gpt-4o-mini', 'gpt-4o'] },
		{ provider: 'anthropic', models: ['claude-opus-5'] },
		{ provider: 'ollama' },
	],
}

describe('AgentFormModal pickers', () => {
	it('heads the form by whether an agent is edited', async () => {
		expect((await modal()).heading).toBe('Create agent')
		expect((await modal({ agent: { id: 'a1', name: 'Digest' } })).heading).toBe('Edit agent')
	})

	it('maps the voice engines to options and back, defaulting to automatic', async () => {
		const vm = await modal()
		expect(vm.voiceEngineOptions.map((option) => option.value)).toEqual(['auto', 'browser', 'local', 'off'])

		vm.form.voiceInputEngine = 'local'
		expect(vm.voiceInputEngineOption.value).toBe('local')
		vm.form.voiceOutputEngine = 'unknown'
		expect(vm.voiceOutputEngineOption.value).toBe('auto')

		vm.voiceInputEngineOption = { value: 'off' }
		vm.voiceOutputEngineOption = null
		expect([vm.form.voiceInputEngine, vm.form.voiceOutputEngine]).toEqual(['off', 'auto'])
	})

	it('offers the known providers and their models without a policy', async () => {
		const vm = await modal()

		expect(vm.providerOptions.map((option) => option.value)).toEqual(Object.keys(KNOWN_MODELS))
		vm.providerOption = { value: 'openai' }
		expect(vm.form.provider).toBe('openai')
		expect(vm.modelOptions.map((option) => option.value)).toEqual(KNOWN_MODELS.openai)
		expect(vm.modelHint).toMatch(/^Known models for this provider — type any other model to use it\. The API key is not set here/)
	})

	it('offers only the providers and models the policy allows', async () => {
		const vm = await modal()
		vm.policy = policy

		expect(vm.providerOptions.map((option) => option.value)).toEqual(['openai', 'anthropic', 'ollama'])
		vm.form.provider = 'openai'
		expect(vm.modelOptions.map((option) => option.value)).toEqual(['gpt-4o-mini', 'gpt-4o'])
		expect(vm.modelHint).toMatch(/^Models allowed by your organisation's policy\./)
	})

	it('resets a model outside the new provider allowlist to the default or the first allowed', async () => {
		const vm = await modal()
		vm.policy = policy

		vm.form.model = 'claude-opus-5'
		vm.providerOption = { value: 'openai' }
		expect(vm.form.model).toBe('gpt-4o')

		vm.providerOption = { value: 'anthropic' }
		expect(vm.form.model).toBe('claude-opus-5')

		vm.form.model = 'custom'
		vm.providerOption = { value: 'ollama' }
		expect(vm.form.model).toBe('custom')

		vm.providerOption = null
		expect(vm.form.provider).toBe('')
	})

	it('keeps an allowed model when the provider changes', async () => {
		const vm = await modal()
		vm.policy = policy
		vm.form.model = 'gpt-4o-mini'

		vm.providerOption = { value: 'openai' }

		expect(vm.form.model).toBe('gpt-4o-mini')
		expect(vm.providerOption).toEqual({ label: 'openai', value: 'openai' })
		expect(vm.modelOption).toEqual({ label: 'gpt-4o-mini', value: 'gpt-4o-mini' })
		vm.modelOption = null
		expect([vm.form.model, vm.modelOption]).toEqual(['', null])
	})

	it('never offers the edited agent as its own delegate', async () => {
		const vm = await modal({ agent: { uuid: 'self', name: 'Me' } })
		vm.agentCatalog = [{ uuid: 'self', name: 'Me' }, { uuid: 'u2', name: 'Helper' }, { id: 'i3' }]

		expect(vm.delegationAllowlistOptions).toEqual([
			{ label: 'Helper', value: 'u2' },
			{ label: 'i3', value: 'i3' },
		])
	})
})
