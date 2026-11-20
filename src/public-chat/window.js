// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The public chat window (delivery-public-web-chat): the standalone page a
 * website frames. It shows the channel's disclosure before the input, posts
 * the visitor's questions, and shows each answer with its sources as links.
 * No sign-in, no Vue: it is a small page on someone else's website.
 *
 * @spec openspec/changes/delivery-public-web-chat/specs/public-chat-channel/spec.md#requirement-a-visitor-chats-without-signing-in-and-gets-answers-only-from-public-content-req-pubchat-002
 */

import { loadState } from '@nextcloud/initial-state'
import { getLanguage, translate as t } from '@nextcloud/l10n'
import { MAX_QUESTION, questionBody, replyOf } from './chat.js'

import './window.css'

/**
 * Append one message to the log.
 *
 * @param {HTMLElement} log The message list.
 * @param {string} role `visitor` or `assistant`.
 * @param {string} text The message.
 * @param {Array<{title: string, url: string}>} sources The links under an answer.
 * @return {void}
 * @spec openspec/changes/delivery-public-web-chat/specs/public-chat-channel/spec.md#requirement-a-visitor-chats-without-signing-in-and-gets-answers-only-from-public-content-req-pubchat-002
 */
export function appendMessage(log, role, text, sources = []) {
	const doc = log.ownerDocument
	const item = doc.createElement('li')
	item.className = `hermiq-public-chat__message hermiq-public-chat__message--${role}`
	const body = doc.createElement('p')
	body.textContent = text
	item.appendChild(body)

	if (sources.length > 0) {
		const label = doc.createElement('p')
		label.className = 'hermiq-public-chat__sources-label'
		label.textContent = t('hermiq', 'Sources')
		const list = doc.createElement('ul')
		list.className = 'hermiq-public-chat__sources'
		for (const source of sources) {
			const link = doc.createElement('a')
			link.href = source.url
			link.textContent = source.title
			link.target = '_blank'
			link.rel = 'noopener noreferrer'
			const entry = doc.createElement('li')
			entry.appendChild(link)
			list.appendChild(entry)
		}
		item.appendChild(label)
		item.appendChild(list)
	}

	log.appendChild(item)
}

/**
 * Build the window in a root element.
 *
 * @param {HTMLElement} root The page's root.
 * @param {{available: boolean, disclosure: string, endpoint: string, title: string}} state The channel, from the page.
 * @param {function(string, object): Promise<{status: number, data: object}>} post Posts a question.
 * @return {void}
 * @spec openspec/changes/delivery-public-web-chat/specs/public-chat-channel/spec.md#requirement-a-visitor-chats-without-signing-in-and-gets-answers-only-from-public-content-req-pubchat-002
 */
export function mountWindow(root, state, post) {
	const doc = root.ownerDocument
	root.replaceChildren()

	const heading = doc.createElement('h1')
	heading.className = 'hermiq-public-chat__title'
	heading.textContent = state.title || t('hermiq', 'Ask a question')
	root.appendChild(heading)

	// The disclosure comes before anything the visitor can type into.
	const disclosure = doc.createElement('p')
	disclosure.className = 'hermiq-public-chat__disclosure'
	disclosure.textContent = state.disclosure
	root.appendChild(disclosure)

	const log = doc.createElement('ul')
	log.className = 'hermiq-public-chat__log'
	log.setAttribute('aria-live', 'polite')
	root.appendChild(log)

	if (!state.available) {
		appendMessage(log, 'assistant', t('hermiq', 'This assistant is not available right now.'))
		return
	}

	const form = doc.createElement('form')
	form.className = 'hermiq-public-chat__form'
	const label = doc.createElement('label')
	label.htmlFor = 'hermiq-public-chat-question'
	label.textContent = t('hermiq', 'Your question')
	const input = doc.createElement('textarea')
	input.id = 'hermiq-public-chat-question'
	input.maxLength = MAX_QUESTION
	input.rows = 2
	input.required = true
	const send = doc.createElement('button')
	send.type = 'submit'
	send.textContent = t('hermiq', 'Send')
	form.append(label, input, send)
	root.appendChild(form)

	let token = null
	form.addEventListener('submit', async (event) => {
		event.preventDefault()
		const question = input.value.trim()
		if (question === '') {
			return
		}
		appendMessage(log, 'visitor', question)
		input.value = ''
		send.disabled = true
		let reply
		try {
			const { status, data } = await post(state.endpoint, questionBody(question, token, getLanguage()))
			reply = replyOf(status, data, t('hermiq', 'Something went wrong. Try again.'))
		} catch {
			reply = replyOf(0, null, t('hermiq', 'Something went wrong. Try again.'))
		}
		token = reply.token || token
		appendMessage(log, 'assistant', reply.text, reply.sources)
		send.disabled = reply.ended
		input.disabled = reply.ended
		if (!reply.ended) {
			input.focus()
		}
	})
}

/**
 * Post a question with fetch, without credentials: the chat carries no identity.
 *
 * @param {string} url The endpoint.
 * @param {object} body The question.
 * @return {Promise<{status: number, data: object}>} The response.
 * @spec openspec/changes/delivery-public-web-chat/specs/public-chat-channel/spec.md#requirement-a-visitor-chats-without-signing-in-and-gets-answers-only-from-public-content-req-pubchat-002
 */
export async function postQuestion(url, body) {
	const response = await fetch(url, {
		method: 'POST',
		credentials: 'omit',
		headers: { 'Content-Type': 'application/json' },
		body: JSON.stringify(body),
	})
	let data
	try {
		data = await response.json()
	} catch {
		data = null
	}
	return { status: response.status, data }
}

if (typeof document !== 'undefined') {
	const root = document.getElementById('hermiq-public-chat')
	if (root) {
		mountWindow(root, loadState('hermiq', 'public-chat', { available: false, disclosure: '', endpoint: '', title: '' }), postQuestion)
	}
}
