/**
 * The record summary on the agent leaf (agents-bound-to-their-app, task 5):
 * when the section and its button show, how the AI label reads its date, and
 * which reason a refusal gives.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/agents-bound-to-their-app/specs/agent-object-leaf/spec.md#requirement-a-record-page-can-show-an-ai-written-summary-of-the-record-req-appag-004
 */

/**
 * Whether the summary section shows at all: the feature is on and there is
 * either a stored summary or an agent that can write one.
 *
 * @param {object|null} status The answer of GET /api/assistant/summary, null when the record is unreadable.
 * @return {boolean}
 * @spec openspec/changes/agents-bound-to-their-app/specs/agent-object-leaf/spec.md#requirement-a-record-page-can-show-an-ai-written-summary-of-the-record-req-appag-004
 */
export function showsSummarySection(status) {
	if (!status || status.enabled !== true) {
		return false
	}
	return Boolean(status.agent) || Boolean(status.summary)
}

/**
 * Whether "Write a summary" is offered: an agent answers for the app and no
 * summary of the record as it is now is stored.
 *
 * @param {object|null} status The answer of GET /api/assistant/summary.
 * @return {boolean}
 * @spec openspec/changes/agents-bound-to-their-app/specs/agent-object-leaf/spec.md#requirement-a-record-page-can-show-an-ai-written-summary-of-the-record-req-appag-004
 */
export function offersSummaryButton(status) {
	return showsSummarySection(status) && Boolean(status.agent) && !status.summary
}

/**
 * The day a summary was written, for the AI label.
 *
 * @param {string} iso The ISO 8601 time the summary was written.
 * @param {string|undefined} locale The reader's locale.
 * @return {string} The date, or '' when there is none.
 * @spec openspec/changes/agents-bound-to-their-app/specs/agent-object-leaf/spec.md#requirement-a-record-page-can-show-an-ai-written-summary-of-the-record-req-appag-004
 */
export function summaryDate(iso, locale) {
	if (!iso) {
		return ''
	}
	const date = new Date(iso)
	if (Number.isNaN(date.getTime())) {
		return ''
	}
	return date.toLocaleDateString(locale, {
		day: 'numeric',
		month: 'long',
		year: 'numeric',
		timeZone: 'UTC',
	})
}

/**
 * The reason behind a refused summary, by HTTP status.
 *
 * @param {number} status The HTTP status.
 * @return {string} not-found, switched-off, no-agent, blocked or failed.
 * @spec openspec/changes/agents-bound-to-their-app/specs/agent-object-leaf/spec.md#scenario-no-summary-for-a-record-the-user-cannot-read
 */
export function summaryRefusal(status) {
	const reasons = {
		404: 'not-found',
		403: 'switched-off',
		409: 'no-agent',
		422: 'blocked',
	}
	return reasons[status] || 'failed'
}
