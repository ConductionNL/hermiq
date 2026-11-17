/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The validated tag block on the admin page (memory-retrieval-scope D2): what it
 * reads, what creating and saving send, and the message a refused call shows.
 *
 * @spec openspec/changes/memory-retrieval-scope/specs/retrieval-scope/spec.md#requirement-validated-documents-carry-a-restricted-nextcloud-tag-req-rscope-002
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../../src/api/validatedTag.js', () => ({
	getValidatedTag: vi.fn(),
	createValidatedTag: vi.fn(),
	updateValidatedTag: vi.fn(),
}))
vi.mock('../../src/api/chat.js', () => ({ searchGroups: vi.fn() }))

const api = await import('../../src/api/validatedTag.js')
const chat = await import('../../src/api/chat.js')
const { default: ValidatedTagSettings } = await import('../../src/components/settings/ValidatedTagSettings.vue')

/**
 * Mount the block with a t() that fills placeholders.
 *
 * @return {Promise<object>} The wrapper, after the load settled.
 */
async function mountBlock() {
	const wrapper = mount(ValidatedTagSettings, {
		global: {
			mocks: {
				t: (app, text, vars = {}) => text.replace(/{(\w+)}/g, (m, k) => (k in vars ? vars[k] : m)),
			},
		},
	})
	await flushPromises()
	return wrapper
}

describe('ValidatedTagSettings', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('shows that no tag is set and offers to create one', async () => {
		api.getValidatedTag.mockResolvedValue({ tagId: null, name: null, groups: [] })

		const wrapper = await mountBlock()

		expect(api.getValidatedTag).toHaveBeenCalledTimes(1)
		expect(wrapper.find('[data-testid="validated-tag-current"]').text()).toContain('No validated tag is set.')
		expect(wrapper.vm.newName).toBe('Validated')
		expect(wrapper.vm.loading).toBe(false)
	})

	it('creates the tag with the chosen groups and then names it', async () => {
		api.getValidatedTag.mockResolvedValue({ tagId: null, name: null, groups: [] })
		api.createValidatedTag.mockResolvedValue({ tagId: '7', name: 'Gevalideerd', groups: ['informatiebeheer'] })
		const wrapper = await mountBlock()
		wrapper.vm.newName = '  Gevalideerd '
		wrapper.vm.groups = [{ label: 'Informatiebeheer', value: 'informatiebeheer' }]

		await wrapper.vm.create()
		await flushPromises()

		expect(api.createValidatedTag).toHaveBeenCalledWith('Gevalideerd', ['informatiebeheer'])
		expect(wrapper.vm.tagId).toBe('7')
		expect(wrapper.vm.groups).toEqual([{ label: 'informatiebeheer', value: 'informatiebeheer' }])
		expect(wrapper.find('[data-testid="validated-tag-current"]').text()).toBe(
			'Validated documents carry the tag “Gevalideerd”.',
		)
	})

	it('saves who may assign the named tag', async () => {
		api.getValidatedTag.mockResolvedValue({ tagId: '7', name: 'Validated', groups: ['a'] })
		api.updateValidatedTag.mockResolvedValue({ tagId: '7', name: 'Validated', groups: ['a', 'b'] })
		const wrapper = await mountBlock()
		wrapper.vm.groups = [...wrapper.vm.groups, { label: 'B', value: 'b' }]

		await wrapper.vm.save()

		expect(api.updateValidatedTag).toHaveBeenCalledWith('7', ['a', 'b'])
		expect(wrapper.vm.groups.map((option) => option.value)).toEqual(['a', 'b'])
		expect(wrapper.vm.busy).toBe(false)
	})

	it('shows the server’s message when a call is refused', async () => {
		api.getValidatedTag.mockResolvedValue({ tagId: '7', name: 'Validated', groups: [] })
		api.updateValidatedTag.mockRejectedValue({ response: { data: { error: 'Choose a tag.' } } })
		const wrapper = await mountBlock()

		await wrapper.vm.save()

		expect(wrapper.vm.error).toBe('Choose a tag.')
		expect(wrapper.vm.tagId).toBe('7')
	})

	it('offers the groups matching the typed text, and none when the search fails', async () => {
		api.getValidatedTag.mockResolvedValue({ tagId: null, name: null, groups: [] })
		chat.searchGroups.mockResolvedValueOnce([{ gid: 'ib', displayName: 'Informatiebeheer' }])
		const wrapper = await mountBlock()

		await wrapper.vm.onSearchGroups('info')
		expect(chat.searchGroups).toHaveBeenCalledWith('info')
		expect(wrapper.vm.groupOptions).toEqual([{ label: 'Informatiebeheer', value: 'ib' }])

		chat.searchGroups.mockRejectedValueOnce(new Error('down'))
		await wrapper.vm.onSearchGroups('x')
		expect(wrapper.vm.groupOptions).toEqual([])
	})
})
