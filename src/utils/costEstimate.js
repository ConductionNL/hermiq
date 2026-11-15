/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The pre-run cost estimate line (cost-guardrails) the schedule form and the
 * run operations widget show: a trailing average, clearly labelled an
 * estimate, and never a figure without run history behind it.
 */

import { t } from '@nextcloud/l10n'

/**
 * The text of the estimate line.
 *
 * @param {object} estimate The estimate endpoint's answer.
 * @return {string} The trailing average, or the "not enough history" note.
 * @spec openspec/specs/run-analytics/spec.md#requirement-pre-run-cost-estimate-derived-from-trailing-per-agent-run-history
 */
export function estimateLine(estimate) {
	if (estimate.available) {
		return t(
			'hermiq',
			'Estimate: ~{tokens} tokens per run (average of the last {count} runs)',
			{
				tokens: estimate.avgTotalTokens,
				count: estimate.sampleSize,
			},
		)
	}
	return t('hermiq', 'Not enough run history yet for a cost estimate.')
}
