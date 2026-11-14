/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The chat API helpers: which route each call hits with which body, how list
 * envelopes are normalised, and how the SSE stream is parsed, dispatched and
 * settled into a final payload or a transport / server-side error.
 */

import axios from '@nextcloud/axios'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/auth', () => ({ getRequestToken: () => 'tok-1' }))

const chat = await import('../../src/api/chat.js')

/**
 * Point every axios verb at a recording double that answers `data`.
 *
 * @param {*} data The response body.
 * @return {Array} The recorded calls as [verb, ...args].
 */
function record(data) {
	const calls = []
	for (const verb of ['get', 'post', 'put', 'patch', 'delete']) {
		vi.spyOn(axios, verb).mockImplementation(async (...args) => {
			calls.push([verb, ...args])
			return { data }
		})
	}
	return calls
}

/**
 * A fetch Response double whose body streams the given chunks.
 *
 * @param {Array<string>} chunks The text chunks, in order.
 * @param {object} [init] Response fields to override (ok, status, body).
 * @return {object} The response.
 */
function streamResponse(chunks, init = {}) {
	const encoder = new TextEncoder()
	let i = 0
	return {
		ok: true,
		status: 200,
		body: {
			getReader: () => ({
				read: async () => (i < chunks.length ? { done: false, value: encoder.encode(chunks[i++]) } : { done: true }),
			}),
		},
		...init,
	}
}

afterEach(() => {
	vi.restoreAllMocks()
	vi.unstubAllGlobals()
})

describe('session list and read', () => {
	it('lists active sessions by default, and archived ones on request', async () => {
		const calls = record({ results: [{ uuid: 'a' }], total: 3 })
		expect(await chat.listSessions()).toEqual({ results: [{ uuid: 'a' }], total: 3, truncated: false })
		await chat.listSessions({ archived: true, limit: 5, offset: 10 })
		expect(calls[0]).toEqual(['get', '/index.php/apps/hermiq/api/sessions', { params: { _deleted: 'false', limit: 50, offset: 0 } }])
		expect(calls[1][2].params).toEqual({ _deleted: 'true', limit: 5, offset: 10 })
	})

	it('sends a trimmed search term and passes the truncation flag back, and omits an empty term', async () => {
		const calls = record({ results: [{ uuid: 'a', match: { turnUuid: 't1', excerpt: '…monument…' } }], total: 1, truncated: true })
		expect(await chat.listSessions({ search: '  monument ' })).toEqual({
			results: [{ uuid: 'a', match: { turnUuid: 't1', excerpt: '…monument…' } }],
			total: 1,
			truncated: true,
		})
		await chat.listSessions({ archived: true, search: '   ' })
		expect(calls[0][2].params).toEqual({ _deleted: 'false', limit: 50, offset: 0, search: 'monument' })
		expect(calls[1][2].params).toEqual({ _deleted: 'true', limit: 50, offset: 0 })
	})

	it('posts a bulk action and returns the outcome per uuid', async () => {
		const calls = record({ action: 'delete', results: [{ uuid: 'a', status: 'ok' }, { uuid: 'b', status: 'not_found' }] })
		expect(await chat.bulkSessions('delete', ['a', 'b'])).toEqual([{ uuid: 'a', status: 'ok' }, { uuid: 'b', status: 'not_found' }])
		expect(calls[0]).toEqual(['post', '/index.php/apps/hermiq/api/sessions/bulk', { action: 'delete', uuids: ['a', 'b'] }])
	})

	it('sends more than 100 uuids in requests of at most 100 and joins the answers', async () => {
		const calls = []
		vi.spyOn(axios, 'post').mockImplementation(async (url, body) => {
			calls.push(body.uuids.length)
			return { data: { results: body.uuids.map((uuid) => ({ uuid, status: 'ok' })) } }
		})
		const uuids = Array.from({ length: 205 }, (_, i) => `s${i}`)
		const results = await chat.bulkSessions('archive', uuids)
		expect(calls).toEqual([100, 100, 5])
		expect(results.map((entry) => entry.uuid)).toEqual(uuids)
	})

	it('normalises a malformed bulk answer to no outcomes', async () => {
		record({ results: 'nope' })
		expect(await chat.bulkSessions('archive', ['a'])).toEqual([])
	})

	it('normalises a malformed list envelope to an empty page', async () => {
		record({ results: 'nope' })
		expect(await chat.listSessions()).toEqual({ results: [], total: 0, truncated: false })
		expect(await chat.listMessages('s1')).toEqual({ results: [], total: 0 })
	})

	it('reads a session and pages its messages', async () => {
		const calls = record({ results: [{ id: 1 }], total: 1 })
		await chat.getSession('s1')
		expect(await chat.listMessages('s1', { limit: 2, offset: 4 })).toEqual({ results: [{ id: 1 }], total: 1 })
		expect(calls[0][1]).toBe('/index.php/apps/hermiq/api/sessions/s1')
		expect(calls[1]).toEqual(['get', '/index.php/apps/hermiq/api/sessions/s1/messages', { params: { limit: 2, offset: 4 } }])
	})
})

describe('session lifecycle', () => {
	it('creates a session with a title only when one is given', async () => {
		const calls = record({ uuid: 'new' })
		expect(await chat.createSession('agent-1')).toEqual({ uuid: 'new' })
		await chat.createSession('agent-1', 'Mine')
		expect(calls[0][2]).toEqual({ agentUuid: 'agent-1' })
		expect(calls[1][2]).toEqual({ agentUuid: 'agent-1', title: 'Mine' })
	})

	it('renames, archives, restores and permanently deletes on their own routes', async () => {
		const calls = record({ ok: true })
		await chat.renameSession('s1', 'T')
		await chat.archiveSession('s1')
		await chat.restoreSession('s1')
		await chat.deleteSessionPermanent('s1')
		expect(calls.map((c) => [c[0], c[1]])).toEqual([
			['patch', '/index.php/apps/hermiq/api/sessions/s1'],
			['delete', '/index.php/apps/hermiq/api/sessions/s1'],
			['post', '/index.php/apps/hermiq/api/sessions/s1/restore'],
			['delete', '/index.php/apps/hermiq/api/sessions/s1/permanent'],
		])
		expect(calls[0][2]).toEqual({ title: 'T' })
	})

	it('stores start answers under an encoded session id', async () => {
		const calls = record({ uuid: 'a b', startValues: { x: 1 } })
		await chat.answerStartFields('a b', { x: 1 })
		expect(calls[0]).toEqual(['put', '/index.php/apps/hermiq/api/sessions/a%20b/start-values', { values: { x: 1 } }])
	})
})

describe('participants and people search', () => {
	it('returns the participant list after each change, empty when absent', async () => {
		const calls = record({ results: [{ uid: 'u' }] })
		expect(await chat.listParticipants('s1')).toEqual([{ uid: 'u' }])
		expect(await chat.addParticipant('s1', 'bob')).toEqual([{ uid: 'u' }])
		expect(await chat.removeParticipant('s1', 'a/b')).toEqual([{ uid: 'u' }])
		expect(calls[1][2]).toEqual({ uid: 'bob' })
		expect(calls[2][1]).toBe('/index.php/apps/hermiq/api/sessions/s1/participants/a%2Fb')
		record({})
		expect(await chat.listParticipants('s1')).toEqual([])
	})

	it('maps autocomplete hits to users and groups, falling back to the id as name', async () => {
		const calls = record({ ocs: { data: [{ id: 'g1', label: 'Group one' }, { id: 'g2' }] } })
		expect(await chat.searchGroups('g')).toEqual([{ gid: 'g1', displayName: 'Group one' }, { gid: 'g2', displayName: 'g2' }])
		expect(await chat.searchUsers('g')).toEqual([{ uid: 'g1', displayName: 'Group one' }, { uid: 'g2', displayName: 'g2' }])
		expect(calls[0][2].params.shareTypes).toEqual([1])
		expect(calls[1][2].params.shareTypes).toEqual([0])
		record({})
		expect(await chat.searchUsers('x')).toEqual([])
	})
})

describe('sendChatMessage', () => {
	it('sends the continuing session under the conversation key, with only non-empty lists and set RAG fields', async () => {
		const calls = record({ message: 'hi' })
		await chat.sendChatMessage({
			message: 'Q',
			sessionUuid: 's1',
			agentUuid: 'ignored',
			views: [],
			tools: ['t1'],
			ragSettings: { includeObjects: true, numSourcesFiles: 0, includeFiles: undefined },
			attachments: [{ fileId: 1, origin: 'files' }],
		})
		expect(calls[0][1]).toBe('/index.php/apps/hermiq/api/chat/send')
		expect(calls[0][2]).toEqual({
			message: 'Q',
			attachments: [{ fileId: 1, origin: 'files' }],
			conversation: 's1',
			tools: ['t1'],
			includeObjects: true,
			numSourcesFiles: 0,
		})
	})

	it('starts a new session from the agent when there is none, ignoring a non-object RAG setting', async () => {
		const calls = record({})
		await chat.sendChatMessage({ message: 'Q', agentUuid: 'a1', ragSettings: 'x', attachments: [] })
		expect(calls[0][2]).toEqual({ message: 'Q', agentUuid: 'a1' })
	})
})

describe('attachments, images, feedback and goals', () => {
	it('uploads the file as form data', async () => {
		const calls = record({ fileId: 9 })
		const file = new Blob(['x'])
		expect(await chat.uploadChatAttachment(file)).toEqual({ fileId: 9 })
		expect(calls[0][2]).toBeInstanceOf(FormData)
		expect(calls[0][2].get('file')).toBeTruthy()
	})

	it('reports image availability only for an explicit true', async () => {
		record({ available: true })
		expect(await chat.getImageAvailability()).toBe(true)
		record({ available: 'yes' })
		expect(await chat.getImageAvailability()).toBe(false)
	})

	it('creates an image as a turn pair', async () => {
		const calls = record({ userTurn: {}, assistantTurn: {} })
		await chat.createChatImage('s1', 'a cat')
		expect(calls[0].slice(1)).toEqual(['/index.php/apps/hermiq/api/chat/images', { sessionId: 's1', prompt: 'a cat' }])
	})

	it('sends feedback with a comment only when given', async () => {
		const calls = record({})
		await chat.sendMessageFeedback('s1', 'm1', { type: 'negative', comment: 'wrong' })
		await chat.sendMessageFeedback('s1', 'm1', { type: 'positive', comment: '' })
		expect(calls[0][1]).toBe('/index.php/apps/hermiq/api/sessions/s1/messages/m1/feedback')
		expect(calls[0][2]).toEqual({ type: 'negative', comment: 'wrong' })
		expect(calls[1][2]).toEqual({ type: 'positive' })
	})

	it('reads, sets and stops a goal', async () => {
		const calls = record({ goal: { id: 'g' } })
		expect(await chat.getSessionGoal('s1')).toEqual({ id: 'g' })
		record({})
		expect(await chat.getSessionGoal('s1')).toBeNull()
		const later = record({ id: 'g' })
		await chat.setSessionGoal('s1', { statement: 'S' })
		await chat.stopGoal('g')
		expect(calls[0][1]).toBe('/index.php/apps/hermiq/api/sessions/s1/goal')
		expect(later.map((c) => c[1])).toEqual(['/index.php/apps/hermiq/api/sessions/s1/goal', '/index.php/apps/hermiq/api/goals/g/stop'])
	})
})

describe('streamChatMessage', () => {
	let fetchCalls

	beforeEach(() => {
		fetchCalls = []
	})

	/**
	 * Stub fetch to answer with the given response.
	 *
	 * @param {object|Function} response The response, or a function that throws.
	 */
	function stubFetch(response) {
		vi.stubGlobal('fetch', async (...args) => {
			fetchCalls.push(args)
			if (typeof response === 'function') {
				return response()
			}
			return response
		})
	}

	it('dispatches every frame across chunk boundaries and resolves with the final payload', async () => {
		stubFetch(streamResponse([
			'event: token\ndata: {"delta":"Hel"}\n\nevent: tok',
			'en\ndata: {"delta":"lo"}\r\n\r\nevent: tool_call\ndata: {"name":"t"}\n\n',
			'event: tool_result\ndata: {"ok":true}\n\nevent: heartbeat\ndata: {}\n\n',
			': comment only\n\nevent: token\ndata: not json\n\nevent: unknown\ndata: {}\n\n',
			'event: token\ndata: {}\n\nevent: final\ndata: {"fullText":"Hello"}',
		]))
		const seen = []
		const final = await chat.streamChatMessage(
			{ message: 'Q', agentUuid: 'a1' },
			{
				onToken: (d) => seen.push(['token', d]),
				onToolCall: (d) => seen.push(['call', d]),
				onToolResult: (d) => seen.push(['result', d]),
				onHeartbeat: () => seen.push(['beat']),
			},
		)
		expect(final).toEqual({ fullText: 'Hello' })
		expect(seen).toEqual([['token', 'Hel'], ['token', 'lo'], ['call', { name: 't' }], ['result', { ok: true }], ['beat'], ['token', '']])
		const [url, init] = fetchCalls[0]
		expect(url).toBe('/index.php/apps/hermiq/api/chat/stream')
		expect(init.method).toBe('POST')
		expect(init.headers.requesttoken).toBe('tok-1')
		expect(JSON.parse(init.body)).toEqual({ message: 'Q', agentUuid: 'a1' })
	})

	it('sends a continuing session under the conversationUuid key and tolerates missing handlers', async () => {
		stubFetch(streamResponse(['event: token\ndata: {"delta":"x"}\n\nevent: final\ndata: {"ok":1}\n\n']))
		expect(await chat.streamChatMessage({ message: 'Q', sessionUuid: 's1' })).toEqual({ ok: 1 })
		expect(JSON.parse(fetchCalls[0][1].body)).toEqual({ message: 'Q', conversationUuid: 's1' })
	})

	it('raises a server-side error, without fallback, on a terminal error event', async () => {
		stubFetch(streamResponse(['event: error\ndata: {"message":"No access","code":"forbidden"}\n\n']))
		const error = await chat.streamChatMessage({ message: 'Q' }).catch((e) => e)
		expect(error).toBeInstanceOf(chat.ChatStreamError)
		expect([error.message, error.transport, error.code, error.name]).toEqual(['No access', false, 'forbidden', 'ChatStreamError'])
	})

	it('falls back to defaults for an error event without message or code', async () => {
		stubFetch(streamResponse(['event: error\ndata: {}\n\n']))
		const error = await chat.streamChatMessage({ message: 'Q' }).catch((e) => e)
		expect([error.message, error.transport, error.code]).toEqual(['The chat turn failed', false, ''])
	})

	it.each([
		['a stream without a terminal event', () => streamResponse(['event: token\ndata: {"delta":"x"}\n\n']), 'Stream ended without a terminal event'],
		['a non-OK handshake', () => streamResponse([], { ok: false, status: 502 }), 'Stream endpoint returned HTTP 502'],
		['a handshake without a body', () => streamResponse([], { body: null }), 'Stream endpoint returned HTTP 200'],
		['a refused connection', () => () => { throw new Error('offline') }, 'offline'],
		['a refused connection without a message', () => () => { throw {} }, 'Stream connection failed'],
		['a dropped connection', () => ({ ok: true, body: { getReader: () => ({ read: async () => { throw new Error('reset') } }) } }), 'reset'],
		['a dropped connection without a message', () => ({ ok: true, body: { getReader: () => ({ read: async () => { throw {} } }) } }), 'Stream connection dropped'],
	])('raises a transport error for %s, so the caller can retry over POST', async (_label, make, message) => {
		stubFetch(make())
		const error = await chat.streamChatMessage({ message: 'Q' }).catch((e) => e)
		expect(error).toBeInstanceOf(chat.ChatStreamError)
		expect([error.message, error.transport]).toEqual([message, true])
	})
})
