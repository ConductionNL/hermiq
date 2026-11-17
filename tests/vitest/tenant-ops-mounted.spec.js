// @vitest-environment jsdom
/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The tenant operations page as mounted: the budget list with its edit,
 * delete and organisation switch, the inline model-policy editor with the
 * refused-provider warning, and the access review's attestation and
 * reassignment, over mocked API modules.
 *
 * @spec openspec/changes/archive/2026-09-06-multi-tenant-ops/tasks.md#task-3-2
 */

import { state } from '@nextcloud/initial-state'
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const toasts = vi.hoisted(() => [])
vi.mock('@nextcloud/dialogs', () => ({
	showError: (m) => toasts.push(['error', m]),
	showSuccess: (m) => toasts.push(['success', m]),
}))
vi.mock('@conduction/nextcloud-vue', async () => {
	const { defineComponent, h } = await import('vue')
	return {
		CnDataTable: defineComponent({
			name: 'CnDataTable',
			props: { rows: { type: Array, default: () => [] } },
			setup(props, { slots }) {
				return () => h('div', { class: 'table-stub' }, props.rows.map((row) => h('div', { class: 'row-stub', 'data-uuid': row.uuid }, [
					slots['column-reassignmentFlag']?.({ row }),
					slots['row-actions']?.({ row }),
				])))
			},
		}),
	}
})
vi.mock('@nextcloud/vue', async () => {
	const { defineComponent, h } = await import('vue')
	const field = (name) => defineComponent({
		name,
		props: { modelValue: { default: null } },
		emits: ['update:modelValue'],
		setup(props, { slots }) {
			return () => h('div', { class: 'field-stub' }, slots.default?.())
		},
	})
	return {
		NcButton: defineComponent({
			name: 'NcButton',
			props: { disabled: { type: Boolean, default: false } },
			emits: ['click'],
			setup(props, { slots, emit }) {
				return () => h('button', { disabled: props.disabled, onClick: () => emit('click') }, slots.default?.())
			},
		}),
		NcCheckboxRadioSwitch: field('NcCheckboxRadioSwitch'),
		NcEmptyContent: defineComponent({
			name: 'NcEmptyContent',
			setup(props, { slots }) {
				return () => h('div', { class: 'empty-stub' }, slots.icon?.())
			},
		}),
		NcLoadingIcon: field('NcLoadingIcon'),
		NcNoteCard: field('NcNoteCard'),
		NcSelect: field('NcSelect'),
		NcTextArea: field('NcTextArea'),
		NcTextField: field('NcTextField'),
	}
})
vi.mock('../../src/modals/BudgetFormModal.vue', () => ({
	default: { name: 'BudgetFormModal', props: ['show', 'organisation', 'budget'], emits: ['close', 'saved'], render: () => null },
}))
vi.mock('../../src/api/budgets.js', () => ({ deleteBudget: vi.fn(), getBudgetStatus: vi.fn(), listBudgets: vi.fn() }))
vi.mock('../../src/api/modelPolicy.js', () => ({ listModelPoliciesWithDataUse: vi.fn(), updateModelPolicy: vi.fn() }))
vi.mock('../../src/api/tenantOps.js', () => ({ attestReviewed: vi.fn(), getAccessReview: vi.fn(), reassignAgent: vi.fn() }))

const budgets = await import('../../src/api/budgets.js')
const policy = await import('../../src/api/modelPolicy.js')
const ops = await import('../../src/api/tenantOps.js')
const { default: TenantOps } = await import('../../src/views/TenantOps.vue')

const t = (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m))
globalThis.t = t

/**
 * Mount the page and let its loads settle.
 *
 * @return {Promise<object>} The wrapper.
 */
async function mountPage() {
	const wrapper = mount(TenantOps, { global: { mocks: { t }, stubs: { ShieldIcon: true } } })
	await flushPromises()
	return wrapper
}

/**
 * The button with the given text within a wrapper.
 *
 * @param {object} wrapper The wrapper.
 * @param {string} text    The text.
 * @return {object} The button.
 */
function button(wrapper, text) {
	const found = wrapper.findAll('button').find((b) => b.text() === text)
	expect(found, text).toBeDefined()
	return found
}

beforeEach(() => {
	toasts.length = 0
	vi.clearAllMocks()
	for (const key of Object.keys(state)) {
		delete state[key]
	}
	state.can_manage_killswitch = true
	state.managed_organisations = [{ id: 'org-1', name: 'Gemeente A' }, { id: 'org-2', name: 'Gemeente B' }]
	budgets.listBudgets.mockResolvedValue([{ id: 'b1', agentId: 'a1', scope: 'agent', tokenLimit: 100 }])
	budgets.getBudgetStatus.mockResolvedValue({ tokens: { used: 10, limit: 100, percent: 10 } })
	policy.listModelPoliciesWithDataUse.mockResolvedValue({
		policies: [{ id: 'p1', allowed: [{ provider: 'openai', models: ['gpt'] }], defaultModel: 'gpt', requireNoTraining: false }],
		dataUse: { openai: 'no-training', mistral: 'may-train' },
	})
	ops.getAccessReview.mockResolvedValue({ agents: [{ uuid: 'u1', name: 'Bot', reassignmentFlag: true }, { uuid: 'u2', name: 'Calm' }] })
})

describe('TenantOps mounted', () => {
	it('shows the empty state without the capability', async () => {
		state.can_manage_killswitch = false
		const wrapper = await mountPage()
		expect(wrapper.find('.empty-stub').exists()).toBe(true)
		expect(budgets.listBudgets).not.toHaveBeenCalled()
	})

	it('adds, edits and deletes budgets and switches organisation', async () => {
		const wrapper = await mountPage()
		const modal = () => wrapper.findComponent({ name: 'BudgetFormModal' })

		await button(wrapper, 'Add budget').trigger('click')
		expect(modal().props('show')).toBe(true)
		expect(wrapper.vm.editingBudget).toBeNull()
		modal().vm.$emit('close')
		await flushPromises()
		expect(modal().props('show')).toBe(false)

		await wrapper.findAll('button').filter((b) => b.text() === 'Edit')[0].trigger('click')
		expect(wrapper.vm.editingBudget.id).toBe('b1')
		modal().vm.$emit('saved')
		await flushPromises()
		expect(budgets.listBudgets).toHaveBeenCalledTimes(2)

		budgets.deleteBudget.mockResolvedValue(undefined)
		await button(wrapper, 'Delete').trigger('click')
		await flushPromises()
		expect(budgets.deleteBudget).toHaveBeenCalledWith('b1')
		expect(toasts).toContainEqual(['success', 'Budget deleted.'])

		budgets.deleteBudget.mockRejectedValue(new Error('no'))
		await button(wrapper, 'Delete').trigger('click')
		await flushPromises()
		expect(toasts).toContainEqual(['error', 'Could not delete the budget.'])

		wrapper.findComponent({ name: 'NcSelect' }).vm.$emit('update:modelValue', { value: 'org-2', label: 'Gemeente B' })
		await flushPromises()
		expect(budgets.listBudgets).toHaveBeenLastCalledWith('org-2')
	})

	it('edits a model policy, warns about training providers and saves it', async () => {
		const wrapper = await mountPage()

		const editPolicy = () => wrapper.findAll('button').filter((b) => b.text() === 'Edit').at(-1)
		await editPolicy().trigger('click')
		expect(wrapper.vm.policyDraft).toEqual({ allowedText: 'openai: gpt', defaultModel: 'gpt', requireNoTraining: false })
		wrapper.findComponent({ name: 'NcTextArea' }).vm.$emit('update:modelValue', 'openai: gpt, gpt-mini\nmistral')
		wrapper.findAllComponents({ name: 'NcTextField' })[0].vm.$emit('update:modelValue', '')
		wrapper.findComponent({ name: 'NcCheckboxRadioSwitch' }).vm.$emit('update:modelValue', true)
		await flushPromises()
		expect(wrapper.vm.refusedProviders).toEqual(['mistral'])
		expect(wrapper.text()).toContain('mistral')

		policy.updateModelPolicy.mockResolvedValue({})
		await button(wrapper, 'Save policy').trigger('click')
		await flushPromises()
		expect(policy.updateModelPolicy).toHaveBeenCalledWith('p1', {
			allowed: [{ provider: 'openai', models: ['gpt', 'gpt-mini'] }, { provider: 'mistral', models: [] }],
			defaultModel: null,
			requireNoTraining: true,
		})
		expect(toasts).toContainEqual(['success', 'Model policy saved.'])
		expect(wrapper.vm.editingPolicyId).toBeNull()

		await editPolicy().trigger('click')
		await button(wrapper, 'Cancel').trigger('click')
		expect(wrapper.vm.editingPolicyId).toBeNull()
	})

	it('marks agents reviewed and reassigns a flagged one', async () => {
		const wrapper = await mountPage()
		const flagged = () => wrapper.find('[data-uuid="u1"]')
		expect(flagged().text()).toContain('Flagged for reassignment')
		expect(wrapper.find('[data-uuid="u2"]').text()).toContain('—')

		ops.attestReviewed.mockResolvedValue({})
		await button(flagged(), 'Mark reviewed').trigger('click')
		await flushPromises()
		expect(ops.attestReviewed).toHaveBeenCalledWith('u1')
		expect(toasts).toContainEqual(['success', 'Agent marked as reviewed.'])

		flagged().findComponent({ name: 'NcTextField' }).vm.$emit('update:modelValue', ' carol ')
		await flushPromises()
		ops.reassignAgent.mockResolvedValue({})
		await button(flagged(), 'Reassign').trigger('click')
		await flushPromises()
		expect(ops.reassignAgent).toHaveBeenCalledWith('u1', 'carol')
		expect(toasts).toContainEqual(['success', 'Agent reassigned.'])
		expect(wrapper.vm.reassignDrafts.u1).toBe('')
	})
})
