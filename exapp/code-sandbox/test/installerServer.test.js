// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The installer's HTTP surface and its egress proxy
// (tools-agent-workspace-and-installed-tools D4).

'use strict'

process.env.APP_SECRET = 'test-secret'
process.env.APP_ID = 'hermiq-code-sandbox'

const test = require('node:test')
const assert = require('node:assert/strict')
const http = require('http')
const net = require('net')
const { createServer } = require('../src/installerServer.js')
const proxy = require('../src/egressAllowlistProxy.js')

const authed = { 'EX-APP-ID': 'hermiq-code-sandbox', 'AUTHORIZATION-APP-API': Buffer.from('alice:test-secret').toString('base64'), 'Content-Type': 'application/json' }

/**
 * Listen on a free port.
 *
 * @param {http.Server} server The server.
 * @return {Promise<string>} The base URL.
 */
async function listen(server) {
	await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve))
	return 'http://127.0.0.1:' + server.address().port
}

test('an install needs the shared secret and passes only manager, package and version on', async () => {
	const seen = []
	const server = createServer({ install: async (request) => { seen.push(request); return { manager: request.manager, layer: { sha256: 'x' } } } })
	const base = await listen(server)
	try {
		const body = JSON.stringify({ manager: 'pip', package: 'csvkit', version: '2.1.0', index: 'https://evil.example' })
		assert.equal((await fetch(base + '/install', { method: 'POST', body })).status, 401)
		assert.deepEqual(seen, [])
		const res = await fetch(base + '/install', { method: 'POST', headers: authed, body })
		assert.equal(res.status, 200)
		assert.deepEqual(seen, [{ manager: 'pip', package: 'csvkit', version: '2.1.0' }])
		assert.equal((await fetch(base + '/heartbeat')).status, 200)
		assert.equal((await fetch(base + '/run', { method: 'POST', headers: authed, body: '{}' })).status, 404)
	} finally {
		server.close()
	}
})

test('a refused install answers its reason and status', async () => {
	const server = createServer({ install: async () => { const e = new Error('npm is not an allowed registry on this instance.'); e.status = 400; throw e } })
	const base = await listen(server)
	try {
		const res = await fetch(base + '/install', { method: 'POST', headers: authed, body: JSON.stringify({ manager: 'npm', package: 'cowsay' }) })
		assert.equal(res.status, 400)
		assert.equal((await res.json()).error, 'npm is not an allowed registry on this instance.')
	} finally {
		server.close()
	}
})

test('the proxy tunnels only to an exact allowlisted host and port', () => {
	const allowed = proxy.allowlist({})
	assert.deepEqual(allowed.hosts, ['pypi.org', 'files.pythonhosted.org', 'registry.npmjs.org'])
	assert.deepEqual(proxy.decide('pypi.org:443', allowed), { host: 'pypi.org', port: 443 })
	assert.deepEqual(proxy.decide('PyPI.org.:443', allowed), { host: 'pypi.org', port: 443 })
	for (const target of ['pypi.org:80', 'evil.pypi.org:443', 'pypi.org.evil.example:443', '10.0.0.1:443', '[::1]:443', 'pypi.org', 'pypi.org:443/x']) {
		assert.equal(proxy.decide(target, allowed), null, target)
	}
})

test('the proxy refuses a CONNECT to a host outside the list, and plain HTTP', async () => {
	const server = proxy.createProxy({ EGRESS_ALLOWED_HOSTS: 'registry.npmjs.org' })
	const base = await listen(server)
	const port = server.address().port
	try {
		const status = await new Promise((resolve, reject) => {
			const socket = net.connect(port, '127.0.0.1', () => socket.write('CONNECT example.org:443 HTTP/1.1\r\nHost: example.org:443\r\n\r\n'))
			socket.once('data', (chunk) => { resolve(chunk.toString().split(' ')[1]); socket.destroy() })
			socket.on('error', reject)
		})
		assert.equal(status, '403')
		assert.equal((await fetch(base + '/anything')).status, 403)
	} finally {
		server.close()
	}
})

test('the proxy tunnels to an allowlisted host', async () => {
	const target = http.createServer((req, res) => res.end('ok'))
	await listen(target)
	const server = proxy.createProxy({ EGRESS_ALLOWED_HOSTS: 'localhost', EGRESS_ALLOWED_PORTS: String(target.address().port) })
	await listen(server)
	try {
		const answer = await new Promise((resolve, reject) => {
			const socket = net.connect(server.address().port, '127.0.0.1', () => {
				socket.write(`CONNECT localhost:${target.address().port} HTTP/1.1\r\n\r\n`)
			})
			let text = ''
			socket.on('data', (chunk) => {
				text += chunk.toString()
				if (text.startsWith('HTTP/1.1 200') && !text.includes('ok')) {
					socket.write('GET / HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n')
				}
			})
			socket.on('end', () => resolve(text))
			socket.on('error', reject)
		})
		assert.match(answer, /^HTTP\/1\.1 200 Connection Established/)
		assert.match(answer, /ok$/)
	} finally {
		server.close()
		target.close()
	}
})
