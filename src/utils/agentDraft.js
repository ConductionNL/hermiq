// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Agent drafts from chat (agents-plain-language-builder): find the
// `hermiq-agent-draft` block in an assistant message, turn a checked draft
// into the agent form's fields and the schedule form's fields, and group the
// check's findings by field. Plain functions, so tests/agent-draft.spec.js runs
// them under node. The server checks the draft (AgentDraftService.php); these
// only shape the page.

const FENCE = /(?:```|~~~)\s*hermiq-agent-draft[^\n]*\n([\s\S]*?)\n?(?:```|~~~)/

/**
 * The text of the draft block in a message, or '' when there is none.
 *
 * @param {string} content The message text.
 * @return {string}
 */
export function draftBlockOf(content) {
	const match = FENCE.exec(String(content ?? ''))
	return match ? match[1].trim() : ''
}

/**
 * The agent form's sharing choice for a draft's sharing.
 *
 * @param {{mode: string, groups: string[]}} sharing The draft's sharing.
 * @return {{choice: string, groups: string[]}}
 */
export function sharingChoiceOf(sharing) {
	const groups = Array.isArray(sharing?.groups) ? sharing.groups : []
	if (sharing?.mode === 'organisation') {
		return { choice: 'organisation', groups: [] }
	}
	if (sharing?.mode === 'groups' && groups.length > 0) {
		return { choice: 'people-and-groups', groups }
	}
	return { choice: 'only-me', groups: [] }
}

/**
 * The agent the form opens with: the draft's fields as an agent object, private
 * unless the draft shares it, with no id, so saving creates a new agent.
 *
 * @param {object} draft The checked draft.
 * @return {object}
 */
export function agentFromDraft(draft) {
	const { choice, groups } = sharingChoiceOf(draft?.sharing)
	return {
		name: draft?.name || '',
		description: draft?.description || '',
		prompt: draft?.prompt || '',
		provider: draft?.provider || '',
		model: draft?.model || '',
		tools: Array.isArray(draft?.tools) ? [...draft.tools] : [],
		startFields: Array.isArray(draft?.startFields) ? [...draft.startFields] : [],
		isPrivate: choice !== 'organisation',
		invitedUsers: [],
		groups,
	}
}

/**
 * The schedule form's fields for a draft's schedule, or null when it has none.
 *
 * @param {object} draft The checked draft.
 * @return {object|null}
 */
export function scheduleFromDraft(draft) {
	const schedule = draft?.schedule
	if (!schedule || typeof schedule !== 'object') {
		return null
	}
	return {
		name: draft?.name || '',
		kind: ['once', 'interval', 'cron'].includes(schedule.kind)
			? schedule.kind
			: 'cron',
		cronExpr: schedule.cronExpr || '',
		intervalMinutes: schedule.intervalMinutes ?? null,
		runAt: schedule.runAt || '',
		prompt: schedule.prompt || '',
	}
}

/**
 * The findings by field, each field's messages joined.
 *
 * @param {Array<{field: string, message: string, suggestion: string}>} findings The check's findings.
 * @return {object} Field => [{message, suggestion}].
 */
export function findingsByField(findings) {
	const byField = {}
	for (const finding of Array.isArray(findings) ? findings : []) {
		const field = String(finding?.field || 'other')
		byField[field] = byField[field] || []
		byField[field].push({
			message: finding.message || '',
			suggestion: finding.suggestion || '',
		})
	}
	return byField
}
