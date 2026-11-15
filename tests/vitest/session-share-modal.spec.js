/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * SessionShareModal: loads the shares when it opens, shares with the chosen
 * user or group, takes a share back, says why the server refused, and copies
 * the link to the session's read-only page.
 *
 * @spec openspec/changes/chat-fork-and-share-link/specs/session-sharing/spec.md#requirement-the-owner-shares-a-session-with-named-users-or-groups-req-sshare-001
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const api = vi.hoisted(() => ({
	listShares: vi.fn(),
	addShare: vi.fn(),
	removeShare: vi.fn(),
	searchSharees: vi.fn(),
}))

vi.mock('../../src/api/chat.js', () => api)

const { default: SessionShareModal } = await import('../../src/modals/SessionShareModal.vue')

const t = (app, text, vars = {}) => text.replace(/{(\w+)}/g, (m, key) => (key in vars ? String(vars[key]) : m))
const session = { uuid: 's-1', userId: 'anne', title: 'Offerte dakrenovatie Stadskantoor' }
const group = { type: 'group', id: 'team-inkoop', displayName: 'Team inkoop', sharedBy: 'anne', sharedAt: '2026-09-12T10:04:00+02:00' }

/**
 * Mount the dialog closed and open it.
 *
 * @return {Promise<object>} The wrapper.
 */
async function opened() {
	const wrapper = mount(SessionShareModal, { props: { show: false, session }, global: { mocks: { t } } })
	await wrapper.setProps({ show: true })
	await flushPromises()
	return wrapper
}

describe('SessionShareModal', () => {
	beforeEach(() => {
		for (const fn of Object.values(api)) {
			fn.mockReset()
		}
	})

	it('lists the shares, a group with its now-and-later line', async () => {
		api.listShares.mockResolvedValue([group])
		const wrapper = await opened()

		expect(api.listShares).toHaveBeenCalledWith('s-1')
		const rows = wrapper.findAll('[data-testid="session-share-row"]')
		expect(rows).toHaveLength(1)
		expect(rows[0].text()).toContain('Team inkoop')
		expect(rows[0].text()).toContain('Everyone in this group, now and later')
	})

	it('shares with the chosen option and shows the new list', async () => {
		api.listShares.mockResolvedValue([])
		api.addShare.mockResolvedValue([group])
		const wrapper = await opened()

		wrapper.vm.selected = { type: 'group', id: 'team-inkoop', displayName: 'Team inkoop', key: 'group:team-inkoop' }
		await wrapper.vm.add()

		expect(api.addShare).toHaveBeenCalledWith('s-1', 'group', 'team-inkoop')
		expect(wrapper.emitted('changed')[0][0].map((s) => s.key)).toEqual(['group:team-inkoop'])
		expect(wrapper.vm.selected).toBeNull()
	})

	it('takes a share back', async () => {
		api.listShares.mockResolvedValue([group])
		api.removeShare.mockResolvedValue([])
		const wrapper = await opened()

		await wrapper.vm.remove(wrapper.vm.shares[0])

		expect(api.removeShare).toHaveBeenCalledWith('s-1', 'group', 'team-inkoop')
		expect(wrapper.vm.shares).toEqual([])
	})

	it('says why the server refused, and falls back for anything else', async () => {
		api.listShares.mockResolvedValue([])
		const wrapper = await opened()

		api.addShare.mockRejectedValueOnce({ response: { status: 403, data: { error: 'You can only share with members of your groups.' } } })
		wrapper.vm.selected = { type: 'user', id: 'dirk', key: 'user:dirk' }
		await wrapper.vm.add()
		expect(wrapper.vm.error).toBe('You can only share with members of your groups.')

		api.addShare.mockRejectedValueOnce({ response: { status: 500, data: { error: 'database gone' } } })
		wrapper.vm.selected = { type: 'user', id: 'dirk', key: 'user:dirk' }
		await wrapper.vm.add()
		expect(wrapper.vm.error).toBe('The change could not be saved.')
	})

	it('searches without offering what is shared already or the owner', async () => {
		api.listShares.mockResolvedValue([group])
		api.searchSharees.mockResolvedValue([
			{ type: 'group', id: 'team-inkoop', displayName: 'Team inkoop' },
			{ type: 'user', id: 'anne', displayName: 'Anne de Vries' },
			{ type: 'user', id: 'bram', displayName: 'Bram Jansen' },
		])
		const wrapper = await opened()

		await wrapper.vm.search('a')
		expect(api.searchSharees).not.toHaveBeenCalled()
		await wrapper.vm.search('br')
		expect(wrapper.vm.options.map((o) => o.key)).toEqual(['user:bram'])
	})

	it('copies the link to the read-only page', async () => {
		api.listShares.mockResolvedValue([group])
		const writeText = vi.fn().mockResolvedValue(undefined)
		Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true })
		const wrapper = await opened()

		await wrapper.vm.copyLink()

		expect(writeText).toHaveBeenCalledWith(`${window.location.origin}/apps/hermiq/chat/shared/s-1`)
	})
})
