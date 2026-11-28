/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The one-line description of a session's goal the chat page shows.
 */

import { t } from '@nextcloud/l10n'

/**
 * How an ended goal ended, or '' while it is still active.
 *
 * @param {object} goal The session goal.
 * @param {string} statement Its statement.
 * @return {string} The line, or ''.
 * @spec openspec/specs/session-surface/spec.md#requirement-the-application-must-use-one-word-for-a-session
 */
function endedGoalLine(goal, statement) {
	if (goal.status === 'stopped') {
		return t('hermiq', 'Goal stopped: {statement}', { statement })
	}
	if (goal.status === 'reached') {
		return t('hermiq', 'Goal reached: {statement}', { statement })
	}
	if (goal.status === 'exhausted') {
		return t('hermiq', 'Turn limit used: {statement}', { statement })
	}
	return ''
}

/**
 * An active goal: its statement, the turns used of the maximum, whether it is
 * blocked, and the last check's summary.
 *
 * @param {object} goal The session goal.
 * @param {string} statement Its statement.
 * @return {string} The line.
 * @spec openspec/specs/session-surface/spec.md#requirement-the-application-must-use-one-word-for-a-session
 */
function activeGoalLine(goal, statement) {
	const summary = goal.lastCheckResult?.summary
	return [
		t('hermiq', 'Goal: {statement}', { statement }),
		t('hermiq', 'turn {used} of {max}', {
			used: goal.turnsUsed ?? 0,
			max: goal.maxTurns ?? 10,
		}),
		goal.blocked ? t('hermiq', 'blocked') : '',
		summary ? t('hermiq', 'last check: {summary}', { summary }) : '',
	]
		.filter(Boolean)
		.join(', ')
}

/**
 * The goal line of a session, or '' without a goal.
 *
 * @param {object|null} goal The session goal.
 * @return {string} The line.
 * @spec openspec/specs/session-surface/spec.md#requirement-the-application-must-use-one-word-for-a-session
 */
export function goalLine(goal) {
	if (!goal) {
		return ''
	}
	const statement = goal.statement || ''
	return endedGoalLine(goal, statement) || activeGoalLine(goal, statement)
}
