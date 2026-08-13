// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Bounded, fail-closed agent-context builder (JS mirror of the PHP
 * `AgentContextBuilder`).
 *
 * The agent render leaf's chat forwards ONLY the fields a schema's
 * `x-openregister-agent-context` allowlist names, so the chat surface never
 * leaks an unlisted (confidential) property to the agent. The rule is
 * fail-closed and identical to the server-side builder:
 *   - allowlist absent or empty        → EMPTY context (never the whole object);
 *   - a listed property missing on the instance → omitted;
 *   - a property never listed          → never forwarded.
 *
 * Accepted allowlist shapes on `x-openregister-agent-context`:
 *   - a list of names: `["title", "status", "description"]`
 *   - an associative map of caps: `{"description": {"maxLength": 500}}`
 *   - a list of `{property, maxLength}` entries.
 *
 * @module utils/agentContext
 * @spec openspec/changes/hermiq-agent-leaf/specs/agent-object-leaf/spec.md#requirement-declarative-bounded-agent-context-allowlist
 */

export const AGENT_CONTEXT_KEYWORD = 'x-openregister-agent-context'

/**
 * Normalise the raw allowlist spec into a `{ name: caps }` map. Anything
 * unexpected yields an empty allowlist (fail-closed).
 *
 * @param {*} spec The raw `x-openregister-agent-context` value.
 * @return {{[key: string]: object}} Property name → caps map.
 */
function normaliseAllowlist(spec) {
	const allowlist = {}
	if (spec === null || typeof spec !== 'object') {
		return allowlist
	}

	if (Array.isArray(spec)) {
		for (const entry of spec) {
			if (typeof entry === 'string' && entry !== '') {
				allowlist[entry] = {}
			} else if (
				entry
				&& typeof entry === 'object'
				&& typeof entry.property === 'string'
				&& entry.property !== ''
			) {
				allowlist[entry.property] = entry
			}
		}
		return allowlist
	}

	for (const [key, caps] of Object.entries(spec)) {
		if (key !== '') {
			allowlist[key] = caps && typeof caps === 'object' ? caps : {}
		}
	}
	return allowlist
}

/**
 * Apply per-field caps (currently `maxLength`, code-point-safe) to a value.
 *
 * @param {*} value The property value.
 * @param {object} caps The per-field caps.
 * @return {*} The capped value.
 */
function applyCaps(value, caps) {
	const maxLength = caps && caps.maxLength
	if (
		typeof maxLength !== 'number'
		|| maxLength <= 0
		|| typeof value !== 'string'
	) {
		return value
	}
	// Array.from() splits on code points, so a multi-byte character is never cut.
	const chars = Array.from(value)
	if (chars.length <= maxLength) {
		return value
	}
	return chars.slice(0, maxLength).join('') + '…'
}

/**
 * Build the bounded context from an object's data and its schema definition.
 *
 * @param {object} objectData The object's data (its properties).
 * @param {object} schema The target schema definition (as returned by OpenRegister);
 *   the `x-openregister-agent-context` allowlist is read from it (top-level or
 *   under a `configuration` bag).
 * @return {object} The bounded context — only allowlisted, present, capped fields.
 *   Empty when the allowlist is absent or empty.
 */
export function buildAgentContext(objectData, schema) {
	const data = objectData && typeof objectData === 'object' ? objectData : {}
	const schemaObj = schema && typeof schema === 'object' ? schema : {}
	const rawSpec =
		schemaObj[AGENT_CONTEXT_KEYWORD]
		?? (schemaObj.configuration
			&& schemaObj.configuration[AGENT_CONTEXT_KEYWORD])
		?? null

	const allowlist = normaliseAllowlist(rawSpec)
	const context = {}
	for (const [name, caps] of Object.entries(allowlist)) {
		if (!Object.prototype.hasOwnProperty.call(data, name)) {
			continue
		}
		const value = data[name]
		if (value === null || value === '' || value === undefined) {
			continue
		}
		context[name] = applyCaps(value, caps)
	}
	return context
}
