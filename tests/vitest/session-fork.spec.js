/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * "Fork from here": offered to the owner only, and the new session is opened.
 *
 * @spec openspec/changes/chat-fork-and-share-link/specs/session-fork/spec.md#requirement-the-owner-can-fork-a-session-at-an-answer-req-sfork-001
 */

import { describe, expect, it } from 'vitest'
import { canForkSession, forkedSessionToOpen } from '../../src/utils/sessionFork.js'

describe('canForkSession', () => {
	it('offers the fork to the owner of an open session', () => {
		expect(canForkSession({ uuid: 's-1', role: 'owner' })).toBe(true)
	})

	it('does not offer it to a participant, on an archived session, or without a session', () => {
		expect(canForkSession({ uuid: 's-1', role: 'participant' })).toBe(false)
		expect(canForkSession({ uuid: 's-1', role: 'owner', deletedAt: '2026-11-01T10:00:00+00:00' })).toBe(false)
		expect(canForkSession({ role: 'owner' })).toBe(false)
		expect(canForkSession(null)).toBe(false)
	})
})

describe('forkedSessionToOpen', () => {
	const fork = { uuid: 's-2', title: 'Omgevingsvisie 2040 (fork)', agentId: 'agent-1', forkedFrom: { sessionId: 's-1' } }

	it('opens the listed entry when the list already has it', () => {
		const listed = { uuid: 's-2', title: 'Omgevingsvisie 2040 (fork)', role: 'owner', participants: [] }
		expect(forkedSessionToOpen([{ uuid: 's-1' }, listed], fork)).toBe(listed)
	})

	it('builds the entry from the response otherwise', () => {
		expect(forkedSessionToOpen(undefined, fork)).toEqual({
			uuid: 's-2',
			title: 'Omgevingsvisie 2040 (fork)',
			agentId: 'agent-1',
			forkedFrom: { sessionId: 's-1' },
			role: 'owner',
		})
	})
})
