/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The "Per department" list on the approvals page: one row per department
 * with its switch, who switched it and when, the refused runs and the
 * evidence download; administrators only.
 *
 * @spec openspec/changes/oversight-switch-ai-off-per-department/specs/human-approval-gate/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const state = { is_admin: true }
vi.mock('@nextcloud/initial-state', () => ({
	loadState: (app, key, fallback) => (key in state ? state[key] : fallback),
}))
vi.mock('../../src/api/departments.js', () => ({
	listDepartments: vi.fn(),
	toggleDepartment: vi.fn(),
	departmentEvidenceUrl: (group) => `/evidence/${group}`,
}))

const api = await import('../../src/api/departments.js')
const { default: DepartmentSwitchList } = await import('../../src/components/DepartmentSwitchList.vue')

const t = (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m))

/**
 * A switch stub that shows its label and emits the flipped value.
 */
const NcCheckboxRadioSwitch = {
	props: ['modelValue', 'disabled'],
	emits: ['update:modelValue'],
	setup(props, { slots, emit, attrs }) {
		return () => h('button', {
			class: 'switch',
			'aria-label': attrs['aria-label'],
			'data-on': String(props.modelValue),
			onClick: () => emit('update:modelValue', !props.modelValue),
		}, slots.default?.())
	},
}

/**
 * A link-button stub.
 */
const NcButton = {
	props: ['href'],
	setup(props, { slots }) {
		return () => h('a', { href: props.href }, slots.default?.())
	},
}

/**
 * Mount the list and let its load settle.
 *
 * @return {Promise<object>} The wrapper.
 */
async function list() {
	const wrapper = mount(DepartmentSwitchList, {
		global: {
			mocks: { t },
			stubs: {
				NcButton,
				NcCheckboxRadioSwitch,
				NcLoadingIcon: true,
				NcNoteCard: { setup(props, { slots }) { return () => h('aside', slots.default?.()) } },
			},
		},
	})
	await flushPromises()
	return wrapper
}

/**
 * Two departments: Burgerzaken on, Sociaal domein off.
 *
 * @return {Array<object>} The rows.
 */
function rows() {
	return [
	{ group: 'burgerzaken', label: 'Burgerzaken', aiOff: false, switchedBy: '', switchedAt: '', agentCount: 1, lastRun: null, refusedRuns: 0 },
	{ group: 'sociaal-domein', label: 'Sociaal domein', aiOff: true, switchedBy: 'admin', switchedAt: '2026-11-14T14:00:00+00:00', agentCount: 2, lastRun: '2026-11-14T15:10:00+00:00', refusedRuns: 3 },
	]
}

describe('the Per department list', () => {
	beforeEach(() => {
		vi.clearAllMocks()
		state.is_admin = true
		api.listDepartments.mockResolvedValue(rows())
		api.toggleDepartment.mockResolvedValue({})
	})

	it('shows a department that is off with who switched it, the refused runs and the evidence link', async () => {
		const wrapper = await list()
		const cells = wrapper.findAll('tbody tr')[1].findAll('td').map((cell) => cell.text())

		expect(cells[0]).toBe('Sociaal domein')
		expect(cells[1]).toBe('Off')
		expect(cells[2]).toMatch(/^admin at /)
		expect(cells[3]).toBe('2')
		expect(cells[5]).toBe('3')
		expect(wrapper.findAll('tbody tr')[1].find('a').attributes('href')).toBe('/evidence/sociaal-domein')
		expect(wrapper.findAll('tbody tr')[0].findAll('td')[2].text()).toBe('—')
	})

	it('switches a department off and reloads the stored state', async () => {
		const wrapper = await list()
		api.listDepartments.mockResolvedValue(rows().map((row) => ({ ...row, aiOff: true })))

		await wrapper.findAll('.switch')[0].trigger('click')
		await flushPromises()

		expect(api.toggleDepartment).toHaveBeenCalledWith('burgerzaken', true, '')
		expect(api.listDepartments).toHaveBeenCalledTimes(2)
		expect(wrapper.findAll('.switch')[0].text()).toBe('Off')
	})

	it('shows the error of a switch that fails and the state the server kept', async () => {
		api.toggleDepartment.mockRejectedValue(new Error('Only an administrator can switch a department.'))
		const wrapper = await list()

		await wrapper.findAll('.switch')[1].trigger('click')
		await flushPromises()

		expect(wrapper.find('aside').text()).toContain('Only an administrator can switch a department.')
		expect(wrapper.findAll('.switch')[1].text()).toBe('Off')
	})

	it('shows nothing and asks for nothing when the user is not an administrator', async () => {
		state.is_admin = false
		const wrapper = await list()

		expect(api.listDepartments).not.toHaveBeenCalled()
		expect(wrapper.find('section').exists()).toBe(false)
	})
})
