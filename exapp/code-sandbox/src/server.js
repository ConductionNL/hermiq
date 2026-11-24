// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// server.js — the hermiq-code-sandbox ExApp's HTTP surface (tools-code-sandbox).
//
//   GET  /heartbeat  AppAPI's health probe; no auth, runs nothing.
//   PUT  /enabled    AppAPI's enable/disable call; AppAPI auth.
//   POST /run        One run of code, or of an installed tool; AppAPI auth before anything else.
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
const { sendJson, log, lowerHeaders, readBody, failure } = require('./http.js')
const { run, LIMITS } = require('./run.js')
const { runInstalled, LIMITS: INSTALLED_LIMITS } = require('./installedTool.js')

// Code, stdin and files together (the files' own cap is 10 MB), plus a tool
// layer of at most 64 MB as base64 when an installed tool's layer is sent.
const MAX_BODY_BYTES = LIMITS.maxFilesBytes * 2 + Math.ceil(INSTALLED_LIMITS.maxLayerBytes * 4 / 3)
const CONCURRENCY = Math.max(1, parseInt(process.env.SANDBOX_CONCURRENCY || '2', 10) || 2)

let running = 0

// The sibling services a request is forwarded to, each in its own container.
const SERVICES = Object.freeze({
	browser: { env: 'BROWSER_CONTROL_URL', name: 'browser service', code: 'browser_unavailable' },
	installer: { env: 'INSTALLER_CONTROL_URL', name: 'installer', code: 'installer_unavailable' },
})

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
		const request = JSON.parse(await readBody(req, MAX_BODY_BYTES, 'The request is larger than the sandbox takes.'))
		// An installed tool (tools-agent-workspace-and-installed-tools D5) or code.
		const result = await (request && request.installed !== undefined ? runInstalled(request) : run(request))
		sendJson(res, 200, result)
		log('info', `/run 200 in ${Date.now() - started} ms`)
	} catch (error) {
		const { status, message } = failure(error, 'The sandbox could not run the code.')
		sendJson(res, status, error.code ? { error: message, code: error.code } : { error: message })
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
 * @param {{env: string, name: string, code: string}} service The service.
 * @return {void}
 */
function forward(req, res, path, service) {
	const verdict = auth.verify(lowerHeaders(req.headers), '')
	if (!verdict.ok) {
		req.resume()
		sendJson(res, verdict.status, { error: 'unauthorised' })
		return
	}
	let target
	try {
		target = new URL(path, process.env[service.env] || '')
	} catch (e) {
		req.resume()
		sendJson(res, 503, { error: 'The ' + service.name + ' is not configured.', code: service.code })
		return
	}
	const upstream = http.request(target, { method: 'POST', headers: req.headers }, (answer) => {
		res.writeHead(answer.statusCode || 502, { 'Content-Type': 'application/json' })
		answer.pipe(res)
	})
	upstream.on('error', () => sendJson(res, 503, { error: 'The ' + service.name + ' could not be reached.', code: service.code }))
	req.pipe(upstream)
}

/**
 * The handler for a method and path, or null.
 *
 * @param {string} method The method.
 * @param {string} path The path.
 * @return {function|null} `(req, res) => void`.
 */
function handlerFor(method, path) {
	const routes = {
		'GET /heartbeat': (req, res) => sendJson(res, 200, { status: 'ok', running }),
		'PUT /enabled': handleEnabled,
		'POST /run': handleRun,
		'POST /install': (req, res) => forward(req, res, '/install', SERVICES.installer),
	}
	const key = method + ' ' + path
	if (Object.prototype.hasOwnProperty.call(routes, key)) {
		return routes[key]
	}
	if (method === 'POST' && /^\/browser\/(open|read|click|fill|close)$/.test(path)) {
		return (req, res) => forward(req, res, path, SERVICES.browser)
	}
	return null
}

/**
 * Route a request.
 *
 * @param {http.IncomingMessage} req The request.
 * @param {http.ServerResponse} res The response.
 * @return {void}
 */
function route(req, res) {
	const handler = handlerFor(req.method, new URL(req.url, 'http://localhost').pathname)
	if (handler === null) {
		req.resume()
		sendJson(res, 404, { error: 'not found' })
		return
	}
	handler(req, res)
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
