/**
 * Pool feasibility probe — DEV INSTRUMENT, NOT A SERVING PATH.
 *
 * `persistent-llm-process` rests on one unproven claim: that a `claude` process
 * started with `--input-format stream-json` can answer MORE THAN ONE turn. D0 in
 * that design proved only that the process reaches `{"type":"system",
 * "subtype":"init"}` and stays alive — reaching init is not answering, and a pool
 * built on "stays alive" would be built on an assumption.
 *
 * This probe answers two questions that decide the whole design:
 *
 *   1. Does a second turn on the SAME process actually produce a completion?
 *   2. Does conversation state carry across turns?
 *
 * Question 2 cuts both ways and that is why it is asked here rather than
 * discovered later. Carried state is what makes pooling cheap (no history to
 * re-send), and it is simultaneously the leak: Hermiq sends the FULL flattened
 * history every turn, so a process that also remembers would double it — and a
 * process shared between two callers would carry one caller's words into the
 * other's turn. The answer determines whether a pool may be keyed by (agent,
 * user) at all, or only ever by conversation.
 *
 * It is driven by a real credential because there is no other way to get one: the
 * runner is handed `credentialEnv` per request and never stores it. The probe
 * therefore piggybacks on a live `/run`, AFTER that turn has been served, and
 * holds the value only for the duration of the probe. Nothing is persisted and
 * nothing is logged but timings and a yes/no.
 *
 * Enabled only when `RUNNER_POOL_PROBE=1`, and it self-disables after one run so
 * a forgotten flag cannot spawn a probe per turn.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

'use strict'

const { spawn } = require('child_process')

/** A word the model cannot produce by chance, used to detect carried state. */
const CANARY = 'ZARQUON'

/** Hard ceiling on the whole probe, so a hung CLI cannot pin a slot forever. */
const PROBE_TIMEOUT_MS = 180000

let alreadyRan = false

/**
 * Frame a user message the way `--input-format stream-json` expects.
 *
 * @param {string} text The message text.
 * @returns {string} A newline-terminated JSON frame.
 */
function userFrame(text) {
	return `${JSON.stringify({
		type: 'user',
		message: { role: 'user', content: [{ type: 'text', text }] },
	})}\n`
}

/**
 * Run the two-turn feasibility probe against the real CLI.
 *
 * @param {object}   opts               Probe options.
 * @param {object}   opts.provider      The resolved provider (for `bin`).
 * @param {string}   [opts.model]       Model id to pass through.
 * @param {object}   opts.credentialEnv Credential env for the child process.
 * @param {Function} opts.log           Logger, called as log(level, message).
 * @returns {Promise<void>} Resolves when the probe has reported.
 */
function runProbe({ provider, model, credentialEnv, log }) {
	if (alreadyRan) {
		return Promise.resolve()
	}
	alreadyRan = true

	return new Promise((resolve) => {
		const args = ['-p', '--input-format', 'stream-json', '--output-format', 'stream-json', '--verbose']
		if (model) {
			args.push('--model', model)
		}

		const t0 = Date.now()
		let initAt = -1
		let turn1At = -1
		let turn2At = -1
		let turn1Text = ''
		let turn2Text = ''
		let turn = 0
		let buffer = ''
		let child
		let settled = false
		let rawOut = ''
		let rawErr = ''

		const finish = (verdict) => {
			if (settled) {
				return
			}
			settled = true
			try {
				child && child.kill('SIGKILL')
			} catch { /* already gone */ }
			log(
				'info',
				`POOL PROBE ${verdict} init=${initAt}ms turn1=${turn1At}ms turn2=${turn2At}ms `
					+ `secondTurnAnswered=${turn2At > 0} `
					+ `contextCarried=${turn2Text.toUpperCase().includes(CANARY)} `
					+ `turn1="${turn1Text.slice(0, 40)}" turn2="${turn2Text.slice(0, 60)}"`,
			)
			// When the probe learns nothing, the raw streams are the only evidence
			// of WHY — a verdict with no diagnosis just repeats the experiment.
			if (turn2At < 0) {
				log('info', `POOL PROBE raw stdout[0:400]=${JSON.stringify(rawOut.slice(0, 400))}`)
				log('info', `POOL PROBE raw stderr[0:400]=${JSON.stringify(rawErr.slice(0, 400))}`)
			}
			resolve()
		}

		const timer = setTimeout(() => finish('TIMEOUT'), PROBE_TIMEOUT_MS)
		timer.unref && timer.unref()

		try {
			child = spawn(provider.bin, args, {
				env: { ...process.env, ...credentialEnv },
				stdio: ['pipe', 'pipe', 'pipe'],
			})
		} catch (err) {
			clearTimeout(timer)
			log('error', `POOL PROBE could not spawn: ${err.message}`)
			resolve()
			return
		}

		// Send turn 1 IMMEDIATELY rather than waiting for an `init` event. The
		// first attempt waited for init and deadlocked at the 180s ceiling with
		// init=-1: the CLI does not necessarily announce itself before it has
		// input, so "spawn, then wait to be greeted" is a hang, not a protocol.
		turn = 1
		child.stdin.write(userFrame(`Remember this word: ${CANARY}. Reply with exactly: ACK`))

		child.stdout.on('data', (chunk) => {
			rawOut += chunk.toString('utf8')
			buffer += chunk.toString('utf8')
			const lines = buffer.split('\n')
			buffer = lines.pop() || ''

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

				if (evt.type === 'system' && evt.subtype === 'init' && initAt < 0) {
					initAt = Date.now() - t0
					continue
				}

				// A completed turn arrives as a `result` event.
				if (evt.type === 'result') {
					const text = typeof evt.result === 'string' ? evt.result : ''
					if (turn === 1) {
						turn1At = Date.now() - t0
						turn1Text = text.trim()
						// Turn 2 on the SAME process: does it answer at all, and
						// does it still hold the word?
						turn = 2
						child.stdin.write(
							userFrame(
								'What word did I ask you to remember? Reply with just that word, '
									+ 'or the single word NONE if you were not asked.',
							),
						)
						continue
					}
					if (turn === 2) {
						turn2At = Date.now() - t0
						turn2Text = text.trim()
						clearTimeout(timer)
						finish('OK')
						return
					}
				}
			}
		})

		child.stderr.on('data', (chunk) => {
			rawErr += chunk.toString('utf8')
		})

		child.on('close', () => {
			if (turn2At < 0) {
				clearTimeout(timer)
				finish('PROCESS_EXITED_EARLY')
			}
		})

		child.on('error', (err) => {
			clearTimeout(timer)
			log('error', `POOL PROBE child error: ${err.message}`)
			resolve()
		})
	})
}

module.exports = { runProbe }
