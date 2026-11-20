// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Cell formatters for the Schedules page (scheduling-overview-and-start-choice
// D1): a schedule's last result with the error it gave, its next run as a date
// and time, and whether it is on. Registered on CnAppRoot beside the agent
// catalog's formatters.

/**
 * The Schedules page's cell formatters.
 *
 * @param {Function} t The app's translate function, `t('hermiq', text)`.
 * @return {object} Formatter id to function.
 * @spec openspec/changes/scheduling-overview-and-start-choice/specs/agent-schedule/spec.md#requirement-every-schedule-a-person-may-see-is-on-one-page-req-sched-001
 */
export function scheduleListFormatters(t) {
	return {
		scheduleLastResult: (value, row) => {
			if (value === null || value === undefined || value === '') {
				return t('hermiq', 'Not run yet')
			}
			if (row && row.failed === true) {
				return row.lastError
					? t('hermiq', 'Failed: {error}', { error: row.lastError })
					: t('hermiq', 'Failed')
			}
			if (value === 'ok') {
				return t('hermiq', 'Succeeded')
			}
			return String(value)
		},
		scheduleNextRun: (value) => {
			const date = value ? new Date(value) : null
			if (date === null || Number.isNaN(date.getTime())) {
				return '—'
			}
			return date.toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' })
		},
		scheduleEnabled: (value) =>
			value === false ? t('hermiq', 'Paused') : t('hermiq', 'On'),
	}
}
