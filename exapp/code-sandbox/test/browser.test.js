// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

// The browser service (tools-browser-and-computer-use tasks 1 and 3): the
// egress gate against a PDP stub, the per-conversation contexts and their idle
// close, the snapshot, and the password refusal. Chromium itself is stubbed.

'use strict'

process.env.APP_SECRET = 'test-secret'
process.env.APP_ID = 'hermiq-code-sandbox'

const test = require('node:test')
const assert = require('node:assert/strict')
const http = require('http')
const {
	BrowserSessions,
	PASSWORD_REFUSAL,
	SNAPSHOT_MAX_BYTES,
	destinationOf,
	isCredentialField,
	pdpEgress,
	snapshotOf,
} = require('../src/browser.js')
const { createBrowserServer } = require('../src/browserServer.js')

const FORM = [
	{ role: { value: 'RootWebArea' }, name: { value: 'Parkeervergunning aanvragen' } },
	{ role: { value: 'heading' }, name: { value: 'Parkeervergunning aanvragen' } },
	{ role: { value: 'textbox' }, name: { value: 'Kenteken' }, backendDOMNodeId: 11 },
	{ role: { value: 'textbox' }, name: { value: 'Wachtwoord' }, backendDOMNodeId: 12 },
	{ role: { value: 'button' }, name: { value: 'Aanvraag indienen' }, backendDOMNodeId: 13 },
	{ role: { value: 'button' }, name: { value: 'Hidden' }, backendDOMNodeId: 14, ignored: true },
]

/**
 * A stub Chromium: records what it was asked and asks the gate like the CDP driver does.
 *
 * @return {object}
 */
function stubDriver() {
	const driver = { pages: [], connections: [] }
	driver.openPage = async ({ gate, proxyToken }) => {
		const page = {
			closed: false,
			current: 'about:blank',
			filled: [],
			clicked: [],
			proxyToken,
			async navigate(url) {
				// Every request the page makes passes the gate first.
				for (const request of [url, 'http://10.0.0.5/tracker.js']) {
					const verdict = await gate(request)
					if (verdict.allowed) {
						driver.connections.push(request)
					}
				}
				page.current = url
			},
			async axTree() {
				return FORM
			},
			async click(node) {
				page.clicked.push(node)
			},
			async field(node) {
				return node === 12 ? { type: 'password', autocomplete: '' } : { type: 'text', autocomplete: 'off' }
			},
			async fill(node, text) {
				page.filled.push([node, text])
			},
			async url() {
				return page.current
			},
			async title() {
				return 'Parkeervergunning aanvragen'
			},
			async close() {
				page.closed = true
			},
		}
		driver.pages.push(page)
		return page
	}
	return driver
}

/**
 * An egress check that allows public hosts with a valid token, like the PDP.
 *
 * @param {Array} asked Records each question.
 * @return {Function}
 */
function stubEgress(asked) {
	return async (url, token) => {
		asked.push([url, token])
		const destination = destinationOf(url)
		if (token !== 'run-token' || destination === null || /^10\./.test(destination.host)) {
			return { allowed: false, code: 'private_address', message: 'private or reserved address' }
		}
		return { allowed: true, code: 'allowed', message: '' }
	}
}

test('a page on a private address is never opened', async () => {
	const driver = stubDriver()
	const asked = []
	const sessions = new BrowserSessions({ driver, egress: stubEgress(asked) })

	await assert.rejects(sessions.open('conv-1', 'http://10.0.0.5/admin', 'run-token'), (error) => {
		assert.equal(error.status, 403)
		assert.match(error.message, /^The address is not allowed/)
		return true
	})
	assert.equal(driver.pages.length, 0)
	assert.deepEqual(driver.connections, [])
})

test('every request of an opened page passes the gate, with the run token of the call', async () => {
	const driver = stubDriver()
	const asked = []
	const sessions = new BrowserSessions({ driver, egress: stubEgress(asked) })

	const result = await sessions.open('conv-1', 'https://www.gemeente.nl/parkeren', 'run-token')

	assert.equal(result.url, 'https://www.gemeente.nl/parkeren')
	assert.deepEqual(driver.connections, ['https://www.gemeente.nl/parkeren'])
	assert.deepEqual(asked.map(([url]) => url), ['https://www.gemeente.nl/parkeren', 'https://www.gemeente.nl/parkeren', 'http://10.0.0.5/tracker.js'])
	assert.ok(asked.every(([, token]) => token === 'run-token'))
	assert.equal(driver.pages[0].proxyToken(), 'run-token')
})

test('the snapshot tags actionable elements and skips ignored ones', () => {
	const snapshot = snapshotOf(FORM)

	assert.equal(snapshot.text, '# Parkeervergunning aanvragen\n[e1] textbox "Kenteken"\n[e2] textbox "Wachtwoord"\n[e3] button "Aanvraag indienen"')
	assert.deepEqual(snapshot.refs, { e1: 11, e2: 12, e3: 13 })
	assert.equal(snapshot.truncated, false)
})

test('the snapshot is cut to 20 KB without splitting a character', () => {
	const nodes = Array.from({ length: 3000 }, (_, i) => ({ role: { value: 'StaticText' }, name: { value: `Regel ${i} met één accent` } }))
	const snapshot = snapshotOf(nodes)

	assert.ok(Buffer.byteLength(snapshot.text, 'utf8') <= SNAPSHOT_MAX_BYTES)
	assert.equal(snapshot.truncated, true)
	assert.doesNotMatch(snapshot.text, /�/)
})

test('a click goes to the referenced element and an unknown reference is refused', async () => {
	const driver = stubDriver()
	const sessions = new BrowserSessions({ driver, egress: stubEgress([]) })
	await sessions.open('conv-1', 'https://www.gemeente.nl/parkeren', 'run-token')

	await sessions.click('conv-1', 'e3', 'run-token')
	assert.deepEqual(driver.pages[0].clicked, [13])

	await assert.rejects(sessions.click('conv-1', 'e99', 'run-token'), { code: 'unknown_ref' })
})

test('a password field is refused and nothing is typed', async () => {
	const driver = stubDriver()
	const sessions = new BrowserSessions({ driver, egress: stubEgress([]) })
	await sessions.open('conv-1', 'https://www.gemeente.nl/inloggen', 'run-token')

	await assert.rejects(sessions.fill('conv-1', 'e2', 'geheim', 'run-token'), (error) => {
		assert.equal(error.code, 'password_field')
		assert.equal(error.message, PASSWORD_REFUSAL)
		return true
	})
	assert.deepEqual(driver.pages[0].filled, [])

	await sessions.fill('conv-1', 'e1', 'AB-123-C', 'run-token')
	assert.deepEqual(driver.pages[0].filled, [[11, 'AB-123-C']])
})

test('credential fields are recognised by type and by autocomplete', () => {
	assert.equal(isCredentialField({ type: 'PASSWORD' }), true)
	assert.equal(isCredentialField({ type: 'text', autocomplete: 'section-login current-password' }), true)
	assert.equal(isCredentialField({ type: 'text', autocomplete: 'one-time-code' }), true)
	assert.equal(isCredentialField({ type: 'text', autocomplete: 'postal-code' }), false)
	assert.equal(isCredentialField({}), false)
})

test('reading or acting before a page is open is refused', async () => {
	const sessions = new BrowserSessions({ driver: stubDriver(), egress: stubEgress([]) })

	await assert.rejects(sessions.read('conv-1', 'run-token'), { code: 'no_page', status: 409 })
	await assert.rejects(sessions.open('', 'https://www.gemeente.nl/', 'run-token'), { code: 'invalid_argument' })
})

test('each conversation has its own context, closed when it ends or after 10 idle minutes', async () => {
	let now = 0
	const driver = stubDriver()
	const sessions = new BrowserSessions({ driver, egress: stubEgress([]), now: () => now })

	await sessions.open('conv-1', 'https://www.gemeente.nl/a', 'run-token')
	await sessions.open('conv-2', 'https://www.gemeente.nl/b', 'run-token')
	assert.equal(driver.pages.length, 2)

	now = 9 * 60 * 1000
	await sessions.read('conv-2', 'run-token')
	now = 10 * 60 * 1000
	assert.equal(await sessions.sweep(), 1)
	assert.equal(driver.pages[0].closed, true)
	assert.equal(driver.pages[1].closed, false)

	assert.equal(await sessions.close('conv-2'), true)
	assert.equal(driver.pages[1].closed, true)
	assert.equal(await sessions.close('conv-2'), false)
})

/**
 * A PDP stub answering with the given status and body.
 *
 * @param {number} status The status.
 * @param {string} body The body.
 * @param {Array} seen Records each request.
 * @return {Promise<{url: string, close: Function}>}
 */
async function pdpStub(status, body, seen) {
	const server = http.createServer((req, res) => {
		let raw = ''
		req.on('data', (chunk) => {
			raw += chunk
		})
		req.on('end', () => {
			seen.push({ authorization: req.headers.authorization, body: JSON.parse(raw) })
			res.writeHead(status, { 'Content-Type': 'application/json' })
			res.end(body)
		})
	})
	await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve))
	return { url: `http://127.0.0.1:${server.address().port}/apps/hermiq/api/egress/authorize`, close: () => server.close() }
}

test('the PDP is asked with the run token and host:port, and only allowed:true permits', async () => {
	const seen = []
	const allow = await pdpStub(200, '{"allowed":true,"code":"allowed"}', seen)
	const deny = await pdpStub(200, '{"allowed":false,"code":"private_address","message":"private or reserved address"}', [])
	const broken = await pdpStub(500, 'oops', [])
	try {
		assert.equal((await pdpEgress({ pdpUrl: allow.url })('https://example.org/x', 'tok')).allowed, true)
		assert.deepEqual(seen, [{ authorization: 'Bearer tok', body: { host: 'example.org', port: 443 } }])

		const denied = await pdpEgress({ pdpUrl: deny.url })('http://10.0.0.5/admin', 'tok')
		assert.deepEqual(denied, { allowed: false, code: 'private_address', message: 'private or reserved address' })

		assert.equal((await pdpEgress({ pdpUrl: broken.url })('https://example.org/', 'tok')).allowed, false)
		assert.equal((await pdpEgress({ pdpUrl: allow.url })('https://example.org/', '')).code, 'no_run_token')
		assert.equal((await pdpEgress({ pdpUrl: allow.url })('file:///etc/passwd', 'tok')).code, 'scheme_not_allowed')
		assert.equal((await pdpEgress({ pdpUrl: 'http://127.0.0.1:1/' })('https://example.org/', 'tok')).allowed, false)
	} finally {
		allow.close()
		deny.close()
		broken.close()
	}
})

test('the browser server refuses a request without the shared secret and answers refusals as JSON', async () => {
	const sessions = new BrowserSessions({ driver: stubDriver(), egress: stubEgress([]) })
	const server = createBrowserServer(sessions)
	await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve))
	const base = `http://127.0.0.1:${server.address().port}`
	const authed = { 'EX-APP-ID': 'hermiq-code-sandbox', 'AUTHORIZATION-APP-API': Buffer.from('alice:test-secret').toString('base64'), 'Content-Type': 'application/json' }
	try {
		const open = JSON.stringify({ conversationId: 'c', runToken: 'run-token', url: 'https://www.gemeente.nl/' })
		assert.equal((await fetch(`${base}/browser/open`, { method: 'POST', body: open })).status, 401)

		const ok = await fetch(`${base}/browser/open`, { method: 'POST', headers: authed, body: open })
		assert.equal(ok.status, 200)
		assert.match((await ok.json()).snapshot, /\[e3\] button "Aanvraag indienen"/)

		const refused = await fetch(`${base}/browser/fill`, { method: 'POST', headers: authed, body: JSON.stringify({ conversationId: 'c', runToken: 'run-token', ref: 'e2', text: 'x' }) })
		assert.equal(refused.status, 422)
		assert.deepEqual(await refused.json(), { error: PASSWORD_REFUSAL, code: 'password_field' })

		assert.equal((await fetch(`${base}/browser/nope`, { method: 'POST', headers: authed, body: '{}' })).status, 404)
	} finally {
		server.close()
	}
})
