/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * What the eval schedule form, the run panel and the agent factsheet show of
 * eval schedules and bias checks (reuse-scheduled-bias-evals): the frequency
 * choices, a schedule's one-line summary and the factsheet's bias check line.
 */

import { t } from '@nextcloud/l10n'

/**
 * The cron expressions the schedule form offers; monthly is the default.
 *
 * @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-dataset-can-run-against-an-agent-on-a-schedule-req-biaseval-003
 */
export const SCHEDULE_FREQUENCIES = [
	{ value: '0 6 1 * *', key: 'monthly' },
	{ value: '0 6 1 */3 *', key: 'quarterly' },
	{ value: '0 6 * * 1', key: 'weekly' },
]

/**
 * A frequency's label.
 *
 * @param {string} key monthly, quarterly or weekly.
 * @return {string} The translated label.
 * @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-dataset-can-run-against-an-agent-on-a-schedule-req-biaseval-003
 */
export function frequencyLabel(key) {
	if (key === 'weekly') {
		return t('hermiq', 'Every Monday')
	}
	if (key === 'quarterly') {
		return t('hermiq', 'Every three months')
	}
	return t('hermiq', 'Every month')
}

/**
 * The frequency options for a select, each { value, label }.
 *
 * @return {Array<object>} The options.
 * @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-dataset-can-run-against-an-agent-on-a-schedule-req-biaseval-003
 */
export function frequencyOptions() {
	return SCHEDULE_FREQUENCIES.map((frequency) => ({
		value: frequency.value,
		label: frequencyLabel(frequency.key),
	}))
}

/**
 * The label for a cron expression: the frequency it is, or the expression itself.
 *
 * @param {string} cronExpr The expression.
 * @return {string} The label.
 * @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-dataset-can-run-against-an-agent-on-a-schedule-req-biaseval-003
 */
export function cronLabel(cronExpr) {
	const frequency = SCHEDULE_FREQUENCIES.find((entry) => entry.value === cronExpr)
	return frequency ? frequencyLabel(frequency.key) : String(cronExpr || '')
}

/**
 * A date as YYYY-MM-DD, or an em dash when absent or unreadable.
 *
 * @param {string} value An ISO-8601 timestamp.
 * @return {string} The day.
 */
function day(value) {
	const date = value ? new Date(value) : null
	if (!date || Number.isNaN(date.getTime())) {
		return '—'
	}
	return date.toISOString().slice(0, 10)
}

/**
 * One line for a schedule: how often, for which agent, and when it runs next.
 *
 * @param {object} schedule The schedule.
 * @param {Array<object>} agents The caller's agents, for the name.
 * @return {string} The summary.
 * @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-dataset-can-run-against-an-agent-on-a-schedule-req-biaseval-003
 */
export function scheduleSummary(schedule, agents = []) {
	const agent = agents.find((entry) => (entry.uuid || entry.id) === schedule.agentId)
	const vars = {
		frequency: cronLabel(schedule.cronExpr),
		agent: (agent && agent.name) || schedule.agentId,
		next: day(schedule.nextRun),
	}
	if (schedule.enabled === false) {
		return t('hermiq', '{frequency} for {agent}, switched off', vars)
	}
	return t('hermiq', '{frequency} for {agent}, next run {next}', vars)
}

/**
 * The factsheet's bias check line: the latest pass rate and date, or that none ran.
 *
 * @param {object|null} biasCheck The factsheet's biasCheck: { runId, passRate, date } or null.
 * @return {string} The line.
 * @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-the-latest-bias-check-is-visible-as-evidence-req-biaseval-004
 */
export function biasCheckLabel(biasCheck) {
	if (!biasCheck || typeof biasCheck.passRate !== 'number') {
		return t('hermiq', 'No bias check run')
	}
	return t('hermiq', 'Bias check: {rate}% on {date}', {
		rate: Math.round(biasCheck.passRate * 100),
		date: day(biasCheck.date),
	})
}
