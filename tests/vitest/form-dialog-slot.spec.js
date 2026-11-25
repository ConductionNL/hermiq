/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The `#form-dialog` slot contract the form modals share: a save re-reads the
 * host list through the slot's `refresh` binding, because a dialog mounted from
 * the manifest gets props only and its `saved` event reaches no listener.
 *
 * @spec openspec/specs/public-chat-channel/spec.md#requirement-an-organisation-admin-declares-a-public-chat-channel-req-pubchat-001
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { h } from 'vue'
import { formDialogSlot } from '../../src/modals/formDialogSlot.js'

const Dialog = {
	mixins: [formDialogSlot],
	emits: ['close', 'saved'],
	render() {
		return h('div')
	},
}

describe('form dialog slot', () => {
	it('re-reads the host list after a save and still emits saved', () => {
		const refresh = vi.fn()
		const wrapper = mount(Dialog, { props: { refresh } })

		wrapper.vm.announceSaved({ id: 'c-1' })

		expect(refresh).toHaveBeenCalledTimes(1)
		expect(wrapper.emitted('saved')).toEqual([[{ id: 'c-1' }]])
	})

	it('saves without a refresh binding, as when mounted outside an index page', () => {
		const wrapper = mount(Dialog)

		expect(() => wrapper.vm.announceSaved({ id: 'c-2' })).not.toThrow()
		expect(wrapper.emitted('saved')).toEqual([[{ id: 'c-2' }]])
	})

	it('closing does not re-read the list', () => {
		const refresh = vi.fn()
		const close = vi.fn()
		const wrapper = mount(Dialog, { props: { refresh, close } })

		wrapper.vm.handleClose()

		expect(close).toHaveBeenCalledTimes(1)
		expect(refresh).not.toHaveBeenCalled()
		expect(wrapper.emitted('close')).toHaveLength(1)
	})
})
