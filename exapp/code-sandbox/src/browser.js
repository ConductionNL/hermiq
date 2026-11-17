// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The browser service of the code sandbox app (tools-browser-and-computer-use D1).
 *
 * Headless Chromium driven over the Chrome DevTools Protocol, one browser
 * context per conversation, closed after 10 idle minutes or when the
 * conversation ends. The context starts with no cookies, no stored credentials
 * and downloads off, and nothing is mounted.
 *
 * Every connection the browser opens is asked of Hermiq's egress policy
 * decision point first, with the run's token: the controller intercepts each
 * request (CDP `Fetch`) and fails it unless the PDP answers `allowed: true`,
 * and Chromium's only route out is the browser egress proxy, which asks the
 * same PDP again. A refused connection is never made.
 *
 * The page reaches Hermiq as a text snapshot of its accessibility tree, each
 * actionable element tagged with a short reference (`[e12] button "Indienen"`),
 * cut to 20 KB. Hermiq wraps it in the untrusted-content markers and runs it
 * through the organisation's input filter.
 *
 * `fill` never types into a password field or a field whose `autocomplete`
 * names a credential.
 *
 * @spec openspec/changes/tools-browser-and-computer-use/specs/browser-use/spec.md
 */

'use strict'

const http = require('http')
const { URL } = require('url')

/** The longest a snapshot may be, in bytes. */
const SNAPSHOT_MAX_BYTES = 20 * 1024

/** How long an unused browser context lives. */
const IDLE_MS = 10 * 60 * 1000

/** The answer when the agent tries to type into a credential field. */
const PASSWORD_REFUSAL = 'The agent does not type passwords.'

/** The `autocomplete` tokens that name a credential. */
const CREDENTIAL_AUTOCOMPLETE = ['current-password', 'new-password', 'one-time-code', 'webauthn', 'cc-number', 'cc-csc', 'cc-exp']

/** Roles an agent can act on; they get a reference in the snapshot. */
const ACTIONABLE_ROLES = ['button', 'link', 'textbox', 'searchbox', 'combobox', 'checkbox', 'radio', 'switch', 'menuitem', 'option', 'tab', 'slider', 'spinbutton', 'listbox']

/** Roles whose text is shown without a reference. */
const TEXT_ROLES = ['heading', 'StaticText', 'paragraph', 'label', 'cell', 'columnheader', 'rowheader', 'listitem', 'alert', 'status']

/**
 * A refusal answered to Hermiq; `code` is machine-readable.
 */
class BrowserRefusal extends Error {
	/**
	 * @param {string} code The refusal code.
	 * @param {string} message The text the agent reads.
	 * @param {number} status The HTTP status.
	 */
	constructor(code, message, status = 422) {
		super(message)
		this.code = code
		this.status = status
	}
}

/**
 * Whether a field is one the agent must never type into.
 *
 * @param {{type?: string, autocomplete?: string}} field The field's attributes.
 * @return {boolean}
 */
function isCredentialField(field) {
	const type = String(field.type || '').toLowerCase()
	if (type === 'password') {
		return true
	}
	const tokens = String(field.autocomplete || '').toLowerCase().split(/\s+/).filter(Boolean)
	return tokens.some((token) => CREDENTIAL_AUTOCOMPLETE.includes(token))
}

/**
 * The value of a CDP AX property.
 *
 * @param {object|undefined} prop An AX value.
 * @return {string}
 */
function axValue(prop) {
	if (prop === undefined || prop === null || prop.value === undefined || prop.value === null) {
		return ''
	}
	return String(prop.value)
}

/**
 * Cut a text to a byte budget without splitting a character.
 *
 * @param {string} text The text.
 * @param {number} maxBytes The budget.
 * @return {{text: string, truncated: boolean}}
 */
function cutToBytes(text, maxBytes) {
	if (Buffer.byteLength(text, 'utf8') <= maxBytes) {
		return { text, truncated: false }
	}
	let cut = Buffer.from(text, 'utf8').subarray(0, maxBytes).toString('utf8')
	// A split multi-byte character decodes to U+FFFD at the end: drop it.
	cut = cut.replace(/�+$/, '')
	return { text: cut, truncated: true }
}

/**
 * The text snapshot of a page from its accessibility tree.
 *
 * @param {Array<object>} nodes `Accessibility.getFullAXTree` nodes.
 * @param {number} maxBytes The byte budget.
 * @return {{text: string, refs: Object<string, number>, truncated: boolean}}
 *   The snapshot, and the backend DOM node of every reference.
 */
function snapshotOf(nodes, maxBytes = SNAPSHOT_MAX_BYTES) {
	const lines = []
	const refs = {}
	let next = 1
	for (const node of nodes) {
		if (node.ignored === true) {
			continue
		}
		const role = axValue(node.role)
		const name = axValue(node.name).replace(/\s+/g, ' ').trim()
		if (ACTIONABLE_ROLES.includes(role) && node.backendDOMNodeId !== undefined) {
			const ref = `e${next}`
			next += 1
			refs[ref] = node.backendDOMNodeId
			const value = axValue(node.value)
			lines.push(`[${ref}] ${role} "${name}"` + (value !== '' ? ` = "${value}"` : ''))
			continue
		}
		if (TEXT_ROLES.includes(role) && name !== '') {
			lines.push(role === 'heading' ? `# ${name}` : name)
		}
	}
	const cut = cutToBytes(lines.join('\n'), maxBytes)
	return { text: cut.text, refs, truncated: cut.truncated }
}

/**
 * The host and port a URL connects to, or null for a scheme the browser may not open.
 *
 * @param {string} raw The URL.
 * @return {{host: string, port: number}|null}
 */
function destinationOf(raw) {
	let url
	try {
		url = new URL(raw)
	} catch (e) {
		return null
	}
	if (url.protocol !== 'http:' && url.protocol !== 'https:') {
		return null
	}
	const host = url.hostname.replace(/^\[|\]$/g, '')
	const port = url.port !== '' ? Number(url.port) : (url.protocol === 'https:' ? 443 : 80)
	return { host, port }
}

/**
 * An egress check that asks Hermiq's PDP, default deny.
 *
 * Every failure, a missing token, an unreachable or slow PDP, an answer that is
 * not `allowed: true`, is a denial. It never throws.
 *
 * @param {{pdpUrl: string, timeoutMs?: number, request?: Function}} options Where the PDP is.
 * @return {function(string, string): Promise<{allowed: boolean, code: string, message: string}>}
 */
function pdpEgress(options) {
	const timeoutMs = options.timeoutMs || 15000
	return (rawUrl, token) => new Promise((resolve) => {
		const deny = (code, message) => resolve({ allowed: false, code, message })
		const destination = destinationOf(rawUrl)
		if (destination === null) {
			deny('scheme_not_allowed', 'The browser opens http and https addresses only.')
			return
		}
		if (typeof token !== 'string' || token === '') {
			deny('no_run_token', 'No run token was presented.')
			return
		}
		let target
		try {
			target = new URL(options.pdpUrl)
		} catch (e) {
			deny('pdp_misconfigured', 'The egress policy decision point is not configured.')
			return
		}
		const body = JSON.stringify(destination)
		const transport = options.request || (target.protocol === 'https:' ? require('https').request : http.request)
		const req = transport({
			method: 'POST',
			hostname: target.hostname,
			port: target.port || (target.protocol === 'https:' ? 443 : 80),
			path: target.pathname + target.search,
			headers: { 'content-type': 'application/json', 'content-length': Buffer.byteLength(body), authorization: `Bearer ${token}` },
			timeout: timeoutMs,
		}, (res) => {
			let raw = ''
			res.setEncoding('utf8')
			res.on('data', (chunk) => {
				if (raw.length < 64 * 1024) {
					raw += chunk
				}
			})
			res.on('end', () => {
				if (res.statusCode !== 200) {
					deny('pdp_refused', 'The egress policy refused the run.')
					return
				}
				try {
					const verdict = JSON.parse(raw)
					if (verdict !== null && verdict.allowed === true) {
						resolve({ allowed: true, code: 'allowed', message: '' })
						return
					}
					deny(String((verdict && verdict.code) || 'denied'), String((verdict && verdict.message) || 'The address is not allowed.'))
				} catch (e) {
					deny('pdp_malformed', 'The egress policy answered unreadably.')
				}
			})
			res.on('error', () => deny('pdp_unreachable', 'The egress policy could not be reached.'))
		})
		req.on('timeout', () => {
			req.destroy()
			deny('pdp_timeout', 'The egress policy did not answer in time.')
		})
		req.on('error', () => deny('pdp_unreachable', 'The egress policy could not be reached.'))
		req.end(body)
	})
}

/**
 * The browser contexts, one per conversation.
 *
 * The driver opens a page with a request gate: it must call the gate for every
 * request the page makes and fail the request unless the gate allows it.
 */
class BrowserSessions {
	/**
	 * @param {{driver: object, egress: Function, now?: Function, idleMs?: number}} options
	 *   `driver.openPage({gate, proxyToken})` returns a page with `navigate(url)`, `axTree()`,
	 *   `click(backendNodeId)`, `field(backendNodeId)`, `fill(backendNodeId, text)` and `close()`.
	 */
	constructor(options) {
		this.driver = options.driver
		this.egress = options.egress
		this.now = options.now || (() => Date.now())
		this.idleMs = options.idleMs || IDLE_MS
		this.sessions = new Map()
	}

	/**
	 * The conversation's context, opened on first use.
	 *
	 * @param {string} conversationId The conversation.
	 * @param {string} token The run token for this call.
	 * @return {Promise<object>}
	 */
	async session(conversationId, token) {
		if (typeof conversationId !== 'string' || conversationId === '') {
			throw new BrowserRefusal('invalid_argument', 'A conversation is required.', 400)
		}
		let session = this.sessions.get(conversationId)
		if (session === undefined) {
			session = { page: null, refs: {}, token, lastUsed: this.now() }
			// The gate reads the token of the call in progress, so a page never
			// keeps connecting on a token that has since expired.
			session.page = await this.driver.openPage({ gate: (url) => this.egress(url, session.token), proxyToken: () => session.token })
			this.sessions.set(conversationId, session)
		}
		session.token = token
		session.lastUsed = this.now()
		return session
	}

	/**
	 * Open a URL and answer its snapshot.
	 *
	 * @param {string} conversationId The conversation.
	 * @param {string} url The address.
	 * @param {string} token The run token.
	 * @return {Promise<object>}
	 */
	async open(conversationId, url, token) {
		const verdict = await this.egress(url, token)
		if (verdict.allowed !== true) {
			throw new BrowserRefusal(verdict.code, `The address is not allowed: ${verdict.message}`, 403)
		}
		const session = await this.session(conversationId, token)
		await session.page.navigate(url)
		return this.snapshot(session)
	}

	/**
	 * The current page's snapshot.
	 *
	 * @param {string} conversationId The conversation.
	 * @param {string} token The run token.
	 * @return {Promise<object>}
	 */
	async read(conversationId, token) {
		return this.snapshot(await this.opened(conversationId, token))
	}

	/**
	 * Click a referenced element and answer the snapshot after it.
	 *
	 * @param {string} conversationId The conversation.
	 * @param {string} ref The element reference.
	 * @param {string} token The run token.
	 * @return {Promise<object>}
	 */
	async click(conversationId, ref, token) {
		const session = await this.opened(conversationId, token)
		await session.page.click(this.nodeOf(session, ref))
		return this.snapshot(session)
	}

	/**
	 * Type text into a referenced field, never into a credential field.
	 *
	 * @param {string} conversationId The conversation.
	 * @param {string} ref The element reference.
	 * @param {string} text The text.
	 * @param {string} token The run token.
	 * @return {Promise<object>}
	 */
	async fill(conversationId, ref, text, token) {
		const session = await this.opened(conversationId, token)
		const node = this.nodeOf(session, ref)
		const field = await session.page.field(node)
		if (isCredentialField(field || {})) {
			throw new BrowserRefusal('password_field', PASSWORD_REFUSAL, 422)
		}
		await session.page.fill(node, String(text))
		return this.snapshot(session)
	}

	/**
	 * Close a conversation's context.
	 *
	 * @param {string} conversationId The conversation.
	 * @return {Promise<boolean>} Whether a context was open.
	 */
	async close(conversationId) {
		const session = this.sessions.get(conversationId)
		if (session === undefined) {
			return false
		}
		this.sessions.delete(conversationId)
		await session.page.close()
		return true
	}

	/**
	 * Close every context unused for the idle time.
	 *
	 * @return {Promise<number>} How many were closed.
	 */
	async sweep() {
		const cutoff = this.now() - this.idleMs
		const idle = [...this.sessions.entries()].filter(([, session]) => session.lastUsed <= cutoff).map(([id]) => id)
		for (const id of idle) {
			await this.close(id)
		}
		return idle.length
	}

	/**
	 * A context that already has a page open.
	 *
	 * @param {string} conversationId The conversation.
	 * @param {string} token The run token.
	 * @return {Promise<object>}
	 */
	async opened(conversationId, token) {
		if (!this.sessions.has(conversationId)) {
			throw new BrowserRefusal('no_page', 'No page is open. Open one with hermiq.browserOpen first.', 409)
		}
		return this.session(conversationId, token)
	}

	/**
	 * The DOM node a reference of the last snapshot names.
	 *
	 * @param {object} session The context.
	 * @param {string} ref The reference.
	 * @return {number}
	 */
	nodeOf(session, ref) {
		const node = session.refs[String(ref)]
		if (node === undefined) {
			throw new BrowserRefusal('unknown_ref', `No element ${ref} on the page. Read the page again for current references.`, 422)
		}
		return node
	}

	/**
	 * Take the snapshot and remember its references.
	 *
	 * @param {object} session The context.
	 * @return {Promise<object>}
	 */
	async snapshot(session) {
		const snapshot = snapshotOf(await session.page.axTree())
		session.refs = snapshot.refs
		return { url: await session.page.url(), title: await session.page.title(), snapshot: snapshot.text, truncated: snapshot.truncated }
	}
}

module.exports = {
	BrowserRefusal,
	BrowserSessions,
	CREDENTIAL_AUTOCOMPLETE,
	IDLE_MS,
	PASSWORD_REFUSAL,
	SNAPSHOT_MAX_BYTES,
	destinationOf,
	isCredentialField,
	pdpEgress,
	snapshotOf,
}
