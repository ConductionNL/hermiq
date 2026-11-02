/**
 * Golden test for the `/stage` route handler, `handleStage()`.
 *
 * Sends generated requests to the handler with its stage workload replaced by a
 * recorder: missing and wrong credentials, bodies that are not JSON, empty or
 * `null`, every field the route forwards (and some it must not), synchronous
 * and asynchronous dispatch, and workloads that finish, finish with a push, or
 * fail with and without a refusal code. The status, the body, every log line
 * and every call that reached the workload are compared with a recorded
 * fixture. GOLDEN_RECORD=1 rewrites it.
 *
 * Run: `node --test test/stage.route.golden.test.js`.
 *
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @copyright 2026 Conduction B.V.
 */

'use strict'

process.env.APP_SECRET = process.env.APP_SECRET || 'route-golden-secret'
process.env.APP_ID = process.env.APP_ID || 'hermiq-llm-runner'

const test = require('node:test')
const assert = require('node:assert')
const fs = require('fs')
const Module = require('module')
const path = require('path')

const FIXTURE = path.join(__dirname, 'fixtures', 'stage-route-golden.json')
const CASES = 300

/**
 * A small seeded generator.
 *
 * @param {number} seed The seed.
 * @returns {Function} pick(list)
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

/**
 * Load `server.js` with its stage workload replaced by a recorder whose outcome
 * the current case decides.
 *
 * @param {object} state `{calls, outcome}`, read by the stub on every call.
 * @returns {object} The server module.
 */
function loadServer(state) {
	const stagePath = require.resolve('../src/stage')
	const serverPath = require.resolve('../src/server')
	delete require.cache[stagePath]
	delete require.cache[serverPath]

	const original = Module._load
	Module._load = function patched(request, parent, isMain) {
		if (parent && parent.filename === serverPath && request === './stage') {
			return {
				runStage: async (args) => {
					state.calls.push(args)
					const outcome = state.outcome
					if (outcome.throws !== undefined) {
						const err = new Error(outcome.throws)
						if (outcome.code !== undefined) {
							err.code = outcome.code
						}
						throw err
					}
					return {
						exitCode: outcome.exitCode,
						output: 'out',
						ref: args.ref,
						...outcome.extra,
					}
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

const AUTH_OK = Buffer.from(`admin:${process.env.APP_SECRET}`).toString('base64')
const AUTH_BAD = Buffer.from('admin:wrong').toString('base64')
const FIELD_VALUES = {
	repo: ['https://example.test/target', '', undefined, 7],
	ref: ['development', 'main', undefined],
	command: [['scripts/run-hydra-gates.sh', '--x'], [], 'sh', undefined],
	toolRepo: [undefined, 'https://example.test/tool', ''],
	toolRef: [undefined, 'main'],
	toolTarball: [undefined, 'dGFy', ''],
	collect: [undefined, ['a.json'], { a: 'a.json' }],
	forgeToken: [undefined, 'tok'],
	forgeUser: [undefined, 'x-access-token'],
	timeoutMs: [undefined, 1000, '5'],
	env: [undefined, { A: '1' }],
	runToken: [undefined, 'rt'],
	push: [undefined, undefined, null, 'yes', { branch: 'feature/1/x', issue: 1 }],
	credentialEnv: [undefined, { CLAUDE_CODE_OAUTH_TOKEN: 'oat' }],
	async: [undefined, undefined, true, 'true', false],
	jobKey: [undefined, 'key-1'],
	extra: [undefined, 'must not reach the workload'],
}
const OUTCOMES = [
	{ exitCode: 0 },
	{ exitCode: 3 },
	{ exitCode: 0, extra: { push: { pushed: true, branch: 'feature/1/x' } } },
	{ exitCode: 0, extra: { push: { pushed: false, branch: 'feature/1/x' } } },
	{ throws: 'clone failed' },
	{ throws: 'push refused', code: 'protected_branch' },
	{ throws: '', code: '' },
]

/**
 * One case's request and workload outcome.
 *
 * @param {number} index The case.
 * @returns {object} The case.
 */
function makeCase(index) {
	const pick = picker(index + 1)
	const fields = {}
	for (const [name, values] of Object.entries(FIELD_VALUES)) {
		const value = pick(values)
		if (value !== undefined) {
			fields[name] = value
		}
	}
	const body = pick([
		'json',
		'json',
		'json',
		'json',
		'json',
		'json',
		'broken',
		'empty',
		'null',
	])
	const raw = {
		json: JSON.stringify(fields),
		broken: '{nope',
		empty: '',
		null: 'null',
	}[body]
	return {
		auth: pick(['ok', 'ok', 'ok', 'ok', 'bad', 'none']),
		body,
		raw,
		fields,
		outcome: pick(OUTCOMES),
	}
}

test('every generated /stage request gives the recorded answer', async () => {
	const state = { calls: [], outcome: OUTCOMES[0] }
	const server = loadServer(state)
	const lines = []
	const originalLog = console.log
	const actual = []
	try {
		console.log = (line) => lines.push(String(line))
		for (let index = 0; index < CASES; index++) {
			const c = makeCase(index)
			state.calls = []
			state.outcome = c.outcome
			lines.length = 0
			const headers = { 'ex-app-id': process.env.APP_ID }
			if (c.auth !== 'none') {
				headers['authorization-app-api'] =
					c.auth === 'ok' ? AUTH_OK : AUTH_BAD
			}
			const res = {
				statusCode: 0,
				body: '',
				writeHead(status) {
					this.statusCode = status
				},
				setHeader() {},
				end(payload) {
					this.body = payload || ''
				},
			}
			let thrown = null
			try {
				await server.handleStage(
					{ headers, method: 'POST', url: '/stage' },
					res,
					Buffer.from(c.raw),
				)
			} catch (e) {
				// The type only: V8 words a destructuring error by its source text.
				thrown = e.name
			}
			const body = res.body.replace(
				/"jobId":"[0-9a-f-]{36}"/,
				'"jobId":"<UUID>"',
			)
			actual.push(
				JSON.parse(
					JSON.stringify({
						case: index,
						...c,
						status: res.statusCode,
						body,
						thrown,
						calls: state.calls,
						log: lines.map((line) =>
							line.replace(/job=[0-9a-f-]{36}/, 'job=<UUID>'),
						),
					}),
				),
			)
		}
	} finally {
		console.log = originalLog
	}

	if (process.env.GOLDEN_RECORD === '1') {
		fs.mkdirSync(path.dirname(FIXTURE), { recursive: true })
		fs.writeFileSync(FIXTURE, JSON.stringify(actual, null, 1) + '\n')
		return
	}

	const expected = JSON.parse(fs.readFileSync(FIXTURE, 'utf8'))
	assert.strictEqual(actual.length, expected.length)
	for (let index = 0; index < expected.length; index++) {
		assert.deepStrictEqual(actual[index], expected[index], `case ${index}`)
	}
})
