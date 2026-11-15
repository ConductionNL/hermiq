/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * "Fork from here": who is offered it, and which session opens afterwards.
 *
 * @spec openspec/changes/chat-fork-and-share-link/specs/session-fork/spec.md#requirement-the-owner-can-fork-a-session-at-an-answer-req-sfork-001
 */

/**
 * Whether the person may fork the open session: only its owner, and only
 * while it is not archived. A participant reads and writes, but does not fork.
 *
 * @param {object|null} session The open session.
 * @return {boolean} True to offer "Fork from here".
 * @spec openspec/changes/chat-fork-and-share-link/specs/session-fork/spec.md#requirement-the-owner-can-fork-a-session-at-an-answer-req-sfork-001
 */
export function canForkSession(session) {
	if (!session || !session.uuid) {
		return false
	}
	return session.role !== 'participant' && !session.deletedAt
}

/**
 * The session to open after a fork: the listed entry for the new uuid, else
 * one built from the fork response so it opens even before the list catches up.
 *
 * @param {Array<object>} sessions The listed sessions.
 * @param {object} fork The fork response.
 * @return {object} The session to open.
 * @spec openspec/changes/chat-fork-and-share-link/specs/session-fork/spec.md#requirement-the-owner-can-fork-a-session-at-an-answer-req-sfork-001
 */
export function forkedSessionToOpen(sessions, fork) {
	const listed = (sessions || []).find((session) => session.uuid === fork.uuid)
	if (listed) {
		return listed
	}
	return {
		uuid: fork.uuid,
		title: fork.title,
		agentId: fork.agentId,
		forkedFrom: fork.forkedFrom,
		role: 'owner',
	}
}
