#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// chat-transport-golden.spec.js — golden test for sendChatMessage() and
// streamChatMessage() in src/api/chat.js.
//
// Runs both senders over generated turns (message, session or agent, views,
// tools, RAG settings, attachments) and, for the stream, generated server
// answers: SSE frames of every event type split over chunk boundaries, CRLF
// and LF framing, frames without data or with broken JSON, a trailing frame
// without a blank line, terminal `final` or `error`, no terminal event, a
// refused handshake, a non-OK status and a reader that drops mid-stream.
// The request each sender made, every handler call and the result or error
// are compared with a recorded fixture. GOLDEN_RECORD=1 rewrites it.
//
// Usage:
//   node tests/chat-transport-golden.spec.js
//
// Exit codes:
//   0 — the senders match the fixture.
//   1 — a case differs.

'use strict'

const assert = require('assert')
const fs = require('fs')
const { register } = require('module')
const path = require('path')
const { pathToFileURL } = require('url')

const ROOT = path.resolve(__dirname, '..')
const FIXTURE = path.join(__dirname, 'Fixtures', 'Golden', 'chat-transport.json')
const CASES = 400

// The Nextcloud modules chat.js imports, replaced by stubs that report to the test.
const STUBS = {
	'@nextcloud/auth':
		'export const getRequestToken = () => globalThis.__golden.token',
	'@nextcloud/axios':
		'export default { post: (...args) => globalThis.__golden.axiosPost(...args), get: () => ({}), put: () => ({}), delete: () => ({}) }',
	'@nextcloud/router':
		'export const generateUrl = (p) => "URL" + p; export const generateOcsUrl = (p) => "OCS" + p',
}
const HOOKS = `
const STUBS = ${JSON.stringify(STUBS)}
export async function resolve(specifier, context, next) {
	if (Object.hasOwn(STUBS, specifier)) {
		return { url: 'data:text/javascript,' + encodeURIComponent(STUBS[specifier]), shortCircuit: true }
	}
	return next(specifier, context)
}
`
register('data:text/javascript,' + encodeURIComponent(HOOKS))

/**
 * A small seeded generator.
 *
 * @param {number} seed The seed.
 * @return {Function} pick(list)
 */
function picker(seed) {
	let state = seed * 0x9e3779b9
	return (list) => {
		state = (state + 0x6d2b79f5) | 0
		let t = Math.imul(state ^ (state >>> 15), 1 | state)
		t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t
		return list[
			Math.floor((((t ^ (t >>> 14)) >>> 0) / 4294967296) * list.length)
		]
	}
}

const ATTACHMENTS = [undefined, [], [{ fileId: 4, origin: 'files' }], 'not a list']
const LISTS = [undefined, [], ['v1', 'v2'], 'x']
const RAG = [
	undefined,
	null,
	'flags',
	{},
	{
		includeObjects: true,
		includeFiles: false,
		numSourcesFiles: 3,
		numSourcesObjects: 0,
	},
	{ includeFiles: true, extra: 1 },
	{ includeObjects: undefined, numSourcesObjects: 9 },
]
const FRAMES = [
	'event: token\ndata: {"delta":"Hel"}',
	'event: token\r\ndata: {"delta":""}',
	'event: token\ndata: {}',
	'event: tool_call\ndata: {"name":"lookup","id":1}',
	'event: tool_result\ndata: {"id":1,"ok":true}',
	'event: heartbeat\ndata: {}',
	'event: other\ndata: {"x":1}',
	'data: {"untyped":true}',
	'event: token',
	'event: token\ndata: {broken',
	'event: token\ndata: {"delta":\ndata: "multi"}',
	': comment line\nevent: heartbeat\ndata: {}',
]
const TERMINALS = [
	'event: final\ndata: {"messageId":"m1","fullText":"Hello"}',
	'event: error\ndata: {"message":"refused","code":"forbidden"}',
	'event: error\ndata: {}',
	'event: final\ndata: {"messageId":"m2"}',
	'',
]

/**
 * One case's turn, handlers and server answer.
 *
 * @param {number} index The case.
 * @return {object} The case.
 */
function makeCase(index) {
	const pick = picker(index + 1)
	const turn = {
		message: pick(['Hello', '', 'Twee woorden']),
		sessionUuid: pick([undefined, '', 's-1', 's-1']),
		agentUuid: pick([undefined, '', 'a-1']),
		views: pick(LISTS),
		tools: pick(LISTS),
		ragSettings: pick(RAG),
		attachments: pick(ATTACHMENTS),
	}
	for (const key of Object.keys(turn)) {
		if (turn[key] === undefined) {
			delete turn[key]
		}
	}
	const frames = []
	for (let i = pick([0, 1, 2, 3, 5, 8]); i > 0; i--) {
		frames.push(pick(FRAMES))
	}
	frames.push(pick(TERMINALS))
	if (pick([false, false, true])) {
		frames.push(pick(FRAMES))
	}
	return {
		turn,
		handlers: pick(['all', 'all', 'none', 'some']),
		token: pick(['tok', null, undefined]),
		fetch: pick([
			'ok',
			'ok',
			'ok',
			'ok',
			'ok',
			'throws',
			'throws-plain',
			'status',
			'nobody',
			'drops',
		]),
		newline: pick(['\n\n', '\r\n\r\n', '\n\r\n']),
		trailing: pick([true, false, false]),
		chunk: pick([1, 3, 7, 1000]),
		frames,
	}
}

/**
 * The SSE body of a case, as the chunks the reader returns.
 *
 * @param {object} c The case.
 * @return {Array<Uint8Array>} The chunks.
 */
function chunks(c) {
	let text = c.frames.join(c.newline)
	if (c.trailing === false) {
		text += c.newline
	}
	const bytes = new TextEncoder().encode(text)
	const out = []
	for (let i = 0; i < bytes.length; i += c.chunk) {
		out.push(bytes.slice(i, i + c.chunk))
	}
	return out
}

/**
 * Run one case through both senders and describe what happened.
 *
 * @param {object} chat The chat module.
 * @param {number} index The case.
 * @return {Promise<object>} The recorded shape.
 */
async function runCase(chat, index) {
	const c = makeCase(index)
	const calls = []
	globalThis.__golden = {
		token: c.token,
		axiosPost: (url, payload) => {
			calls.push(['axios.post', url, payload])
			return { data: { echoed: payload } }
		},
	}
	globalThis.fetch = async (url, init) => {
		calls.push(['fetch', url, init.method, init.headers, JSON.parse(init.body)])
		if (c.fetch === 'throws') {
			throw new Error('offline')
		}
		if (c.fetch === 'throws-plain') {
			throw 'no message'
		}
		if (c.fetch === 'status') {
			return { ok: false, status: 502, body: null }
		}
		if (c.fetch === 'nobody') {
			return { ok: true, status: 200, body: null }
		}
		const parts = chunks(c)
		let at = 0
		return {
			ok: true,
			status: 200,
			body: {
				getReader: () => ({
					read: async () => {
						if (
							c.fetch === 'drops'
							&& at === Math.floor(parts.length / 2)
						) {
							throw new Error('reset by peer')
						}
						if (at >= parts.length) {
							return { done: true, value: undefined }
						}
						return { done: false, value: parts[at++] }
					},
				}),
			},
		}
	}
	const handlers = {}
	if (c.handlers !== 'none') {
		handlers.onToken = (delta) => calls.push(['onToken', delta])
		handlers.onToolCall = (data) => calls.push(['onToolCall', data])
	}
	if (c.handlers === 'all') {
		handlers.onToolResult = (data) => calls.push(['onToolResult', data])
		handlers.onHeartbeat = (...args) => calls.push(['onHeartbeat', args.length])
	}

	const outcome = {}
	try {
		outcome.sent = await chat.sendChatMessage(c.turn)
	} catch (e) {
		outcome.sendError = String(e)
	}
	try {
		outcome.streamed = await chat.streamChatMessage(c.turn, handlers)
	} catch (e) {
		outcome.streamError = {
			name: e.name,
			message: e.message,
			transport: e.transport,
			code: e.code,
		}
	}
	return JSON.parse(
		JSON.stringify({ case: index, ...c, frames: c.frames, calls, outcome }),
	)
}

async function main() {
	const chat = await import(
		pathToFileURL(path.join(ROOT, 'src', 'api', 'chat.js'))
	)
	const actual = []
	for (let index = 0; index < CASES; index++) {
		actual.push(await runCase(chat, index))
	}

	if (process.env.GOLDEN_RECORD === '1') {
		fs.mkdirSync(path.dirname(FIXTURE), { recursive: true })
		fs.writeFileSync(FIXTURE, JSON.stringify(actual, null, 1) + '\n')
		console.log(`recorded ${actual.length} cases`)
		return
	}

	const expected = JSON.parse(fs.readFileSync(FIXTURE, 'utf8'))
	assert.strictEqual(actual.length, expected.length)
	let failed = 0
	for (let index = 0; index < expected.length; index++) {
		try {
			assert.deepStrictEqual(actual[index], expected[index])
		} catch (e) {
			failed++
			if (failed <= 3) {
				console.log(
					`not ok - case ${index}\n  ${e.message.split('\n').slice(0, 12).join('\n  ')}`,
				)
			}
		}
	}
	console.log(
		failed === 0
			? `ok - ${actual.length} cases match`
			: `${failed} case(s) differ`,
	)
	process.exitCode = failed === 0 ? 0 : 1
}

main().catch((e) => {
	console.log(`not ok - ${e.stack}`)
	process.exitCode = 1
})
