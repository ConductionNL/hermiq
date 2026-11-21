/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The labels a run trace shows for its steps, shared by the run history and
 * run operations widgets.
 */

import { t } from '@nextcloud/l10n'

/**
 * Human label for a trace step's type.
 *
 * @param {string} type The step type (gate_wait|context|history|llm|tool|delivery).
 * @return {string} The localised label.
 * @spec openspec/specs/manifest-driven-pages/spec.md#requirement-a-run-history-custom-widget-must-show-run-history-with-per-row-trace-expand-re-run-and-replay
 */
export function stepTypeLabel(type) {
	const labels = {
		gate_wait: t('hermiq', 'Awaiting approval'),
		context: t('hermiq', 'Context'),
		history: t('hermiq', 'History'),
		llm: t('hermiq', 'LLM'),
		tool: t('hermiq', 'Tool'),
		delivery: t('hermiq', 'Delivery'),
	}
	return labels[type] || type || '—'
}
