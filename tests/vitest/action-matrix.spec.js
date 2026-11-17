/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The section "Rechten per actie" on the tenant management page: it lists the
 * actions, saves a change, resets an action to its default, and is not shown
 * to someone who is not a Nextcloud administrator.
 *
 * @spec openspec/changes/action-matrix-screen/specs/action-authorization/spec.md
 */

import { state } from '@nextcloud/initial-state'
import { flushPromises, mount, shallowMount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const toasts = []
vi.mock('@nextcloud/dialogs', () => ({
	showError: (m) => toasts.push(['error', m]),
	showSuccess: (m) => toasts.push(['success', m]),
}))
vi.mock('@nextcloud/vue', async (importOriginal) => ({
	...(await importOriginal()),
	NcButton: { name: 'NcButton', props: ['disabled', 'variant'], emits: ['click'], template: '<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>' },
	NcLoadingIcon: { name: 'NcLoadingIcon', template: '<span class="loading" />' },
	NcNoteCard: { name: 'NcNoteCard', template: '<div class="note"><slot /></div>' },
	NcSelect: { name: 'NcSelect', props: ['modelValue', 'inputLabel', 'options', 'multiple', 'disabled'], emits: ['update:modelValue'], template: '<div class="select" :data-label="inputLabel" />' },
}))
vi.mock('@conduction/nextcloud-vue', () => ({ CnDataTable: { name: 'CnDataTable', render: () => null } }))
vi.mock('../../src/api/actionMatrix.js', () => ({ listActions: vi.fn(), saveActionGroups: vi.fn() }))
vi.mock('../../src/api/budgets.js', () => ({ deleteBudget: vi.fn(), getBudgetStatus: vi.fn(), listBudgets: vi.fn(async () => []) }))
vi.mock('../../src/api/modelPolicy.js', () => ({ listModelPoliciesWithDataUse: vi.fn(async () => ({ policies: [], dataUse: {} })), updateModelPolicy: vi.fn() }))
vi.mock('../../src/api/tenantOps.js', () => ({ attestReviewed: vi.fn(), getAccessReview: vi.fn(async () => ({ agents: [] })), reassignAgent: vi.fn() }))

const api = await import('../../src/api/actionMatrix.js')
const { default: ActionMatrixSection } = await import('../../src/components/ActionMatrixSection.vue')
const { default: TenantOps } = await import('../../src/views/TenantOps.vue')

const t = (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m))
globalThis.t = t

const rows = [
	{ action: 'aifeature.acknowledge', label: 'Acknowledge an AI feature', description: 'Confirm it was reviewed.', groups: ['admin'], seeded: ['admin'], changed: false },
	{ action: 'compliance.view-dashboard', label: 'View the compliance dashboard', description: 'Open the overview.', groups: ['admin', 'auditors'], seeded: ['admin'], changed: true },
]

beforeEach(() => {
	toasts.length = 0
	vi.clearAllMocks()
	for (const key of Object.keys(state)) {
		delete state[key]
	}
	api.listActions.mockResolvedValue({ actions: rows.map((r) => ({ ...r })), groups: ['admin', 'auditors', 'dpo'] })
})

/**
 * Mount the section with its API doubled.
 *
 * @return {Promise<object>} The wrapper.
 */
async function mountSection() {
	const w = mount(ActionMatrixSection, { global: { mocks: { t } } })
	await flushPromises()
	return w
}

/**
 * The row of one action.
 *
 * @param {object} w The wrapper.
 * @param {string} action The action.
 * @return {object} The row wrapper.
 */
const rowOf = (w, action) => w.find(`[data-testid="action-matrix-row"][data-action="${action}"]`)

describe('ActionMatrixSection', () => {
	it('lists every action with its label, description, groups and changed mark, and says it is instance-wide', async () => {
		const w = await mountSection()

		expect(w.findAll('[data-testid="action-matrix-row"]')).toHaveLength(2)
		expect(w.find('[data-testid="action-matrix-scope"]').text()).toContain('every organisation on this instance')
		const ack = rowOf(w, 'aifeature.acknowledge')
		expect(ack.text()).toContain('Acknowledge an AI feature')
		expect(ack.text()).toContain('Confirm it was reviewed.')
		expect(ack.find('[data-testid="action-matrix-changed"]').exists()).toBe(false)
		expect(ack.findComponent({ name: 'NcSelect' }).props()).toMatchObject({ modelValue: ['admin'], options: ['admin', 'auditors', 'dpo'], multiple: true, inputLabel: 'Groups allowed to: Acknowledge an AI feature' })
		expect(rowOf(w, 'compliance.view-dashboard').find('[data-testid="action-matrix-changed"]').text()).toBe('Changed')
	})

	it('saves a change for one action and shows what the server stored', async () => {
		api.saveActionGroups.mockResolvedValue({ ...rows[0], groups: ['admin', 'dpo'], changed: true })
		const w = await mountSection()
		const ack = rowOf(w, 'aifeature.acknowledge')
		expect(ack.find('[data-testid="action-matrix-save"]').attributes('disabled')).toBeDefined()

		await ack.findComponent({ name: 'NcSelect' }).vm.$emit('update:modelValue', ['admin', 'dpo'])
		expect(ack.find('[data-testid="action-matrix-save"]').attributes('disabled')).toBeUndefined()
		await ack.find('[data-testid="action-matrix-save"]').trigger('click')
		await flushPromises()

		expect(api.saveActionGroups).toHaveBeenCalledWith('aifeature.acknowledge', ['admin', 'dpo'])
		expect(rowOf(w, 'aifeature.acknowledge').find('[data-testid="action-matrix-changed"]').exists()).toBe(true)
		expect(toasts).toEqual([['success', 'Rights for "Acknowledge an AI feature" saved.']])
	})

	it('resets an action to its seeded groups', async () => {
		api.saveActionGroups.mockResolvedValue({ ...rows[1], groups: ['admin'], changed: false })
		const w = await mountSection()

		await rowOf(w, 'compliance.view-dashboard').find('[data-testid="action-matrix-reset"]').trigger('click')
		await flushPromises()

		expect(api.saveActionGroups).toHaveBeenCalledWith('compliance.view-dashboard', ['admin'])
		expect(rowOf(w, 'compliance.view-dashboard').find('[data-testid="action-matrix-changed"]').exists()).toBe(false)
		expect(rowOf(w, 'aifeature.acknowledge').find('[data-testid="action-matrix-reset"]').attributes('disabled')).toBeDefined()
	})

	it('shows the server refusal when a save is refused', async () => {
		api.saveActionGroups.mockRejectedValue({ response: { data: { message: 'Group "gone" does not exist.' } } })
		const w = await mountSection()

		await rowOf(w, 'compliance.view-dashboard').find('[data-testid="action-matrix-reset"]').trigger('click')
		await flushPromises()

		expect(toasts).toEqual([['error', 'Group "gone" does not exist.']])
		expect(rowOf(w, 'compliance.view-dashboard').find('[data-testid="action-matrix-changed"]').exists()).toBe(true)
	})

	it('says so when the matrix cannot be loaded', async () => {
		api.listActions.mockRejectedValue(new Error('403'))
		const w = await mountSection()

		expect(w.find('[data-testid="action-matrix-error"]').text()).toBe('The rights per action could not be loaded.')
		expect(w.findAll('[data-testid="action-matrix-row"]')).toHaveLength(0)
	})
})

describe('TenantOps and the action matrix', () => {
	it('shows the section to a Nextcloud administrator', async () => {
		state.can_manage_killswitch = true
		state.is_admin = true
		const w = shallowMount(TenantOps, { global: { mocks: { t } } })
		await flushPromises()

		expect(w.findComponent(ActionMatrixSection).exists()).toBe(true)
	})

	it('does not show the section to someone who is not an administrator', async () => {
		state.can_manage_killswitch = true
		state.is_admin = false
		const w = shallowMount(TenantOps, { global: { mocks: { t } } })
		await flushPromises()

		expect(w.findComponent(ActionMatrixSection).exists()).toBe(false)
		expect(api.listActions).not.toHaveBeenCalled()
	})
})
