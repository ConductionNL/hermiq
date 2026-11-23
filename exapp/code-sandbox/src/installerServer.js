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
const { sendJson, log, lowerHeaders, readBody, failure } = require('./http.js')
const { install } = require('./installer.js')

const MAX_BODY_BYTES = 16 * 1024

/**
 * The install handler: auth, one at a time, and only manager, package and
 * version passed on.
 *
 * @param {function} doInstall Installs one tool.
 * @return {function} `(req, res) => Promise<void>`.
 */
function installHandler(doInstall) {
	const state = { busy: false }

	return async (req, res) => {
		const verdict = auth.verify(lowerHeaders(req.headers), '')
		if (!verdict.ok || state.busy) {
			req.resume()
			sendJson(res, verdict.ok ? 429 : verdict.status, { error: verdict.ok ? 'The installer is busy. Try again shortly.' : 'unauthorised' })
			return
		}

		state.busy = true
		const started = Date.now()
		try {
			const request = JSON.parse(await readBody(req, MAX_BODY_BYTES, 'The request is larger than an install request may be.'))
			sendJson(res, 200, await doInstall({ manager: request.manager, package: request.package, version: request.version }))
			log('info', `/install 200 in ${Date.now() - started} ms`)
		} catch (error) {
			const { status, message } = failure(error, 'The installer could not install the tool.')
			sendJson(res, status, { error: message })
			log('warn', `/install ${status} in ${Date.now() - started} ms`)
		} finally {
			state.busy = false
		}
	}
}

/**
 * Create the installer server (not listening).
 *
 * @param {object} deps `{install}` for tests.
 * @return {http.Server}
 */
function createServer(deps = {}) {
	const handleInstall = installHandler(deps.install || install)

	return http.createServer((req, res) => {
		const path = new URL(req.url, 'http://localhost').pathname
		if (req.method === 'POST' && path === '/install') {
			handleInstall(req, res)
			return
		}
		req.resume()
		if (req.method === 'GET' && path === '/heartbeat') {
			sendJson(res, 200, { status: 'ok' })
			return
		}
		sendJson(res, 404, { error: 'not found' })
	})
}

if (require.main === module) {
	const port = parseInt(process.env.INSTALLER_PORT || '9200', 10)
	const host = process.env.INSTALLER_HOST || '0.0.0.0'
	createServer().listen(port, host, () => log('info', `hermiq-code-sandbox installer listening on ${host}:${port}`))
}

module.exports = { createServer }
