/**
 * hermiq-egress-proxy — the governed CONNECT proxy for the llm-runner sidecar.
 *
 * This is the Policy ENFORCEMENT Point (PEP). It terminates every outbound
 * connection the runner container attempts and asks Hermiq's Policy DECISION
 * Point (PDP, `POST /api/egress/authorize`) whether that exact `host:port` is
 * permitted for that exact run. It holds NO allowlist of its own — a second
 * allowlist would be a second policy, and the whole point of routing through
 * the PDP is that `WebResearchEgressGuard` stays the ONE source of truth for
 * both the agent's `webFetch` tool and the network backstop.
 *
 * Why a proxy at all, when the model is already limited to governed MCP tools:
 * the two layers answer different questions. The MCP grant governs what the
 * AGENT is authorized to do; this proxy governs what the CONTAINER can reach —
 * including traffic the agent never asked for (a CLI auto-update check, a
 * built-in web fetch that a future flag fails to disable, anything a compromised
 * dependency tries). The container has NO default route, so this is the only way
 * out, and a capability that arrives un-denied in a future CLI release still
 * cannot reach a host policy forbids.
 *
 * Hard rules:
 *   - DEFAULT DENY. `allowed: true` from the PDP is the ONLY permit signal.
 *     A PDP that is unreachable, slow, erroring, or returns anything unexpected
 *     results in a denial. An egress proxy that fails open is not a control.
 *   - The run token identifies the run; it arrives in `Proxy-Authorization` and
 *     is forwarded to the PDP as a bearer token. It is NEVER logged.
 *   - EVERY request is authorized the same way, whatever its shape. Two shapes
 *     arrive: `CONNECT host:port` (a TLS tunnel) and an absolute-form request
 *     line (`POST http://host/path`) from a client using an `http://` URL. Both
 *     ask the PDP about the same `{host, port}` with the same run token, and
 *     both open nothing until it answers `allowed: true`.
 *
 *     The absolute-form path was refused with a flat 405 until 2026-09-18, and
 *     that made the runner's OWN governance channel unreachable: Hermiq's MCP
 *     endpoint is an HTTP POST, so a governed tool call died on
 *     `this proxy serves CONNECT only` and the model answered as if it had no
 *     tools. The old rule read "plain HTTP proxying would let the PEP see and
 *     mutate cleartext traffic; there is no reason for it to." Seeing is not
 *     avoidable for a forward proxy carrying cleartext, and there is now a
 *     reason. Mutating is: this proxy copies the request through verbatim, and
 *     strips only the hop-by-hop headers RFC 9110 §7.6.1 forbids forwarding —
 *     above all `Proxy-Authorization`, which carries the run token and must
 *     never reach the origin. It reads no body, buffers no body, and logs
 *     nothing beyond `host:port`, exactly as the CONNECT path does.
 *
 * Known limitation, stated plainly: CONNECT gives host:port granularity, not
 * URL. The PDP therefore decides on the host, and any path on an allowed host is
 * reachable. Narrowing that would mean terminating TLS (a MITM with the CLI's
 * credentials in scope), which is a worse trade than the granularity it buys.
 *
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @copyright 2026 Conduction B.V.
 */

'use strict'

const http = require('http')
const net = require('net')
const { URL } = require('url')

const PORT = Number(process.env.PROXY_PORT || '3128')

// The PDP endpoint — Hermiq's governed egress decision point. Required: with no
// PDP configured there is no policy to enforce, and a proxy with no policy that
// still forwards traffic is an open relay. Fail at boot instead.
const PDP_URL = process.env.EGRESS_PDP_URL || ''

// How long to wait for a verdict. A slow PDP denies (see DEFAULT DENY above) —
// this bounds how long a caller waits to be told "no".
//
// It was 5000 ms. Measured 2026-09-18 on the live demo instance, that produced a
// stream of `DENY api.anthropic.com:443 (pdp_timeout)` in the middle of working
// turns, because the PDP is a Nextcloud request and this instance answered
// `status.php` in 4878 ms. The control was denying the provider for being slow,
// which surfaces to the operator as "the provider is down" and to the model as a
// failed turn. Fail-closed is right; five seconds of patience was not.
const PDP_TIMEOUT_MS = Number(process.env.EGRESS_PDP_TIMEOUT_MS || '15000')

/**
 * Ask the PDP whether this run may reach this host:port.
 *
 * Every failure path returns false. The function cannot throw: a throw in the
 * CONNECT handler could otherwise be caught somewhere permissive and read as
 * "no objection".
 *
 * @param {string} host The requested host.
 * @param {number} port The requested port.
 * @param {string} token The run token from Proxy-Authorization.
 * @returns {Promise<{allowed: boolean, code: string, message: string}>} Verdict.
 */
function askPdp(host, port, token) {
	return new Promise((resolve) => {
		const deny = (code, message) => resolve({ allowed: false, code, message })

		if (token === '') {
			deny('no_run_token', 'no run token presented to the proxy')
			return
		}

		let target
		try {
			target = new URL(PDP_URL)
		} catch (e) {
			deny('pdp_misconfigured', 'the PDP URL is not a valid URL')
			return
		}

		const body = JSON.stringify({ host, port })
		const transport = target.protocol === 'https:' ? require('https') : http

		const req = transport.request(
			{
				protocol: target.protocol,
				hostname: target.hostname,
				port: target.port || (target.protocol === 'https:' ? 443 : 80),
				path: target.pathname + target.search,
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'Content-Length': Buffer.byteLength(body),
					// The run token authenticates the run to Hermiq. Never logged.
					Authorization: `Bearer ${token}`,
					'OCS-APIRequest': 'true',
				},
				timeout: PDP_TIMEOUT_MS,
			},
			(res) => {
				let raw = ''
				res.setEncoding('utf8')
				res.on('data', (c) => {
					// Bound the read: a hostile/broken PDP must not exhaust memory.
					if (raw.length < 64 * 1024) {
						raw += c
					}
				})
				res.on('end', () => {
					if (res.statusCode !== 200) {
						deny('pdp_rejected', `the PDP answered ${res.statusCode}`)
						return
					}
					let parsed
					try {
						parsed = JSON.parse(raw)
					} catch (e) {
						deny('pdp_unparseable', 'the PDP answer was not JSON')
						return
					}
					// STRICT: only a literal `true` permits. A truthy string, a
					// missing key or a differently-shaped answer is a denial.
					if (parsed.allowed === true) {
						resolve({ allowed: true, code: 'allowed', message: '' })
						return
					}
					deny(
						String(parsed.code || 'denied'),
						String(parsed.message || 'denied by policy'),
					)
				})
			},
		)

		req.on('timeout', () => {
			req.destroy()
			deny('pdp_timeout', 'the PDP did not answer in time')
		})
		req.on('error', () =>
			deny('pdp_unreachable', 'the PDP could not be reached'),
		)
		req.write(body)
		req.end()
	})
}

/**
 * Extract the run token from a `Proxy-Authorization: Basic base64(run:<token>)`
 * header. Basic is used because it is what every HTTP client (and the CLI's
 * `HTTPS_PROXY` handling) sends for a proxy URL carrying credentials.
 *
 * @param {object} headers The CONNECT request headers.
 * @returns {string} The token, or '' when absent/malformed.
 */
function tokenFromProxyAuth(headers) {
	const raw = headers['proxy-authorization'] || ''
	const m = /^Basic\s+(.+)$/i.exec(raw)
	if (m === null) {
		return ''
	}
	let decoded
	try {
		decoded = Buffer.from(m[1], 'base64').toString('utf8')
	} catch (e) {
		return ''
	}
	// `run:<token>` — split on the FIRST colon; a token may contain colons.
	const i = decoded.indexOf(':')
	return i === -1 ? '' : decoded.slice(i + 1)
}

/**
 * Parse a CONNECT target (`host:port`).
 *
 * @param {string} target The request URL of a CONNECT.
 * @returns {{host: string, port: number}|null} Parsed target, or null.
 */
function parseTarget(target) {
	const i = (target || '').lastIndexOf(':')
	if (i <= 0) {
		return null
	}
	const host = target.slice(0, i)
	const port = Number(target.slice(i + 1))
	if (
		host === ''
		|| Number.isInteger(port) === false
		|| port <= 0
		|| port > 65535
	) {
		return null
	}
	return { host, port }
}

/**
 * Parse an absolute-form request target (`http://host[:port]/path`), which is
 * what a client sends to a forward proxy for an `http://` URL.
 *
 * Only `http:` is accepted. An `https:` URL reaches a proxy as CONNECT, never as
 * an absolute-form request line, so an `https:` target here is a malformed
 * client — not a shape to guess at.
 *
 * @param {string} target The request URL.
 * @returns {{host: string, port: number, path: string}|null} Parsed, or null.
 */
function parseAbsoluteTarget(target) {
	let parsed
	try {
		parsed = new URL(target)
	} catch (e) {
		return null
	}
	if (parsed.protocol !== 'http:' || parsed.hostname === '') {
		return null
	}
	// `URL` strips the brackets from an IPv6 literal in `hostname`; `net.connect`
	// wants it that way too, so nothing is put back.
	return {
		host: parsed.hostname,
		port: Number(parsed.port || 80),
		path: `${parsed.pathname}${parsed.search}`,
	}
}

// Headers a proxy MUST NOT forward (RFC 9110 §7.6.1). `proxy-authorization`
// leads the list for a reason: it carries the run token, and forwarding it would
// hand the origin a live credential it has no business holding.
const HOP_BY_HOP = [
	'proxy-authorization',
	'proxy-connection',
	'connection',
	'keep-alive',
	'te',
	'trailer',
	'transfer-encoding',
	'upgrade',
]

/**
 * Copy request headers for forwarding, dropping the hop-by-hop set.
 *
 * Nothing else is touched: no header is added, rewritten or reordered, so what
 * the origin sees is what the client sent.
 *
 * @param {object} headers The incoming headers.
 * @returns {object} The headers to forward.
 */
function forwardableHeaders(headers) {
	const out = {}
	for (const [name, value] of Object.entries(headers || {})) {
		if (HOP_BY_HOP.includes(name.toLowerCase()) === false) {
			out[name] = value
		}
	}
	return out
}

const server = http.createServer(async (req, res) => {
	const refuse = (status, code, reason, extraHeaders) => {
		// eslint-disable-next-line no-console
		console.log(`[hermiq-egress-proxy] DENY ${req.method} (${code})`)
		res.writeHead(status, {
			'Content-Type': 'text/plain',
			'X-Egress-Deny-Code': code,
			...(extraHeaders || {}),
		})
		res.end(`${reason}\n`)
		// The body is never read on a refusal — drain it so the socket can close
		// without the client seeing a reset instead of its 403.
		req.resume()
	}

	// Origin-form (`GET /health`) is not a proxy request: this process is a proxy,
	// not a web server, and it exposes no endpoints of its own.
	const target = parseAbsoluteTarget(req.url)
	if (target === null) {
		refuse(
			405,
			'not_a_proxy_request',
			'this proxy serves CONNECT and http:// forward requests only',
		)
		return
	}

	const token = tokenFromProxyAuth(req.headers)
	if (token === '') {
		// Identical to the CONNECT path: challenge rather than refuse, so a client
		// holding a credential it has not offered yet learns to offer it. No tunnel,
		// no forward, and the PDP is not consulted — there is no run to ask about.
		// eslint-disable-next-line no-console
		console.log(`[hermiq-egress-proxy] CHALLENGE ${req.method} (no_run_token)`)
		refuse(407, 'no_run_token', 'no run token presented to the proxy', {
			'Proxy-Authenticate': 'Basic realm="hermiq-egress"',
		})
		return
	}

	const verdict = await askPdp(target.host, target.port, token)
	if (verdict.allowed !== true) {
		refuse(403, verdict.code, verdict.message)
		return
	}

	const upstream = http.request(
		{
			host: target.host,
			port: target.port,
			method: req.method,
			// ORIGIN-form: the request line is rewritten to the path, which is what
			// an origin server (and Nextcloud's router) expects. This is the one
			// rewrite a forward proxy must perform.
			path: target.path,
			headers: forwardableHeaders(req.headers),
		},
		(upstreamRes) => {
			// Stream straight through: the response is never buffered, so a
			// Server-Sent-Events stream (which is how the MCP transport answers)
			// reaches the client as it arrives.
			res.writeHead(upstreamRes.statusCode, upstreamRes.headers)
			upstreamRes.pipe(res)
		},
	)

	upstream.on('error', () => {
		if (res.headersSent === false) {
			res.writeHead(502, { 'Content-Type': 'text/plain' })
			res.end('upstream failed\n')
			return
		}
		res.destroy()
	})
	req.on('error', () => upstream.destroy())
	res.on('close', () => upstream.destroy())

	// eslint-disable-next-line no-console
	console.log(`[hermiq-egress-proxy] ALLOW ${target.host}:${target.port} (http)`)
	req.pipe(upstream)
})

server.on('connect', async (req, clientSocket, head) => {
	const refuse = (code, reason) => {
		// eslint-disable-next-line no-console
		console.log(`[hermiq-egress-proxy] DENY ${req.url} (${code})`)
		clientSocket.write(
			`HTTP/1.1 403 Forbidden\r\nX-Egress-Deny-Code: ${code}\r\n\r\n${reason}\n`,
		)
		clientSocket.destroy()
	}

	/**
	 * Answer 407 with a Basic challenge, and open nothing.
	 *
	 * ⚠️ THIS IS WHY GIT COULD NOT GET OUT, AND IT WAS INVISIBLE.
	 *
	 * `HTTPS_PROXY=http://run:<token>@proxy:3128` does NOT make every client
	 * present the credential. curl's CLI defaults to Basic and sends it
	 * PREEMPTIVELY; git sets libcurl's proxy auth to `CURLAUTH_ANY`, which waits
	 * for a 407 challenge before sending anything. Answering 403 to an
	 * unauthenticated CONNECT means git never learns there is a credential to
	 * offer, so it never offers the one it already holds.
	 *
	 * Measured 2026-08-02 inside the jailed container, same proxy, same URL:
	 *
	 *   curl --proxy http://run:tok@proxy:3128 https://github.com/
	 *       => Proxy-Authorization sent  => 200 Connection Established
	 *   git  HTTPS_PROXY=http://run:tok@proxy:3128 ls-remote https://github.com/…
	 *       => NO Proxy-Authorization    => 403, `no_run_token`, every time
	 *
	 * The proxy's own test suite could not see it: its client sets the header
	 * explicitly, so it exercised the authenticated path exclusively. A control
	 * that blocks the one workload it exists to govern is the jail's failure
	 * repeated in a different layer.
	 *
	 * A 407 opens no tunnel and is still a refusal, so default-deny is intact.
	 * It is used ONLY when no credential was presented at all — a token that IS
	 * presented and refused by policy stays a 403, because retrying it cannot
	 * help and challenging for it again would loop.
	 *
	 * @param {string} code Refusal code, for the log and the header.
	 * @returns {void}
	 */
	const challenge = (code) => {
		// eslint-disable-next-line no-console
		console.log(`[hermiq-egress-proxy] CHALLENGE ${req.url} (${code})`)
		clientSocket.write(
			'HTTP/1.1 407 Proxy Authentication Required\r\n'
				+ 'Proxy-Authenticate: Basic realm="hermiq-egress"\r\n'
				+ `X-Egress-Deny-Code: ${code}\r\n`
				+ 'Content-Length: 0\r\n'
				+ 'Connection: close\r\n\r\n',
		)
		clientSocket.destroy()
	}

	const target = parseTarget(req.url)
	if (target === null) {
		refuse('bad_target', 'malformed CONNECT target')
		return
	}

	const token = tokenFromProxyAuth(req.headers)
	if (token === '') {
		// No credential offered. Ask for one instead of refusing outright — see
		// `challenge()`. The PDP is NOT consulted: there is no run to ask about.
		challenge('no_run_token')
		return
	}

	const verdict = await askPdp(target.host, target.port, token)
	if (verdict.allowed !== true) {
		refuse(verdict.code, verdict.message)
		return
	}

	const upstream = net.connect(target.port, target.host, () => {
		clientSocket.write('HTTP/1.1 200 Connection Established\r\n\r\n')
		if (head && head.length > 0) {
			upstream.write(head)
		}
		upstream.pipe(clientSocket)
		clientSocket.pipe(upstream)
	})

	upstream.on('error', () => {
		clientSocket.write('HTTP/1.1 502 Bad Gateway\r\n\r\n')
		clientSocket.destroy()
	})
	clientSocket.on('error', () => upstream.destroy())
	// eslint-disable-next-line no-console
	console.log(`[hermiq-egress-proxy] ALLOW ${target.host}:${target.port}`)
})

if (require.main === module) {
	if (PDP_URL === '') {
		// eslint-disable-next-line no-console
		console.error(
			'[hermiq-egress-proxy] refusing to start: EGRESS_PDP_URL is not set — '
				+ 'a proxy with no policy decision point would be an open relay',
		)
		process.exit(1)
	}
	server.listen(PORT, () => {
		// eslint-disable-next-line no-console
		console.log(`[hermiq-egress-proxy] listening on ${PORT}, PDP=${PDP_URL}`)
	})
}

module.exports = {
	server,
	askPdp,
	tokenFromProxyAuth,
	parseTarget,
	parseAbsoluteTarget,
	forwardableHeaders,
}
