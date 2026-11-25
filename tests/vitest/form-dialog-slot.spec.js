/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The `#form-dialog` slot contract the form modals share: a save re-reads the
 * host list through the slot's `refresh` binding, because a dialog mounted from
 * the manifest gets props only and its `saved` event reaches no listener.
 *
 * @spec openspec/changes/delivery-public-web-chat/specs/public-chat-channel/spec.md#requirement-an-organisation-admin-declares-a-public-chat-channel-req-pubchat-001
 */

import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'
import { emit } from '@nextcloud/event-bus'
import { formDialogSlot, PAGE_REFRESH_CHANNEL } from '../../src/modals/formDialogSlot.js'

vi.mock('@nextcloud/event-bus', () => ({ emit: vi.fn() }))

const Dialog = {
	mixins: [formDialogSlot],
	emits: ['close', 'saved'],
	render() {
		return h('div')
	},
}

describe('form dialog slot', () => {
	beforeEach(() => {
		emit.mockClear()
	})

	it('re-reads the host list after a save and still emits saved', () => {
		const refresh = vi.fn()
		const wrapper = mount(Dialog, { props: { refresh } })

		wrapper.vm.announceSaved({ id: 'c-1' })

		expect(refresh).toHaveBeenCalledTimes(1)
		expect(wrapper.emitted('saved')).toEqual([[{ id: 'c-1' }]])
		// The list is re-read directly; no second read through the page bus.
		expect(emit).not.toHaveBeenCalled()
	})

	it('saves without a refresh binding, as when mounted outside an index page', () => {
		const wrapper = mount(Dialog)

		expect(() => wrapper.vm.announceSaved({ id: 'c-2' })).not.toThrow()
		expect(wrapper.emitted('saved')).toEqual([[{ id: 'c-2' }]])
	})

	// @spec openspec/changes/models-several-models-per-turn/specs/model-ensemble/spec.md#requirement-an-agent-owner-can-switch-on-ensemble-answers-req-mens-001
	it('without a refresh binding, announces the write so a detail page re-reads its record', () => {
		const wrapper = mount(Dialog)

		wrapper.vm.announceSaved({ id: 'a-1' })

		expect(emit).toHaveBeenCalledTimes(1)
		expect(emit).toHaveBeenCalledWith(PAGE_REFRESH_CHANNEL, {})
		expect(PAGE_REFRESH_CHANNEL).toBe('cn:page:refresh')
	})

	it('closing does not re-read the list', () => {
		const refresh = vi.fn()
		const close = vi.fn()
		const wrapper = mount(Dialog, { props: { refresh, close } })

		wrapper.vm.handleClose()

		expect(close).toHaveBeenCalledTimes(1)
		expect(refresh).not.toHaveBeenCalled()
		expect(emit).not.toHaveBeenCalled()
		expect(wrapper.emitted('close')).toHaveLength(1)
	})
})
