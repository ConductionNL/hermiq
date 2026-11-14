/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The context readout under the message box (chat-how-an-answer-came-about D4):
 * how many tokens the session uses, of the model's window when one is declared,
 * "about" when the number is an estimate, and a warning above 80% of the window.
 *
 * @spec openspec/changes/chat-how-an-answer-came-about/specs/answer-transparency/spec.md#requirement-a-person-sees-how-full-the-models-context-is-req-atrn-004
 */

/** The share of a declared window above which the readout warns. */
export const WARN_SHARE = 0.8

/**
 * A usable usage record, or null: a positive whole number of tokens, a window
 * that is a positive whole number or absent, and an `estimated` flag.
 *
 * @param {object|null|undefined} usage The record from the server.
 * @return {{usedTokens: number, windowTokens: number|null, estimated: boolean}|null} The record.
 * @spec openspec/changes/chat-how-an-answer-came-about/specs/answer-transparency/spec.md#requirement-a-person-sees-how-full-the-models-context-is-req-atrn-004
 */
export function readUsage(usage) {
	if (!usage || typeof usage !== 'object') {
		return null
	}
	const used = usage.usedTokens
	if (!Number.isInteger(used) || used <= 0) {
		return null
	}
	const window = Number.isInteger(usage.windowTokens) && usage.windowTokens > 0 ? usage.windowTokens : null
	return { usedTokens: used, windowTokens: window, estimated: usage.estimated !== false }
}

/**
 * The readout's sentence and whether to warn.
 *
 * @param {object|null|undefined} usage The usage record from the server.
 * @param {(app: string, text: string, params?: object) => string} t The translate function.
 * @param {(value: number) => string} [formatNumber] Formats a token count for the reader's locale.
 * @return {{text: string, warning: string}|null} The readout, or null when there is no usage.
 * @spec openspec/changes/chat-how-an-answer-came-about/specs/answer-transparency/spec.md#requirement-a-person-sees-how-full-the-models-context-is-req-atrn-004
 */
export function contextReadout(usage, t, formatNumber = (value) => value.toLocaleString()) {
	const record = readUsage(usage)
	if (record === null) {
		return null
	}
	const used = formatNumber(record.usedTokens)
	if (record.windowTokens === null) {
		return {
			text: record.estimated
				? t('hermiq', 'Context: about {used} tokens', { used })
				: t('hermiq', 'Context: {used} tokens', { used }),
			warning: '',
		}
	}
	const share = record.usedTokens / record.windowTokens
	const params = { used, window: formatNumber(record.windowTokens), percent: Math.round(share * 100) }
	return {
		text: record.estimated
			? t('hermiq', 'Context: about {used} of {window} tokens ({percent}%)', params)
			: t('hermiq', 'Context: {used} of {window} tokens ({percent}%)', params),
		warning: share > WARN_SHARE
			? t('hermiq', 'The session is close to the model\'s limit. Start a new session or fork from an earlier answer.')
			: '',
	}
}

/**
 * Whether a provider returns the model's reasoning when asked.
 *
 * @param {string|null|undefined} provider The agent's provider slug.
 * @return {boolean} True for anthropic and ollama.
 * @spec openspec/changes/chat-how-an-answer-came-about/specs/answer-transparency/spec.md#requirement-the-models-reasoning-is-shown-only-when-the-owner-allows-it-and-the-provider-returns-it-req-atrn-003
 */
export function providerReturnsReasoning(provider) {
	return provider === 'anthropic' || provider === 'ollama'
}
