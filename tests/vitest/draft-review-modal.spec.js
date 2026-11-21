/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The draft review dialog (oversight-ask-and-hold): the recipients read-only,
 * the agent's text in an editor, "Release" sending it as written, "Edit and
 * release" sending only the changed fields, and "Discard" handing over.
 *
 * @spec openspec/changes/oversight-ask-and-hold/specs/human-approval-gate/spec.md#requirement-written-output-can-be-held-until-a-reviewer-releases-it-req-askhold-003
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

vi.mock('../../src/api/approvals.js', () => ({ releaseApproval: vi.fn() }))

const approvals = await import('../../src/api/approvals.js')
const { default: DraftReviewModal, draftEdits, draftFields } = await import('../../src/modals/DraftReviewModal.vue')

const report = { id: 'd1', sourceType: 'draft', draft: { kind: 'delivery', channel: 'email', output: 'Open tickets: 41' } }
const mail = {
	id: 'd2',
	sourceType: 'draft',
	draft: { kind: 'tool', toolId: 'hermiq.sendMail', arguments: { to: 'council@example.org', subject: 'Weekly', body: 'Hi' }, editable: ['subject', 'body'] },
}

/**
 * Mount the dialog for a draft.
 *
 * @param {object} draft The inbox record.
 * @return {Promise<object>} The wrapper.
 */
async function dialog(draft) {
	const field = (tag) => ({
		props: ['modelValue', 'label', 'disabled'],
		emits: ['update:modelValue'],
		render() {
			return h(tag, { 'aria-label': this.label, value: this.modelValue, onInput: (e) => this.$emit('update:modelValue', e.target.value) })
		},
	})
	const wrapper = mount(DraftReviewModal, {
		props: { show: true, draft },
		global: {
			mocks: { t: (app, text) => text },
			stubs: {
				NcModal: { setup(props, { slots }) { return () => h('div', slots.default?.()) } },
				NcButton: {
					props: ['disabled', 'variant'],
					emits: ['click'],
					setup(props, { slots, emit, attrs }) {
						return () => h('button', { 'data-testid': attrs['data-testid'], disabled: props.disabled || undefined, onClick: () => emit('click') }, slots.default?.())
					},
				},
				NcNoteCard: { setup(props, { slots }) { return () => h('aside', slots.default?.()) } },
				NcLoadingIcon: true,
				NcTextArea: field('textarea'),
				NcTextField: field('input'),
			},
		},
	})
	await flushPromises()
	return wrapper
}

describe('DraftReviewModal', () => {
	beforeEach(() => {
		vi.clearAllMocks()
		approvals.releaseApproval.mockResolvedValue({ status: 'approved', ran: true })
	})

	it('shows the recipients read-only and the agent\'s text in the editor', async () => {
		const wrapper = await dialog(mail)

		expect(wrapper.find('[data-testid="draft-review-recipients"] dd').text()).toBe('council@example.org')
		expect(wrapper.find('input').element.value).toBe('Weekly')
		expect(wrapper.find('textarea').element.value).toBe('Hi')
		expect(wrapper.find('[data-testid="draft-review-edit-release"]').attributes('disabled')).toBeDefined()
	})

	it('releases a draft as written', async () => {
		const wrapper = await dialog(report)

		await wrapper.find('[data-testid="draft-review-release"]').trigger('click')
		await flushPromises()

		expect(approvals.releaseApproval).toHaveBeenCalledWith('d1', {})
		expect(wrapper.emitted('released')).toHaveLength(1)
	})

	it('edits and releases a report with only its new text', async () => {
		const wrapper = await dialog(report)

		await wrapper.find('textarea').setValue('Open tickets: 14')
		expect(wrapper.find('[data-testid="draft-review-release"]').attributes('disabled')).toBeDefined()
		await wrapper.find('[data-testid="draft-review-edit-release"]').trigger('click')
		await flushPromises()

		expect(approvals.releaseApproval).toHaveBeenCalledWith('d1', { output: 'Open tickets: 14' })
	})

	it('never sends the recipient of a mail, only its changed text', async () => {
		const wrapper = await dialog(mail)

		await wrapper.find('textarea').setValue('Hello')
		await wrapper.find('[data-testid="draft-review-edit-release"]').trigger('click')
		await flushPromises()

		expect(approvals.releaseApproval).toHaveBeenCalledWith('d2', { body: 'Hello' })
	})

	it('shows why a release was refused and stays open', async () => {
		approvals.releaseApproval.mockRejectedValue(new Error('The field "to" of this draft cannot be changed.'))
		const wrapper = await dialog(report)

		await wrapper.find('[data-testid="draft-review-release"]').trigger('click')
		await flushPromises()

		expect(wrapper.find('aside').text()).toBe('The field "to" of this draft cannot be changed.')
		expect(wrapper.emitted('released')).toBeUndefined()
	})

	it('hands a discard to the parent', async () => {
		const wrapper = await dialog(report)

		await wrapper.find('[data-testid="draft-review-discard"]').trigger('click')

		expect(wrapper.emitted('discard')[0]).toEqual([report])
		expect(approvals.releaseApproval).not.toHaveBeenCalled()
	})

	it('reads and diffs the fields of each kind of draft', () => {
		expect(draftFields(report.draft)).toEqual({ subject: '', text: 'Open tickets: 41', mail: false })
		expect(draftFields(mail.draft)).toEqual({ subject: 'Weekly', text: 'Hi', mail: true })
		expect(draftFields({ kind: 'tool', arguments: { room: 'r', message: 'Yo' } })).toEqual({ subject: '', text: 'Yo', mail: false })
		expect(draftEdits({ kind: 'tool', arguments: { room: 'r', message: 'Yo' } }, { subject: '', text: 'Hey' })).toEqual({ message: 'Hey' })
		expect(draftEdits(mail.draft, { subject: 'Weekly 46', text: 'Hi' })).toEqual({ subject: 'Weekly 46' })
		expect(draftEdits(report.draft, { subject: '', text: 'Open tickets: 41' })).toEqual({})
		expect(draftFields(null)).toEqual({ subject: '', text: '', mail: false })
	})
})
