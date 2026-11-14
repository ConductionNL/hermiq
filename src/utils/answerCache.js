// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The agent form's answer cache setting (models-prompt-and-answer-cache).
 *
 * Plain functions without Vue or Nextcloud imports, so a spec runs them under node.
 * The form edits the lifetime in days; the agent stores it in minutes.
 *
 * @spec openspec/changes/models-prompt-and-answer-cache/tasks.md#task-4
 */

/** Minutes in a day. */
const DAY = 1440

/** The longest lifetime the register accepts, in minutes (30 days). */
export const MAX_TTL_MINUTES = 43200

/** The lifetime when none is set, in minutes (one day). */
export const DEFAULT_TTL_MINUTES = 1440

/** The similarity choices the register accepts. */
export const SIMILARITY = ['off', 'user', 'organisation']

/**
 * The form fields for a stored agent's answer cache.
 *
 * @param {object} source The stored agent or draft.
 * @return {{answerCacheEnabled: boolean, answerCacheDays: number, answerCacheSimilarity: string}} The fields.
 *
 * @spec openspec/changes/models-prompt-and-answer-cache/tasks.md#task-4
 */
export function answerCacheFields(source) {
	const stored = (source && typeof source.answerCache === 'object' && source.answerCache) || {}
	const minutes = Number.isInteger(stored.ttlMinutes) ? stored.ttlMinutes : DEFAULT_TTL_MINUTES
	return {
		answerCacheEnabled: stored.enabled === true,
		answerCacheDays: Math.max(1, Math.round(minutes / DAY)),
		answerCacheSimilarity: SIMILARITY.includes(stored.similarity) ? stored.similarity : 'off',
	}
}

/**
 * The agent's `answerCache` from the form, within the register's bounds.
 *
 * Organisation scope is refused while retrieval is on: an answer built from one
 * person's documents must not reach another person who asks something similar.
 * It falls back to the user's own questions.
 *
 * @param {object} form The form.
 * @return {{enabled: boolean, ttlMinutes: number, similarity: string}} The setting.
 *
 * @spec openspec/changes/models-prompt-and-answer-cache/tasks.md#task-4
 */
export function answerCachePayload(form) {
	const days = Number.parseInt(form.answerCacheDays, 10)
	const minutes = Number.isInteger(days) && days > 0 ? days * DAY : DEFAULT_TTL_MINUTES
	let similarity = SIMILARITY.includes(form.answerCacheSimilarity) ? form.answerCacheSimilarity : 'off'
	if (similarity === 'organisation' && form.enableRag === true) {
		similarity = 'user'
	}

	return {
		enabled: form.answerCacheEnabled === true,
		ttlMinutes: Math.min(minutes, MAX_TTL_MINUTES),
		similarity,
	}
}

/**
 * Whether the organisation scope can be chosen: only with retrieval off.
 *
 * @param {object} form The form.
 * @return {boolean} True when it can.
 */
export function organisationScopeAllowed(form) {
	return form.enableRag !== true
}
