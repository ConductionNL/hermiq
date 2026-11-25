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
import { formDialogSlot, PAGE_REFRESH_CHANNEL } from '../../src/modals/formDialogSlot.js'

const busEmit = vi.hoisted(() => vi.fn())
vi.mock('@nextcloud/event-bus', () => ({ emit: busEmit }))

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
		// The list re-reads through its own binding; the page bus stays quiet.
		expect(busEmit).not.toHaveBeenCalled()
	})

	it('asks the page to re-read when opened from a header action, without a refresh binding', () => {
		busEmit.mockClear()
		const wrapper = mount(Dialog)

		expect(() => wrapper.vm.announceSaved({ id: 'c-2' })).not.toThrow()
		expect(wrapper.emitted('saved')).toEqual([[{ id: 'c-2' }]])
		expect(busEmit).toHaveBeenCalledTimes(1)
		expect(busEmit).toHaveBeenCalledWith(PAGE_REFRESH_CHANNEL, {})
		expect(PAGE_REFRESH_CHANNEL).toBe('cn:page:refresh')
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
