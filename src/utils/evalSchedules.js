// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// "Run on a schedule" (reuse-scheduled-bias-evals, REQ-BIASEVAL-003): the
// frequencies the schedule form offers, and how a stored schedule reads.
// A frequency is a cron expression in UTC; anything the form does not offer
// reads as a custom schedule and is shown as its expression.

/** The frequencies the form offers, as cron expressions in UTC. */
export const FREQUENCY_CRONS = {
	monthly: '0 6 1 * *',
	weekly: '0 6 * * 1',
	daily: '0 6 * * *',
}

/**
 * The frequency a cron expression is, or 'custom'.
 *
 * @param {string} cronExpr The stored cron expression; empty means monthly.
 * @return {string} monthly, weekly, daily or custom.
 * @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-dataset-can-run-against-an-agent-on-a-schedule-req-biaseval-003
 */
export function frequencyOf(cronExpr) {
	const expression = String(cronExpr || '').trim() || FREQUENCY_CRONS.monthly
	const found = Object.keys(FREQUENCY_CRONS).find((key) => FREQUENCY_CRONS[key] === expression)
	return found || 'custom'
}

/**
 * How a schedule's frequency reads.
 *
 * @param {function(string, string, object=): string} t The translate function.
 * @param {string} cronExpr The stored cron expression.
 * @return {string} The label.
 * @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-dataset-can-run-against-an-agent-on-a-schedule-req-biaseval-003
 */
export function frequencyLabel(t, cronExpr) {
	const labels = {
		monthly: t('hermiq', 'Monthly, on the 1st at 06:00 UTC'),
		weekly: t('hermiq', 'Weekly, on Monday at 06:00 UTC'),
		daily: t('hermiq', 'Daily at 06:00 UTC'),
	}
	return labels[frequencyOf(cronExpr)] || t('hermiq', 'Custom: {cron}', { cron: cronExpr })
}

/**
 * The request body for saving a schedule from the form's values.
 *
 * @param {object} values { agentId, frequency, enabled, threshold }.
 * @param {string} [currentCron] The schedule's own expression, kept when the frequency is custom.
 * @return {object} { agentId, cronExpr, enabled, regressionThresholdPercent }.
 * @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-dataset-can-run-against-an-agent-on-a-schedule-req-biaseval-003
 */
export function scheduleBody(values, currentCron = '') {
	const threshold = values.threshold === '' || values.threshold === null || values.threshold === undefined ? null : Number(values.threshold)
	return {
		agentId: values.agentId,
		cronExpr: FREQUENCY_CRONS[values.frequency] || currentCron || FREQUENCY_CRONS.monthly,
		enabled: values.enabled !== false,
		regressionThresholdPercent: threshold,
	}
}
