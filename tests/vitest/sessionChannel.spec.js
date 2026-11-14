/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The doors between a session and its Talk room: the room's URL for "Open in
 * Talk", and which turns read "via Talk".
 *
 * @spec openspec/changes/chat-carry-a-conversation-across-channels/tasks.md#task-4-the-doors-on-the-chat-page-and-in-the-room
 */

import { describe, expect, it } from 'vitest'
import { isTypedInTalk, talkRoomUrl } from '../../src/services/sessionChannel.js'

describe('session channel', () => {
	it('points a session with a room at that room', () => {
		expect(talkRoomUrl({ talkRoomToken: ' abc123 ' })).toBe('/index.php/call/abc123')
	})

	it('has no room for a session without one', () => {
		expect(talkRoomUrl({})).toBe('')
		expect(talkRoomUrl({ talkRoomToken: '  ' })).toBe('')
		expect(talkRoomUrl({ talkRoomToken: 42 })).toBe('')
		expect(talkRoomUrl(null)).toBe('')
	})

	it('reads "via Talk" only under a person\'s turn typed in Talk', () => {
		expect(isTypedInTalk({ role: 'user', channel: 'talk' })).toBe(true)
		expect(isTypedInTalk({ role: 'user', channel: 'hermiq' })).toBe(false)
		expect(isTypedInTalk({ role: 'user' })).toBe(false)
		expect(isTypedInTalk({ role: 'assistant', channel: 'talk' })).toBe(false)
		expect(isTypedInTalk(undefined)).toBe(false)
	})
})
