/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Marking a tool call as misuse on the agent's tool activity (verify-row-scenarios,
 * row ov-tool-oversight): a recorded call offers "Mark", the modal refuses to send
 * a mark without a reason, sends the trimmed reason for the row's call, and the
 * row then shows "Misuse" with who marked it and when.
 *
 * @spec openspec/changes/verify-row-scenarios/specs/agent-tool-governance/spec.md#scenario-an-operator-marks-a-tool-call-as-misuse
 */

import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

vi.mock('../../src/api/toolOversight.js', () => ({
	getToolInvocations: vi.fn(),
	markToolCall: vi.fn(),
	toolInvocationsExportUrl: (id, format) => `/export/${id}.${format}`,
}))
vi.mock('@nextcloud/vue', () => ({
	NcButton: {
		props: ['disabled', 'variant'],
		emits: ['click'],
		render() {
			return h('button', { disabled: this.disabled, class: `btn btn--${this.variant || 'default'}`, onClick: () => this.$emit('click') }, this.$slots.default?.())
		},
	},
	NcEmptyContent: { props: ['name'], render() { return h('div', { class: 'empty' }, this.name) } },
	NcLoadingIcon: { render: () => h('span', { class: 'spinner' }) },
	NcModal: { props: ['show', 'name'], render() { return this.show ? h('div', { class: 'modal' }, this.$slots.default?.()) : null } },
	NcNoteCard: { props: ['type', 'heading'], render() { return h('div', { class: `note note--${this.type}` }, this.$slots.default?.()) } },
	NcTextArea: {
		props: ['modelValue', 'label'],
		emits: ['update:modelValue'],
		render() {
			return h('textarea', { value: this.modelValue, onInput: (e) => this.$emit('update:modelValue', e.target.value) })
		},
	},
}))

const oversight = await import('../../src/api/toolOversight.js')
const { default: ToolInvocationTable } = await import('../../src/components/ToolInvocationTable.vue')
const { default: ToolCallMarkModal } = await import('../../src/modals/ToolCallMarkModal.vue')

const t = (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m))
globalThis.t = t

const mountOptions = (props) => ({ props, global: { mocks: { t } } })
const lastCell = (row) => row.findAll('td').at(-1)

describe('Marking a tool call as misuse', () => {
	beforeEach(() => {
		oversight.getToolInvocations.mockReset()
		oversight.markToolCall.mockReset()
		vi.spyOn(Date.prototype, 'toLocaleString').mockReturnValue('13-11-2026 10:00')
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('offers Mark on a recorded call and shows the label of a call already marked', async () => {
		oversight.getToolInvocations.mockResolvedValueOnce({
			available: true,
			retention: '90 days',
			rows: [
				{ at: '2026-11-13T10:00:00Z', callId: 'c-1', toolId: 'hermiq.sendMail' },
				{ at: '2026-11-13T09:00:00Z', callId: 'c-2', toolId: 'hermiq.sendMail', mark: { callId: 'c-2', label: 'misuse', markedBy: 'olga', markedAt: '2026-11-13T11:00:00Z' } },
			],
		})
		const w = mount(ToolInvocationTable, mountOptions({ agentId: 'a1' }))
		await flushPromises()
		const rows = w.findAll('tbody tr')

		expect(lastCell(rows[0]).find('button').text()).toBe('Mark')
		expect(lastCell(rows[1]).find('.tool-oversight__badge--misuse').text()).toBe('Misuse')
		expect(lastCell(rows[1]).find('.tool-oversight__mark-by').text()).toBe('by olga on 13-11-2026 10:00')
		expect(lastCell(rows[1]).find('button').exists()).toBe(false)
		expect(w.find('.modal').exists()).toBe(false)
	})

	it('marks the chosen call with the reason and labels its row', async () => {
		oversight.getToolInvocations.mockResolvedValueOnce({
			available: true,
			retention: '90 days',
			rows: [{ at: '2026-11-13T10:00:00Z', callId: 'c-1', toolId: 'hermiq.sendMail' }],
		})
		oversight.markToolCall.mockResolvedValueOnce({ callId: 'c-1', label: 'misuse', reason: 'Not its task', markedBy: 'olga', markedAt: '2026-11-13T11:00:00Z' })
		const w = mount(ToolInvocationTable, mountOptions({ agentId: 'a1' }))
		await flushPromises()

		await lastCell(w.find('tbody tr')).find('button').trigger('click')
		expect(w.find('.modal').text()).toContain('hermiq.sendMail')
		await w.find('.modal textarea').setValue('  Sending mail is not part of this agent\'s task  ')
		await w.find('.modal .btn--primary').trigger('click')
		await flushPromises()

		expect(oversight.markToolCall).toHaveBeenCalledWith('a1', 'c-1', 'Sending mail is not part of this agent\'s task')
		expect(w.find('.modal').exists()).toBe(false)
		expect(lastCell(w.find('tbody tr')).find('.tool-oversight__badge--misuse').text()).toBe('Misuse')
		expect(lastCell(w.find('tbody tr')).find('.tool-oversight__mark-by').text()).toBe('by olga on 13-11-2026 10:00')
	})

	it('does not send a mark without a reason', async () => {
		const w = mount(ToolCallMarkModal, mountOptions({ show: true, agentId: 'a1', row: { callId: 'c-1', toolId: 'hermiq.sendMail' } }))
		const mark = w.find('.btn--primary')

		expect(mark.attributes('disabled')).toBeDefined()
		await w.find('textarea').setValue('   ')
		expect(mark.attributes('disabled')).toBeDefined()
		await w.vm.confirm()

		expect(oversight.markToolCall).not.toHaveBeenCalled()
		expect(w.emitted('marked')).toBeUndefined()
	})

	it('shows the server refusal and keeps the modal open', async () => {
		oversight.markToolCall.mockRejectedValueOnce({ response: { data: { error: 'A mark needs a reason.' } } })
		const w = mount(ToolCallMarkModal, mountOptions({ show: true, agentId: 'a1', row: { callId: 'c-1', toolId: 'hermiq.sendMail' } }))

		await w.find('textarea').setValue('Not its task')
		await w.find('.btn--primary').trigger('click')
		await flushPromises()

		expect(w.find('.note--error').text()).toBe('A mark needs a reason.')
		expect(w.emitted('marked')).toBeUndefined()
		expect(w.emitted('close')).toBeUndefined()
	})
})
