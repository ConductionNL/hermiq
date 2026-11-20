/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The chat's waiting line (flows-hand-off-and-wait task 7): where each handoff is
 * shown and what it says while waiting and once it closed.
 *
 * @spec openspec/changes/flows-hand-off-and-wait/specs/agent-handoffs/spec.md#requirement-work-an-agent-starts-and-waits-for-is-recorded-as-a-handoff-req-handoff-001
 */

import { describe, expect, it } from 'vitest'
import { clockTime, handoffLine, handoffsByMessage } from '../../src/utils/handoffLines.js'

const t = (app, text, vars = {}) => text.replace(/{(\w+)}/g, (m, k) => (k in vars ? String(vars[k]) : m))

const messages = [
	{ id: 'm1', role: 'user', created: '2026-11-14T14:01:00Z' },
	{ id: 'm2', role: 'assistant', created: '2026-11-14T14:02:30Z' },
	{ id: 'm3', role: 'user', created: '2026-11-14T15:00:00Z' },
]

describe('handoffsByMessage', () => {
	it('puts each handoff under the last message written at or before it was requested', () => {
		const early = { id: 'h1', status: 'waiting', requestedAt: '2026-11-14T14:02:00Z' }
		const later = { id: 'h2', status: 'done', requestedAt: '2026-11-14T14:30:00Z' }
		const exact = { id: 'h3', status: 'failed', requestedAt: '2026-11-14T15:00:00Z' }

		expect(handoffsByMessage([later, exact, early], messages)).toEqual({
			m1: [early],
			m2: [later],
			m3: [exact],
		})
	})

	it('shows a handoff with no readable time or from before the thread under the last message', () => {
		const undated = { id: 'h1', status: 'expired' }
		const before = { id: 'h2', status: 'waiting', requestedAt: '2026-11-13T09:00:00Z' }

		expect(handoffsByMessage([undated, before], messages)).toEqual({ m3: [undated, before] })
	})

	it('leaves out unknown statuses, and has nowhere to put anything without messages', () => {
		expect(handoffsByMessage([{ status: 'running', requestedAt: '2026-11-14T14:02:00Z' }], messages)).toEqual({})
		expect(handoffsByMessage([{ status: 'waiting' }], [])).toEqual({})
		expect(handoffsByMessage(null, messages)).toEqual({})
	})
})

describe('handoffLine', () => {
	const clock = () => '14:02'

	it('says what a handoff is waiting for and when it started', () => {
		expect(handoffLine({ status: 'waiting', label: 'Vergunningcheck', requestedAt: 'x' }, t, clock)).toBe(
			'Waiting for “Vergunningcheck” (started 14:02)',
		)
	})

	it('says how a closed handoff ended', () => {
		expect(handoffLine({ status: 'done', label: 'Vergunningcheck' }, t, clock)).toBe(
			'“Vergunningcheck” has finished; its result is in the conversation.',
		)
		expect(handoffLine({ status: 'failed', label: 'KvK' }, t, clock)).toBe('“KvK” could not be carried out.')
		expect(handoffLine({ status: 'expired', label: 'KvK' }, t, clock)).toBe('“KvK” did not answer before its deadline.')
	})
})

describe('clockTime', () => {
	it('gives the hour and minute, or nothing for an unreadable time', () => {
		expect(clockTime('2026-11-14T14:02:00Z')).toMatch(/\d{2}.\d{2}/)
		expect(clockTime('soon')).toBe('')
		expect(clockTime(undefined)).toBe('')
	})
})
