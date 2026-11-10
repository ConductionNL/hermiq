/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * What the two eval widgets (EvalRunPanelWidget, SkillEvalEvidence) show the
 * same way: pass rates, deltas and dates, the agent picker and the selected
 * agent's baseline mode, the paired-run cost note, and a load error.
 */

import { t } from '@nextcloud/l10n'

/**
 * Human pass-rate percentage (the stored value is a 0..1 fraction).
 *
 * @param {number} passRate The 0..1 pass rate.
 * @return {string} A percentage label.
 * @spec openspec/specs/agent-evals/spec.md#requirement-the-evaldataset-detail-surface-manages-skill-links-and-paired-runs
 */
export function passRateLabel(passRate) {
	if (typeof passRate !== 'number') {
		return '—'
	}
	return `${Math.round(passRate * 100)}%`
}

/**
 * A signed delta label (sign + value, never color-only).
 *
 * @param {number} delta The baseline delta (-1..1).
 * @return {string} The label, e.g. "+30 pp" / "−10 pp" / "±0 pp".
 * @spec openspec/specs/agent-evals/spec.md#requirement-the-evaldataset-detail-surface-manages-skill-links-and-paired-runs
 */
export function deltaLabel(delta) {
	if (typeof delta !== 'number') {
		return '—'
	}
	const points = Math.round(delta * 100)
	if (points > 0) {
		return `+${points} pp`
	}
	if (points < 0) {
		return `−${Math.abs(points)} pp`
	}
	return '±0 pp'
}

/**
 * Format an ISO timestamp for display.
 *
 * @spec exclude pure date-formatting display helper, no domain behaviour
 * @param {string} value The ISO timestamp.
 * @return {string} The formatted date, or an em dash.
 */
export function formatDate(value) {
	if (!value) {
		return '—'
	}
	const date = new Date(value)
	return Number.isNaN(date.getTime()) ? value : date.toLocaleString()
}

/**
 * The caller's agents as picker options.
 *
 * @param {Array<object>} agents The agents.
 * @return {Array<object>} The { label, value } options.
 * @spec openspec/specs/agent-evals/spec.md#requirement-the-evaldataset-detail-surface-manages-skill-links-and-paired-runs
 */
export function agentPickerOptions(agents) {
	return agents.map((agent) => ({
		label: agent.name || agent.uuid || agent.id,
		value: agent.uuid || agent.id,
	}))
}

/**
 * The selected agent's evalBaselineMode ('joint' unless explicitly 'per-skill').
 *
 * @param {Array<object>} agents The agents.
 * @param {string|undefined} selectedValue The selected agent's uuid or id.
 * @return {string} joint|per-skill.
 * @spec openspec/specs/agent-evals/spec.md#requirement-every-half-of-a-paired-run-counts-toward-the-same-budgets-and-gates
 */
export function agentBaselineMode(agents, selectedValue) {
	const agent = agents.find((entry) => (entry.uuid || entry.id) === selectedValue)
	return agent?.evalBaselineMode === 'per-skill' ? 'per-skill' : 'joint'
}

/**
 * The mode-dependent cost note for a paired run (~2x joint, (N+1)x per-skill).
 *
 * @param {string} mode joint|per-skill.
 * @param {number} times How often every case runs in per-skill mode.
 * @return {string} The translated cost note.
 * @spec openspec/specs/agent-evals/spec.md#requirement-every-half-of-a-paired-run-counts-toward-the-same-budgets-and-gates
 */
export function baselineCostNote(mode, times) {
	if (mode === 'per-skill') {
		return t(
			'hermiq',
			'Per-skill baseline: every case runs {times} times (one without-half per linked skill) — about {times}x the token cost of a normal run, counted against the same budgets.',
			{ times },
		)
	}
	return t(
		'hermiq',
		'Joint baseline: every case runs twice (all linked skills detached together) — about 2x the token cost of a normal run, counted against the same budgets. Link one skill per dataset for the cleanest attribution.',
	)
}

/**
 * The message of a failed load: the server's error, the exception's message,
 * or the fallback.
 *
 * @param {object} error What was thrown.
 * @param {string} fallback The translated fallback.
 * @return {string} The message.
 * @spec exclude pure error-text pick for the eval widgets' load, no domain behaviour
 */
export function loadErrorMessage(error, fallback) {
	return error?.response?.data?.error || error?.message || fallback
}

/**
 * A list as given, or [] when it is not one.
 *
 * @param {unknown} value What a store answered.
 * @return {Array} The list.
 * @spec exclude pure shape guard for the eval widgets' load, no domain behaviour
 */
export function asList(value) {
	return Array.isArray(value) ? value : []
}

/**
 * A dataset's ten latest runs, newest first.
 *
 * @param {Array<object>} runs Every run the caller can see.
 * @param {string} datasetId The dataset.
 * @return {Array<object>} The dataset's latest runs.
 * @spec openspec/specs/agent-evals/spec.md#requirement-the-evaldataset-detail-surface-manages-skill-links-and-paired-runs
 */
export function latestDatasetRuns(runs, datasetId) {
	return runs
		.filter((runRow) => runRow.datasetId === datasetId)
		.sort((a, b) =>
			String(b.startedAt || '').localeCompare(
				String(a.startedAt || ''),
			),
		)
		.slice(0, 10)
}
