/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The public web chat: the window shows the disclosure before the input and
 * each answer with its sources as links; the embed script frames the chat
 * page of its own channel.
 *
 * @spec openspec/changes/delivery-public-web-chat/specs/public-chat-channel/spec.md#requirement-a-visitor-chats-without-signing-in-and-gets-answers-only-from-public-content-req-pubchat-002
 */

import { afterEach, describe, expect, it, vi } from 'vitest'
import { frameUrl, questionBody, replyOf, sourceLinks } from '../../src/public-chat/chat.js'
import { mountEmbed } from '../../src/public-chat/embed.js'
import { appendMessage, mountWindow, postQuestion } from '../../src/public-chat/window.js'

vi.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text, getLanguage: () => 'nl' }))
vi.mock('@nextcloud/initial-state', () => ({ loadState: (app, key, fallback) => fallback }))

const TOKEN = 'Chan0123456789abcdef'

afterEach(() => {
	document.head.replaceChildren()
	document.body.replaceChildren()
	vi.unstubAllGlobals()
})

describe('public chat helpers', () => {
	it('posts a trimmed question and only a token of the minted shape', () => {
		expect(questionBody('  When?  ', 'Conv0123456789abcdef', 'nl')).toEqual({ question: 'When?', locale: 'nl', conversationToken: 'Conv0123456789abcdef' })
		expect(questionBody('Hi', 'bad token', '')).toEqual({ question: 'Hi', locale: 'en' })
		expect(questionBody('x'.repeat(2100), null, 'en').question).toHaveLength(2000)
	})

	it('shows only http(s) sources as links', () => {
		expect(sourceLinks([{ title: 'Waste', url: 'https://www.example.nl/waste' }, { title: 'Bad', url: 'javascript:alert(1)' }, { url: 'http://x.nl' }, null]))
			.toEqual([{ title: 'Waste', url: 'https://www.example.nl/waste' }, { title: 'http://x.nl', url: 'http://x.nl' }])
		expect(sourceLinks(undefined)).toEqual([])
	})

	it('reads an answer, a refusal and a failure', () => {
		expect(replyOf(200, { answer: 'Monday.', sources: [{ title: 'W', url: 'https://w.nl' }], conversationToken: 'Conv0123456789abcdef', available: true }, 'oops'))
			.toEqual({ text: 'Monday.', sources: [{ title: 'W', url: 'https://w.nl' }], token: 'Conv0123456789abcdef', ended: false })
		expect(replyOf(429, { message: 'Try again tomorrow.', available: false }, 'oops')).toEqual({ text: 'Try again tomorrow.', sources: [], token: null, ended: true })
		expect(replyOf(500, null, 'oops')).toEqual({ text: 'oops', sources: [], token: null, ended: false })
	})

	it('frames the chat page of the channel the script was loaded for', () => {
		expect(frameUrl(`https://cloud.example.nl/apps/hermiq/js/public-chat.js?channel=${TOKEN}`)).toBe(`https://cloud.example.nl/index.php/apps/hermiq/public/${TOKEN}`)
		expect(frameUrl(`https://x.nl/nextcloud/custom_apps/hermiq/js/public-chat.js?channel=${TOKEN}`)).toBe(`https://x.nl/nextcloud/index.php/apps/hermiq/public/${TOKEN}`)
		expect(frameUrl('https://x.nl/apps/hermiq/js/public-chat.js?channel=../../evil')).toBeNull()
		expect(frameUrl('not a url')).toBeNull()
	})
})

describe('the embed script', () => {
	it('adds a button that opens the chat page in a frame, and closes it again', () => {
		const button = mountEmbed(document, `https://cloud.example.nl/apps/hermiq/js/public-chat.js?channel=${TOKEN}`)
		const frame = document.querySelector('iframe.hermiq-chat-frame')

		expect(button.getAttribute('aria-expanded')).toBe('false')
		expect(frame.hidden).toBe(true)
		expect(frame.getAttribute('src')).toBeNull()

		button.click()
		expect(frame.hidden).toBe(false)
		expect(frame.src).toBe(`https://cloud.example.nl/index.php/apps/hermiq/public/${TOKEN}`)
		expect(button.getAttribute('aria-expanded')).toBe('true')

		button.click()
		expect(frame.hidden).toBe(true)
		expect(button.getAttribute('aria-expanded')).toBe('false')
	})

	it('adds nothing for a script without a channel', () => {
		expect(mountEmbed(document, 'https://cloud.example.nl/apps/hermiq/js/public-chat.js')).toBeNull()
		expect(document.body.children).toHaveLength(0)
	})
})

describe('the chat window', () => {
	const state = { available: true, disclosure: 'You are chatting with an AI assistant.', endpoint: '/index.php/apps/hermiq/api/public/x/messages', title: 'Website' }

	it('shows the disclosure before the input', () => {
		const root = document.createElement('main')
		document.body.appendChild(root)
		mountWindow(root, state, vi.fn())

		const disclosure = root.querySelector('.hermiq-public-chat__disclosure')
		const input = root.querySelector('textarea')
		expect(disclosure.textContent).toBe('You are chatting with an AI assistant.')
		expect(disclosure.compareDocumentPosition(input) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy()
		expect(root.querySelector('label').htmlFor).toBe(input.id)
		expect(root.querySelector('h1').textContent).toBe('Website')
	})

	it('posts the question and shows the answer with its sources as links, then continues the visit', async () => {
		const post = vi.fn()
			.mockResolvedValueOnce({ status: 200, data: { answer: 'On the first Monday.', sources: [{ title: 'Bulky waste', url: 'https://www.example.nl/bulky-waste' }], conversationToken: 'Conv0123456789abcdef', available: true } })
			.mockResolvedValueOnce({ status: 200, data: { answer: 'Yes.', sources: [], conversationToken: 'Conv0123456789abcdef' } })
		const root = document.createElement('main')
		document.body.appendChild(root)
		mountWindow(root, state, post)

		const input = root.querySelector('textarea')
		const form = root.querySelector('form')
		input.value = 'When is bulky waste collected?'
		form.dispatchEvent(new Event('submit', { cancelable: true }))
		await vi.waitFor(() => expect(root.querySelectorAll('.hermiq-public-chat__message--assistant')).toHaveLength(1))

		expect(post).toHaveBeenCalledWith(state.endpoint, { question: 'When is bulky waste collected?', locale: 'nl' })
		const link = root.querySelector('.hermiq-public-chat__sources a')
		expect(link.textContent).toBe('Bulky waste')
		expect(link.href).toBe('https://www.example.nl/bulky-waste')
		expect(link.rel).toBe('noopener noreferrer')
		expect(root.querySelector('.hermiq-public-chat__message--visitor').textContent).toBe('When is bulky waste collected?')

		input.value = 'And on holidays?'
		form.dispatchEvent(new Event('submit', { cancelable: true }))
		await vi.waitFor(() => expect(root.querySelectorAll('.hermiq-public-chat__message--assistant')).toHaveLength(2))
		expect(post.mock.calls[1][1]).toEqual({ question: 'And on holidays?', locale: 'nl', conversationToken: 'Conv0123456789abcdef' })
	})

	it('stops the input when the channel says it is not available', async () => {
		const post = vi.fn().mockResolvedValue({ status: 503, data: { message: 'This assistant is not available right now.', available: false } })
		const root = document.createElement('main')
		document.body.appendChild(root)
		mountWindow(root, state, post)

		root.querySelector('textarea').value = 'Hello'
		root.querySelector('form').dispatchEvent(new Event('submit', { cancelable: true }))
		await vi.waitFor(() => expect(root.querySelector('button').disabled).toBe(true))
		expect(root.querySelector('textarea').disabled).toBe(true)
		expect(root.querySelector('.hermiq-public-chat__message--assistant').textContent).toBe('This assistant is not available right now.')
	})

	it('shows an unavailable channel without an input', () => {
		const root = document.createElement('main')
		mountWindow(root, { ...state, available: false }, vi.fn())
		expect(root.querySelector('form')).toBeNull()
		expect(root.textContent).toContain('This assistant is not available right now.')
	})

	it('escapes what it shows', () => {
		const log = document.createElement('ul')
		appendMessage(log, 'assistant', '<img src=x onerror=alert(1)>')
		expect(log.querySelector('img')).toBeNull()
	})

	it('posts without credentials', async () => {
		const fetchMock = vi.fn().mockResolvedValue({ status: 200, json: async () => ({ answer: 'ok' }) })
		vi.stubGlobal('fetch', fetchMock)

		expect(await postQuestion('/x', { question: 'q' })).toEqual({ status: 200, data: { answer: 'ok' } })
		expect(fetchMock).toHaveBeenCalledWith('/x', { method: 'POST', credentials: 'omit', headers: { 'Content-Type': 'application/json' }, body: '{"question":"q"}' })

		fetchMock.mockResolvedValue({ status: 502, json: async () => { throw new Error('html') } })
		expect(await postQuestion('/x', {})).toEqual({ status: 502, data: null })
	})
})
