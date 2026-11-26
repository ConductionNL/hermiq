// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The waiting line in the chat (flows-hand-off-and-wait task 7): each handoff of a
// session shown under the message that started it, as "Waiting for 'X' (started
// 14:02)" while it runs and as how it ended once it closes. A handoff records when
// it was requested but not which message asked for it, so it is placed under the
// last message written at or before that moment.
//
// @spec openspec/changes/archive/2026-11-25-flows-hand-off-and-wait/specs/agent-handoffs/spec.md#requirement-work-an-agent-starts-and-waits-for-is-recorded-as-a-handoff-req-handoff-001

/** The statuses a handoff ends in; only `waiting` is not terminal. */
export const HANDOFF_STATUSES = ['waiting', 'done', 'failed', 'expired']

/**
 * A time as milliseconds, or NaN.
 *
 * @param {string|undefined} value An ISO date-time.
 * @return {number} The time.
 */
function timeOf(value) {
	return typeof value === 'string' ? Date.parse(value) : Number.NaN
}

/**
 * The handoffs of a session grouped by the id of the message each one belongs under.
 *
 * A handoff requested before the first message, or without a readable time, goes
 * under the last message, so it is never hidden.
 *
 * @param {Array<object>} handoffs The session's handoffs.
 * @param {Array<object>} messages The session's messages, oldest first.
 * @return {Object<string, Array<object>>} Message id → its handoffs, oldest first.
 * @spec openspec/changes/archive/2026-11-25-flows-hand-off-and-wait/specs/agent-handoffs/spec.md#requirement-work-an-agent-starts-and-waits-for-is-recorded-as-a-handoff-req-handoff-001
 */
export function handoffsByMessage(handoffs, messages) {
	const list = Array.isArray(messages) ? messages.filter((message) => message && message.id) : []
	const grouped = {}
	if (list.length === 0) {
		return grouped
	}
	const sorted = (Array.isArray(handoffs) ? handoffs : [])
		.filter((handoff) => handoff && HANDOFF_STATUSES.includes(handoff.status))
		.sort((a, b) => (timeOf(a.requestedAt) || 0) - (timeOf(b.requestedAt) || 0))
	for (const handoff of sorted) {
		const owner = ownerOf(handoff, list)
		grouped[owner.id] = [...(grouped[owner.id] || []), handoff]
	}
	return grouped
}

/**
 * The message a handoff belongs under: the last one created at or before it was
 * requested, else the last message.
 *
 * @param {object} handoff The handoff.
 * @param {Array<object>} list The messages with an id, oldest first; not empty.
 * @return {object} The owning message.
 * @spec openspec/changes/archive/2026-11-25-flows-hand-off-and-wait/specs/agent-handoffs/spec.md#requirement-work-an-agent-starts-and-waits-for-is-recorded-as-a-handoff-req-handoff-001
 */
function ownerOf(handoff, list) {
	const at = timeOf(handoff.requestedAt)
	const before = Number.isNaN(at) ? [] : list.filter((message) => timeOf(message.created) <= at)
	return before.length > 0 ? before[before.length - 1] : list[list.length - 1]
}

/**
 * The line a handoff shows.
 *
 * @param {object} handoff The handoff.
 * @param {Function} t The translate function, called as t(app, text, vars).
 * @param {Function} clock Formats a date-time as the time of day the person reads.
 * @return {string} The line.
 * @spec openspec/changes/archive/2026-11-25-flows-hand-off-and-wait/specs/agent-handoffs/spec.md#requirement-work-an-agent-starts-and-waits-for-is-recorded-as-a-handoff-req-handoff-001
 */
export function handoffLine(handoff, t, clock) {
	const label = String(handoff?.label || '')
	switch (handoff?.status) {
	case 'done':
		return t('hermiq', '“{label}” has finished; its result is in the conversation.', { label })
	case 'failed':
		return t('hermiq', '“{label}” could not be carried out.', { label })
	case 'expired':
		return t('hermiq', '“{label}” did not answer before its deadline.', { label })
	default:
		return t('hermiq', 'Waiting for “{label}” (started {time})', { label, time: clock(handoff?.requestedAt) })
	}
}

/**
 * A date-time as the hour and minute the person reads, or '' when unreadable.
 *
 * @param {string|undefined} value An ISO date-time.
 * @return {string} For example 14:02.
 * @spec openspec/changes/archive/2026-11-25-flows-hand-off-and-wait/specs/agent-handoffs/spec.md#requirement-work-an-agent-starts-and-waits-for-is-recorded-as-a-handoff-req-handoff-001
 */
export function clockTime(value) {
	const at = timeOf(value)
	if (Number.isNaN(at)) {
		return ''
	}
	return new Date(at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
}
