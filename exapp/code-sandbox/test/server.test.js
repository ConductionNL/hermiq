// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The HTTP surface: AppAPI auth before any run, the run contract, and the probe.

'use strict'

process.env.APP_SECRET = 'test-secret'
process.env.APP_ID = 'hermiq-code-sandbox'

const test = require('node:test')
const assert = require('node:assert/strict')
const { createServer } = require('../src/server.js')

let server
let base

test.before(async () => {
	server = createServer()
	await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve))
	base = 'http://127.0.0.1:' + server.address().port
})

test.after(() => server.close())

const authed = { 'EX-APP-ID': 'hermiq-code-sandbox', 'AUTHORIZATION-APP-API': Buffer.from('alice:test-secret').toString('base64'), 'Content-Type': 'application/json' }

test('heartbeat answers without auth', async () => {
	const res = await fetch(base + '/heartbeat')
	assert.equal(res.status, 200)
	assert.equal((await res.json()).status, 'ok')
})

test('a run without the shared secret is refused before it runs', async () => {
	const res = await fetch(base + '/run', { method: 'POST', body: JSON.stringify({ language: 'python', code: 'open("/tmp/ran","w")' }) })
	assert.equal(res.status, 401)
	const wrong = await fetch(base + '/run', { method: 'POST', headers: { ...authed, 'AUTHORIZATION-APP-API': Buffer.from('alice:nope').toString('base64') }, body: '{}' })
	assert.equal(wrong.status, 403)
})

test('a run answers the run contract', async () => {
	const res = await fetch(base + '/run', { method: 'POST', headers: authed, body: JSON.stringify({ language: 'python', code: 'print(1 + 1)' }) })
	assert.equal(res.status, 200)
	const body = await res.json()
	assert.equal(body.exitCode, 0)
	assert.equal(body.stdout, '2\n')
	for (const key of ['stderr', 'durationMs', 'timedOut', 'files', 'limits']) {
		assert.ok(key in body, key)
	}
})

test('a bad or unparsable request is 400 with the reason', async () => {
	const bad = await fetch(base + '/run', { method: 'POST', headers: authed, body: JSON.stringify({ language: 'ruby', code: 'x' }) })
	assert.equal(bad.status, 400)
	assert.equal((await bad.json()).error, 'The sandbox runs python or node.')
	const junk = await fetch(base + '/run', { method: 'POST', headers: authed, body: '{nope' })
	assert.equal(junk.status, 400)
})

test('enabled needs auth; other routes are 404', async () => {
	assert.equal((await fetch(base + '/enabled?enabled=1', { method: 'PUT' })).status, 401)
	assert.equal((await fetch(base + '/enabled?enabled=1', { method: 'PUT', headers: authed })).status, 200)
	assert.equal((await fetch(base + '/init', { method: 'POST', headers: authed })).status, 404)
})

test('browser routes need the shared secret and answer 503 without a browser service', async () => {
	const body = JSON.stringify({ conversationId: 'c', url: 'https://example.org/' })
	assert.equal((await fetch(base + '/browser/open', { method: 'POST', body })).status, 401)
	const res = await fetch(base + '/browser/open', { method: 'POST', headers: authed, body })
	assert.equal(res.status, 503)
	assert.equal((await res.json()).code, 'browser_unavailable')
})

test('install needs the shared secret and answers 503 without an installer service', async () => {
	const body = JSON.stringify({ manager: 'pip', package: 'csvkit' })
	assert.equal((await fetch(base + '/install', { method: 'POST', body })).status, 401)
	const res = await fetch(base + '/install', { method: 'POST', headers: authed, body })
	assert.equal(res.status, 503)
	assert.equal((await res.json()).code, 'installer_unavailable')
})
