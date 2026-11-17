// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The HTTP surface of the browser service (tools-browser-and-computer-use D1).
 *
 * Runs in its own container, next to the code sandbox and not inside it: its
 * only network routes are the control network the sandbox server forwards
 * over and the browser egress network the proxy sits on. Every request carries
 * the AppAPI shared secret, checked exactly as the sandbox checks /run.
 *
 *   POST /browser/open  {conversationId, runToken, url}
 *   POST /browser/read  {conversationId, runToken}
 *   POST /browser/click {conversationId, runToken, ref}
 *   POST /browser/fill  {conversationId, runToken, ref, text}
 *   POST /browser/close {conversationId}
 *
 * Nothing a page shows, nothing typed and no token is logged.
 *
 * @spec openspec/changes/tools-browser-and-computer-use/specs/browser-use/spec.md
 */

'use strict'

const http = require('http')
const auth = require('./auth.js')
const { BrowserRefusal, BrowserSessions, pdpEgress } = require('./browser.js')

/** The largest request body. */
const MAX_BODY_BYTES = 256 * 1024

/** How often idle contexts are swept. */
const SWEEP_MS = 60 * 1000

/**
 * Answer JSON.
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
 * Read a bounded JSON body.
 *
 * @param {http.IncomingMessage} req The request.
 * @return {Promise<object>}
 */
function readJson(req) {
	return new Promise((resolve, reject) => {
		let raw = ''
		req.setEncoding('utf8')
		req.on('data', (chunk) => {
			raw += chunk
			if (raw.length > MAX_BODY_BYTES) {
				reject(new BrowserRefusal('too_large', 'The request is too large.', 413))
				req.destroy()
			}
		})
		req.on('end', () => {
			try {
				resolve(JSON.parse(raw || '{}'))
			} catch (e) {
				reject(new BrowserRefusal('invalid_json', 'The request is not JSON.', 400))
			}
		})
		req.on('error', reject)
	})
}

/**
 * A string field of the request body, empty when absent.
 *
 * @param {object} body The request body.
 * @param {string} key The field.
 * @return {string}
 */
function field(body, key) {
	return String(body[key] || '')
}

/** Every action: (sessions, conversationId, body, token) => result. */
const ACTIONS = {
	open: (sessions, id, body, token) => sessions.open(id, field(body, 'url'), token),
	read: (sessions, id, body, token) => sessions.read(id, token),
	click: (sessions, id, body, token) => sessions.click(id, field(body, 'ref'), token),
	fill: (sessions, id, body, token) => sessions.fill(id, field(body, 'ref'), field(body, 'text'), token),
	close: async (sessions, id) => ({ closed: await sessions.close(id) }),
}

/**
 * The action one route performs.
 *
 * @param {BrowserSessions} sessions The contexts.
 * @param {string} action open, read, click, fill or close.
 * @param {object} body The request body.
 * @return {Promise<object>}
 */
async function perform(sessions, action, body) {
	const handler = Object.hasOwn(ACTIONS, action) ? ACTIONS[action] : null
	if (handler === null) {
		throw new BrowserRefusal('not_found', 'not found', 404)
	}
	return handler(sessions, String(body.conversationId || ''), body, String(body.runToken || ''))
}

/**
 * Create the browser server (not listening).
 *
 * @param {BrowserSessions} sessions The contexts.
 * @return {http.Server}
 */
function createBrowserServer(sessions) {
	return http.createServer(async (req, res) => {
		const path = new URL(req.url, 'http://localhost').pathname
		if (req.method === 'GET' && path === '/heartbeat') {
			sendJson(res, 200, { status: 'ok', contexts: sessions.sessions.size })
			return
		}
		const match = path.match(/^\/browser\/(open|read|click|fill|close)$/)
		if (req.method !== 'POST' || match === null) {
			req.resume()
			sendJson(res, 404, { error: 'not found' })
			return
		}
		const verdict = auth.verify(Object.fromEntries(Object.entries(req.headers).map(([k, v]) => [k.toLowerCase(), v])), '')
		if (!verdict.ok) {
			req.resume()
			sendJson(res, verdict.status, { error: 'unauthorised' })
			return
		}
		try {
			sendJson(res, 200, await perform(sessions, match[1], await readJson(req)))
		} catch (error) {
			if (error instanceof BrowserRefusal) {
				sendJson(res, error.status, { error: error.message, code: error.code })
				return
			}
			sendJson(res, 502, { error: 'The browser could not complete the action.', code: 'browser_failed' })
		}
	})
}

if (require.main === module) {
	const { cdpDriver, launch } = require('./cdp.js')
	launch({ chromium: process.env.BROWSER_CHROMIUM, proxy: process.env.BROWSER_EGRESS_PROXY }).then((browser) => {
		const sessions = new BrowserSessions({ driver: cdpDriver(browser), egress: pdpEgress({ pdpUrl: process.env.EGRESS_PDP_URL || '' }) })
		setInterval(() => sessions.sweep().catch(() => {}), SWEEP_MS).unref()
		const port = parseInt(process.env.BROWSER_PORT || '9100', 10)
		createBrowserServer(sessions).listen(port, '0.0.0.0')
	})
}

module.exports = { createBrowserServer, perform }
