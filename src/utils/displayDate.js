/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * How the run, compliance and operations pages show a stored timestamp.
 */

/**
 * Human-readable date, or an em-dash when absent/unparseable.
 *
 * @param {string} value An ISO-8601 timestamp.
 * @return {string} The localised date, or '—'.
 * @spec exclude pure date-formatting display helper, no domain behaviour
 */
export function dateOrDash(value) {
	if (!value) {
		return '—'
	}
	const date = new Date(value)
	return Number.isNaN(date.getTime()) ? '—' : date.toLocaleString()
}

/**
 * Human-readable date, an em-dash when absent, or the value itself as text when
 * it does not parse as a date.
 *
 * @param {string} value An ISO-8601 timestamp.
 * @return {string} The localised date, '—', or the value.
 * @spec exclude pure date-formatting display helper, no domain behaviour
 */
export function dateOrText(value) {
	const shown = dateOrDash(value)
	return shown === '—' && value ? String(value) : shown
}
