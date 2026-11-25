// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// @spec openspec/specs/tenant-model-policy/spec.md#requirement-every-attempt-is-on-the-run-record-req-mfail-005

import { describe, expect, it } from 'vitest'
import { attemptOutcome, receivedByList } from '../../src/utils/providerAttempts.js'

describe('provider attempts on the run record', () => {
	it('names a failure by its kind and keeps the other outcomes', () => {
		expect(attemptOutcome({ outcome: 'failed', reason: 'rate_limit' })).toBe('rate_limit')
		expect(attemptOutcome({ outcome: 'failed', reason: '' })).toBe('failed')
		expect(attemptOutcome({ outcome: 'answered', reason: '' })).toBe('answered')
		expect(attemptOutcome({ outcome: 'skipped', reason: 'residency' })).toBe('skipped')
		expect(attemptOutcome(null)).toBe('failed')
	})

	it('lists every provider that was sent the prompt, in order', () => {
		expect(receivedByList([
			{ provider: 'anthropic', model: 'claude-opus-4-8' },
			{ provider: 'ollama', model: 'qwen2.5' },
			{ model: 'no-provider' },
		])).toBe('anthropic claude-opus-4-8, ollama qwen2.5')
		expect(receivedByList(undefined)).toBe('')
	})
})
