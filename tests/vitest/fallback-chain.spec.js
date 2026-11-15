/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

import { describe, expect, it } from 'vitest'
import {
	addFallback,
	fallbackPayload,
	fallbackRows,
	fallbackSummary,
	MAX_FALLBACKS,
	moveFallback,
	removeFallback,
} from '../../src/utils/fallbackChain.js'

const chain = [
	{ provider: 'ollama', model: 'qwen2.5' },
	{ provider: 'anthropic', model: 'claude-haiku' },
	{ provider: 'mistral', model: 'small' },
]

describe('fallback chain editing', () => {
	it('seeds fresh rows from the stored chain', () => {
		const rows = fallbackRows(chain)
		expect(rows).toEqual(chain)
		expect(rows[0]).not.toBe(chain[0])
		expect(fallbackRows(undefined)).toEqual([])
	})

	it('adds an empty row up to the maximum', () => {
		let rows = fallbackRows(chain)
		rows = addFallback(rows)
		expect(rows).toHaveLength(4)
		expect(rows[3]).toEqual({ provider: '', model: '' })
		expect(addFallback(rows)).toHaveLength(MAX_FALLBACKS)
	})

	it('reorders and stays put past either end', () => {
		const rows = fallbackRows(chain)
		expect(moveFallback(rows, 2, -1).map((row) => row.provider)).toEqual(['ollama', 'mistral', 'anthropic'])
		expect(moveFallback(rows, 0, 1).map((row) => row.provider)).toEqual(['anthropic', 'ollama', 'mistral'])
		expect(moveFallback(rows, 0, -1)).toBe(rows)
		expect(moveFallback(rows, 2, 1)).toBe(rows)
	})

	it('removes one row', () => {
		expect(removeFallback(fallbackRows(chain), 1).map((row) => row.provider)).toEqual(['ollama', 'mistral'])
	})

	it('saves trimmed rows in order and drops only fully empty ones', () => {
		const payload = fallbackPayload([
			{ provider: ' ollama ', model: ' qwen2.5 ' },
			{ provider: '', model: '' },
			{ provider: 'openai', model: '' },
		])
		expect(payload).toEqual([
			{ provider: 'ollama', model: 'qwen2.5' },
			{ provider: 'openai', model: '' },
		])
	})

	it('summarises a chain in order', () => {
		expect(fallbackSummary(chain)).toBe('ollama/qwen2.5 → anthropic/claude-haiku → mistral/small')
		expect(fallbackSummary([])).toBe('')
	})
})
