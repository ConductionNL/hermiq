/**
 * Golden test for the request ROUTER in `server.js`: which method and path
 * reach which handler, the status and body each answers with, and the log
 * line each one writes.
 *
 * It pins the routing table before it is split into named route handlers, so
 * the split can be shown to leave every route, every refusal and every log
 * line where it was. The `GET /stage` poll is covered in each job state,
 * because that branch carries its own logging and forgets collected jobs.
 *
 * Run: `node --test test/server.router.golden.test.js`.
 *
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @copyright 2026 Conduction B.V.
 */

'use strict'

// Set before the server module reads it at load time.
process.env.APP_SECRET = process.env.APP_SECRET || 'router-test-secret'
process.env.APP_ID = process.env.APP_ID || 'hermiq-llm-runner'

const test = require('node:test')
const assert = require('node:assert')
const http = require('http')

const { server } = require('../src/server')
const jobs = require('../src/jobs')

const AUTH_OK = Buffer.from(`admin:${process.env.APP_SECRET}`).toString('base64')
const SIGNED = {
	'EX-APP-ID': process.env.APP_ID,
	'AUTHORIZATION-APP-API': AUTH_OK,
}

let port = 0

test.before(async () => {
	await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve))
	port = server.address().port
})

test.after(async () => {
	await new Promise((resolve) => server.close(resolve))
})

/**
 * Send one request and capture the response plus every log line it wrote.
 *
 * @param {string} method The HTTP method.
 * @param {string} path The request path.
 * @param {object} [headers] Extra request headers.
 * @returns {Promise<{status: number, body: object, logs: string[]}>} The outcome.
 */
function send(method, path, headers = {}) {
	const logs = []
	const original = console.log
	console.log = (line) => logs.push(String(line))
	return new Promise((resolve, reject) => {
		const req = http.request(
			{ host: '127.0.0.1', port, method, path, headers },
			(res) => {
				const chunks = []
				res.on('data', (c) => chunks.push(c))
				res.on('end', () => {
					console.log = original
					resolve({
						status: res.statusCode,
						body: JSON.parse(Buffer.concat(chunks).toString('utf8')),
						logs,
					})
				})
			},
		)
		req.on('error', (err) => {
			console.log = original
			reject(err)
		})
		req.end()
	})
}

const LOG = (level, message) => `[hermiq-llm-runner] ${level}: ${message}`

test('GET /heartbeat answers ok with the job counts and no auth', async () => {
	const out = await send('GET', '/heartbeat')
	assert.strictEqual(out.status, 200)
	assert.deepStrictEqual(Object.keys(out.body), ['status', 'jobs'])
	assert.strictEqual(out.body.status, 'ok')
	assert.deepStrictEqual(out.logs, [])
})

test('the heartbeat route matches the exact path only', async () => {
	const out = await send('GET', '/heartbeat?x=1')
	assert.strictEqual(out.status, 404)
	assert.deepStrictEqual(out.body, { error: 'not found' })
})

test('PUT /enabled refuses an unsigned call', async () => {
	const out = await send('PUT', '/enabled?enabled=1')
	assert.strictEqual(out.status, 401)
	assert.deepStrictEqual(out.body, { error: 'unauthorised' })
	assert.deepStrictEqual(out.logs, [
		LOG('warn', '/enabled rejected: missing AppAPI authentication headers'),
	])
})

test('PUT /enabled answers an empty body for a signed call', async () => {
	const on = await send('PUT', '/enabled?enabled=1', SIGNED)
	assert.strictEqual(on.status, 200)
	assert.deepStrictEqual(on.body, {})
	assert.deepStrictEqual(on.logs, [LOG('info', '/enabled -> enabled')])

	const off = await send('PUT', '/enabled?enabled=0', SIGNED)
	assert.deepStrictEqual(off.logs, [LOG('info', '/enabled -> disabled')])
})

test('GET /stage refuses an unsigned poll', async () => {
	const out = await send('GET', '/stage?jobId=x')
	assert.strictEqual(out.status, 401)
	assert.deepStrictEqual(out.body, { error: 'unauthorised' })
	assert.deepStrictEqual(out.logs, [
		LOG('warn', '/stage poll rejected: missing AppAPI authentication headers'),
	])
})

test('GET /stage needs a jobId', async () => {
	for (const path of ['/stage', '/stage?jobId=']) {
		const out = await send('GET', path, SIGNED)
		assert.strictEqual(out.status, 400)
		assert.deepStrictEqual(out.body, { error: 'jobId is required' })
		assert.deepStrictEqual(out.logs, [])
	}
})

test('GET /stage reports an unknown job as terminal', async () => {
	const out = await send('GET', '/stage?jobId=never-started', SIGNED)
	assert.strictEqual(out.status, 200)
	assert.deepStrictEqual(out.body, { status: 'unknown' })
	assert.deepStrictEqual(out.logs, [
		LOG('warn', '/stage poll for unknown job=never-started — restarted runner, or a result that aged out'),
	])
})

test('GET /stage reports a running job without logging or forgetting it', async () => {
	jobs.start(new Promise(() => {}), 'router-running')
	const out = await send('GET', '/stage?jobId=router-running', SIGNED)
	assert.strictEqual(out.status, 200)
	assert.strictEqual(out.body.status, 'running')
	assert.deepStrictEqual(out.logs, [])
	assert.strictEqual(jobs.get('router-running').status, 'running')
	jobs.forget('router-running')
})

test('GET /stage collects a done job once, then forgets it', async () => {
	jobs.start(Promise.resolve({ exitCode: 3, output: 'o' }), 'router-done')
	await new Promise((resolve) => setImmediate(resolve))
	const out = await send('GET', '/stage?jobId=router-done', SIGNED)
	assert.strictEqual(out.status, 200)
	assert.deepStrictEqual(out.body, {
		status: 'done',
		result: { exitCode: 3, output: 'o' },
	})
	assert.deepStrictEqual(out.logs, [
		LOG('info', '/stage collected job=router-done exit=3'),
	])
	assert.strictEqual(jobs.get('router-done').status, 'unknown')
})

test('GET /stage collects a failed job once, with and without a code', async () => {
	const coded = new Error('push refused')
	coded.code = 'protected_branch'
	jobs.start(Promise.reject(coded), 'router-failed-coded')
	jobs.start(Promise.reject(new Error('clone failed')), 'router-failed')
	await new Promise((resolve) => setImmediate(resolve))

	const withCode = await send('GET', '/stage?jobId=router-failed-coded', SIGNED)
	assert.strictEqual(withCode.status, 200)
	assert.deepStrictEqual(withCode.body, {
		status: 'failed',
		error: 'push refused',
		code: 'protected_branch',
	})
	assert.deepStrictEqual(withCode.logs, [
		LOG('warn', '/stage collected job=router-failed-coded FAILED (protected_branch): push refused'),
	])

	const plain = await send('GET', '/stage?jobId=router-failed', SIGNED)
	assert.deepStrictEqual(plain.logs, [
		LOG('warn', '/stage collected job=router-failed FAILED: clone failed'),
	])
	assert.strictEqual(jobs.get('router-failed-coded').status, 'unknown')
	assert.strictEqual(jobs.get('router-failed').status, 'unknown')
})

test('POST /stage and POST /run reach their handlers, which refuse unsigned calls', async () => {
	for (const path of ['/stage', '/run']) {
		const out = await send('POST', path)
		assert.strictEqual(out.status, 401, path)
		assert.deepStrictEqual(out.body, { error: 'unauthorised' }, path)
	}
})

test('every other method and path is not found', async () => {
	const cases = [
		['POST', '/heartbeat'],
		['GET', '/enabled'],
		['DELETE', '/stage'],
		['GET', '/run'],
		['POST', '/run?x=1'],
		['POST', '/stage?x=1'],
		['GET', '/'],
	]
	for (const [method, path] of cases) {
		const out = await send(method, path)
		assert.strictEqual(out.status, 404, `${method} ${path}`)
		assert.deepStrictEqual(out.body, { error: 'not found' }, `${method} ${path}`)
		assert.deepStrictEqual(out.logs, [], `${method} ${path}`)
	}
})
