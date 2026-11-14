/**
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * The code sandbox admin section (src/components/settings/CodeSandboxSettings.vue):
 * it says which backend is live, saves the admin's choice, and shows the result
 * of "Run a test" (tools-code-sandbox).
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

vi.mock('../../src/api/codeSandbox.js', () => ({ getCodeSandbox: vi.fn(), setCodeSandbox: vi.fn(), testCodeSandbox: vi.fn() }))

const api = await import('../../src/api/codeSandbox.js')
const { default: CodeSandboxSettings } = await import('../../src/components/settings/CodeSandboxSettings.vue')

const t = (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (all, key) => (key in vars ? vars[key] : all))

const NcButton = defineComponent({
	name: 'NcButton',
	props: { disabled: { type: Boolean, default: false } },
	emits: ['click'],
	setup(props, { slots, emit }) {
		return () => h('button', { disabled: props.disabled, onClick: () => emit('click') }, slots.default?.())
	},
})

const NcNoteCard = defineComponent({
	name: 'NcNoteCard',
	setup(props, { slots }) {
		return () => h('div', { class: 'note' }, slots.default?.())
	},
})

/**
 * Mount the section over a stored choice and let it load.
 *
 * @param {object} stored What the API answers.
 * @return {Promise<object>} The wrapper.
 */
async function section(stored) {
	api.getCodeSandbox.mockResolvedValueOnce(stored)
	const w = mount(CodeSandboxSettings, {
		global: { mocks: { t }, stubs: { NcButton, NcNoteCard, NcCheckboxRadioSwitch: true, NcTextField: true } },
	})
	await flushPromises()
	return w
}

/**
 * The button with a label.
 *
 * @param {object} w The wrapper.
 * @param {string} label The label.
 * @return {object} The button.
 */
const button = (w, label) => w.findAll('button').find((b) => b.text() === label)

beforeEach(() => {
	api.getCodeSandbox.mockReset()
	api.setCodeSandbox.mockReset()
	api.testCodeSandbox.mockReset()
})

describe('CodeSandboxSettings', () => {
	it('says code runs on this server and warns when no sandbox can take runs', async () => {
		const w = await section({ backend: 'exapp', hostedSource: null, reach: 'self', available: false })

		expect(w.find('.code-sandbox__live').text()).toBe('Code runs at: this server')
		expect(w.text()).toContain('No code sandbox can take runs right now.')
		expect(button(w, 'Save').attributes('disabled')).toBeDefined()
	})

	it('shows a hosted source as outside this server and the passed test', async () => {
		api.testCodeSandbox.mockResolvedValueOnce({ passed: true, output: '2', backend: 'hosted', hostedSource: 'hosted-sandbox' })
		const w = await section({ backend: 'hosted', hostedSource: 'hosted-sandbox', reach: 'external', available: true })

		expect(w.find('.code-sandbox__live').text()).toBe('Code runs at: hosted-sandbox (outside this server)')
		await button(w, 'Run a test').trigger('click')
		await flushPromises()

		expect(api.testCodeSandbox).toHaveBeenCalledTimes(1)
		expect(w.find('.code-sandbox__test').text()).toBe('Test passed: 2')
	})

	it('saves a hosted choice with its trimmed source', async () => {
		api.setCodeSandbox.mockResolvedValueOnce({ backend: 'hosted', hostedSource: 'hosted-sandbox', reach: 'external', available: true })
		const w = await section({ backend: 'exapp', hostedSource: null, reach: 'self', available: true })

		w.vm.backend = 'hosted'
		w.vm.hostedSource = ' hosted-sandbox '
		await flushPromises()
		await button(w, 'Save').trigger('click')
		await flushPromises()

		expect(api.setCodeSandbox).toHaveBeenCalledWith('hosted', 'hosted-sandbox')
		expect(w.find('.code-sandbox__live').text()).toBe('Code runs at: hosted-sandbox (outside this server)')
	})

	it('shows a failed test and a refused save', async () => {
		api.testCodeSandbox.mockResolvedValueOnce({ passed: false, output: '3' })
		api.setCodeSandbox.mockRejectedValueOnce({ response: { data: { error: 'A hosted sandbox needs the integriq source it is reached through.' } } })
		const w = await section({ backend: 'exapp', hostedSource: null, reach: 'self', available: true })

		await button(w, 'Run a test').trigger('click')
		await flushPromises()
		expect(w.find('.code-sandbox__test').text()).toBe('Test failed: 3')

		w.vm.backend = 'hosted'
		await flushPromises()
		await button(w, 'Save').trigger('click')
		await flushPromises()
		expect(api.setCodeSandbox).toHaveBeenCalledWith('hosted', '')
		expect(w.find('.note').exists()).toBe(true)
	})
})
