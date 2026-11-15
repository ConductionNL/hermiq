// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Placeholders and start fields (agents-instruction-variables): which fields the
// chat page asks before a session's first message, when that message may go,
// what the session header shows, and how the agent form inserts a placeholder.
// Plain functions, so tests/agent-instruction-variables.spec.js runs them under node.
// The server repeats every check (StartFields.php); these only shape the page.

/** The field types: short text, long text, choice, number, date. */
export const FIELD_TYPES = ['text', 'paragraph', 'select', 'number', 'date']

/** At most this many start fields per agent. */
export const MAX_FIELDS = 10

/** A field key: lower case, starts with a letter, at most 32 characters. */
export const KEY_PATTERN = /^[a-z][a-z0-9_]{0,31}$/

/**
 * The placeholders the engine fills in, in menu order. `id` names the label the
 * agent form shows for each.
 */
export const PLACEHOLDERS = [
	{ id: 'displayName', token: '{{user.displayName}}' },
	{ id: 'userId', token: '{{user.id}}' },
	{ id: 'language', token: '{{user.language}}' },
	{ id: 'organisation', token: '{{organisation.name}}' },
	{ id: 'today', token: '{{today}}' },
	{ id: 'now', token: '{{now}}' },
	{ id: 'agentName', token: '{{agent.name}}' },
	{ id: 'appId', token: '{{app.id}}' },
]

/**
 * The agent's well-formed start fields, without duplicates, at most ten.
 *
 * @param {object|null} agent The agent's data.
 * @return {Array<{key: string, label: string, type: string, options: string[], required: boolean, default: string}>}
 * @spec openspec/specs/agent-management-ui/spec.md#requirement-an-agent-can-ask-for-fields-before-a-conversation-starts-req-agvar-002
 */
export function startFieldsOf(agent) {
	const fields = new Map()
	for (const entry of storedStartFields(agent)) {
		const field = startField(entry)
		if (field !== null && !fields.has(field.key)) {
			fields.set(field.key, field)
		}
		if (fields.size === MAX_FIELDS) {
			break
		}
	}
	return [...fields.values()]
}

/**
 * The agent's stored start field entries; none when it stores no list.
 *
 * @param {object|null|undefined} agent The agent's data.
 * @return {Array} The stored entries.
 * @spec openspec/specs/agent-management-ui/spec.md#requirement-an-agent-can-ask-for-fields-before-a-conversation-starts-req-agvar-002
 */
function storedStartFields(agent) {
	return Array.isArray(agent?.startFields) ? agent.startFields : []
}

/**
 * One start field in the shape the page uses, or null when its key or type is not valid.
 *
 * @param {unknown} entry One entry of the agent's `startFields`.
 * @return {{key: string, label: string, type: string, options: string[], required: boolean, default: string}|null}
 * @spec openspec/specs/agent-management-ui/spec.md#requirement-an-agent-can-ask-for-fields-before-a-conversation-starts-req-agvar-002
 */
function startField(entry) {
	const key = String(entry?.key ?? '')
	const type = String(entry?.type ?? 'text')
	if (!KEY_PATTERN.test(key) || !FIELD_TYPES.includes(type)) {
		return null
	}
	return {
		key,
		label: fieldLabel(entry?.label, key),
		type,
		options: fieldOptions(entry?.options),
		required: entry?.required === true,
		default: fieldDefault(entry?.default),
	}
}

/**
 * A field's label, trimmed; the key when no label is stored.
 *
 * @param {unknown} label The stored label.
 * @param {string} key The field's key.
 * @return {string} The label.
 * @spec openspec/specs/agent-management-ui/spec.md#requirement-an-agent-can-ask-for-fields-before-a-conversation-starts-req-agvar-002
 */
function fieldLabel(label, key) {
	return String(label ?? '').trim() || key
}

/**
 * A field's choices as trimmed, non-empty strings.
 *
 * @param {unknown} options The stored options.
 * @return {string[]} The choices; none when the stored value is not a list.
 * @spec openspec/specs/agent-management-ui/spec.md#requirement-an-agent-can-ask-for-fields-before-a-conversation-starts-req-agvar-002
 */
function fieldOptions(options) {
	return (Array.isArray(options) ? options : [])
		.map((option) => String(option).trim())
		.filter((option) => option !== '')
}

/**
 * A field's default as text; empty when none is stored.
 *
 * @param {unknown} value The stored default.
 * @return {string} The default.
 * @spec openspec/specs/agent-management-ui/spec.md#requirement-an-agent-can-ask-for-fields-before-a-conversation-starts-req-agvar-002
 */
function fieldDefault(value) {
	return value === null || value === undefined ? '' : String(value)
}

/**
 * The fields to ask now: only on a session without answers and without messages.
 *
 * @param {object|null} agent The session's agent.
 * @param {object|null} session The session.
 * @param {number} messageCount Messages already in the session.
 * @return {Array<object>}
 * @spec openspec/specs/agent-management-ui/spec.md#requirement-an-agent-can-ask-for-fields-before-a-conversation-starts-req-agvar-002
 */
export function pendingStartFields(agent, session, messageCount) {
	if (!session || messageCount > 0) {
		return []
	}
	const answered =
		session.startValues && Object.keys(session.startValues).length > 0
	if (answered) {
		return []
	}
	return startFieldsOf(agent)
}

/**
 * The keys of required fields that have no answer yet.
 *
 * @param {Array<object>} fields The fields.
 * @param {object} answers The answers per key.
 * @return {string[]}
 * @spec openspec/specs/agent-management-ui/spec.md#requirement-an-agent-can-ask-for-fields-before-a-conversation-starts-req-agvar-002
 */
export function missingRequired(fields, answers) {
	return fields
		.filter(
			(field) =>
				field.required && String(answers?.[field.key] ?? '').trim() === '',
		)
		.map((field) => field.key)
}

/**
 * The answers a new session starts with: each field's default.
 *
 * @param {Array<object>} fields The fields.
 * @return {object}
 * @spec openspec/specs/agent-management-ui/spec.md#requirement-an-agent-can-ask-for-fields-before-a-conversation-starts-req-agvar-002
 */
export function initialAnswers(fields) {
	return Object.fromEntries(fields.map((field) => [field.key, field.default]))
}

/**
 * "Label: answer" for each answered field, for the session header.
 *
 * @param {Array<object>} fields The agent's fields.
 * @param {object} values The session's answers.
 * @return {string[]}
 * @spec openspec/specs/agent-management-ui/spec.md#requirement-an-agent-can-ask-for-fields-before-a-conversation-starts-req-agvar-002
 */
export function startValueSummary(fields, values) {
	const labels = Object.fromEntries(
		fields.map((field) => [field.key, field.label]),
	)
	return Object.entries(values || {})
		.filter(([, value]) => String(value ?? '').trim() !== '')
		.map(([key, value]) => `${labels[key] || key}: ${value}`)
}

/**
 * Put a placeholder in at the cursor, replacing a selection.
 *
 * @param {string} text The instructions.
 * @param {string} token The placeholder.
 * @param {number} [start] Selection start (defaults to the end).
 * @param {number} [end] Selection end (defaults to start).
 * @return {{text: string, cursor: number}}
 * @spec openspec/specs/agent-management-ui/spec.md#requirement-an-agent-can-ask-for-fields-before-a-conversation-starts-req-agvar-002
 */
export function insertPlaceholder(text, token, start, end) {
	const value = String(text ?? '')
	const from = Number.isInteger(start) ? start : value.length
	const to = Number.isInteger(end) ? end : from
	return {
		text: value.slice(0, from) + token + value.slice(to),
		cursor: from + token.length,
	}
}
