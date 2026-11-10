/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * How the chat page groups its session list: by who started a session.
 */

import { t } from '@nextcloud/l10n'

/**
 * Split sessions into those the person started, those shared with them, and
 * those started automatically.
 *
 * @param {Array<object>} sessions The visible sessions.
 * @param {function(object): boolean} isAutomated Whether a session was started automatically.
 * @return {{human: Array<object>, shared: Array<object>, automated: Array<object>}} The parts.
 * @spec openspec/specs/session-surface/spec.md#requirement-human-and-automated-sessions-must-be-listed-separately
 */
export function partitionSessions(sessions, isAutomated) {
	const parts = { human: [], shared: [], automated: [] }
	for (const session of sessions) {
		if (session.role === 'participant') {
			parts.shared.push(session)
			continue
		}
		;(isAutomated(session) ? parts.automated : parts.human).push(session)
	}
	return parts
}

/**
 * The non-empty parts as headed groups, in the order human, shared, automated.
 * One group needs no heading: the tab label already says what it is.
 *
 * @param {{human: Array<object>, shared: Array<object>, automated: Array<object>}} parts The parts.
 * @return {Array<object>} The { key, heading, sessions } groups.
 * @spec openspec/specs/session-surface/spec.md#requirement-human-and-automated-sessions-must-be-listed-separately
 */
export function headedSessionGroups(parts) {
	const headings = {
		human: t('hermiq', 'Started by you'),
		shared: t('hermiq', 'Shared with me'),
		automated: t('hermiq', 'Started automatically'),
	}
	const groups = ['human', 'shared', 'automated']
		.filter((key) => parts[key].length > 0)
		.map((key) => ({ key, heading: headings[key], sessions: parts[key] }))
	if (groups.length === 1) {
		groups[0].heading = ''
	}
	return groups
}
