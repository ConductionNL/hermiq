/**
 * Subscription sign-in through the vendor's own CLI (models-provider-connections D5).
 *
 * A subscription can only be used through the vendor's CLI, so the sign-in runs
 * that CLI too: Hermiq never becomes an OAuth client of the vendor. One sign-in
 * is one CLI process in a fresh throwaway home:
 *
 *   1. `start()` spawns the provider's login command and waits for the sign-in
 *      URL it prints. It answers with a login id and that URL (and, for a device
 *      sign-in, the code the user enters on the vendor's page).
 *   2. `finish()` hands the one-time code the vendor's page showed to the CLI on
 *      STDIN (a code sign-in), or only waits (a device sign-in), until the CLI
 *      exits. The token is read from its output or from the auth file it wrote,
 *      returned ONCE, and the home directory is removed.
 *   3. A sign-in that is not finished is killed after `LOGIN_TTL_MS` and its home
 *      removed. The run token Hermiq minted for it expires at the same moment,
 *      so the vendor's sign-in hosts leave the egress allowlist with it.
 *
 * The token is never logged and never written anywhere but the response.
 *
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @copyright 2026 Conduction B.V.
 */

'use strict'

const crypto = require('crypto')
const fs = require('fs')
const os = require('os')
const path = require('path')
const { spawn } = require('child_process')

const { proxyEnvFor } = require('./proxyEnv')

/**
 * How long a sign-in may stay open, in milliseconds. Ten minutes, per the spec.
 *
 * @type {number}
 */
const LOGIN_TTL_MS = Number(process.env.RUNNER_LOGIN_TTL_MS || String(10 * 60 * 1000))

/**
 * How long `start()` waits for the CLI to print its sign-in URL.
 *
 * @type {number}
 */
const URL_WAIT_MS = Number(process.env.RUNNER_LOGIN_URL_WAIT_MS || '30000')

/**
 * The egress proxy authority (`host:port`); '' when ungoverned.
 *
 * @type {string}
 */
const EGRESS_PROXY = (process.env.EGRESS_PROXY_AUTHORITY || '').trim()

/**
 * Output kept per sign-in, in bytes: enough for a URL and a token, bounded so
 * a CLI that floods cannot exhaust memory.
 *
 * @type {number}
 */
const MAX_OUTPUT_BYTES = 64 * 1024

/**
 * The open sign-ins, by login id.
 *
 * @type {Map<string, object>}
 */
const logins = new Map()

/**
 * A refusal the route maps to a status, with a code a caller can branch on.
 */
class LoginError extends Error {

	/**
	 * @param {string} code Machine-readable reason.
	 * @param {string} message Human-readable reason (never carries a token).
	 * @param {number} status HTTP status for the route.
	 */
	constructor(code, message, status) {
		super(message)
		this.code = code
		this.status = status
	}

}

/**
 * The first https URL in a text, or ''.
 *
 * @param {string} text CLI output.
 * @returns {string} The URL.
 */
function firstUrl(text) {
	const match = /https:\/\/[^\s"'<>]+/.exec(text)
	return match === null ? '' : match[0]
}

/**
 * The device code a CLI printed, or ''.
 *
 * @param {object} login The provider's login adapter.
 * @param {string} text CLI output.
 * @returns {string} The code.
 */
function deviceCode(login, text) {
	if (!(login.deviceCodePattern instanceof RegExp)) {
		return ''
	}
	const match = login.deviceCodePattern.exec(text)
	return match === null ? '' : match[1] || match[0]
}

/**
 * The environment of a sign-in child: a throwaway home and the sign-in's own
 * proxy identity, nothing of the runner's own environment beyond PATH and LANG.
 *
 * @param {string} home The throwaway home.
 * @param {string} runToken The sign-in's run token.
 * @returns {object} The environment.
 */
function loginEnv(home, runToken) {
	return {
		PATH: process.env.PATH,
		HOME: home,
		TMPDIR: home,
		LANG: process.env.LANG || 'C.UTF-8',
		...proxyEnvFor(EGRESS_PROXY, runToken),
	}
}

/**
 * Remove a sign-in: kill its process if it still runs, delete its home, forget it.
 *
 * @param {string} loginId The login id.
 * @returns {void}
 */
function discard(loginId) {
	const entry = logins.get(loginId)
	if (entry === undefined) {
		return
	}
	logins.delete(loginId)
	clearTimeout(entry.timer)
	if (entry.child.exitCode === null && entry.child.signalCode === null) {
		try {
			process.kill(-entry.child.pid, 'SIGKILL')
		} catch (err) {
			entry.child.kill('SIGKILL')
		}
	}
	fs.rmSync(entry.home, { recursive: true, force: true })
}

/**
 * Append a chunk to a sign-in's bounded output and wake whoever waits on it.
 *
 * @param {object} entry The sign-in.
 * @param {Buffer} chunk The chunk.
 * @returns {void}
 */
function appendOutput(entry, chunk) {
	if (entry.output.length < MAX_OUTPUT_BYTES) {
		entry.output += chunk.toString('utf8').slice(0, MAX_OUTPUT_BYTES - entry.output.length)
	}
	for (const waiter of entry.waiters.splice(0)) {
		waiter()
	}
}

/**
 * Resolve once the CLI printed its sign-in URL; refuse when it exits first or
 * does not print one in time.
 *
 * @param {object} entry The sign-in.
 * @returns {Promise<string>} The URL.
 */
function waitForUrl(entry) {
	return new Promise((resolve, reject) => {
		const deadline = setTimeout(() => {
			reject(new LoginError('no_url', 'the CLI printed no sign-in URL in time', 502))
		}, URL_WAIT_MS)
		const check = () => {
			const url = firstUrl(entry.output)
			if (url !== '') {
				clearTimeout(deadline)
				resolve(url)
				return
			}
			if (entry.exited) {
				clearTimeout(deadline)
				reject(new LoginError('cli_exited', 'the CLI ended before it printed a sign-in URL', 502))
				return
			}
			entry.waiters.push(check)
		}
		check()
	})
}

/**
 * Start a sign-in for a provider.
 *
 * @param {object} provider The provider adapter; its `login` describes the sign-in.
 * @param {string} runToken The run token Hermiq minted for this sign-in (egress identity).
 * @returns {Promise<{loginId: string, url: string, mode: string, userCode: string}>} The sign-in.
 */
async function start(provider, runToken) {
	const login = provider && provider.login
	if (!login) {
		throw new LoginError('unsupported', 'this provider has no sign-in', 400)
	}

	const home = fs.mkdtempSync(path.join(os.tmpdir(), 'llm-login-'))
	let child
	try {
		child = spawn(provider.bin, login.args, {
			cwd: home,
			env: loginEnv(home, runToken),
			stdio: ['pipe', 'pipe', 'pipe'],
			// Its own process group, so a kill reaches whatever the CLI started.
			detached: true,
		})
	} catch (err) {
		fs.rmSync(home, { recursive: true, force: true })
		throw new LoginError('spawn_failed', `failed to start the sign-in: ${err.message}`, 502)
	}

	const loginId = crypto.randomUUID()
	const entry = { child, home, login, output: '', exited: false, exitCode: null, waiters: [], timer: null }
	entry.timer = setTimeout(() => discard(loginId), LOGIN_TTL_MS)
	logins.set(loginId, entry)

	child.stdout.on('data', (chunk) => appendOutput(entry, chunk))
	child.stderr.on('data', (chunk) => appendOutput(entry, chunk))
	child.on('error', () => {
		entry.exited = true
		appendOutput(entry, Buffer.alloc(0))
	})
	child.on('close', (code) => {
		entry.exited = true
		entry.exitCode = code
		appendOutput(entry, Buffer.alloc(0))
	})

	let url
	try {
		url = await waitForUrl(entry)
	} catch (err) {
		discard(loginId)
		throw err
	}

	return { loginId, url, mode: login.mode, userCode: deviceCode(login, entry.output) }
}

/**
 * Resolve once the CLI exited.
 *
 * @param {object} entry The sign-in.
 * @returns {Promise<void>} Resolves on exit.
 */
function waitForExit(entry) {
	return new Promise((resolve) => {
		const check = () => {
			if (entry.exited) {
				resolve()
				return
			}
			entry.waiters.push(check)
		}
		check()
	})
}

/**
 * The token a finished sign-in produced: from the auth file the CLI wrote, or
 * matched in its output.
 *
 * @param {object} entry The sign-in.
 * @returns {string} The token, '' when none was produced.
 */
function producedToken(entry) {
	if (typeof entry.login.authFile === 'string') {
		const file = path.join(entry.home, entry.login.authFile)
		try {
			return fs.readFileSync(file, 'utf8').trim()
		} catch (err) {
			return ''
		}
	}
	const match = entry.login.tokenPattern.exec(entry.output)
	return match === null ? '' : match[0]
}

/**
 * Finish a sign-in: pass the code on (code mode), wait for the CLI, return the token once.
 *
 * @param {string} loginId The login id `start()` returned.
 * @param {string} code The one-time code the vendor's page showed ('' for a device sign-in).
 * @returns {Promise<{token: string}>} The token.
 */
async function finish(loginId, code) {
	const entry = logins.get(loginId)
	if (entry === undefined) {
		throw new LoginError('unknown_login', 'no open sign-in has this id; it may have expired', 404)
	}

	if (entry.login.mode === 'code') {
		if (typeof code !== 'string' || code.trim() === '' || /[\r\n]/.test(code.trim())) {
			throw new LoginError('code_required', 'the code from the sign-in page is required', 400)
		}
		entry.child.stdin.write(code.trim() + '\n')
		entry.child.stdin.end()
	}

	try {
		await waitForExit(entry)
		const token = entry.exitCode === 0 ? producedToken(entry) : ''
		if (token === '') {
			throw new LoginError('not_signed_in', 'the sign-in did not complete; start it again', 502)
		}
		return { token }
	} finally {
		discard(loginId)
	}
}

/**
 * Whether a sign-in is open (for tests and the poll of a caller).
 *
 * @param {string} loginId The login id.
 * @returns {boolean} True while open.
 */
function isOpen(loginId) {
	return logins.has(loginId)
}

module.exports = { start, finish, isOpen, discard, LoginError, LOGIN_TTL_MS }
