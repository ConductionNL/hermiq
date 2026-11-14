// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The prompt cache share on the run list (models-prompt-and-answer-cache).
 *
 * @spec openspec/changes/models-prompt-and-answer-cache/tasks.md#task-3
 */

import { describe, expect, it } from 'vitest'
import { cacheShare } from '../../src/utils/runUsage.js'

describe('cacheShare', () => {
	it('gives the cached and total input tokens of a run that read from the cache', () => {
		expect(cacheShare({ promptTokens: 2300, completionTokens: 120, cacheReadTokens: 1840 })).toEqual({ cached: 1840, total: 2300 })
	})

	it('shows no line for a run that read nothing from the cache', () => {
		expect(cacheShare({ promptTokens: 2300, cacheReadTokens: 0 })).toBeNull()
		expect(cacheShare({ promptTokens: 2300 })).toBeNull()
		expect(cacheShare({ promptTokens: 2300, cacheWriteTokens: 900 })).toBeNull()
	})

	it('shows no line without an input total or recorded usage', () => {
		expect(cacheShare({ cacheReadTokens: 10 })).toBeNull()
		expect(cacheShare({ promptTokens: 0, cacheReadTokens: 10 })).toBeNull()
		expect(cacheShare([])).toBeNull()
		expect(cacheShare(null)).toBeNull()
		expect(cacheShare(undefined)).toBeNull()
		expect(cacheShare({ promptTokens: '2300', cacheReadTokens: 1840 })).toBeNull()
	})

	it('never reports more cached tokens than the input total', () => {
		expect(cacheShare({ promptTokens: 100, cacheReadTokens: 150 })).toEqual({ cached: 100, total: 100 })
	})
})
