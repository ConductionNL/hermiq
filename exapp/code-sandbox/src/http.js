// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// http.js — the small HTTP helpers the sandbox server and the installer
// service share: a JSON answer, a log line that carries no request content,
// lower-cased headers for the AppAPI check, and a body read with a cap.
//
// @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-workspace/spec.md#requirement-an-agent-installs-command-line-tools-only-from-an-allowlisted-registry-without-install-scripts-req-wksp-004

'use strict'

/**
 * Send a JSON answer.
 *
 * @param {import('http').ServerResponse} res The response.
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
 * One log line, without anything a request carries.
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
 * Read the body, refusing one over the cap with 413.
 *
 * @param {import('http').IncomingMessage} req The request.
 * @param {number} maxBytes The cap.
 * @param {string} refusal The message of the refusal.
 * @return {Promise<string>}
 */
function readBody(req, maxBytes, refusal) {
	return new Promise((resolve, reject) => {
		const chunks = []
		let size = 0
		req.on('data', (chunk) => {
			size += chunk.length
			if (size > maxBytes) {
				const error = new Error(refusal)
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
 * The status and message a failed request answers: the error's own status
 * and message, 400 for unparsable JSON, and a fixed message for a 500.
 *
 * @param {Error} error The failure.
 * @param {string} fallback The message of a 500.
 * @return {{status: number, message: string}}
 */
function failure(error, fallback) {
	const status = error.status || (error instanceof SyntaxError ? 400 : 500)
	return { status, message: status === 500 ? fallback : error.message }
}

module.exports = { sendJson, log, lowerHeaders, readBody, failure }
