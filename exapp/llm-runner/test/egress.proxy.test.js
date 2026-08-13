/**
 * Governed egress-proxy tests (cli-runner-governed-mcp-and-egress, Task 8).
 *
 * The proxy is the container's ONLY route out, so the property that matters most
 * is not "it forwards traffic" — it is "it refuses to, unless Hermiq's PDP said
 * yes". These tests therefore spend most of their effort on the deny paths:
 *
 *   - a verdict of `allowed: true` is the ONLY thing that opens a tunnel;
 *   - an unreachable / erroring / timing-out / unparseable PDP DENIES
 *     (fail-closed — an egress proxy that fails open is not a control);
 *   - a CONNECT with no run token opens nothing and never reaches the PDP — it is
 *     answered with a 407 CHALLENGE rather than a flat 403, because a client
 *     using `CURLAUTH_ANY` (which is what git does) waits for that challenge
 *     before sending the credential it already holds, and a 403 told it there
 *     was nothing to send;
 *   - the run token is forwarded to the PDP as a bearer token, and the PDP is
 *     asked about the EXACT host:port requested;
 *   - a non-allowlisted host is denied at the network layer even though the CLI
 *     flags are not involved at all — proving the backstop does not depend on
 *     them (a built-in that arrives un-denied in a future CLI still cannot reach
 *     a host policy forbids).
 *
 * Run: `node --test test/egress.proxy.test.js`.
 *
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @copyright 2026 Conduction B.V.
 */

'use strict'

const test = require('node:test')
const assert = require('node:assert')
const http = require('http')
const net = require('net')

/**
 * Start a fake PDP that answers with the given handler.
 *
 * @param {Function} handler (req, res, body) => void
 * @returns {Promise<{url: string, close: Function, calls: Array}>} The fake PDP.
 */
function startFakePdp(handler) {
	const calls = []
	const server = http.createServer((req, res) => {
		let raw = ''
		req.on('data', (c) => {
			raw += c
		})
		req.on('end', () => {
			calls.push({
				headers: req.headers,
				body: raw === '' ? {} : JSON.parse(raw),
			})
			handler(req, res, raw)
		})
	})
	return new Promise((resolve) => {
		server.listen(0, '127.0.0.1', () => {
			resolve({
				url: `http://127.0.0.1:${server.address().port}/authorize`,
				close: () => server.close(),
				calls,
			})
		})
	})
}

/**
 * Load a FRESH copy of proxy.js with the given env (its config is read at module
 * load, so the cache must be dropped between cases).
 *
 * @param {object} env Env vars to set for the load.
 * @returns {object} The proxy module.
 */
function loadProxy(env) {
	delete require.cache[require.resolve('../deploy/egress-proxy/proxy.js')]
	Object.assign(process.env, env)
	// eslint-disable-next-line global-require
	return require('../deploy/egress-proxy/proxy.js')
}

/**
 * Start the proxy's server on an ephemeral port.
 *
 * @param {object} mod The loaded proxy module.
 * @returns {Promise<{port: number, close: Function}>} The listening proxy.
 */
function listen(mod) {
	return new Promise((resolve) => {
		mod.server.listen(0, '127.0.0.1', () => {
			resolve({
				port: mod.server.address().port,
				close: () => mod.server.close(),
			})
		})
	})
}

/**
 * Issue a CONNECT through the proxy.
 *
 * @param {number} port Proxy port.
 * @param {string} target `host:port` to CONNECT to.
 * @param {string|null} token Run token, or null to omit Proxy-Authorization.
 * @returns {Promise<{status: number, denyCode: string, headers: object}>} The answer.
 */
function connectThrough(port, target, token) {
	return new Promise((resolve, reject) => {
		const headers = {}
		if (token !== null) {
			headers['Proxy-Authorization'] =
				`Basic ${Buffer.from(`run:${token}`).toString('base64')}`
		}
		const req = http.request({
			port,
			host: '127.0.0.1',
			method: 'CONNECT',
			path: target,
			headers,
		})
		req.on('connect', (res, socket) => {
			socket.destroy()
			resolve({
				status: res.statusCode,
				denyCode: res.headers['x-egress-deny-code'] || '',
				headers: res.headers,
			})
		})
		// A refused CONNECT arrives as a plain response, not a 'connect' event.
		req.on('response', (res) => {
			const denyCode = res.headers['x-egress-deny-code'] || ''
			const { headers } = res
			res.resume()
			resolve({ status: res.statusCode, denyCode, headers })
		})
		req.on('error', reject)
		req.end()
	})
}

test('a PDP verdict of allowed:true opens the tunnel', async () => {
	const upstream = net.createServer((s) => s.end())
	await new Promise((r) => upstream.listen(0, '127.0.0.1', r))
	const upstreamPort = upstream.address().port

	const pdp = await startFakePdp((req, res) => {
		res.writeHead(200, { 'Content-Type': 'application/json' })
		res.end(JSON.stringify({ allowed: true, code: 'allowed', message: '' }))
	})
	const proxy = await listen(loadProxy({ EGRESS_PDP_URL: pdp.url }))

	const out = await connectThrough(
		proxy.port,
		`127.0.0.1:${upstreamPort}`,
		'tok-123',
	)
	assert.strictEqual(out.status, 200, 'an allowed host must tunnel')

	proxy.close()
	pdp.close()
	upstream.close()
})

test('a PDP verdict of allowed:false denies (the non-allowlisted host case)', async () => {
	const pdp = await startFakePdp((req, res) => {
		res.writeHead(200, { 'Content-Type': 'application/json' })
		res.end(
			JSON.stringify({
				allowed: false,
				code: 'host_not_allowlisted',
				message: 'nope',
			}),
		)
	})
	const proxy = await listen(loadProxy({ EGRESS_PDP_URL: pdp.url }))

	const out = await connectThrough(proxy.port, 'evil.example.com:443', 'tok-123')
	assert.strictEqual(out.status, 403)
	assert.strictEqual(out.denyCode, 'host_not_allowlisted')

	proxy.close()
	pdp.close()
})

test('the backstop does not depend on the CLI flags — any client is denied the same', async () => {
	// No CLI, no --disallowedTools, no MCP: just a raw client (what a built-in
	// WebFetch, an auto-updater, or a compromised dependency looks like).
	const pdp = await startFakePdp((req, res) => {
		res.writeHead(200, { 'Content-Type': 'application/json' })
		res.end(
			JSON.stringify({
				allowed: false,
				code: 'host_not_allowlisted',
				message: 'nope',
			}),
		)
	})
	const proxy = await listen(loadProxy({ EGRESS_PDP_URL: pdp.url }))

	const out = await connectThrough(
		proxy.port,
		'telemetry.example.com:443',
		'tok-123',
	)
	assert.strictEqual(
		out.status,
		403,
		'the network layer denies regardless of how the CLI was invoked',
	)

	proxy.close()
	pdp.close()
})

test('an UNREACHABLE PDP denies (fail-closed)', async () => {
	// Port 1 on loopback: nothing listens, connection refused.
	const proxy = await listen(
		loadProxy({ EGRESS_PDP_URL: 'http://127.0.0.1:1/authorize' }),
	)

	const out = await connectThrough(proxy.port, 'api.anthropic.com:443', 'tok-123')
	assert.strictEqual(out.status, 403)
	assert.strictEqual(out.denyCode, 'pdp_unreachable')

	proxy.close()
})

test('an ERRORING PDP denies (fail-closed)', async () => {
	const pdp = await startFakePdp((req, res) => {
		res.writeHead(500)
		res.end('boom')
	})
	const proxy = await listen(loadProxy({ EGRESS_PDP_URL: pdp.url }))

	const out = await connectThrough(proxy.port, 'api.anthropic.com:443', 'tok-123')
	assert.strictEqual(out.status, 403)
	assert.strictEqual(out.denyCode, 'pdp_rejected')

	proxy.close()
	pdp.close()
})

test('a TIMING-OUT PDP denies (fail-closed)', async () => {
	const pdp = await startFakePdp(() => {
		/* never answers */
	})
	const proxy = await listen(
		loadProxy({ EGRESS_PDP_URL: pdp.url, EGRESS_PDP_TIMEOUT_MS: '150' }),
	)

	const out = await connectThrough(proxy.port, 'api.anthropic.com:443', 'tok-123')
	assert.strictEqual(out.status, 403)
	assert.strictEqual(out.denyCode, 'pdp_timeout')

	proxy.close()
	pdp.close()
})

test('an UNPARSEABLE PDP answer denies (fail-closed)', async () => {
	const pdp = await startFakePdp((req, res) => {
		res.writeHead(200, { 'Content-Type': 'application/json' })
		res.end('<html>not json</html>')
	})
	const proxy = await listen(loadProxy({ EGRESS_PDP_URL: pdp.url }))

	const out = await connectThrough(proxy.port, 'api.anthropic.com:443', 'tok-123')
	assert.strictEqual(out.status, 403)
	assert.strictEqual(out.denyCode, 'pdp_unparseable')

	proxy.close()
	pdp.close()
})

test('a TRUTHY-but-not-true allowed value denies (only a literal true permits)', async () => {
	const pdp = await startFakePdp((req, res) => {
		res.writeHead(200, { 'Content-Type': 'application/json' })
		res.end(JSON.stringify({ allowed: 'yes' }))
	})
	const proxy = await listen(loadProxy({ EGRESS_PDP_URL: pdp.url }))

	const out = await connectThrough(proxy.port, 'api.anthropic.com:443', 'tok-123')
	assert.strictEqual(
		out.status,
		403,
		'"yes" is truthy in JS — it must NOT be read as a permit',
	)

	proxy.close()
	pdp.close()
})

test('a CONNECT with no run token is CHALLENGED, opens nothing, and never reaches the PDP', async () => {
	// ⚠️ THIS ANSWER USED TO BE A 403, AND THAT IS WHY GIT COULD NOT GET OUT.
	//
	// `HTTPS_PROXY=http://run:<token>@proxy:3128` does not make every client
	// present the credential. curl's CLI defaults to Basic and sends it
	// preemptively; git sets libcurl's proxy auth to `CURLAUTH_ANY`, which waits
	// for a 407 challenge first. A 403 tells it there is nothing to offer, so it
	// never offers the token it already holds — and every clone through the
	// governed proxy failed `no_run_token`, forever.
	//
	// Measured 2026-08-02 in the jailed container, same proxy, same URL: curl
	// sent `Proxy-Authorization` and got 200; git sent none and got 403.
	//
	// This suite could not have caught it: `connectThrough()` sets the header
	// itself, so every case here exercised the authenticated path. The test
	// below is the one that crosses that line.
	//
	// 407 opens no tunnel, so default-deny is intact — it is a refusal that
	// names how to proceed rather than one that ends the conversation.
	const pdp = await startFakePdp((req, res) => {
		res.writeHead(200, { 'Content-Type': 'application/json' })
		res.end(JSON.stringify({ allowed: true }))
	})
	const proxy = await listen(loadProxy({ EGRESS_PDP_URL: pdp.url }))

	const out = await connectThrough(proxy.port, 'api.anthropic.com:443', null)
	assert.strictEqual(
		out.status,
		407,
		'an unauthenticated CONNECT must be challenged, not dismissed',
	)
	assert.strictEqual(
		out.headers['proxy-authenticate'],
		'Basic realm="hermiq-egress"',
		'without a Proxy-Authenticate header a CURLAUTH_ANY client never sends its credential',
	)
	assert.strictEqual(out.denyCode, 'no_run_token')
	assert.strictEqual(
		pdp.calls.length,
		0,
		'an anonymous CONNECT must not even reach the PDP',
	)

	proxy.close()
	pdp.close()
})

test('a client that waits for the challenge gets through on the retry', async () => {
	// The positive half. Asserting the 407 alone would certify a proxy that
	// challenges and then refuses the answer — which is indistinguishable from
	// the bug it replaces. So this drives the ACTUAL two-step exchange a
	// CURLAUTH_ANY client performs: CONNECT, take the challenge, CONNECT again
	// with the credential.
	const upstream = net.createServer((s) => s.end())
	await new Promise((r) => upstream.listen(0, '127.0.0.1', r))

	const pdp = await startFakePdp((req, res) => {
		res.writeHead(200, { 'Content-Type': 'application/json' })
		res.end(JSON.stringify({ allowed: true }))
	})
	const proxy = await listen(loadProxy({ EGRESS_PDP_URL: pdp.url }))
	const target = `127.0.0.1:${upstream.address().port}`

	const first = await connectThrough(proxy.port, target, null)
	assert.strictEqual(first.status, 407, 'step 1: challenged')
	assert.strictEqual(pdp.calls.length, 0, 'step 1 asks the PDP nothing')

	const second = await connectThrough(proxy.port, target, 'tok-123')
	assert.strictEqual(second.status, 200, 'step 2: the tunnel opens')
	assert.strictEqual(pdp.calls.length, 1, 'step 2 is the one the PDP decides')

	proxy.close()
	pdp.close()
	upstream.close()
})

test('the run token is forwarded as a bearer token and the EXACT host:port is asked about', async () => {
	const pdp = await startFakePdp((req, res) => {
		res.writeHead(200, { 'Content-Type': 'application/json' })
		res.end(JSON.stringify({ allowed: false, code: 'denied', message: '' }))
	})
	const proxy = await listen(loadProxy({ EGRESS_PDP_URL: pdp.url }))

	await connectThrough(proxy.port, 'api.anthropic.com:443', 'tok-abc')

	assert.strictEqual(pdp.calls.length, 1)
	assert.strictEqual(pdp.calls[0].headers.authorization, 'Bearer tok-abc')
	assert.deepStrictEqual(pdp.calls[0].body, {
		host: 'api.anthropic.com',
		port: 443,
	})

	proxy.close()
	pdp.close()
})

test('a malformed CONNECT target is denied', async () => {
	const pdp = await startFakePdp((req, res) => {
		res.writeHead(200, { 'Content-Type': 'application/json' })
		res.end(JSON.stringify({ allowed: true }))
	})
	const proxy = await listen(loadProxy({ EGRESS_PDP_URL: pdp.url }))

	const out = await connectThrough(proxy.port, 'no-port-here', 'tok-123')
	assert.strictEqual(out.status, 403)
	assert.strictEqual(out.denyCode, 'bad_target')

	proxy.close()
	pdp.close()
})

test('tokenFromProxyAuth keeps a token containing colons intact', () => {
	const mod = loadProxy({ EGRESS_PDP_URL: 'http://127.0.0.1:9/authorize' })
	const header = {
		'proxy-authorization': `Basic ${Buffer.from('run:a:b:c').toString('base64')}`,
	}
	assert.strictEqual(mod.tokenFromProxyAuth(header), 'a:b:c')
	assert.strictEqual(mod.tokenFromProxyAuth({}), '')
})

test('the runner builds a PER-RUN proxy URL carrying the token, in env only', () => {
	delete require.cache[require.resolve('../src/runner.js')]
	process.env.EGRESS_PROXY_AUTHORITY = 'egress-proxy:3128'
	// eslint-disable-next-line global-require
	const { buildEgressProxyEnv } = require('../src/runner.js')

	const env = buildEgressProxyEnv('tok-xyz')
	assert.strictEqual(env.HTTPS_PROXY, 'http://run:tok-xyz@egress-proxy:3128')
	assert.strictEqual(
		env.https_proxy,
		env.HTTPS_PROXY,
		'lowercase twin: some clients only read that one',
	)
	// NO_PROXY would be a hole in the only route out.
	assert.ok(
		!('NO_PROXY' in env) && !('no_proxy' in env),
		'no exemption list may be set',
	)

	// No token ⇒ no proxy env: the PDP would deny an identity-less run anyway,
	// and a URL without one would silently look like a working proxy.
	assert.deepStrictEqual(buildEgressProxyEnv(''), {})
	assert.deepStrictEqual(buildEgressProxyEnv(undefined), {})

	delete process.env.EGRESS_PROXY_AUTHORITY
	delete require.cache[require.resolve('../src/runner.js')]
})

test('with no proxy configured the runner injects nothing (Option A jail unchanged)', () => {
	delete require.cache[require.resolve('../src/runner.js')]
	delete process.env.EGRESS_PROXY_AUTHORITY
	// eslint-disable-next-line global-require
	const { buildEgressProxyEnv } = require('../src/runner.js')
	assert.deepStrictEqual(buildEgressProxyEnv('tok-xyz'), {})
	delete require.cache[require.resolve('../src/runner.js')]
})
