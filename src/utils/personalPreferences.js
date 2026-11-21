/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Pure helpers for a person's own agent preferences (personal-preferences):
 * the answer languages the settings dialog offers, how a switch is stored,
 * and which agent a new chat starts with.
 *
 * @spec openspec/specs/personal-preferences/spec.md#requirement-the-voorkeuren-section-offers-the-drawn-preferences-req-pref-002
 */

/**
 * The languages agents can answer in. `value` is what is stored and handed to
 * the agent; `label` is the English source of the option's text.
 */
export const ANSWER_LANGUAGES = Object.freeze([
	Object.freeze({ value: 'nl', label: 'Dutch' }),
	Object.freeze({ value: 'nl-B1', label: 'Dutch, B1' }),
	Object.freeze({ value: 'en', label: 'English' }),
	Object.freeze({ value: 'de', label: 'German' }),
	Object.freeze({ value: 'fr', label: 'French' }),
])

/** The stored values that switch a preference on, as the server reads them. */
const ON_VALUES = ['1', 'true', 'on', 'yes']

/**
 * Whether a stored switch value is on.
 *
 * @param {string} value The stored value.
 * @return {boolean} On or off.
 * @spec openspec/specs/personal-preferences/spec.md#requirement-the-voorkeuren-section-offers-the-drawn-preferences-req-pref-002
 */
export function switchIsOn(value) {
	return ON_VALUES.includes(String(value ?? '').trim().toLowerCase())
}

/**
 * The stored value of a switch: `'1'` when on, `''` (cleared) when off.
 *
 * @param {boolean} on The switch.
 * @return {string} The value to store.
 * @spec openspec/specs/personal-preferences/spec.md#requirement-the-voorkeuren-section-offers-the-drawn-preferences-req-pref-002
 */
export function switchValue(on) {
	return on ? '1' : ''
}

/**
 * The id of an agent object as the chat and the agent store use it.
 *
 * @param {object} agent The agent.
 * @return {string} Its id, or `''`.
 * @spec openspec/specs/personal-preferences/spec.md#requirement-the-voorkeuren-section-offers-the-drawn-preferences-req-pref-002
 */
export function agentIdOf(agent) {
	if (!agent || typeof agent !== 'object') {
		return ''
	}
	return String(agent.uuid || agent.id || agent['@self']?.id || '')
}

/**
 * The agent a new chat starts with: the person's default agent when it is
 * still among the agents they can pick, else none (the picker shows).
 *
 * @param {Array<object>} agents The agents the person can pick.
 * @param {string} defaultAgentId The stored default agent, or `''`.
 * @return {object|null} The agent, or null.
 * @spec openspec/specs/personal-preferences/spec.md#requirement-the-voorkeuren-section-offers-the-drawn-preferences-req-pref-002
 */
export function defaultAgentOf(agents, defaultAgentId) {
	if (!defaultAgentId || !Array.isArray(agents)) {
		return null
	}
	return agents.find((agent) => {
		if (!agent || typeof agent !== 'object') {
			return false
		}
		return [agent.uuid, agent.id, agent['@self']?.id].some((id) => id && String(id) === String(defaultAgentId))
	}) || null
}
