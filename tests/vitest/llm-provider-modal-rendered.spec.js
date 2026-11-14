/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The LLM provider dialog mounted in full over the @nextcloud/vue stand-ins:
 * each provider's fields write its own config block, the credential pickers
 * report whether a credential is chosen, the capability switches declare what
 * the current model reads, and the modal and Cancel close it.
 */

import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const api = vi.hoisted(() => ({ getLlmSettings: vi.fn(), patchLlmSettings: vi.fn() }))
vi.mock('../../src/api/llm.js', () => api)
vi.mock('../../src/components/ProviderDeclarations.vue', () => ({
	default: { name: 'ProviderDeclarations', render: () => null, methods: { save: async () => {} } },
}))

const { default: LlmProviderModal } = await import('../../src/modals/LlmProviderModal.vue')

const t = (app, text) => text

/**
 * Mount the dialog open on a provider, with empty stored config.
 *
 * @param {string} provider The chosen provider.
 * @return {Promise<object>} The wrapper.
 */
async function open(provider) {
	axios.get = vi.fn(async () => ({ data: { results: [] } }))
	api.getLlmSettings.mockResolvedValue({ chatProvider: provider })
	const w = mount(LlmProviderModal, { props: { show: true }, global: { mocks: { t } } })
	await flushPromises()
	return w
}

/**
 * The stand-in fields with a label, in template order.
 *
 * @param {object} w The wrapper.
 * @param {string} label The label.
 * @return {Array<object>} The component wrappers.
 */
function fields(w, label) {
	return w.findAllComponents({ name: 'NcTextField' }).filter((c) => c.attributes('data-label') === label)
}

beforeEach(() => {
	api.getLlmSettings.mockReset()
	api.patchLlmSettings.mockReset()
})

describe('LlmProviderModal as rendered', () => {
	it('writes the OpenAI model', async () => {
		const w = await open('openai')
		fields(w, 'Model')[0].vm.pick('gpt-5')
		expect(w.vm.form.openaiConfig.chatModel).toBe('gpt-5')
	})

	it('writes the Ollama address and model', async () => {
		const w = await open('ollama')
		fields(w, 'Ollama URL')[0].vm.pick('http://ollama:11434')
		fields(w, 'Model')[0].vm.pick('llama3')
		expect(w.vm.form.ollamaConfig).toMatchObject({ url: 'http://ollama:11434', chatModel: 'llama3' })
	})

	it('writes the Fireworks API path and model, and shows the host read-only', async () => {
		const w = await open('fireworks')
		fields(w, 'API path')[0].vm.pick('/inference/v1')
		fields(w, 'Model')[0].vm.pick('fw-1')
		expect(w.vm.form.fireworksConfig).toMatchObject({ baseUrl: '/inference/v1', chatModel: 'fw-1' })
		expect(fields(w, 'Host')).toHaveLength(1)
		expect(w.vm.fireworksHost).toBe('api.fireworks.ai')
		expect(fields(w, 'Base URL')).toHaveLength(0)
	})

	it('writes the Anthropic model', async () => {
		const w = await open('anthropic')
		fields(w, 'Model')[0].vm.pick('claude-opus-5')
		expect(w.vm.form.anthropicConfig.chatModel).toBe('claude-opus-5')
	})

	it('says whether OpenAI and Fireworks have a credential chosen', async () => {
		const w = await open('openai')
		expect([w.vm.openaiHasCredential, w.vm.fireworksHasCredential]).toEqual([false, false])

		w.vm.openaiCredential = { value: 'c-1', label: 'Key' }
		w.vm.fireworksCredential = { value: 'c-2', label: 'Key' }
		expect([w.vm.openaiHasCredential, w.vm.fireworksHasCredential]).toEqual([true, true])
	})

	it('declares and withdraws what the current model reads, per provider and model', async () => {
		const w = await open('openai')
		fields(w, 'Model')[0].vm.pick('gpt-5')
		await flushPromises()

		const switches = w.findAllComponents({ name: 'NcCheckboxRadioSwitch' })
		const images = switches.find((c) => c.text().includes('image'))
		const pdfs = switches.find((c) => c.text().toLowerCase().includes('pdf'))
		images.vm.pick(true)
		pdfs.vm.pick(true)
		expect(w.vm.modelCapabilities['openai/gpt-5']).toEqual(['image', 'pdf'])
		expect([w.vm.readsImages, w.vm.readsPdfs]).toEqual([true, true])

		images.vm.pick(false)
		expect(w.vm.modelCapabilities['openai/gpt-5']).toEqual(['pdf'])
		expect(w.vm.readsImages).toBe(false)
	})

	it('declares nothing while no model is set', async () => {
		const w = await open('openai')

		w.vm.readsImages = true

		expect(w.vm.capabilityKey).toBe('')
		expect(w.vm.readsImages).toBe(false)
		expect(w.vm.modelCapabilities).toEqual({})
	})

	it('closes from the modal and from Cancel', async () => {
		const w = await open('openai')

		w.findComponent({ name: 'NcModal' }).vm.$emit('close')
		w.findAllComponents({ name: 'NcButton' }).find((c) => c.text() === 'Cancel').vm.$emit('click')

		expect(w.emitted('close')).toHaveLength(2)
	})
})
