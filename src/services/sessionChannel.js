/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The doors between a session on the Chat page and its Talk room
 * (chat-carry-a-conversation-across-channels REQ-SCHAN-003, REQ-SCHAN-004).
 *
 * @spec openspec/changes/chat-carry-a-conversation-across-channels/tasks.md#task-4-the-doors-on-the-chat-page-and-in-the-room
 */
import { generateUrl } from '@nextcloud/router'

/**
 * The session's Talk room, or '' for a session without one.
 *
 * @param {object} session The session row.
 * @return {string}
 * @spec openspec/changes/chat-carry-a-conversation-across-channels/tasks.md#task-4-the-doors-on-the-chat-page-and-in-the-room
 */
export function talkRoomUrl(session) {
	const token = typeof session?.talkRoomToken === 'string' ? session.talkRoomToken.trim() : ''
	if (token === '') {
		return ''
	}
	return generateUrl('/call/' + encodeURIComponent(token))
}

/**
 * Whether a person's turn was typed in Talk.
 *
 * @param {object} message The turn.
 * @return {boolean}
 * @spec openspec/changes/chat-carry-a-conversation-across-channels/tasks.md#task-4-the-doors-on-the-chat-page-and-in-the-room
 */
export function isTypedInTalk(message) {
	return message?.role === 'user' && message?.channel === 'talk'
}
