/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * What the API helpers answer when the server leaves a field out: an empty
 * package, an empty participant list, a group named by its id, a Talk room
 * named as asked, and a stream opened without a request token.
 */

import axios from '@nextcloud/axios'
import { afterEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/auth', () => ({ getRequestToken: () => null }))

const { exportAgentToTemplate } = await import('../../src/api/agentTemplates.js')
const chat = await import('../../src/api/chat.js')
const { createRoom } = await import('../../src/api/talk.js')

const original = { ...axios }

afterEach(() => {
	Object.assign(axios, original)
	vi.unstubAllGlobals()
})

describe('API fallbacks', () => {
	it('answers an empty package when the export carries none', async () => {
		axios.get = vi.fn(async () => ({ data: {} }))

		expect(await exportAgentToTemplate('a1')).toBe('')
		expect(axios.get).toHaveBeenCalledWith('/index.php/apps/hermiq/api/agent-templates/from-agent/a1/export')

		axios.get = vi.fn(async () => ({ data: null }))
		expect(await exportAgentToTemplate('a1')).toBe('')
	})

	it('answers no participants when adding or removing returns no list', async () => {
		axios.post = vi.fn(async () => ({ data: {} }))
		axios.delete = vi.fn(async () => ({ data: {} }))

		expect(await chat.addParticipant('s1', 'ann')).toEqual([])
		expect(axios.post).toHaveBeenCalledWith('/index.php/apps/hermiq/api/sessions/s1/participants', { uid: 'ann' })
		expect(await chat.removeParticipant('s1', 'a b/c')).toEqual([])
		expect(axios.delete).toHaveBeenCalledWith('/index.php/apps/hermiq/api/sessions/s1/participants/a%20b%2Fc')
	})

	it('names a group by its id when it has no label, and answers none without data', async () => {
		axios.get = vi.fn(async () => ({ data: { ocs: { data: [{ id: 'admin', label: 'Admins' }, { id: 'staff', label: '' }] } } }))

		expect(await chat.searchGroups('a')).toEqual([
			{ gid: 'admin', displayName: 'Admins' },
			{ gid: 'staff', displayName: 'staff' },
		])
		expect(axios.get).toHaveBeenCalledWith(expect.stringMatching(/core\/autocomplete\/get$/), {
			params: { search: 'a', itemType: '', itemId: '', shareTypes: [1], limit: 10 },
		})

		axios.get = vi.fn(async () => ({ data: {} }))
		expect(await chat.searchGroups('a')).toEqual([])
	})

	it('names a Talk room as asked when the answer has no display name or no room', async () => {
		axios.post = vi.fn(async () => ({ data: { ocs: { data: { token: 'tk1' } } } }))

		expect(await createRoom('Intake')).toEqual({ token: 'tk1', name: 'Intake' })
		expect(axios.post).toHaveBeenCalledWith(expect.stringMatching(/apps\/spreed\/api\/v4\/room$/), { roomType: 2, roomName: 'Intake' })

		axios.post = vi.fn(async () => ({ data: null }))
		expect(await createRoom('Intake')).toEqual({ token: undefined, name: 'Intake' })
	})

	it('opens the stream with an empty request token when there is none', async () => {
		const calls = []
		vi.stubGlobal('fetch', async (url, init) => {
			calls.push([url, init])
			return { ok: false, status: 401, body: null }
		})

		const error = await chat.streamChatMessage({ message: 'Q' }).catch((e) => e)

		expect(error.message).toBe('Stream endpoint returned HTTP 401')
		expect(calls).toHaveLength(1)
		expect(calls[0][1].headers).toEqual({
			'Content-Type': 'application/json',
			Accept: 'text/event-stream',
			requesttoken: '',
		})
	})
})
