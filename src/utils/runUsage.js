// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Run usage helpers (models-prompt-and-answer-cache).
 *
 * Plain functions without Vue or Nextcloud imports, so a spec runs them under node.
 *
 * @spec openspec/changes/models-prompt-and-answer-cache/tasks.md#task-3
 */

/**
 * How many of a run's input tokens came from the provider's prompt cache.
 *
 * `promptTokens` already counts the cached tokens, so the share is cached of
 * total. Null when the run read nothing from the cache or recorded no input
 * total, so the run list shows no line for it.
 *
 * @param {object|Array|null|undefined} usage The run row's `usage`.
 * @return {{cached: number, total: number}|null} The cached and total input tokens.
 *
 * @spec openspec/changes/models-prompt-and-answer-cache/tasks.md#task-3
 */
export function cacheShare(usage) {
	if (!usage || typeof usage !== 'object' || Array.isArray(usage)) {
		return null
	}

	const cached = usage.cacheReadTokens
	const total = usage.promptTokens
	if (!Number.isInteger(cached) || !Number.isInteger(total) || cached <= 0 || total <= 0) {
		return null
	}

	return { cached: Math.min(cached, total), total }
}
