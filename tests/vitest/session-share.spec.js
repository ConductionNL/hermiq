/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Read-only session shares: the dialog is offered to the owner only, the
 * copied link names the session's read-only page, the search offers users
 * and groups not yet shared with, and the read-only page shows the thread
 * with "Shared by", or "This session is not available" when the server
 * refuses.
 *
 * @spec openspec/changes/chat-fork-and-share-link/specs/session-sharing/spec.md#requirement-a-share-recipient-can-read-and-do-nothing-else-req-sshare-002
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import {
	canShareSession,
	openShareOptions,
	sharedByParts,
	sharedSessionLink,
	sharedSessionPath,
	sharedSourceName,
} from '../../src/utils/sessionShare.js'

const api = vi.hoisted(() => ({
	getSession: vi.fn(),
	listMessages: vi.fn(),
}))

vi.mock('@conduction/nextcloud-vue', () => ({
	SAFE_MARKDOWN_DOMPURIFY_CONFIG: {},
}))

vi.mock('../../src/api/chat.js', async (importOriginal) => ({
	...(await importOriginal()),
	getSession: api.getSession,
	listMessages: api.listMessages,
}))

const { shareeOptions } = await import('../../src/api/chat.js')
const { default: SharedSession } = await import('../../src/views/SharedSession.vue')

const t = (app, text, vars = {}) => text.replace(/{(\w+)}/g, (m, key) => (key in vars ? String(vars[key]) : m))

describe('canShareSession', () => {
	it('offers the dialog to the owner of an open session only', () => {
		expect(canShareSession({ uuid: 's-1', role: 'owner' })).toBe(true)
		expect(canShareSession({ uuid: 's-1', role: 'participant' })).toBe(false)
		expect(canShareSession({ uuid: 's-1', role: 'recipient' })).toBe(false)
		expect(canShareSession({ uuid: 's-1', role: 'owner', deletedAt: '2026-11-01T10:00:00+00:00' })).toBe(false)
		expect(canShareSession(null)).toBe(false)
	})
})

describe('the copied link', () => {
	it('names the read-only page of the session, under the web root', () => {
		expect(sharedSessionPath('a b')).toBe('/apps/hermiq/chat/shared/a%20b')
		expect(sharedSessionLink('https://cloud.example', '', 's-1')).toBe('https://cloud.example/apps/hermiq/chat/shared/s-1')
		expect(sharedSessionLink('https://cloud.example', '/nextcloud/', 's-1')).toBe('https://cloud.example/nextcloud/apps/hermiq/chat/shared/s-1')
	})
})

describe('the share search', () => {
	it('turns users and groups into options and drops everything else', () => {
		expect(shareeOptions([
			{ id: 'bram', label: 'Bram Jansen', source: 'users' },
			{ id: 'team-inkoop', label: 'Team inkoop', source: 'groups' },
			{ id: 'x@remote', label: 'Remote', source: 'remotes' },
			{ id: '', source: 'users' },
		])).toEqual([
			{ type: 'user', id: 'bram', displayName: 'Bram Jansen' },
			{ type: 'group', id: 'team-inkoop', displayName: 'Team inkoop' },
		])
	})

	it('leaves out the owner and what is shared already, by type and id', () => {
		const options = [
			{ type: 'user', id: 'anne' },
			{ type: 'user', id: 'bram' },
			{ type: 'group', id: 'bram' },
			{ type: 'group', id: 'team-inkoop' },
		]
		const shares = [{ type: 'user', id: 'bram' }]
		expect(openShareOptions(options, shares, 'anne')).toEqual([
			{ type: 'group', id: 'bram' },
			{ type: 'group', id: 'team-inkoop' },
		])
	})
})

describe('sharedByParts', () => {
	it('names who shared and when, and copes without a date or a share', () => {
		const parts = sharedByParts({ sharedBy: 'anne', sharedByDisplayName: 'Anne de Vries', sharedAt: '2026-09-12T10:04:00+02:00' }, 'en')
		expect(parts.name).toBe('Anne de Vries')
		expect(parts.date).toContain('12')
		expect(parts.date).toContain('September')
		expect(sharedByParts({ sharedBy: 'anne', sharedAt: 'not a date' })).toEqual({ name: 'anne', date: '' })
		expect(sharedByParts(null)).toBeNull()
		expect(sharedSourceName({ title: 'Offerte.pdf' })).toBe('Offerte.pdf')
		expect(sharedSourceName({})).toBe('')
	})
})

describe('SharedSession', () => {
	beforeEach(() => {
		api.getSession.mockReset()
		api.listMessages.mockReset()
	})

	/**
	 * Mount the page for one session id.
	 *
	 * @return {object} The wrapper.
	 */
	function page() {
		return mount(SharedSession, {
			global: { mocks: { t, $route: { params: { id: 's-1' } } } },
		})
	}

	it('shows the thread, the sources and who shared it, without a message box', async () => {
		api.getSession.mockResolvedValue({
			uuid: 's-1',
			title: 'Offerte dakrenovatie Stadskantoor',
			role: 'recipient',
			share: { sharedBy: 'anne', sharedByDisplayName: 'Anne de Vries', sharedAt: '2026-09-12T10:04:00+02:00' },
		})
		api.listMessages.mockResolvedValue({
			results: [
				{ uuid: 't-1', role: 'user', content: 'Past dit binnen budget?', authorDisplayName: 'Anne de Vries' },
				{ uuid: 't-2', role: 'assistant', content: 'Ja, **ruim**.', sources: [{ title: 'Offerte.pdf' }] },
			],
		})

		const wrapper = page()
		await flushPromises()

		expect(api.getSession).toHaveBeenCalledWith('s-1')
		expect(api.listMessages).toHaveBeenCalledWith('s-1')
		expect(wrapper.text()).toContain('Offerte dakrenovatie Stadskantoor')
		expect(wrapper.find('[data-testid="shared-session-by"]').text()).toContain('Shared by Anne de Vries on')
		expect(wrapper.findAll('.shared-session__turn')).toHaveLength(2)
		expect(wrapper.html()).toContain('<strong>ruim</strong>')
		expect(wrapper.text()).toContain('Offerte.pdf')
		expect(wrapper.find('textarea').exists()).toBe(false)
		expect(wrapper.find('nc-emptycontent').exists()).toBe(false)
	})

	it('shows "not available" and no title when the server refuses', async () => {
		api.getSession.mockRejectedValue(Object.assign(new Error('gone'), { response: { status: 404 } }))
		api.listMessages.mockRejectedValue(Object.assign(new Error('gone'), { response: { status: 404 } }))

		const wrapper = page()
		await flushPromises()

		// The stand-in renders NcEmptyContent as <nc-emptycontent data-name="...">.
		const empty = wrapper.find('nc-emptycontent')
		expect(empty.exists()).toBe(true)
		expect(empty.attributes('data-name')).toBe('This session is not available')
		expect(wrapper.findAll('.shared-session__turn')).toHaveLength(0)
		expect(wrapper.text()).not.toContain('Offerte')
	})
})
