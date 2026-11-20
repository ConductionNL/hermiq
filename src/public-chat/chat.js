// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Public web chat helpers (delivery-public-web-chat): what the window posts,
 * how an answer becomes something to show, and where the embed script frames
 * the chat page. Pure, so they run under vitest without a browser.
 *
 * @spec openspec/changes/delivery-public-web-chat/specs/public-chat-channel/spec.md#requirement-a-visitor-chats-without-signing-in-and-gets-answers-only-from-public-content-req-pubchat-002
 */

/**
 * The shape of a channel token: what hermiq mints, nothing else.
 */
export const TOKEN_PATTERN = /^[A-Za-z0-9]{16,64}$/

/**
 * The longest question the endpoint accepts.
 */
export const MAX_QUESTION = 2000

/**
 * The request body for one question.
 *
 * @param {string} question The visitor's question.
 * @param {string|null} conversationToken The token from the previous answer.
 * @param {string} locale The page's locale.
 * @return {{question: string, conversationToken?: string, locale: string}} The body.
 * @spec openspec/changes/delivery-public-web-chat/specs/public-chat-channel/spec.md#requirement-a-visitor-chats-without-signing-in-and-gets-answers-only-from-public-content-req-pubchat-002
 */
export function questionBody(question, conversationToken, locale) {
	const body = { question: String(question || '').trim().slice(0, MAX_QUESTION), locale: locale || 'en' }
	if (typeof conversationToken === 'string' && TOKEN_PATTERN.test(conversationToken)) {
		body.conversationToken = conversationToken
	}
	return body
}

/**
 * The sources of an answer that may be shown as links: http(s) only.
 *
 * @param {Array<{title: string, url: string}>} sources The answer's sources.
 * @return {Array<{title: string, url: string}>} The links.
 * @spec openspec/changes/delivery-public-web-chat/specs/public-chat-channel/spec.md#requirement-a-visitor-chats-without-signing-in-and-gets-answers-only-from-public-content-req-pubchat-002
 */
export function sourceLinks(sources) {
	return (Array.isArray(sources) ? sources : [])
		.filter((s) => s && typeof s.url === 'string' && /^https?:\/\//i.test(s.url))
		.map((s) => ({ title: String(s.title || s.url), url: s.url }))
}

/**
 * What the window shows for a response: the answer, or the sentence of a refusal.
 *
 * @param {number} status The HTTP status.
 * @param {object} data The response body.
 * @param {string} fallback What to show when the body says nothing.
 * @return {{text: string, sources: Array, token: string|null, ended: boolean}} What to show.
 * @spec openspec/changes/delivery-public-web-chat/specs/public-chat-channel/spec.md#requirement-the-public-endpoint-is-limited-and-origin-bound-req-pubchat-003
 */
export function replyOf(status, data, fallback) {
	const body = data && typeof data === 'object' ? data : {}
	if (status >= 200 && status < 300 && typeof body.answer === 'string') {
		return {
			text: body.answer,
			sources: sourceLinks(body.sources),
			token: typeof body.conversationToken === 'string' ? body.conversationToken : null,
			ended: body.available === false,
		}
	}
	return {
		text: typeof body.message === 'string' && body.message !== '' ? body.message : fallback,
		sources: [],
		token: null,
		ended: body.available === false,
	}
}

/**
 * Where the embed script frames the chat page, from the script's own address.
 *
 * Works for an instance under a sub path and for apps served from
 * `custom_apps`: the base is everything before `/apps/` or `/custom_apps/`.
 *
 * @param {string} scriptSrc The embed script's URL, carrying `?channel=<token>`.
 * @return {string|null} The chat page URL, or null when the script names no valid channel.
 * @spec openspec/changes/delivery-public-web-chat/specs/public-chat-channel/spec.md#requirement-the-public-endpoint-is-limited-and-origin-bound-req-pubchat-003
 */
export function frameUrl(scriptSrc) {
	let url
	try {
		url = new URL(scriptSrc)
	} catch {
		return null
	}
	const channel = url.searchParams.get('channel') || ''
	if (!TOKEN_PATTERN.test(channel)) {
		return null
	}
	const match = url.pathname.match(/^(.*?)\/(?:custom_)?apps\//)
	const base = match ? match[1].replace(/\/index\.php$/, '') : ''
	return `${url.origin}${base}/index.php/apps/hermiq/public/${channel}`
}
