/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Characterisation of the form dialogs that reset each time they open: the
 * `show` prop defaults to closed, opening runs `resetForm()` (a stale error
 * and edited fields go), mounting and closing do not.
 */

import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'

vi.mock('@conduction/nextcloud-vue', () => ({
	CnMarkdownEditor: { name: 'CnMarkdownEditor', render: () => null },
	createObjectStore: () => () => ({}),
}))
vi.mock('../../src/store/store.js', () => ({
	useEvalDatasetStore: () => ({ registerObjectType: () => {}, saveObject: async () => ({}) }),
}))
vi.mock('../../src/api/budgets.js', () => ({ createBudget: vi.fn(), updateBudget: vi.fn() }))
vi.mock('../../src/api/skills.js', () => ({ importSkill: vi.fn(), installFromSource: vi.fn(), updateSkill: vi.fn() }))
vi.mock('../../src/api/tenantOps.js', () => ({ createIncident: vi.fn() }))

const dialogs = {
	EvalDatasetFormModal: (await import('../../src/modals/EvalDatasetFormModal.vue')).default,
	BudgetFormModal: (await import('../../src/modals/BudgetFormModal.vue')).default,
	SkillFormModal: (await import('../../src/modals/SkillFormModal.vue')).default,
	CreateIncidentDialog: (await import('../../src/dialogs/CreateIncidentDialog.vue')).default,
}

/**
 * Mount a dialog closed.
 *
 * @param {object} component The dialog.
 * @param {object} props Extra props.
 * @return {Promise<object>} The wrapper.
 */
async function closed(component, props = {}) {
	const wrapper = mount(component, {
		props,
		global: { mocks: { t: (app, text) => text } },
	})
	await flushPromises()
	return wrapper
}

describe.each(Object.entries(dialogs))('%s', (name, component) => {
	it('declares a show prop that defaults to closed', () => {
		const show = { ...(component.mixins || []).reduce((all, mixin) => ({ ...all, ...mixin.props }), {}), ...component.props }.show
		expect(show).toEqual({ type: Boolean, default: false })
	})

	it('resets only when it opens', async () => {
		const wrapper = await closed(component)
		const blank = JSON.stringify(wrapper.vm.form)
		wrapper.vm.error = 'stale'
		wrapper.vm.form = { ...wrapper.vm.form, edited: true }
		await wrapper.setProps({ show: false })
		expect(wrapper.vm.error).toBe('stale')

		await wrapper.setProps({ show: true })
		expect(wrapper.vm.error).toBe('')
		expect(JSON.stringify(wrapper.vm.form)).toBe(blank)

		wrapper.vm.error = 'kept'
		await wrapper.setProps({ show: false })
		expect(wrapper.vm.error).toBe('kept')
	})

	it('does not reset when it mounts open', async () => {
		const wrapper = mount(component, {
			props: { show: true },
			global: { mocks: { t: (app, text) => text } },
			data: () => ({ error: 'from before' }),
		})
		await flushPromises()
		expect(wrapper.vm.error).toBe('from before')
	})
})

describe('CreateIncidentDialog reset', () => {
	it('prefills the agent and runs it was opened for', async () => {
		const wrapper = await closed(dialogs.CreateIncidentDialog, { defaultAgentId: 'a-1', defaultRunIds: ['r-1', 'r-2'] })
		await wrapper.setProps({ show: true })
		expect(wrapper.vm.form.linkedAgentId).toBe('a-1')
		expect(wrapper.vm.linkedRunIdsText).toBe('r-1, r-2')
	})
})
