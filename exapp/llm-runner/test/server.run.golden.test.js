/**
 * Golden test for the `/run` handler in `server.js`: every refusal it answers
 * before a turn runs, the arguments a runnable turn hands to `run()`, and the
 * answer and log line for a turn that succeeds or fails.
 *
 * The CLI workload is replaced by a recorder, so the question here is what the
 * handler does with a body, not what a CLI does with a turn — `test.sh` covers
 * the second half against stub CLIs.
 *
 * Run: `node --test test/server.run.golden.test.js`.
 *
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @copyright 2026 Conduction B.V.
 */

'use strict'

// Set before the server module reads it at load time.
process.env.APP_SECRET = process.env.APP_SECRET || 'run-test-secret'
process.env.APP_ID = process.env.APP_ID || 'hermiq-llm-runner'

const test = require('node:test')
const assert = require('node:assert')
const Module = require('module')

const state = { calls: [], outcome: null }

/**
 * Load `server.js` with its CLI workload replaced by a recorder.
 *
 * @returns {{handleRun: Function}} The server module's handler.
 */
function loadServerWithStubbedRunner() {
	const serverPath = require.resolve('../src/server')
	delete require.cache[serverPath]

	const original = Module._load
	Module._load = function patched(request, parent, isMain) {
		if (parent && parent.filename === serverPath && request === './runner') {
			return {
				run: async (args) => {
					state.calls.push(args)
					if (state.outcome.throws !== undefined) {
						throw new Error(state.outcome.throws)
					}
					return state.outcome.result
				},
			}
		}
		return original.apply(this, [request, parent, isMain])
	}

	try {
		return require('../src/server')
	} finally {
		Module._load = original
	}
}

const server = loadServerWithStubbedRunner()

/**
 * Drive the server's `/run` route with one body and capture what it did.
 *
 * @param {string} body The raw request body.
 * @param {object} outcome What the stubbed `run()` does.
 * @returns {Promise<{status: number, body: object, logs: string[], calls: Array}>} The outcome.
 */
async function post(body, outcome = { result: {} }) {
	state.calls = []
	state.outcome = outcome
	const logs = []
	const original = console.log
	console.log = (line) => logs.push(String(line))
	try {
		const reply = await new Promise((resolve, reject) => {
			const http = require('http')
			const listener = server.server.listen(0, '127.0.0.1', () => {
				const req = http.request(
					{
						host: '127.0.0.1',
						port: listener.address().port,
						method: 'POST',
						path: '/run',
						headers: {
							'EX-APP-ID': process.env.APP_ID,
							'AUTHORIZATION-APP-API': Buffer.from(`admin:${process.env.APP_SECRET}`).toString('base64'),
						},
					},
					(res) => {
						const chunks = []
						res.on('data', (c) => chunks.push(c))
						res.on('end', () => {
							listener.close(() => resolve({
								status: res.statusCode,
								body: JSON.parse(Buffer.concat(chunks).toString('utf8')),
							}))
						})
					},
				)
				req.on('error', reject)
				req.end(body)
			})
		})
		return { ...reply, logs, calls: state.calls }
	} finally {
		console.log = original
	}
}

const LOG = (level, message) => `[hermiq-llm-runner] ${level}: ${message}`
const TURN = { provider: 'anthropic', model: 'm1', messages: [{ role: 'user', content: 'hi' }] }

test('a body that is not JSON is answered 400 before any turn runs', async () => {
	const out = await post('{not json')
	assert.strictEqual(out.status, 400)
	assert.deepStrictEqual(out.body, { error: 'invalid JSON body' })
	assert.deepStrictEqual(out.calls, [])
	assert.deepStrictEqual(out.logs, [])
})

test('an empty body is an empty turn, refused for its provider', async () => {
	const out = await post('')
	assert.strictEqual(out.status, 400)
	assert.deepStrictEqual(out.body, { error: "unknown provider 'undefined'" })
	assert.deepStrictEqual(out.calls, [])
})

test('an unknown provider is refused before the messages are looked at', async () => {
	const out = await post(JSON.stringify({ provider: 'nope', messages: 'x' }))
	assert.strictEqual(out.status, 400)
	assert.deepStrictEqual(out.body, { error: "unknown provider 'nope'" })
	assert.deepStrictEqual(out.calls, [])
})

test('messages must be a non-empty array', async () => {
	for (const messages of [undefined, [], 'hi', { 0: 'a' }]) {
		const out = await post(JSON.stringify({ provider: 'anthropic', messages }))
		assert.strictEqual(out.status, 400)
		assert.deepStrictEqual(out.body, { error: 'messages must be a non-empty array' })
		assert.deepStrictEqual(out.calls, [])
	}
})

test('a JSON null body fails as an unreadable request', async () => {
	const out = await post('null')
	assert.strictEqual(out.status, 413)
	assert.deepStrictEqual(out.body, {
		error: "Cannot destructure property 'provider' of 'payload' as it is null.",
	})
	assert.deepStrictEqual(out.logs, [
		LOG('warn', "request error: Cannot destructure property 'provider' of 'payload' as it is null."),
	])
})

test('a runnable turn hands exactly its fields to run() and answers its result', async () => {
	const body = {
		...TURN,
		credentialEnv: { A: '1' },
		mcpConfig: { mcpServers: {} },
		runToken: 'rt',
		extra: 'not forwarded',
	}
	const out = await post(JSON.stringify(body), {
		result: { text: 't', toolCalls: [1], usage: { u: 1 }, other: 'dropped' },
	})
	assert.strictEqual(out.status, 200)
	assert.deepStrictEqual(out.body, { text: 't', toolCalls: [1], usage: { u: 1 } })
	assert.strictEqual(out.calls.length, 1)
	const { provider, ...rest } = out.calls[0]
	assert.strictEqual(provider.bin !== undefined, true)
	assert.deepStrictEqual(rest, {
		model: 'm1',
		messages: TURN.messages,
		credentialEnv: { A: '1' },
		mcpConfig: { mcpServers: {} },
		runToken: 'rt',
	})
	assert.deepStrictEqual(out.logs, [
		LOG('info', '/run provider=anthropic model=m1 messages=1 governed=yes'),
	])
})

test('a turn without model or MCP config logs the defaults', async () => {
	const out = await post(JSON.stringify({ provider: 'anthropic', messages: [1, 2] }))
	assert.strictEqual(out.status, 200)
	assert.deepStrictEqual(out.logs, [
		LOG('info', '/run provider=anthropic model=(default) messages=2 governed=no'),
	])
	const { provider, ...rest } = out.calls[0]
	assert.strictEqual(provider.bin !== undefined, true)
	assert.deepStrictEqual(rest, {
		model: undefined,
		messages: [1, 2],
		credentialEnv: undefined,
		mcpConfig: undefined,
		runToken: undefined,
	})
})

test('a turn that fails is answered 502 with the redacted message', async () => {
	const out = await post(JSON.stringify(TURN), { throws: 'cli exited 1' })
	assert.strictEqual(out.status, 502)
	assert.deepStrictEqual(out.body, { error: 'runner execution failed', detail: 'cli exited 1' })
	assert.deepStrictEqual(out.logs, [
		LOG('info', '/run provider=anthropic model=m1 messages=1 governed=no'),
		LOG('error', '/run failed: cli exited 1'),
	])
})
