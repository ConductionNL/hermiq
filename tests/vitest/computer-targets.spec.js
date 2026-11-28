/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The admin computer targets screen (tools-browser-and-computer-use, REQ-COMPUTER-001):
 * the registered machines, a test screenshot shown and not stored, removing a
 * machine, and the form that needs a name and an integriq source.
 *
 * @spec openspec/changes/tools-browser-and-computer-use/specs/computer-use/spec.md#requirement-an-admin-registers-a-machine-reached-only-through-integriq-req-computer-001
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

vi.mock('../../src/api/computerTargets.js', () => ({
	listComputerTargets: vi.fn(),
	saveComputerTarget: vi.fn(),
	deleteComputerTarget: vi.fn(),
	takeTestScreenshot: vi.fn(),
	listIntegriqSources: vi.fn(),
}))
vi.mock('@nextcloud/vue', () => ({
	NcButton: {
		props: ['disabled', 'variant', 'type'],
		emits: ['click'],
		render() {
			return h('button', { 'disabled': this.disabled, 'type': this.type || 'button', 'data-variant': this.variant, 'onClick': () => this.$emit('click') }, this.$slots.default?.())
		},
	},
	NcCheckboxRadioSwitch: { props: ['modelValue'], render() { return h('label', { class: 'switch' }, this.$slots.default?.()) } },
	NcModal: { props: ['show'], render() { return this.show ? h('div', { class: 'modal' }, this.$slots.default?.()) : null } },
	NcNoteCard: { props: ['type'], render() { return h('div', { class: `note note--${this.type}` }, this.$slots.default?.()) } },
	NcSelect: {
		props: ['modelValue', 'options'],
		emits: ['update:modelValue'],
		render() {
			return h('select', { onChange: (e) => this.$emit('update:modelValue', this.options[Number(e.target.value)]) }, this.options.map((o, i) => h('option', { value: i }, o.label)))
		},
	},
	NcTextField: {
		props: ['modelValue', 'label'],
		emits: ['update:modelValue'],
		render() {
			return h('input', { 'value': this.modelValue, 'aria-label': this.label, 'onInput': (e) => this.$emit('update:modelValue', e.target.value) })
		},
	},
}))

const api = await import('../../src/api/computerTargets.js')
const { default: ComputerTargets } = await import('../../src/components/settings/ComputerTargets.vue')
const { default: ComputerTargetFormModal } = await import('../../src/modals/ComputerTargetFormModal.vue')

const t = (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m))
globalThis.t = t
const options = (props = {}) => ({ props, global: { mocks: { t } } })
const target = { id: 't-1', name: 'Werkplek 03', applications: 'Suite4Sociaal Domein', enabled: true, source: 's-1' }
const button = (w, text) => w.findAll('button').find((b) => b.text() === text)

describe('ComputerTargets', () => {
	beforeEach(() => {
		vi.resetAllMocks()
		api.listIntegriqSources.mockResolvedValue([{ id: 's-1', label: 'Werkplekken' }, { id: 's-2', label: 'Balie' }])
	})

	it('lists the machines and shows a test screenshot without storing it', async () => {
		api.listComputerTargets.mockResolvedValue([target])
		api.takeTestScreenshot.mockResolvedValueOnce({ image: 'aGVsbG8=', mimeType: 'image/png', width: 10, height: 10 })
		const w = mount(ComputerTargets, options())
		await flushPromises()

		expect(w.findAll('tbody tr')).toHaveLength(1)
		expect(w.findAll('tbody td').slice(0, 3).map((td) => td.text())).toEqual(['Werkplek 03', 'Suite4Sociaal Domein', 'Yes'])

		await button(w, 'Test screenshot').trigger('click')
		await flushPromises()

		expect(api.takeTestScreenshot).toHaveBeenCalledWith('t-1')
		expect(w.find('figure img').attributes('src')).toBe('data:image/png;base64,aGVsbG8=')
		expect(w.find('figcaption').text()).toBe('Test screenshot of Werkplek 03. It is shown here only and not stored.')
		expect(api.saveComputerTarget).not.toHaveBeenCalled()
	})

	it('shows the server refusal of a screenshot in place', async () => {
		api.listComputerTargets.mockResolvedValue([target])
		api.takeTestScreenshot.mockRejectedValueOnce({ response: { data: { error: 'The machine did not answer with a screenshot.' } } })
		const w = mount(ComputerTargets, options())
		await flushPromises()

		await button(w, 'Test screenshot').trigger('click')
		await flushPromises()

		expect(w.find('.note--error').text()).toBe('The machine did not answer with a screenshot.')
		expect(w.find('figure').exists()).toBe(false)
	})

	it('removes a machine and reads the list again', async () => {
		api.listComputerTargets.mockResolvedValueOnce([target]).mockResolvedValueOnce([])
		api.deleteComputerTarget.mockResolvedValueOnce()
		const w = mount(ComputerTargets, options())
		await flushPromises()

		await button(w, 'Delete').trigger('click')
		await flushPromises()

		expect(api.deleteComputerTarget).toHaveBeenCalledWith('t-1')
		expect(w.find('table').exists()).toBe(false)
		expect(w.find('.computer-targets__empty').exists()).toBe(true)
	})

	it('opens the form for a new machine', async () => {
		api.listComputerTargets.mockResolvedValue([])
		const w = mount(ComputerTargets, options())
		await flushPromises()

		expect(w.find('.modal').exists()).toBe(false)
		await button(w, 'Add computer target').trigger('click')
		await flushPromises()

		expect(w.find('.modal h2').text()).toBe('Add computer target')
	})
})

describe('ComputerTargetFormModal', () => {
	beforeEach(() => {
		vi.resetAllMocks()
		api.listIntegriqSources.mockResolvedValue([{ id: 's-1', label: 'Werkplekken' }, { id: 's-2', label: 'Balie' }])
	})

	it('needs a name and a source before it saves', async () => {
		const w = mount(ComputerTargetFormModal, options({ show: true, target: null }))
		await flushPromises()
		const save = () => button(w, 'Save')

		expect(save().attributes('disabled')).toBeDefined()
		await w.find('input[aria-label="Name"]').setValue('Werkplek 04')
		expect(save().attributes('disabled')).toBeDefined()
		await w.find('select').setValue('1')
		expect(save().attributes('disabled')).toBeUndefined()
	})

	it('saves a changed machine with its id and hands it to the parent', async () => {
		api.saveComputerTarget.mockResolvedValueOnce({ ...target, name: 'Werkplek 03b' })
		const w = mount(ComputerTargetFormModal, options({ show: true, target }))
		await flushPromises()

		await w.find('input[aria-label="Name"]').setValue('  Werkplek 03b ')
		await w.find('form').trigger('submit')
		await flushPromises()

		expect(api.saveComputerTarget).toHaveBeenCalledWith({
			id: 't-1',
			name: 'Werkplek 03b',
			description: '',
			applications: 'Suite4Sociaal Domein',
			source: 's-1',
			enabled: true,
		})
		expect(w.emitted('saved')[0][0].name).toBe('Werkplek 03b')
		expect(w.emitted('close')).toHaveLength(1)
	})

	it('shows the server refusal and stays open', async () => {
		api.saveComputerTarget.mockRejectedValueOnce({ response: { data: { error: 'Choose an integriq source that reaches the machine.' } } })
		const w = mount(ComputerTargetFormModal, options({ show: true, target }))
		await flushPromises()

		await w.find('form').trigger('submit')
		await flushPromises()

		expect(w.find('.note--error').text()).toBe('Choose an integriq source that reaches the machine.')
		expect(w.emitted('close')).toBeUndefined()
	})
})
