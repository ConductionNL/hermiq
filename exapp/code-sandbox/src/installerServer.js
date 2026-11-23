// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// installerServer.js — the installer service's HTTP surface
// (tools-agent-workspace-and-installed-tools D4). It runs in its own
// container, on a network whose only way out is the installer egress proxy.
//
//   GET  /heartbeat  Health probe; installs nothing.
//   POST /install    One install; AppAPI auth before anything else.
//
// One install at a time: a second one is refused with 429 rather than queued.
// A log line names the route, the status and the duration only.
//
// @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-workspace/spec.md#requirement-an-agent-installs-command-line-tools-only-from-an-allowlisted-registry-without-install-scripts-req-wksp-004

'use strict'

const http = require('http')
const auth = require('./auth.js')
const { install } = require('./installer.js')

const MAX_BODY_BYTES = 16 * 1024

let busy = false

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
 * One log line, without the request's content.
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
 * Read a small body, refusing one over the cap.
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
				const error = new Error('The request is larger than an install request may be.')
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
 * Create the installer server (not listening).
 *
 * @param {object} deps `{install}` for tests.
 * @return {http.Server}
 */
function createServer(deps = {}) {
	const doInstall = deps.install || install

	return http.createServer(async (req, res) => {
		const path = new URL(req.url, 'http://localhost').pathname
		if (req.method === 'GET' && path === '/heartbeat') {
			sendJson(res, 200, { status: 'ok', busy })
			return
		}
		if (req.method !== 'POST' || path !== '/install') {
			req.resume()
			sendJson(res, 404, { error: 'not found' })
			return
		}

		const verdict = auth.verify(lowerHeaders(req.headers), '')
		if (!verdict.ok) {
			req.resume()
			sendJson(res, verdict.status, { error: 'unauthorised' })
			return
		}
		if (busy) {
			req.resume()
			sendJson(res, 429, { error: 'The installer is busy. Try again shortly.' })
			return
		}

		busy = true
		const started = Date.now()
		try {
			const request = JSON.parse(await readBody(req))
			const result = await doInstall({ manager: request.manager, package: request.package, version: request.version })
			sendJson(res, 200, result)
			log('info', `/install 200 in ${Date.now() - started} ms`)
		} catch (error) {
			const status = error.status || (error instanceof SyntaxError ? 400 : 500)
			const message = status === 500 ? 'The installer could not install the tool.' : error.message
			sendJson(res, status, { error: message })
			log('warn', `/install ${status} in ${Date.now() - started} ms`)
		} finally {
			busy = false
		}
	})
}

if (require.main === module) {
	const port = parseInt(process.env.INSTALLER_PORT || '9200', 10)
	const host = process.env.INSTALLER_HOST || '0.0.0.0'
	createServer().listen(port, host, () => log('info', `hermiq-code-sandbox installer listening on ${host}:${port}`))
}

module.exports = { createServer }
