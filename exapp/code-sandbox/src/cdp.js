// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The Chromium driver behind BrowserSessions (tools-browser-and-computer-use D1).
 *
 * Launches headless Chromium with a throwaway profile, routes it through the
 * browser egress proxy, and speaks the DevTools Protocol over one WebSocket.
 * Each page lives in its own browser context (no shared cookies), has
 * downloads denied, and pauses every request (`Fetch.enable`) until the gate
 * allows it; a refused request is failed before any connection is made.
 *
 * @spec openspec/changes/tools-browser-and-computer-use/specs/browser-use/spec.md#requirement-the-browser-reaches-only-what-the-egress-policy-allows-req-browse-001
 */

'use strict'

const { spawn } = require('child_process')
const fs = require('fs')
const os = require('os')
const path = require('path')

/** How long a navigation may take before the snapshot is taken anyway. */
const NAVIGATION_MS = 20000

/**
 * One DevTools connection.
 */
class CdpConnection {
	/**
	 * @param {WebSocket} socket An open socket to the browser endpoint.
	 */
	constructor(socket) {
		this.socket = socket
		this.nextId = 1
		this.pending = new Map()
		this.listeners = []
		socket.addEventListener('message', (event) => this.dispatch(JSON.parse(String(event.data))))
	}

	/**
	 * Send a command and wait for its answer.
	 *
	 * @param {string} method The CDP method.
	 * @param {object} params Its parameters.
	 * @param {string|undefined} sessionId The target session.
	 * @return {Promise<object>}
	 */
	send(method, params = {}, sessionId = undefined) {
		const id = this.nextId++
		const message = { id, method, params }
		if (sessionId !== undefined) {
			message.sessionId = sessionId
		}
		return new Promise((resolve, reject) => {
			this.pending.set(id, { resolve, reject })
			this.socket.send(JSON.stringify(message))
		})
	}

	/**
	 * Listen to events.
	 *
	 * @param {function(object): void} listener Called with every event.
	 * @return {function(): void} Stops the listener.
	 */
	on(listener) {
		this.listeners.push(listener)
		return () => {
			this.listeners = this.listeners.filter((other) => other !== listener)
		}
	}

	/**
	 * Route an incoming message.
	 *
	 * @param {object} message The message.
	 * @return {void}
	 */
	dispatch(message) {
		if (message.id !== undefined && this.pending.has(message.id)) {
			const { resolve, reject } = this.pending.get(message.id)
			this.pending.delete(message.id)
			if (message.error) {
				reject(new Error(message.error.message))
				return
			}
			resolve(message.result || {})
			return
		}
		for (const listener of this.listeners) {
			listener(message)
		}
	}
}

/**
 * Launch Chromium and connect to it.
 *
 * @param {{chromium?: string, proxy?: string}} options The binary and the egress proxy.
 * @return {Promise<{connection: CdpConnection, process: object}>}
 */
async function launch(options) {
	const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'hermiq-browser-'))
	const args = [
		'--headless=new',
		'--remote-debugging-port=0',
		`--user-data-dir=${profile}`,
		'--no-first-run',
		'--no-default-browser-check',
		'--disable-extensions',
		'--disable-sync',
		'--disable-background-networking',
		'--password-store=basic',
		'--incognito',
	]
	if (options.proxy) {
		// Chromium's only route out: the proxy asks the same PDP again.
		args.push(`--proxy-server=${options.proxy}`, '--proxy-bypass-list=<-loopback>')
	}
	const child = spawn(options.chromium || 'chromium', args, { stdio: ['ignore', 'ignore', 'pipe'] })
	const endpoint = await new Promise((resolve, reject) => {
		let seen = ''
		child.stderr.on('data', (chunk) => {
			seen += chunk
			const match = seen.match(/DevTools listening on (ws:\/\/\S+)/)
			if (match) {
				resolve(match[1])
			}
		})
		child.on('exit', () => reject(new Error('Chromium exited before it listened.')))
	})
	const socket = new WebSocket(endpoint)
	await new Promise((resolve, reject) => {
		socket.addEventListener('open', resolve)
		socket.addEventListener('error', reject)
	})
	return { connection: new CdpConnection(socket), process: child }
}

/**
 * A driver for BrowserSessions over a CDP connection.
 *
 * @param {{connection: CdpConnection}} browser The launched browser.
 * @return {{openPage: function({gate: Function, proxyToken: Function}): Promise<object>}}
 */
function cdpDriver(browser) {
	const cdp = browser.connection
	const gates = new Map()
	const tokens = new Map()

	cdp.on((event) => {
		if (event.method === 'Fetch.authRequired') {
			// Credentials go to the egress proxy only, and they are the run token:
			// the browser never answers a site's own login challenge.
			const token = tokens.get(event.sessionId)
			const fromProxy = event.params.authChallenge && event.params.authChallenge.source === 'Proxy'
			const response = fromProxy && token ? { response: 'ProvideCredentials', username: 'run', password: token() } : { response: 'CancelAuth' }
			cdp.send('Fetch.continueWithAuth', { requestId: event.params.requestId, authChallengeResponse: response }, event.sessionId).catch(() => {})
			return
		}
		if (event.method !== 'Fetch.requestPaused') {
			return
		}
		const gate = gates.get(event.sessionId)
		const requestId = event.params.requestId
		const refuse = () => cdp.send('Fetch.failRequest', { requestId, errorReason: 'BlockedByClient' }, event.sessionId).catch(() => {})
		if (gate === undefined) {
			refuse()
			return
		}
		Promise.resolve(gate(event.params.request.url)).then((verdict) => {
			if (verdict && verdict.allowed === true) {
				cdp.send('Fetch.continueRequest', { requestId }, event.sessionId).catch(() => {})
				return
			}
			refuse()
		}, refuse)
	})

	return {
		async openPage({ gate, proxyToken }) {
			const { browserContextId } = await cdp.send('Target.createBrowserContext', { disposeOnDetach: true })
			await cdp.send('Browser.setDownloadBehavior', { behavior: 'deny', browserContextId })
			const { targetId } = await cdp.send('Target.createTarget', { url: 'about:blank', browserContextId })
			const { sessionId } = await cdp.send('Target.attachToTarget', { targetId, flatten: true })
			gates.set(sessionId, gate)
			tokens.set(sessionId, proxyToken)
			const send = (method, params = {}) => cdp.send(method, params, sessionId)
			await send('Fetch.enable', { patterns: [{ urlPattern: '*' }], handleAuthRequests: true })
			await send('Page.enable')
			await send('DOM.enable')
			await send('Accessibility.enable')

			const call = async (backendNodeId, fn, args = []) => {
				const { object } = await send('DOM.resolveNode', { backendNodeId })
				const { result } = await send('Runtime.callFunctionOn', { objectId: object.objectId, functionDeclaration: fn, arguments: args.map((value) => ({ value })), returnByValue: true, awaitPromise: true })
				return result.value
			}
			const evaluate = async (expression) => (await send('Runtime.evaluate', { expression, returnByValue: true })).result.value

			return {
				async navigate(url) {
					const loaded = new Promise((resolve) => {
						let stop = () => {}
						const timer = setTimeout(() => {
							stop()
							resolve()
						}, NAVIGATION_MS)
						stop = cdp.on((event) => {
							if (event.sessionId === sessionId && event.method === 'Page.loadEventFired') {
								clearTimeout(timer)
								stop()
								resolve()
							}
						})
					})
					await send('Page.navigate', { url })
					await loaded
				},
				async axTree() {
					return (await send('Accessibility.getFullAXTree')).nodes || []
				},
				async click(backendNodeId) {
					await call(backendNodeId, 'function () { this.scrollIntoView({block: "center"}); this.click() }')
				},
				async field(backendNodeId) {
					return call(backendNodeId, 'function () { return { type: String(this.type || ""), autocomplete: String(this.getAttribute && this.getAttribute("autocomplete") || "") } }')
				},
				async fill(backendNodeId, text) {
					await call(backendNodeId, 'function (text) { this.focus(); this.value = text; this.dispatchEvent(new Event("input", {bubbles: true})); this.dispatchEvent(new Event("change", {bubbles: true})) }', [text])
				},
				async url() {
					return String(await evaluate('location.href'))
				},
				async title() {
					return String(await evaluate('document.title'))
				},
				async close() {
					gates.delete(sessionId)
					tokens.delete(sessionId)
					await cdp.send('Target.disposeBrowserContext', { browserContextId }).catch(() => {})
				},
			}
		},
	}
}

module.exports = { CdpConnection, cdpDriver, launch }
