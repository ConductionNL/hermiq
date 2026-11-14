/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The context readout under the message box, and which providers return reasoning.
 *
 * @spec openspec/changes/chat-how-an-answer-came-about/specs/answer-transparency/spec.md#requirement-a-person-sees-how-full-the-models-context-is-req-atrn-004
 */

import { describe, expect, it } from 'vitest'
import { contextReadout, providerReturnsReasoning, readUsage } from '../../src/utils/contextUsage.js'

/**
 * A translate function that fills {placeholders}, like @nextcloud/l10n.
 *
 * @param {string} app The app id.
 * @param {string} text The source text.
 * @param {object} [params] The placeholder values.
 * @return {string} The text.
 */
function t(app, text, params = {}) {
	expect(app).toBe('hermiq')
	return text.replace(/\{(\w+)\}/g, (match, key) => String(params[key] ?? match))
}

const en = (value) => value.toLocaleString('en-US')

describe('context readout', () => {
	it('shows the used tokens of the declared window and warns above 80%', () => {
		const readout = contextReadout({ usedTokens: 106300, windowTokens: 128000, estimated: false }, t, en)
		expect(readout.text).toBe('Context: 106,300 of 128,000 tokens (83%)')
		expect(readout.warning).toBe('The session is close to the model\'s limit. Start a new session or fork from an earlier answer.')
	})

	it('does not warn at or below 80%', () => {
		expect(contextReadout({ usedTokens: 12400, windowTokens: 128000, estimated: false }, t, en))
			.toEqual({ text: 'Context: 12,400 of 128,000 tokens (10%)', warning: '' })
		expect(contextReadout({ usedTokens: 800, windowTokens: 1000, estimated: false }, t, en).warning).toBe('')
	})

	it('says "about" for an estimate, with or without a window', () => {
		expect(contextReadout({ usedTokens: 12400, windowTokens: null, estimated: true }, t, en).text).toBe('Context: about 12,400 tokens')
		expect(contextReadout({ usedTokens: 900, windowTokens: 1000, estimated: true }, t, en))
			.toEqual({ text: 'Context: about 900 of 1,000 tokens (90%)', warning: expect.stringContaining('close to the model') })
		expect(contextReadout({ usedTokens: 12400, estimated: false }, t, en).text).toBe('Context: 12,400 tokens')
	})

	it('shows nothing for a missing or malformed record, and treats an unflagged one as an estimate', () => {
		expect(contextReadout(null, t)).toBeNull()
		expect(contextReadout({ usedTokens: 0 }, t)).toBeNull()
		expect(contextReadout({ usedTokens: '12' }, t)).toBeNull()
		expect(readUsage({ usedTokens: 5, windowTokens: -1 })).toEqual({ usedTokens: 5, windowTokens: null, estimated: true })
	})
})

describe('reasoning providers', () => {
	it('only anthropic and ollama return reasoning', () => {
		expect(providerReturnsReasoning('anthropic')).toBe(true)
		expect(providerReturnsReasoning('ollama')).toBe(true)
		for (const provider of ['openai', 'fireworks', 'nextcloud', '', null, undefined]) {
			expect(providerReturnsReasoning(provider)).toBe(false)
		}
	})
})
