// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// server.js — the hermiq-code-sandbox ExApp's HTTP surface (tools-code-sandbox).
//
//   GET  /heartbeat  AppAPI's health probe; no auth, runs nothing.
//   PUT  /enabled    AppAPI's enable/disable call; AppAPI auth.
//   POST /run        One run of code; AppAPI auth before anything else.
//   POST /install    One tool install, forwarded to the installer service, which
//                    runs in its own container on its own network
//                    (tools-agent-workspace-and-installed-tools D4).
//
// There is no /init handler on purpose: AppAPI reads a 404 there as "nothing
// to initialise" (see exapp/llm-runner/src/server.js). Runs are taken one at a
// time per container (SANDBOX_CONCURRENCY, default 2); a run over the limit is
// refused with 429 rather than queued, so a caller's timeout means something.
//
// Nothing a run sends or prints is logged: a log line names the route, the
// status and the duration only.
//
// @spec openspec/changes/tools-code-sandbox/specs/code-sandbox/spec.md#requirement-the-sandbox-runs-code-without-network-without-data-mounts-and-within-limits-req-sandbox-001

'use strict'

const http = require('http')
const auth = require('./auth.js')
const { run, LIMITS } = require('./run.js')

// Code, stdin and files together; the files' own cap is 10 MB.
const MAX_BODY_BYTES = LIMITS.maxFilesBytes * 2
const CONCURRENCY = Math.max(1, parseInt(process.env.SANDBOX_CONCURRENCY || '2', 10) || 2)

let running = 0

/**
 * Send a JSON answer.
 *
 * @param {http.ServerResponse} res The response.
 * @param {number} status The status.
 * @param {object} body The body.
 * @return {void}
 */
function sendJson(res, status, body) {
	const text = JSON.stringify(body)
	res.writeHead(status, { 'Content-Type': 'application/json', 'Content-Length': Buffer.byteLength(text) })
	res.end(text)
}

/**
 * One log line, without anything a run carries.
 *
 * @param {string} level The level.
 * @param {string} message The message.
 * @return {void}
 */
function log(level, message) {
	process.stdout.write(JSON.stringify({ level, message, time: new Date().toISOString() }) + '\n')
}

/**
 * The request headers, lower-cased.
 *
 * @param {object} headers The raw headers.
 * @return {object}
 */
function lowerHeaders(headers) {
	const out = {}
	for (const [key, value] of Object.entries(headers || {})) {
		out[key.toLowerCase()] = Array.isArray(value) ? value[0] : value
	}
	return out
}

/**
 * Read the body, refusing one over the cap.
 *
 * @param {http.IncomingMessage} req The request.
 * @return {Promise<string>}
 */
function readBody(req) {
	return new Promise((resolve, reject) => {
		const chunks = []
		let size = 0
		req.on('data', (chunk) => {
			size += chunk.length
			if (size > MAX_BODY_BYTES) {
				const error = new Error('The request is larger than the sandbox takes.')
				error.status = 413
				reject(error)
				req.destroy()
				return
			}
			chunks.push(chunk)
		})
		req.on('end', () => resolve(Buffer.concat(chunks).toString('utf8')))
		req.on('error', reject)
	})
}

/**
 * Handle POST /run.
 *
 * @param {http.IncomingMessage} req The request.
 * @param {http.ServerResponse} res The response.
 * @return {Promise<void>}
 */
async function handleRun(req, res) {
	const verdict = auth.verify(lowerHeaders(req.headers), '')
	if (!verdict.ok) {
		sendJson(res, verdict.status, { error: 'unauthorised' })
		return
	}
	if (running >= CONCURRENCY) {
		sendJson(res, 429, { error: 'The sandbox is busy. Try again shortly.' })
		return
	}

	running += 1
	const started = Date.now()
	try {
		const request = JSON.parse(await readBody(req))
		const result = await run(request)
		sendJson(res, 200, result)
		log('info', `/run 200 in ${Date.now() - started} ms`)
	} catch (error) {
		const status = error.status || (error instanceof SyntaxError ? 400 : 500)
		const message = status === 500 ? 'The sandbox could not run the code.' : error.message
		sendJson(res, status, { error: message })
		log('warn', `/run ${status} in ${Date.now() - started} ms`)
	} finally {
		running -= 1
	}
}

/**
 * Handle PUT /enabled.
 *
 * @param {http.IncomingMessage} req The request.
 * @param {http.ServerResponse} res The response.
 * @return {void}
 */
function handleEnabled(req, res) {
	const verdict = auth.verify(lowerHeaders(req.headers), '')
	if (!verdict.ok) {
		sendJson(res, verdict.status, { error: 'unauthorised' })
		return
	}
	req.resume()
	sendJson(res, 200, {})
}

/**
 * Forward a request to a sibling service in its own container: the browser
 * service (tools-browser-and-computer-use D1) or the installer
 * (tools-agent-workspace-and-installed-tools D4). The AppAPI headers travel
 * with it and the service checks them itself; this server only refuses early,
 * and answers 503 when the service is not configured or cannot be reached.
 *
 * @param {http.IncomingMessage} req The request.
 * @param {http.ServerResponse} res The response.
 * @param {string} path The request path.
 * @param {string|undefined} baseUrl The service's base URL.
 * @param {string} name The service, for the refusal.
 * @param {string} code The machine code of the refusal.
 * @return {void}
 */
function forward(req, res, path, baseUrl, name, code) {
	const verdict = auth.verify(lowerHeaders(req.headers), '')
	if (!verdict.ok) {
		req.resume()
		sendJson(res, verdict.status, { error: 'unauthorised' })
		return
	}
	let target
	try {
		target = new URL(path, baseUrl || '')
	} catch (e) {
		req.resume()
		sendJson(res, 503, { error: 'The ' + name + ' is not configured.', code })
		return
	}
	const upstream = http.request(target, { method: 'POST', headers: req.headers }, (answer) => {
		res.writeHead(answer.statusCode || 502, { 'Content-Type': 'application/json' })
		answer.pipe(res)
	})
	upstream.on('error', () => sendJson(res, 503, { error: 'The ' + name + ' could not be reached.', code }))
	req.pipe(upstream)
}

/**
 * Forward a browser action to the browser service.
 *
 * @param {http.IncomingMessage} req The request.
 * @param {http.ServerResponse} res The response.
 * @param {string} path The request path.
 * @return {void}
 */
function handleBrowser(req, res, path) {
	forward(req, res, path, process.env.BROWSER_CONTROL_URL, 'browser service', 'browser_unavailable')
}

/**
 * Forward an install to the installer service.
 *
 * @param {http.IncomingMessage} req The request.
 * @param {http.ServerResponse} res The response.
 * @return {void}
 */
function handleInstall(req, res) {
	forward(req, res, '/install', process.env.INSTALLER_CONTROL_URL, 'installer', 'installer_unavailable')
}

/**
 * Route a request.
 *
 * @param {http.IncomingMessage} req The request.
 * @param {http.ServerResponse} res The response.
 * @return {void}
 */
function route(req, res) {
	const path = new URL(req.url, 'http://localhost').pathname
	if (req.method === 'GET' && path === '/heartbeat') {
		sendJson(res, 200, { status: 'ok', running })
		return
	}
	if (req.method === 'PUT' && path === '/enabled') {
		handleEnabled(req, res)
		return
	}
	if (req.method === 'POST' && path === '/run') {
		handleRun(req, res)
		return
	}
	if (req.method === 'POST' && path === '/install') {
		handleInstall(req, res)
		return
	}
	if (req.method === 'POST' && /^\/browser\/(open|read|click|fill|close)$/.test(path)) {
		handleBrowser(req, res, path)
		return
	}
	req.resume()
	sendJson(res, 404, { error: 'not found' })
}

/**
 * Create the server (not listening).
 *
 * @return {http.Server}
 */
function createServer() {
	return http.createServer(route)
}

if (require.main === module) {
	const port = parseInt(process.env.APP_PORT || process.env.SANDBOX_PORT || '9000', 10)
	const host = process.env.APP_HOST || '0.0.0.0'
	createServer().listen(port, host, () => log('info', `hermiq-code-sandbox listening on ${host}:${port}`))
}

module.exports = { createServer }
