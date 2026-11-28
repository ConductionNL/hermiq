/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The session rename and delete modals, the approval deny and preview modals
 * and the app-template collect modal, driven through their rendered controls
 * rather than their methods: every field and button the template wires is
 * exercised, so a binding that stops writing back fails here.
 *
 * Mounted with the shared @nextcloud/vue stand-ins (tests/vitest/stubs), whose
 * `pick(value)` emits `update:modelValue`; the APIs are doubled.
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../../src/api/chat.js', () => ({ renameSession: vi.fn(), deleteSessionPermanent: vi.fn() }))
vi.mock('../../src/api/approvals.js', () => ({ denyApproval: vi.fn() }))
vi.mock('../../src/api/agentTemplates.js', () => ({ collectAppTemplates: vi.fn() }))

const chat = await import('../../src/api/chat.js')
const approvals = await import('../../src/api/approvals.js')
const templates = await import('../../src/api/agentTemplates.js')
const { default: SessionRenameModal } = await import('../../src/modals/SessionRenameModal.vue')
const { default: SessionDeleteModal } = await import('../../src/modals/SessionDeleteModal.vue')
const { default: ApprovalDenyModal } = await import('../../src/modals/ApprovalDenyModal.vue')
const { default: ApprovalPreviewModal } = await import('../../src/modals/ApprovalPreviewModal.vue')
const { default: AppTemplateCollectModal } = await import('../../src/modals/AppTemplateCollectModal.vue')

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
 * The button whose text is given.
 *
 * @param {object} w The wrapper.
 * @param {string} text The button text.
 * @return {object} The button stand-in.
 */
function button(w, text) {
	const found = w.findAllComponents({ name: 'NcButton' }).find((b) => b.text().trim() === text)
	if (!found) {
		throw new Error(`no button ${text}`)
	}
	return found
}

/**
 * Click the button whose text is given.
 *
 * @param {object} w The wrapper.
 * @param {string} text The button text.
 * @return {Promise<void>}
 */
async function click(w, text) {
	button(w, text).vm.$emit('click')
	await flushPromises()
}

beforeEach(() => {
	vi.clearAllMocks()
})

describe('SessionRenameModal controls', () => {
	const session = { uuid: 's-1', title: 'Old' }

	it('fills the field on opening and saves what is typed, trimmed', async () => {
		chat.renameSession.mockResolvedValue({ uuid: 's-1', title: 'New' })
		const w = mountIt(SessionRenameModal, { show: false, session })
		await w.setProps({ show: true })
		const field = w.findComponent({ name: 'NcTextField' })

		expect(field.vm.$attrs.modelValue).toBe('Old')
		field.vm.pick('  New  ')
		await flushPromises()
		await click(w, 'Save')

		expect(chat.renameSession).toHaveBeenCalledWith('s-1', 'New')
		expect(w.emitted('saved')).toEqual([[{ uuid: 's-1', title: 'New' }]])
		expect(w.emitted('close')).toHaveLength(1)
	})

	it('disables save for a blank title, and closes from cancel and the modal', async () => {
		const w = mountIt(SessionRenameModal, { show: true, session })
		w.findComponent({ name: 'NcTextField' }).vm.pick('   ')
		await flushPromises()

		expect(button(w, 'Save').vm.$attrs.disabled).toBe(true)
		await click(w, 'Cancel')
		w.findComponent({ name: 'NcModal' }).vm.$emit('close')
		expect(w.emitted('close')).toHaveLength(2)
	})

	it('shows the refusal in a note card', async () => {
		chat.renameSession.mockRejectedValue({ response: { data: { message: 'Not yours' } } })
		const w = mountIt(SessionRenameModal, { show: true, session })
		w.findComponent({ name: 'NcTextField' }).vm.pick('New')
		await flushPromises()
		await click(w, 'Save')

		expect(w.findComponent({ name: 'NcNoteCard' }).text()).toBe('Not yours')
		expect(w.emitted('close')).toBeUndefined()
	})
})

describe('SessionDeleteModal controls', () => {
	it('names the session, or "New session" for an untitled one', () => {
		expect(mountIt(SessionDeleteModal, { show: true, session: { uuid: 's', title: 'Budget' } }).find('.session-delete__name').text()).toBe('Budget')
		expect(mountIt(SessionDeleteModal, { show: true, session: { uuid: 's' } }).find('.session-delete__name').text()).toBe('New session')
		expect(mountIt(SessionDeleteModal, { show: true }).find('.session-delete__name').exists()).toBe(false)
	})

	it('deletes from the button and reports it; cancel and the modal close', async () => {
		chat.deleteSessionPermanent.mockResolvedValue(undefined)
		const session = { uuid: 's-2', title: 'Budget' }
		const w = mountIt(SessionDeleteModal, { show: true, session })

		await click(w, 'Delete permanently')
		await click(w, 'Cancel')
		w.findComponent({ name: 'NcModal' }).vm.$emit('close')

		expect(chat.deleteSessionPermanent).toHaveBeenCalledWith('s-2')
		expect(w.emitted('deleted')).toEqual([[session]])
		expect(w.emitted('close')).toHaveLength(3)
	})

	it('shows a refused delete, and clears it when reopened', async () => {
		chat.deleteSessionPermanent.mockRejectedValue(new Error('Locked'))
		const w = mountIt(SessionDeleteModal, { show: true, session: { uuid: 's-3' } })

		await click(w, 'Delete permanently')
		expect(w.findComponent({ name: 'NcNoteCard' }).text()).toBe('Locked')

		await w.setProps({ show: false })
		await w.setProps({ show: true })
		expect(w.findComponent({ name: 'NcNoteCard' }).exists()).toBe(false)
	})
})

describe('ApprovalDenyModal controls', () => {
	it('denies with the reason typed in the area', async () => {
		approvals.denyApproval.mockResolvedValue(undefined)
		const w = mountIt(ApprovalDenyModal, { show: true, approval: { id: 'a-1', scheduleName: 'Nightly' } })

		expect(w.find('.approval-deny__subtitle').text()).toBe('Nightly')
		w.findComponent({ name: 'NcTextArea' }).vm.pick('Too costly')
		await flushPromises()
		await click(w, 'Deny')

		expect(approvals.denyApproval).toHaveBeenCalledWith('a-1', 'Too costly')
		expect(w.emitted('denied')).toEqual([['a-1']])
	})

	it('reopening clears the reason, and cancel and the modal close', async () => {
		const w = mountIt(ApprovalDenyModal, { show: true, approval: { id: 'a-1', prompt: 'Summarise' } })
		w.findComponent({ name: 'NcTextArea' }).vm.pick('draft')
		await w.setProps({ show: false })
		await w.setProps({ show: true })

		expect(w.findComponent({ name: 'NcTextArea' }).vm.$attrs.modelValue).toBe('')
		expect(w.find('.approval-deny__subtitle').text()).toBe('Summarise')
		await click(w, 'Cancel')
		w.findComponent({ name: 'NcModal' }).vm.$emit('close')
		expect(w.emitted('close')).toHaveLength(2)
	})
})

describe('ApprovalPreviewModal rendering', () => {
	it('lists each tool with its effect, reach and arguments', () => {
		const approval = {
			prompt: 'Clean up',
			preview: {
				kind: 'toolcall',
				tools: [
					{ id: 't1', name: 'Delete file', effect: 'deletes', reach: 'user', arguments: { path: '/a' } },
					{ id: 't2', name: 'Ping', effect: 'pings', reach: 'moon' },
				],
			},
		}
		const w = mountIt(ApprovalPreviewModal, { approval })

		expect(w.find('.approval-preview__held').text()).toBe('This tool needs a reviewer before the agent may call it.')
		expect(w.find('.approval-preview__prompt').text()).toBe('Prompt: Clean up')
		expect(w.findAll('.approval-preview__effect').map((e) => e.text())).toEqual(['Deletes', 'pings'])
		expect(w.findAll('.approval-preview__effect')[0].classes()).toContain('approval-preview__effect--deletes')
		expect(w.findAll('.approval-preview__reach').map((e) => e.text())).toEqual(['Your own files and data', 'Reach unknown'])
		expect(JSON.parse(w.find('.approval-preview__arguments').text())).toEqual({ path: '/a' })
		expect(w.findAll('.approval-preview__arguments')).toHaveLength(1)
	})

	it('says when no tools are named, for a held run', () => {
		const w = mountIt(ApprovalPreviewModal, { approval: { preview: { tools: 'none' } } })

		expect(w.find('.approval-preview__held').text()).toBe('This run needs a reviewer before it starts.')
		expect(w.find('.approval-preview__none').exists()).toBe(true)
		expect(w.find('.approval-preview__prompt').exists()).toBe(false)
	})

	it('approve, deny and dismiss each emit their event', async () => {
		const w = mountIt(ApprovalPreviewModal, { approval: { preview: { tools: [] } } })

		await click(w, 'Approve')
		await click(w, 'Deny')
		w.findComponent({ name: 'NcDialog' }).vm.$emit('update:open', false)

		expect(w.emitted('approve')).toHaveLength(1)
		expect(w.emitted('deny')).toHaveLength(1)
		expect(w.emitted('close')).toHaveLength(1)
	})
})

describe('AppTemplateCollectModal controls', () => {
	it('checks the apps from its button and shows the summary', async () => {
		templates.collectAppTemplates.mockResolvedValue({ imported: 0, updated: 0, unchanged: 3, refused: 1 })
		const w = mountIt(AppTemplateCollectModal)

		const run = w.findAllComponents({ name: 'NcButton' }).find((b) => b.vm.$attrs.variant === 'primary')
		run.vm.$emit('click')
		await flushPromises()

		expect(templates.collectAppTemplates).toHaveBeenCalledTimes(1)
		expect(w.findComponent({ name: 'NcNoteCard' }).text()).toBe('0 new, 0 updated, 3 unchanged, 1 refused.')
		expect(w.emitted('imported')).toBeUndefined()
	})

	it('close and the modal both close it', async () => {
		const w = mountIt(AppTemplateCollectModal)

		await click(w, 'Close')
		w.findComponent({ name: 'NcModal' }).vm.$emit('close')

		expect(w.emitted('close')).toHaveLength(2)
	})
})
