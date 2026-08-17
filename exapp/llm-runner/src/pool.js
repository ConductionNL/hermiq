/**
 * Persistent CLI process pool.
 *
 * A `-p` one-shot pays process start plus session init on EVERY turn. Measured
 * 2026-08-17: that is ~1.3s on a quiet machine and up to ~9.2s under load, while
 * the vendor API time stays near-constant at ~2.1-2.7s. The harness, not the
 * model, is the slow part, and it is the only part that degrades under pressure.
 * A process kept alive between turns pays it once: a second turn on the same
 * process measured 1398ms against 2948ms for the first.
 *
 * ## What keeps this honest
 *
 * **The cold path is always correct.** Every failure here — no key, no free slot,
 * an unhealthy process, a dispatch error, a timeout — returns null so the caller
 * spawns a one-shot exactly as before. Pooling is an optimisation that may always
 * decline; it must never be a new way for a turn to fail.
 *
 * **Processes remember.** A stream-json session carries its turns (measured: a
 * second turn recalled a canary with no history re-sent). So the key is the
 * CONVERSATION, chosen by Hermiq, and a process is never shared across keys.
 * Hermiq also stops re-sending history on a hit — see `dispatch()`.
 *
 * **The token cannot be refreshed.** The CLI reads `--mcp-config` once at startup
 * and never again (measured, `REREAD=false`), so a pooled process holds its
 * original run token for life. Hermiq mints that token with a TTL equal to
 * `poolLifetimeSeconds` and does NOT consume it at turn end. This module must
 * therefore never keep a process beyond that lifetime: doing so leaves a live
 * process holding a dead token, whose next tool call fails as "the model has no
 * tools" — silent, and the shape of two prior bugs in this codebase.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

'use strict'

const { spawn } = require('child_process')

/** Most processes held at once, across all keys. */
const MAX_PROCESSES = 8

/** Idle time after which a process is reaped even if its lifetime remains. */
const IDLE_REAP_MS = 120000

/** Ceiling on one pooled turn before the process is declared unhealthy. */
const TURN_TIMEOUT_MS = 180000

/** key -> entry */
const processes = new Map()

let hits = 0
let misses = 0

/**
 * Statistics for the `/pool-stats` endpoint. A pool that never hits is
 * indistinguishable from one that works unless the hit rate is observable.
 *
 * @returns {object} Counters and current occupancy.
 */
function stats() {
	return {
		hits,
		misses,
		live: processes.size,
		keys: [...processes.keys()].map((k) => k.slice(0, 12)),
	}
}

/**
 * Frame a user message for `--input-format stream-json`.
 *
 * @param {string} text The message text.
 * @returns {string} Newline-terminated JSON frame.
 */
function userFrame(text) {
	return `${JSON.stringify({
		type: 'user',
		message: { role: 'user', content: [{ type: 'text', text }] },
	})}\n`
}

/**
 * Terminate and forget a pooled process.
 *
 * @param {string} key    Pool key.
 * @param {string} reason Why it is being reaped (logged).
 * @param {Function} log  Logger.
 * @returns {void}
 */
function reap(key, reason, log) {
	const entry = processes.get(key)
	if (!entry) {
		return
	}
	processes.delete(key)
	try {
		entry.child.kill('SIGKILL')
	} catch { /* already gone */ }
	log('info', `pool reaped key=${key.slice(0, 12)} reason=${reason}`)
}

/**
 * Reap anything idle, expired, or dead. Called before every acquisition so a
 * stale entry is never handed out.
 *
 * @param {Function} log Logger.
 * @returns {void}
 */
function sweep(log) {
	const now = Date.now()
	for (const [key, entry] of [...processes.entries()]) {
		if (entry.child.exitCode !== null || entry.child.signalCode !== null) {
			reap(key, 'process exited', log)
			continue
		}
		if (now >= entry.expiresAt) {
			// The token died with this deadline. Keeping the process would be
			// keeping one that can no longer call a tool.
			reap(key, 'lifetime reached (token expired)', log)
			continue
		}
		if (now - entry.lastUsedAt > IDLE_REAP_MS) {
			reap(key, 'idle', log)
		}
	}
}

/**
 * Start a pooled process for `key`.
 *
 * @param {object} opts Spawn options (see dispatch).
 * @returns {object|null} The new entry, or null if it could not be started.
 */
function start({ key, provider, model, args, childEnv, lifetimeMs, log }) {
	let child
	try {
		child = spawn(provider.bin, args, {
			cwd: opts_cwd(),
			env: childEnv,
			stdio: ['pipe', 'pipe', 'pipe'],
		})
	} catch (err) {
		log('warn', `pool spawn failed key=${key.slice(0, 12)}: ${err.message}`)
		return null
	}

	const entry = {
		child,
		key,
		model,
		createdAt: Date.now(),
		lastUsedAt: Date.now(),
		expiresAt: Date.now() + lifetimeMs,
		turns: 0,
		buffer: '',
		busy: false,
		onResult: null,
	}

	child.stdout.on('data', (chunk) => {
		entry.buffer += chunk.toString('utf8')
		const lines = entry.buffer.split('\n')
		entry.buffer = lines.pop() || ''
		for (const line of lines) {
			if (line.trim() === '') {
				continue
			}
			let evt
			try {
				evt = JSON.parse(line)
			} catch {
				continue
			}
			// An auth failure does NOT kill the CLI; it retries with exponential
			// backoff toward ten attempts while looking alive and responsive, so a
			// liveness probe cannot see it. Treat it as terminal for this process.
			if (evt.type === 'system' && evt.subtype === 'api_retry') {
				const status = evt.error_status ?? evt.status
				if (String(status) === '401') {
					const cb = entry.onResult
					entry.onResult = null
					reap(entry.key, 'api_retry 401', log)
					cb && cb(new Error('pooled process lost its credential'))
				}
				continue
			}
			if (evt.type === 'result') {
				const cb = entry.onResult
				entry.onResult = null
				cb && cb(null, evt)
			}
		}
	})

	child.stderr.on('data', () => { /* provider noise; never logged (may echo input) */ })
	child.on('close', () => {
		const cb = entry.onResult
		entry.onResult = null
		processes.delete(entry.key)
		cb && cb(new Error('pooled process exited mid-turn'))
	})
	child.on('error', () => { /* surfaced via close */ })

	processes.set(key, entry)
	log(
		'info',
		`pool started key=${key.slice(0, 12)} lifetime=${Math.round(lifetimeMs / 1000)}s live=${processes.size}`,
	)
	return entry
}

/**
 * The pooled child's working directory. Kept as a function so the scratch
 * convention stays in one place if it changes.
 *
 * @returns {string} A writable cwd.
 */
function opts_cwd() {
	return '/tmp'
}

/**
 * Serve one turn from a pooled process, starting one if needed.
 *
 * Returns null whenever the pool declines, and the caller MUST then take the
 * cold path. Never throws for a pooling reason.
 *
 * @param {object}   o                     Options.
 * @param {string}   o.key                 Pool key (the conversation).
 * @param {object}   o.provider            Resolved provider.
 * @param {string}   [o.model]             Model id.
 * @param {string}   o.prompt              Prompt for a COLD start (full history).
 * @param {string}   o.latestMessage       Just the newest user message, for a HIT.
 * @param {object}   o.childEnv            Environment for a cold start.
 * @param {Array}    o.args                Argv for a cold start.
 * @param {number}   o.lifetimeMs          How long the process (and token) live.
 * @param {Function} o.log                 Logger.
 * @returns {Promise<object|null>} `{text, pooled, turns}` or null to fall back.
 */
async function dispatch(o) {
	const { key, log } = o
	if (!key) {
		return null
	}

	sweep(log)

	let entry = processes.get(key)
	let coldStart = false

	if (entry && entry.busy) {
		// Two turns for one conversation at once. Serialising them here would make
		// the second wait on the first for no reason the caller can see.
		misses += 1
		log('info', `pool declined key=${key.slice(0, 12)} reason=busy`)
		return null
	}

	if (!entry) {
		if (processes.size >= MAX_PROCESSES) {
			misses += 1
			log('info', `pool declined key=${key.slice(0, 12)} reason=full`)
			return null
		}
		entry = start({ ...o, lifetimeMs: o.lifetimeMs })
		if (!entry) {
			misses += 1
			return null
		}
		coldStart = true
	}

	// On a COLD start the process knows nothing, so it gets the full flattened
	// history. On a HIT it already holds every prior turn of THIS conversation
	// (measured), so re-sending history would show it to the model twice.
	const text = coldStart ? o.prompt : o.latestMessage
	if (!text) {
		misses += 1
		return null
	}

	entry.busy = true
	const startedAt = Date.now()

	const result = await new Promise((resolve) => {
		let done = false
		const timer = setTimeout(() => {
			if (done) {
				return
			}
			done = true
			entry.onResult = null
			reap(key, 'turn timeout', log)
			resolve({ error: new Error('pooled turn timed out') })
		}, TURN_TIMEOUT_MS)

		entry.onResult = (err, evt) => {
			if (done) {
				return
			}
			done = true
			clearTimeout(timer)
			resolve(err ? { error: err } : { evt })
		}

		try {
			entry.child.stdin.write(userFrame(text))
		} catch (err) {
			if (!done) {
				done = true
				clearTimeout(timer)
				entry.onResult = null
				reap(key, 'stdin write failed', log)
				resolve({ error: err })
			}
		}
	})

	entry.busy = false

	if (result.error) {
		misses += 1
		log('warn', `pool turn failed key=${key.slice(0, 12)}: ${result.error.message} -- falling back`)
		return null
	}

	const evt = result.evt
	const answer = typeof evt.result === 'string' ? evt.result : ''
	if (answer === '') {
		misses += 1
		log('warn', `pool turn produced no text key=${key.slice(0, 12)} -- falling back`)
		return null
	}

	entry.lastUsedAt = Date.now()
	entry.turns += 1
	if (coldStart) {
		misses += 1
	} else {
		hits += 1
	}

	log(
		'info',
		`pool ${coldStart ? 'COLD' : 'HIT'} key=${key.slice(0, 12)} turn=${entry.turns} `
			+ `in=${Date.now() - startedAt}ms api=${evt.duration_api_ms ?? -1}ms`,
	)

	return {
		text: answer,
		pooled: !coldStart,
		turns: entry.turns,
		usage: {
			...(evt.usage || {}),
			cliDurationMs: evt.duration_ms,
			cliApiMs: evt.duration_api_ms,
			pooled: !coldStart,
		},
	}
}

/**
 * Drop every pooled process for an agent whose capability changed. A warm
 * process holds a tool set fixed at startup, so a revoked tool would stay
 * callable until the process aged out.
 *
 * @param {Function} log Logger.
 * @returns {number} How many were reaped.
 */
function drainAll(log) {
	const n = processes.size
	for (const key of [...processes.keys()]) {
		reap(key, 'drained', log)
	}
	return n
}

module.exports = { dispatch, stats, drainAll, sweep }
