/**
 * The `/stage` ROUTE, not the function behind it.
 *
 * This file exists because of a specific failure. `toolRepo` was added to the
 * caller and to `runStage()`, both were tested, and it still did not work: the
 * route's destructuring is an allowlist of fields, nobody added it there, and
 * NO test crossed that line — the stage tests call `runStage()` directly and the
 * PHP tests mock the transport away. The parameter existed on both sides of the
 * boundary and not in it.
 *
 * What made it expensive is the SYMPTOM. A dropped `toolRepo` means the command
 * is looked up in the target tree, so the failure is
 * `spawn scripts/... ENOENT` — a missing FILE, pointing at the clone rather
 * than at the route. Nothing about it suggests the field never arrived.
 *
 * So these tests assert what only a route test can: that every field the body
 * carries reaches the workload. They stub the workload and inspect what it was
 * handed, because the question here is transport, not behaviour.
 *
 * Run: `node --test test/stage.route.test.js`.
 *
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @copyright 2026 Conduction B.V.
 */

'use strict'

// Set before the server module reads it at load time.
process.env.APP_SECRET = process.env.APP_SECRET || 'route-test-secret'
process.env.APP_ID = process.env.APP_ID || 'hermiq-llm-runner'

const test = require('node:test')
const assert = require('node:assert')
const path = require('path')
const Module = require('module')

/**
 * Load `server.js` with its stage workload replaced by a recorder.
 *
 * Patching the module loader rather than the filesystem keeps this honest: the
 * server is required exactly as it is in production, and only its dependency is
 * swapped.
 *
 * @returns {{handleStage: Function, calls: Array}} The captured calls.
 */
function loadServerWithStubbedStage() {
	const stagePath = require.resolve('../src/stage')
	const serverPath = require.resolve('../src/server')
	const calls = []

	delete require.cache[stagePath]
	delete require.cache[serverPath]

	const original = Module._load
	Module._load = function patched(request, parent, isMain) {
		if (parent && parent.filename === serverPath && request === './stage') {
			return {
				runStage: async (args) => {
					calls.push(args)

					return { exitCode: 0, output: '', ref: args.ref }
				},
			}
		}

		return original.apply(this, [request, parent, isMain])
	}

	let server
	try {
		server = require('../src/server')
	} finally {
		Module._load = original
		delete require.cache[serverPath]
		delete require.cache[stagePath]
	}

	return { server, calls }
}

test('every field the body carries reaches the workload', async () => {
	const { server, calls } = loadServerWithStubbedStage()

	// No conditional skip. An earlier version of this test returned early when
	// the handler was not exported, which made it pass while asserting nothing —
	// the same shape of false coverage that let the dropped field through in the
	// first place. If the seam is not reachable, this test must FAIL and say so.
	assert.strictEqual(
		typeof server.handleStage,
		'function',
		'handleStage is not exported, so the route boundary cannot be tested at all',
	)

	const body = Buffer.from(
		JSON.stringify({
			repo: 'https://example.test/target',
			ref: 'development',
			toolRepo: 'https://example.test/tool',
			toolRef: 'main',
			command: ['scripts/run-hydra-gates.sh', '--scope-to-diff'],
			timeoutMs: 1000,
			// The two fields that decide whether a stage may WRITE and whether it
			// has an identity to get out with. A route that dropped `push` would
			// silently downgrade a builder to a read-only gating stage, which looks
			// like a stage that simply found nothing to do; a route that dropped
			// `runToken` would leave the clone with no per-run identity and the
			// governed proxy would deny it `no_run_token`.
			runToken: 'run-token-not-a-secret-in-this-test',
			// The model credential. A route that dropped this would leave a
			// build stage's `claude` with no token, and the CLI's own error
			// ("no credential") points at the sidecar rather than at the route
			// that swallowed it.
			credentialEnv: { CLAUDE_CODE_OAUTH_TOKEN: 'oat-not-a-real-token' },
			push: {
				branch: 'feature/493/x',
				issue: 493,
				scope: ['lib'],
				allowedRepo: 'https://example.test/target',
			},
		}),
	)

	// Auth runs FIRST, before the body is parsed and long before anything is
	// cloned — so an unauthenticated request never reaches the workload and
	// this test would assert nothing. AppAPI 34's scheme is
	// base64(`userId:secret`), verified against APP_SECRET.
	const headers = {
		'ex-app-id': process.env.APP_ID,
		'authorization-app-api': Buffer.from(
			`admin:${process.env.APP_SECRET}`,
		).toString('base64'),
	}

	const res = {
		statusCode: 0,
		body: '',
		writeHead(status) {
			this.statusCode = status
		},
		setHeader() {},
		end(b) {
			this.body = b
		},
	}
	await server.handleStage({ headers, method: 'POST', url: '/stage' }, res, body)

	assert.strictEqual(
		res.statusCode,
		200,
		`route rejected the request: ${res.body}`,
	)

	assert.strictEqual(calls.length, 1, 'the workload was not reached')
	const got = calls[0]

	// The four that a destructuring filter can silently drop.
	assert.strictEqual(got.repo, 'https://example.test/target')
	assert.strictEqual(got.ref, 'development')
	assert.strictEqual(
		got.toolRepo,
		'https://example.test/tool',
		'toolRepo was dropped by the route',
	)
	assert.strictEqual(got.toolRef, 'main', 'toolRef was dropped by the route')
	assert.deepStrictEqual(got.command, [
		'scripts/run-hydra-gates.sh',
		'--scope-to-diff',
	])
	assert.strictEqual(
		got.runToken,
		'run-token-not-a-secret-in-this-test',
		'runToken was dropped by the route',
	)
	assert.deepStrictEqual(
		got.credentialEnv,
		{ CLAUDE_CODE_OAUTH_TOKEN: 'oat-not-a-real-token' },
		'credentialEnv was dropped by the route',
	)
	assert.deepStrictEqual(
		got.push,
		{
			branch: 'feature/493/x',
			issue: 493,
			scope: ['lib'],
			allowedRepo: 'https://example.test/target',
		},
		'push was dropped or reshaped by the route',
	)
})

test('the route forwards exactly the fields the workload accepts, and no more', () => {
	// A structural check that needs no HTTP: the two lists must agree, so a
	// field added to one and not the other is caught even when no test happens
	// to exercise it. This is the generalisation of the bug — the specific
	// field was `toolRepo`, the class is "the boundary has its own list".
	const fs = require('fs')
	const serverSrc = fs.readFileSync(
		path.join(__dirname, '..', 'src', 'server.js'),
		'utf8',
	)
	const stageSrc = fs.readFileSync(
		path.join(__dirname, '..', 'src', 'stage.js'),
		'utf8',
	)

	const accepted = (stageSrc.match(/async function runStage\(\{([^}]*)\}/)
		|| [])[1]
	assert.ok(accepted, 'could not read runStage signature')

	const fields = accepted
		.split(',')
		.map((s) => s.trim())
		.filter(Boolean)
	assert.ok(fields.length >= 8, 'runStage signature looks unexpectedly small')

	for (const field of fields) {
		assert.ok(
			new RegExp(`\\b${field}\\b`).test(serverSrc),
			`runStage accepts "${field}" but the /stage route never mentions it — `
				+ 'the route destructures a fixed list, so an unmentioned field is silently dropped',
		)
	}
})

test('async: the route accepts a handle-returning dispatch and still reaches the workload', async () => {
	const { server, calls } = loadServerWithStubbedStage()

	const body = Buffer.from(
		JSON.stringify({
			repo: 'https://example.test/target',
			ref: 'development',
			toolRepo: 'https://example.test/tool',
			command: ['scripts/run-hydra-gates.sh'],
			async: true,
		}),
	)
	const headers = {
		'ex-app-id': process.env.APP_ID,
		'authorization-app-api': Buffer.from(
			`admin:${process.env.APP_SECRET}`,
		).toString('base64'),
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

	await server.handleStage({ headers, method: 'POST', url: '/stage' }, res, body)

	// 202, not 200. The request was ACCEPTED; the stage has not finished, and a
	// caller reading only the status must not be able to confuse the two.
	assert.strictEqual(res.statusCode, 202, 'an accepted async dispatch answers 202')

	const parsed = JSON.parse(res.body)
	assert.match(parsed.jobId, /^[0-9a-f-]{36}$/, 'a handle comes back to poll with')
	assert.strictEqual(parsed.status, 'running')

	// The whole point of the earlier route bug: a field that exists on both
	// sides of this boundary and not IN it is silently dropped. Async must not
	// become a path where the workload is never actually started.
	assert.strictEqual(
		calls.length,
		1,
		'the workload was dispatched by the ROUTE, not deferred to the first poll',
	)
	assert.strictEqual(calls[0].repo, 'https://example.test/target')
	assert.strictEqual(calls[0].toolRepo, 'https://example.test/tool')
})
