/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Three components mounted whole with the @nextcloud/vue stand-ins, so their
 * templates render and their inputs are driven through `pick()`: the kill
 * switch shows its label, reason and a failed reread; the provider declarations take an
 * edit from each of their four inputs; the start-fields form reports a text,
 * paragraph and cleared edit and shows the reason a field was refused.
 */

import { state } from '@nextcloud/initial-state'
import { t } from '@nextcloud/l10n'
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../../src/api/approvals.js', () => ({ getKillSwitch: vi.fn(), toggleKillSwitch: vi.fn() }))
vi.mock('../../src/api/llm.js', () => ({ declareProviderDataUse: vi.fn(), declareProviderResidency: vi.fn(), getProviderDataUse: vi.fn(), getProviderResidency: vi.fn() }))

globalThis.t = t

const approvals = await import('../../src/api/approvals.js')
const llm = await import('../../src/api/llm.js')
const { default: KillSwitchToggle } = await import('../../src/components/KillSwitchToggle.vue')
const { default: ProviderDeclarations } = await import('../../src/components/ProviderDeclarations.vue')
const { default: StartFieldsForm } = await import('../../src/components/StartFieldsForm.vue')

/**
 * Mount a component whole and let it load.
 *
 * @param {object} component The component.
 * @param {object} [props] Its props.
 * @return {Promise<object>} The wrapper.
 */
async function mountWhole(component, props = {}) {
	const wrapper = mount(component, { props, global: { mocks: { t } } })
	await flushPromises()
	return wrapper
}

/**
 * The stand-in of the named @nextcloud/vue component at an index.
 *
 * @param {object} wrapper The wrapper.
 * @param {string} name The component name.
 * @param {number} [index] Which one.
 * @return {object} The stand-in's wrapper.
 */
function standIn(wrapper, name, index = 0) {
	return wrapper.findAllComponents({ name })[index]
}

beforeEach(() => {
	vi.clearAllMocks()
	for (const key of Object.keys(state)) {
		delete state[key]
	}
})

describe('KillSwitchToggle rendered', () => {
	it('shows the engaged label and reason, and switches organisation from the select', async () => {
		state.can_manage_killswitch = true
		state.managed_organisations = [{ id: 'o1', label: 'One' }, { id: 'o2', label: 'Two' }]
		approvals.getKillSwitch.mockResolvedValueOnce({ engaged: true, reason: 'Incident 7' })
		const wrapper = await mountWhole(KillSwitchToggle)

		expect(wrapper.find('nc-checkboxradioswitch').text()).toBe('All runs halted for this organisation')
		expect(wrapper.find('.kill-switch__reason').text()).toBe('Incident 7')
		expect(wrapper.find('.kill-switch__icon--on').exists()).toBe(true)

		approvals.getKillSwitch.mockResolvedValueOnce({ engaged: false })
		standIn(wrapper, 'NcSelect').vm.pick({ label: 'Two', value: 'o2' })
		await flushPromises()

		expect(approvals.getKillSwitch).toHaveBeenLastCalledWith('o2')
		expect(wrapper.find('nc-checkboxradioswitch').text()).toBe('Halt all runs for this organisation')
		expect(wrapper.find('.kill-switch__reason').exists()).toBe(false)
	})

	it('toggles through the switch and shows a failed reread in an error card', async () => {
		state.can_manage_killswitch = true
		state.managed_organisations = [{ id: 'o1' }]
		approvals.getKillSwitch.mockResolvedValueOnce({ engaged: false })
		approvals.getKillSwitch.mockRejectedValueOnce(new Error('Read failed'))
		approvals.toggleKillSwitch.mockRejectedValueOnce(new Error('Not allowed'))
		const wrapper = await mountWhole(KillSwitchToggle)

		expect(wrapper.find('nc-select').exists()).toBe(false)
		standIn(wrapper, 'NcCheckboxRadioSwitch').vm.pick(true)
		await flushPromises()

		expect(approvals.toggleKillSwitch).toHaveBeenCalledWith('o1', true, '')
		expect(approvals.getKillSwitch).toHaveBeenCalledTimes(2)
		expect(wrapper.find('nc-notecard').attributes('data-heading')).toBe('Kill-switch error')
		expect(wrapper.find('nc-notecard').text()).toBe('Read failed')
	})
})

describe('ProviderDeclarations rendered', () => {
	beforeEach(() => {
		llm.getProviderResidency.mockResolvedValue({ residencies: {} })
		llm.getProviderDataUse.mockResolvedValue({ dataUse: { mistral: { dataUse: 'may-train', declaredBy: 'dpo', declaredAt: '2026-04-02T10:00:00Z' } } })
	})

	it('shows who declared the data use and when', async () => {
		const wrapper = await mountWhole(ProviderDeclarations, { provider: 'mistral' })

		expect(wrapper.find('.provider-declarations__hint').text()).toBe('Declared by dpo on 2026-04-02.')
	})

	it('saves what was entered in each of its four inputs', async () => {
		const wrapper = await mountWhole(ProviderDeclarations, { provider: 'mistral' })
		standIn(wrapper, 'NcSelect', 0).vm.pick({ value: 'outside-eu', label: 'Outside the EU' })
		standIn(wrapper, 'NcTextField', 0).vm.pick('Virginia')
		standIn(wrapper, 'NcSelect', 1).vm.pick({ value: 'zero-retention', label: 'Keeps nothing it is sent' })
		standIn(wrapper, 'NcTextField', 1).vm.pick('DPA 2026')
		await wrapper.vm.save()

		expect(llm.declareProviderResidency).toHaveBeenCalledWith('mistral', { residency: 'outside-eu', location: 'Virginia' })
		expect(llm.declareProviderDataUse).toHaveBeenCalledWith('mistral', { dataUse: 'zero-retention', termsReference: 'DPA 2026' })
	})

	it('shows no hint for a provider nobody declared', async () => {
		const wrapper = await mountWhole(ProviderDeclarations, { provider: 'other' })

		expect(wrapper.find('.provider-declarations__hint').exists()).toBe(false)
	})
})

describe('StartFieldsForm rendered', () => {
	const fields = [
		{ key: 'count', label: 'Count', type: 'number', required: false },
		{ key: 'notes', label: 'Notes', type: 'paragraph', required: true },
		{ key: 'tone', label: 'Tone', type: 'select', options: ['formal'], required: false },
	]

	it('reports a number and a paragraph edit as strings, and a cleared select as empty', () => {
		const wrapper = mount(StartFieldsForm, { props: { fields, modelValue: { tone: 'formal' } }, global: { mocks: { t } } })

		expect(wrapper.find('nc-textfield').attributes('data-type')).toBe('number')
		standIn(wrapper, 'NcTextField').vm.pick(3)
		standIn(wrapper, 'NcTextArea').vm.pick('Hello')
		standIn(wrapper, 'NcSelect').vm.pick(null)

		expect(wrapper.emitted('update:modelValue')).toEqual([
			[{ tone: 'formal', count: '3' }],
			[{ tone: 'formal', notes: 'Hello' }],
			[{ tone: '' }],
		])
	})

	it('shows the reason each refused field was refused', () => {
		const problems = { count: 'not a number', notes: 'required', tone: 'something else' }
		const wrapper = mount(StartFieldsForm, { props: { fields, modelValue: {}, problems }, global: { mocks: { t } } })

		expect(wrapper.findAll('[role="alert"]').map((p) => p.text())).toEqual([
			'Enter a number.',
			'Fill this in to start.',
			'something else',
		])
		expect(wrapper.find('nc-textfield').attributes('data-error')).toBe('true')
	})
})
