/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The budget, skill import and eval dataset modals driven through their
 * rendered controls: every field, picker and button the template wires is
 * exercised, so a binding that stops writing back fails here.
 *
 * Mounted with the shared @nextcloud/vue stand-ins (tests/vitest/stubs), whose
 * `pick(value)` emits `update:modelValue`; the APIs and the store are doubled.
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../../src/api/budgets.js', () => ({ createBudget: vi.fn(), updateBudget: vi.fn() }))
vi.mock('../../src/api/skills.js', () => ({ importSkill: vi.fn(), installFromSource: vi.fn() }))
const datasetStore = { registerObjectType: vi.fn(), saveObject: vi.fn(), errors: {} }
vi.mock('../../src/store/store.js', () => ({ useEvalDatasetStore: () => datasetStore }))

const budgets = await import('../../src/api/budgets.js')
const skills = await import('../../src/api/skills.js')
const { default: BudgetFormModal } = await import('../../src/modals/BudgetFormModal.vue')
const { default: SkillImportModal } = await import('../../src/modals/SkillImportModal.vue')
const { default: EvalDatasetFormModal } = await import('../../src/modals/EvalDatasetFormModal.vue')

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
 * Mount a component with the stand-ins rendered.
 *
 * @param {object} component The component.
 * @param {object} props Its props.
 * @return {object} The wrapper.
 */
function mountIt(component, props = {}) {
	return mount(component, { props, global: { mocks: { t } } })
}

/**
 * The stand-in of a kind carrying an attribute value.
 *
 * @param {object} w The wrapper.
 * @param {string} name The component name.
 * @param {string} attr The attribute.
 * @param {string} value Its value.
 * @return {object|undefined} The stand-in.
 */
function by(w, name, attr, value) {
	return w.findAllComponents({ name }).find((c) => c.vm.$attrs[attr] === value)
}

/**
 * Click the button whose text is given.
 *
 * @param {object} w The wrapper.
 * @param {string} text The button text.
 * @return {Promise<void>}
 */
async function click(w, text) {
	const button = w.findAllComponents({ name: 'NcButton' }).find((b) => b.text().trim() === text)
	if (!button) {
		throw new Error(`no button ${text}`)
	}
	button.vm.$emit('click')
	await flushPromises()
}

beforeEach(() => {
	vi.clearAllMocks()
	datasetStore.errors = {}
})

describe('BudgetFormModal controls', () => {
	it('builds an agent budget from its fields and creates it for the organisation', async () => {
		budgets.createBudget.mockResolvedValue({ id: 'b1' })
		const w = mountIt(BudgetFormModal, { organisation: 'org-1', show: true })

		by(w, 'NcSelect', 'inputLabel', 'Scope').vm.pick({ value: 'agent' })
		await flushPromises()
		by(w, 'NcTextField', 'label', 'Agent UUID').vm.pick('agent-7')
		by(w, 'NcSelect', 'inputLabel', 'Period').vm.pick({ value: 'weekly' })
		by(w, 'NcTextField', 'label', 'Token limit').vm.pick('5000')
		by(w, 'NcTextField', 'label', 'EUR limit (needs an instance-wide rate)').vm.pick('12.5')
		by(w, 'NcTextField', 'label', 'Soft-threshold warning (%)').vm.pick('90')
		w.findComponent({ name: 'NcCheckboxRadioSwitch' }).vm.pick(false)
		await flushPromises()
		await click(w, 'Save')

		expect(budgets.createBudget).toHaveBeenCalledWith({
			scope: 'agent',
			period: 'weekly',
			softThresholdPercent: 90,
			enabled: false,
			agentId: 'agent-7',
			tokenLimit: 5000,
			eurLimit: 12.5,
			organisation: 'org-1',
		})
		expect(w.emitted('saved')).toEqual([[{ id: 'b1' }]])
		expect(w.emitted('close')).toHaveLength(1)
	})

	it('clearing a picker falls back to organisation and monthly', async () => {
		const w = mountIt(BudgetFormModal, { organisation: 'org-1', show: true })

		by(w, 'NcSelect', 'inputLabel', 'Scope').vm.pick(null)
		by(w, 'NcSelect', 'inputLabel', 'Period').vm.pick(null)

		expect([w.vm.form.scope, w.vm.form.period]).toEqual(['organisation', 'monthly'])
	})

	it('shows a refused save, and closes from Cancel and the dialog', async () => {
		budgets.updateBudget.mockRejectedValue({ response: { data: { error: 'Limit too low' } } })
		const w = mountIt(BudgetFormModal, { organisation: 'org-1', budget: { id: 'b2', tokenLimit: 10 } })
		await w.setProps({ show: true })

		await click(w, 'Save')
		expect(budgets.updateBudget).toHaveBeenCalledWith('b2', expect.objectContaining({ tokenLimit: 10 }))
		expect(by(w, 'NcNoteCard', 'type', 'error').text()).toBe('Limit too low')

		await click(w, 'Cancel')
		w.findComponent({ name: 'NcDialog' }).vm.$emit('update:open', false)
		expect(w.emitted('close')).toHaveLength(2)
	})
})

describe('SkillImportModal controls', () => {
	it('imports the pasted package directly, or installs it from the hub', async () => {
		skills.importSkill.mockResolvedValue({})
		skills.installFromSource.mockResolvedValue({})
		const w = mountIt(SkillImportModal)

		await w.find('textarea').setValue('  ---\nname: Triage\n---  ')
		await click(w, 'Import')
		await click(w, 'Install from hub (quarantine)')

		expect(skills.importSkill).toHaveBeenCalledWith('---\nname: Triage\n---')
		expect(skills.installFromSource).toHaveBeenCalledWith('---\nname: Triage\n---', 'hub')
		expect(w.emitted('imported')).toHaveLength(2)
		expect(w.emitted('close')).toHaveLength(2)
	})

	it('does nothing for an empty package, shows a refusal, and closes from Cancel', async () => {
		skills.importSkill.mockRejectedValue(new Error('Not a skill package'))
		const w = mountIt(SkillImportModal)

		await w.find('textarea').setValue('   ')
		await click(w, 'Import')
		expect(skills.importSkill).not.toHaveBeenCalled()

		await w.find('textarea').setValue('junk')
		await click(w, 'Import')
		expect(by(w, 'NcNoteCard', 'type', 'error').text()).toBe('Not a skill package')

		await click(w, 'Cancel')
		w.findComponent({ name: 'NcModal' }).vm.$emit('close')
		expect(w.emitted('close')).toHaveLength(2)
	})
})

describe('EvalDatasetFormModal controls', () => {
	it('writes each case per expectation type and saves the trimmed cases', async () => {
		datasetStore.saveObject.mockResolvedValue({ id: 'd1' })
		const w = mountIt(EvalDatasetFormModal, { show: false })
		await w.setProps({ show: true })

		by(w, 'NcTextField', 'label', 'Name').vm.pick('Smoke')
		by(w, 'NcTextArea', 'label', 'Prompt').vm.pick('Say hi')
		by(w, 'NcTextField', 'label', 'Expected substring').vm.pick('hi')
		await click(w, 'Add case')
		const selects = w.findAllComponents({ name: 'NcSelect' })
		selects[1].vm.pick({ value: 'jsonPathEquals' })
		await flushPromises()
		by(w, 'NcTextField', 'label', 'JSON path').vm.pick('result.status')
		by(w, 'NcTextField', 'label', 'Expected value').vm.pick('ok')
		await click(w, 'Add case')
		w.findAllComponents({ name: 'NcSelect' })[2].vm.pick({ value: 'rubric' })
		await flushPromises()
		by(w, 'NcTextArea', 'label', 'Rubric (graded by an LLM judge)').vm.pick('Polite')
		await flushPromises()
		await click(w, 'Save')

		expect(datasetStore.saveObject).toHaveBeenCalledWith('evaldataset', {
			name: 'Smoke',
			cases: [
				{ prompt: 'Say hi', expectationType: 'contains', expectedSubstring: 'hi' },
				{ prompt: '', expectationType: 'jsonPathEquals', jsonPath: 'result.status', expectedValue: 'ok' },
				{ prompt: '', expectationType: 'rubric', rubric: 'Polite' },
			],
		})
		expect(w.emitted('saved')).toEqual([[{ id: 'd1' }]])
	})

	it('removing the last case leaves a blank one, a cleared expectation is contains, and Cancel closes', async () => {
		const w = mountIt(EvalDatasetFormModal, { show: true })

		w.findAllComponents({ name: 'NcSelect' })[0].vm.pick(null)
		expect(w.vm.form.cases[0].expectationType).toBe('contains')
		await click(w, 'Remove')
		expect(w.vm.form.cases).toHaveLength(1)

		await click(w, 'Cancel')
		w.findComponent({ name: 'NcModal' }).vm.$emit('close')
		expect(w.emitted('close')).toHaveLength(2)
	})
})
