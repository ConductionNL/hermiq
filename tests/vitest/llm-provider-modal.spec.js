/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Characterisation of LlmProviderModal's load and save: how the stored
 * configuration fills the form and the pickers, and the patch each provider
 * sends (or the message that stops it).
 */

import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const api = vi.hoisted(() => ({
	getLlmSettings: vi.fn(),
	patchLlmSettings: vi.fn(),
}))

vi.mock('../../src/api/llm.js', () => api)

vi.mock('../../src/components/ProviderDeclarations.vue', () => ({
	default: { name: 'ProviderDeclarations', render: () => null, methods: { save: async () => {} } },
}))

const { default: LlmProviderModal } = await import('../../src/modals/LlmProviderModal.vue')

const CREDENTIALS = [
	{ id: 'c-openai', name: 'OpenAI key', provider: 'openai' },
	{ id: 'c-fw', name: '', provider: 'fireworks' },
	{ id: 'c-an', name: 'Claude key', provider: 'anthropic' },
]

const FULL = {
	chatProvider: 'anthropic',
	openaiApiKeySet: true,
	fireworksApiKeySet: 'yes',
	openaiConfig: { chatModel: 'gpt-x', credentialId: 'c-openai' },
	ollamaConfig: { url: 'http://ollama:11434', chatModel: 'llama3' },
	fireworksConfig: { baseUrl: 'https://api.fireworks.ai/inference/v1', chatModel: 'fw-1', credentialId: 'c-fw' },
	anthropicConfig: { chatModel: 'claude-x', credentialId: 'c-an', authMode: 'oauth' },
	modelCapabilities: { 'anthropic/claude-x': ['image'] },
}

/**
 * Mount the modal open, with the given stored config, and wait for load().
 *
 * @param {object|Error} config The stored config, or an Error getLlmSettings throws.
 * @return {Promise<object>} The wrapper.
 */
async function open(config) {
	axios.get = vi.fn(async () => ({ data: { results: CREDENTIALS } }))
	if (config instanceof Error) {
		api.getLlmSettings.mockRejectedValue(config)
	} else {
		api.getLlmSettings.mockResolvedValue(config)
	}
	const wrapper = mount(LlmProviderModal, {
		props: { show: true },
		global: { mocks: { t: (app, text) => text } },
	})
	await flushPromises()
	return wrapper
}

beforeEach(() => {
	api.getLlmSettings.mockReset()
	api.patchLlmSettings.mockReset()
	api.patchLlmSettings.mockResolvedValue({})
})

describe('LlmProviderModal load', () => {
	it('fills the form, the key flags and every picker from the stored config', async () => {
		const vm = (await open(FULL)).vm

		expect(vm.loading).toBe(false)
		expect(vm.error).toBe('')
		expect(vm.openaiKeySet).toBe(true)
		expect(vm.fireworksKeySet).toBe(false)
		expect(JSON.parse(JSON.stringify(vm.form))).toEqual({
			openaiConfig: { chatModel: 'gpt-x', credentialId: 'c-openai', baseUrl: '', ownNetwork: false, apiMode: 'chat_completions' },
			openaiCompatibleConfig: { label: '', baseUrl: '', chatModel: '', credentialId: '', ownNetwork: false, apiMode: 'chat_completions' },
			ollamaConfig: { url: 'http://ollama:11434', chatModel: 'llama3' },
			// The broker locks the host to the credential, so only the path is kept.
			fireworksConfig: { baseUrl: '/inference/v1', chatModel: 'fw-1', credentialId: 'c-fw' },
			anthropicConfig: { chatModel: 'claude-x', credentialId: 'c-an', authMode: 'oauth' },
		})
		expect(vm.modelCapabilities).toEqual({ 'anthropic/claude-x': ['image'] })
		expect(vm.openaiCredential).toEqual({ label: 'OpenAI key', value: 'c-openai' })
		expect(vm.fireworksCredential).toEqual({ label: 'c-fw', value: 'c-fw' })
		expect(vm.anthropicCredential).toEqual({ label: 'Claude key', value: 'c-an' })
		expect(vm.anthropicAuthMode.value).toBe('oauth')
		expect(vm.selectedProvider.value).toBe('anthropic')
	})

	it('falls back to empty fields, the API-key mode and no pickers', async () => {
		const vm = (await open({ chatProvider: 'unknown', anthropicConfig: { authMode: 'weird' } })).vm

		expect(JSON.parse(JSON.stringify(vm.form))).toEqual({
			openaiConfig: { chatModel: '', credentialId: '', baseUrl: '', ownNetwork: false, apiMode: 'chat_completions' },
			openaiCompatibleConfig: { label: '', baseUrl: '', chatModel: '', credentialId: '', ownNetwork: false, apiMode: 'chat_completions' },
			ollamaConfig: { url: '', chatModel: '' },
			fireworksConfig: { baseUrl: '', chatModel: '', credentialId: '' },
			anthropicConfig: { chatModel: '', credentialId: '', authMode: 'weird' },
		})
		expect(vm.modelCapabilities).toEqual({})
		expect(vm.openaiCredential).toBe(null)
		expect(vm.fireworksCredential).toBe(null)
		expect(vm.anthropicCredential).toBe(null)
		expect(vm.anthropicAuthMode.value).toBe('api_key')
		expect(vm.selectedProvider).toBe(null)
	})

	it('reports a failed load', async () => {
		const vm = (await open(new Error('down'))).vm

		expect(vm.error).toBe('Could not load the current LLM configuration.')
		expect(vm.loading).toBe(false)
	})
})

describe('LlmProviderModal save', () => {
	it('refuses without a provider', async () => {
		const vm = (await open({})).vm
		await vm.save()

		expect(vm.error).toBe('Select a provider first.')
		expect(api.patchLlmSettings).not.toHaveBeenCalled()
	})

	it.each([
		['openai', {}, 'Pick an OpenAI credential first.'],
		['fireworks', {}, 'Pick a Fireworks AI credential first.'],
		['anthropic', { authMode: 'oauth' }, 'A Claude Max subscription (OAuth) may only be set as a personal token in your personal settings, per the Anthropic Terms of Service. Use an API key here.'],
		['anthropic', {}, 'Pick an Anthropic credential first.'],
	])('refuses %s without what it needs', async (provider, anthropicConfig, message) => {
		const vm = (await open({ chatProvider: provider, anthropicConfig })).vm
		await vm.save()

		expect(vm.error).toBe(message)
		expect(vm.saving).toBe(false)
		expect(api.patchLlmSettings).not.toHaveBeenCalled()
	})

	it.each([
		['openai', { chatProvider: 'openai', openaiConfig: { chatModel: 'gpt-x', credentialId: 'c-openai' } }],
		['ollama', { chatProvider: 'ollama', ollamaConfig: { url: 'http://ollama:11434', chatModel: 'llama3' }, modelCapabilities: { 'ollama/llama3': [] } }],
		['fireworks', { chatProvider: 'fireworks', fireworksConfig: { baseUrl: '/inference/v1', chatModel: 'fw-1', credentialId: 'c-fw' }, modelCapabilities: { 'fireworks/fw-1': [] } }],
		['anthropic', { chatProvider: 'anthropic', anthropicConfig: { chatModel: 'claude-x', credentialId: 'c-an', authMode: 'api_key', scope: 'organisation' }, modelCapabilities: { 'anthropic/claude-x': ['image'] } }],
		['nextcloud', { chatProvider: 'nextcloud' }],
	])('patches %s with its section and the model ticks', async (provider, expected) => {
		const stored = { ...FULL, chatProvider: provider, anthropicConfig: { ...FULL.anthropicConfig, authMode: 'api_key' } }
		if (provider === 'openai') {
			stored.openaiConfig = { chatModel: 'gpt-x', credentialId: 'c-openai' }
			stored.modelCapabilities = {}
		}
		const wrapper = await open(stored)
		if (provider === 'openai') {
			wrapper.vm.form.openaiConfig.chatModel = ''
			expected = { chatProvider: 'openai', openaiConfig: { chatModel: '', credentialId: 'c-openai', baseUrl: '', ownNetwork: false, apiMode: 'chat_completions' } }
		}
		await wrapper.vm.save()

		expect(JSON.stringify(api.patchLlmSettings.mock.calls[0][0])).toBe(JSON.stringify(expected))
		expect(wrapper.emitted('saved')).toEqual([[provider]])
		expect(wrapper.emitted('close')).toEqual([[]])
		expect(wrapper.vm.saving).toBe(false)
	})

	it('reports a failed save', async () => {
		api.patchLlmSettings.mockRejectedValue(new Error('no'))
		const vm = (await open({ ...FULL, chatProvider: 'ollama' })).vm
		await vm.save()

		expect(vm.error).toBe('Could not save the LLM configuration.')
		expect(vm.saving).toBe(false)
	})
})

describe('LlmProviderModal OpenAI-compatible endpoint and OpenAI base URL', () => {
	it('lists the endpoint by its own name', async () => {
		const vm = (await open({})).vm

		expect(vm.providerOptions.find((o) => o.value === 'openai-compatible').label).toBe('OpenAI-compatible endpoint')
	})

	it('keeps the key field disabled until OpenRegister offers the credential type, and saves a keyless endpoint', async () => {
		const wrapper = await open({ chatProvider: 'openai-compatible' })
		const vm = wrapper.vm

		expect(vm.compatibleKeyAvailable).toBe(false)
		expect(wrapper.find('[data-testid="llm-compatible-credential-hint"]').text())
			.toBe('A key needs OpenRegister\'s OpenAI-compatible credential type.')

		Object.assign(vm.form.openaiCompatibleConfig, {
			label: ' vLLM Rekencentrum ',
			baseUrl: 'https://llm.rekencentrum.example.nl/v1',
			chatModel: 'mistral-small-3.2',
			ownNetwork: true,
		})
		await vm.save()

		expect(api.patchLlmSettings.mock.calls[0][0]).toEqual({
			chatProvider: 'openai-compatible',
			openaiCompatibleConfig: {
				label: 'vLLM Rekencentrum',
				baseUrl: 'https://llm.rekencentrum.example.nl/v1',
				chatModel: 'mistral-small-3.2',
				credentialId: '',
				ownNetwork: true,
				apiMode: 'chat_completions',
			},
			modelCapabilities: { 'openai-compatible/mistral-small-3.2': [] },
		})
	})

	it('sends a chosen key through the broker and never claims its own network for it', async () => {
		CREDENTIALS.push({ id: 'c-compat', name: 'Gateway key', provider: 'openai-compatible' })
		try {
			const vm = (await open({
				chatProvider: 'openai-compatible',
				openaiCompatibleConfig: { label: 'Gateway', baseUrl: 'https://gw/v1', chatModel: 'm', credentialId: 'c-compat', ownNetwork: true },
			})).vm

			expect(vm.compatibleKeyAvailable).toBe(true)
			expect(vm.compatibleCredential).toEqual({ label: 'Gateway key', value: 'c-compat' })
			await vm.save()

			expect(api.patchLlmSettings.mock.calls[0][0].openaiCompatibleConfig).toEqual({
				label: 'Gateway', baseUrl: 'https://gw/v1', chatModel: 'm', credentialId: 'c-compat', ownNetwork: false, apiMode: 'chat_completions',
			})
		} finally {
			CREDENTIALS.pop()
		}
	})

	it.each([
		[{ chatModel: 'm' }, 'Enter the base URL of the endpoint first.'],
		[{ baseUrl: 'https://gw/v1' }, 'Enter the model the endpoint serves first.'],
	])('refuses an endpoint without what it needs', async (config, message) => {
		const vm = (await open({ chatProvider: 'openai-compatible', openaiCompatibleConfig: config })).vm
		await vm.save()

		expect(vm.error).toBe(message)
		expect(api.patchLlmSettings).not.toHaveBeenCalled()
	})

	it('saves OpenAI with a base URL and no credential as a keyless server', async () => {
		const vm = (await open({ chatProvider: 'openai', openaiConfig: { chatModel: 'gpt-x', baseUrl: 'http://10.0.0.5/v1', ownNetwork: true } })).vm
		await vm.save()

		expect(api.patchLlmSettings.mock.calls[0][0].openaiConfig).toEqual({
			chatModel: 'gpt-x', credentialId: '', baseUrl: 'http://10.0.0.5/v1', ownNetwork: true, apiMode: 'chat_completions',
		})
	})

	it('sends the Responses API mode it was set to', async () => {
		const vm = (await open({ chatProvider: 'openai-compatible', openaiCompatibleConfig: { baseUrl: 'https://gw/v1', chatModel: 'm', apiMode: 'responses' } })).vm

		expect(vm.apiModeOption(vm.form.openaiCompatibleConfig.apiMode).value).toBe('responses')
		expect(vm.apiModeOption('unknown').value).toBe('chat_completions')
		await vm.save()

		expect(api.patchLlmSettings.mock.calls[0][0].openaiCompatibleConfig.apiMode).toBe('responses')
	})
})

