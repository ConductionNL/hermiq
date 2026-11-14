/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Approvals page as a reviewer uses it: one row per held run, and each
 * row's Details, Approve and Deny buttons doing what they say.
 *
 * @spec openspec/changes/archive/2026-09-06-human-approval-gate-ui/specs/human-approval-gate-ui/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

vi.mock('../../src/api/approvals.js', () => ({ approveApproval: vi.fn(), listPendingApprovals: vi.fn() }))

const agentStore = { registerObjectType: vi.fn(), fetchCollection: vi.fn() }
const scheduleStore = { registerObjectType: vi.fn(), fetchCollection: vi.fn() }
vi.mock('../../src/store/store.js', () => ({
	useAgentStore: () => agentStore,
	useScheduleStore: () => scheduleStore,
}))

const approvals = await import('../../src/api/approvals.js')
const { default: ApprovalInbox } = await import('../../src/views/ApprovalInbox.vue')

/**
 * A stub that renders its default slot.
 *
 * @param {string} tag The element.
 * @return {object} The stub.
 */
function slotted (tag) {
  return {
	setup(props, { slots, attrs }) {
		return () => h(tag, attrs, slots.default?.())
	},
}
}

/**
 * A button stub that renders its label and emits click.
 */
const NcButton = {
	emits: ['click'],
	setup(props, { slots, emit, attrs }) {
		return () => h('button', { 'aria-label': attrs['aria-label'], disabled: attrs.disabled, onClick: () => emit('click') }, slots.default?.())
	},
}

/**
 * A modal stub that records its props and can emit.
 *
 * @param {string} name The modal's name.
 * @param {Array<string>} emits What it can emit.
 * @return {object} The stub.
 */
function modal (name, emits) {
  return {
	name,
	props: ['approval', 'show'],
	emits,
	render: () => null,
}
}

const t = (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m))

/**
 * Mount the page and let its load settle.
 *
 * @return {Promise<object>} The wrapper.
 */
async function page() {
	const wrapper = mount(ApprovalInbox, {
		global: {
			mocks: { t },
			stubs: {
				NcButton,
				NcNoteCard: slotted('aside'),
				NcEmptyContent: { props: ['name'], render() { return h('p', { class: 'empty' }, this.name) } },
				NcLoadingIcon: true,
				KillSwitchToggle: true,
				DepartmentSwitchList: true,
				CheckDecagram: true,
				ApprovalPreviewModal: modal('ApprovalPreviewModal', ['close', 'approve', 'deny']),
				ApprovalDenyModal: modal('ApprovalDenyModal', ['close', 'denied']),
			},
		},
	})
	await flushPromises()
	return wrapper
}

/**
 * The first row's button with this label.
 *
 * @param {object} wrapper The page.
 * @param {string} label The aria-label.
 * @return {object} The button.
 */
const button = (wrapper, label) => wrapper.find(`tbody tr button[aria-label="${label}"]`)

describe('the Approvals page rows', () => {
	beforeEach(() => {
		vi.clearAllMocks()
		approvals.listPendingApprovals.mockResolvedValue([
			{ id: 'p1', scheduleId: 's1', agentId: 'a1', prompt: 'Vat de week samen', requestedAt: '' },
			{ id: 'p2', scheduleId: 's2', agentId: 'a1', prompt: '' },
		])
		scheduleStore.fetchCollection.mockResolvedValue([{ uuid: 's1', name: 'Weekoverzicht' }])
		agentStore.fetchCollection.mockResolvedValue([{ id: 'a1', name: 'Samenvatter' }])
	})

	it('draws one row per held run, with its schedule, agent and prompt', async () => {
		const wrapper = await page()

		const rows = wrapper.findAll('tbody tr')
		expect(rows).toHaveLength(2)
		expect(rows[0].findAll('td').slice(0, 4).map((td) => td.text())).toEqual(['Weekoverzicht', 'Samenvatter', 'Vat de week samen', '—'])
		expect(rows[1].find('.approval-inbox__prompt').text()).toBe('—')
	})

	it('opens the details of a row, and closes them again', async () => {
		const wrapper = await page()

		await button(wrapper, 'What this will do').trigger('click')
		const preview = wrapper.findComponent({ name: 'ApprovalPreviewModal' })
		expect(preview.props('approval').id).toBe('p1')

		preview.vm.$emit('close')
		await flushPromises()
		expect(wrapper.findComponent({ name: 'ApprovalPreviewModal' }).exists()).toBe(false)
	})

	it('approves a row and reads the list again', async () => {
		approvals.approveApproval.mockResolvedValue({})
		const wrapper = await page()

		await button(wrapper, 'Approve run').trigger('click')
		await flushPromises()

		expect(approvals.approveApproval).toHaveBeenCalledWith('p1')
		expect(approvals.listPendingApprovals).toHaveBeenCalledTimes(2)
	})

	it('opens the deny dialog for a row, and closes it again', async () => {
		const wrapper = await page()

		await button(wrapper, 'Deny run').trigger('click')
		const deny = wrapper.findComponent({ name: 'ApprovalDenyModal' })
		expect(deny.props('show')).toBe(true)
		expect(deny.props('approval').id).toBe('p1')

		deny.vm.$emit('close')
		await flushPromises()
		expect(wrapper.findComponent({ name: 'ApprovalDenyModal' }).props('show')).toBe(false)
	})

	it('reads the list again once a run is denied', async () => {
		const wrapper = await page()

		wrapper.findComponent({ name: 'ApprovalDenyModal' }).vm.$emit('denied')
		await flushPromises()

		expect(approvals.listPendingApprovals).toHaveBeenCalledTimes(2)
	})

	it('names the empty inbox, and shows why a load failed', async () => {
		approvals.listPendingApprovals.mockResolvedValueOnce([])
		const wrapper = await page()
		expect(wrapper.find('.empty').text()).toBe('No approvals waiting')

		approvals.listPendingApprovals.mockRejectedValueOnce(new Error('Inbox down'))
		await wrapper.vm.load()
		await flushPromises()
		expect(wrapper.find('aside').text()).toBe('Inbox down')
	})
})
