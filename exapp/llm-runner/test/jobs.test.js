/**
 * The async stage-job registry.
 *
 * `POST /stage` answers when the workload is finished, which is six to
 * twenty-five minutes for a build. OpenRegister's `FlowRunWorker` advances
 * queued runs SERIALLY in one process, so that does not merely block its own
 * run — it blocks every other flow in the pass, and it makes hydra's slot pool
 * decorative, because four slots cannot produce four agents while the thing
 * holding a slot occupies the only worker.
 *
 * This registry is what lets the flow dispatch, suspend, and collect later. The
 * two properties worth testing are both about a caller NOT being misled:
 *
 *   * a stage that could not be carried out — a refused push above all — stays
 *     FAILED and never becomes a `done` carrying a falsy field. The synchronous
 *     endpoint answers 502 for exactly this reason;
 *   * an id this process does not have is `unknown` and TERMINAL, never
 *     `running`. The registry is in memory, so a restart loses every job, and a
 *     poller that cannot tell "lost" from "not finished yet" waits forever.
 *
 * Run: `node --test test/jobs.test.js`.
 *
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @copyright 2026 Conduction B.V.
 */

'use strict'

const test = require('node:test')
const assert = require('node:assert')

const jobs = require('../src/jobs')

/**
 * Let the microtask queue drain so a settled promise has reached the registry.
 *
 * @returns {Promise<void>} Resolves on the next tick.
 */
const settle = () => new Promise((resolve) => setImmediate(resolve))

test('a handle comes back immediately, while the work is still running', async () => {
	let finish
	const id = jobs.start(
		new Promise((resolve) => {
			finish = resolve
		}),
	)

	assert.match(
		id,
		/^[0-9a-f-]{36}$/,
		'the handle is a uuid the caller can poll with',
	)
	assert.strictEqual(
		jobs.get(id).status,
		'running',
		'work in flight reads as running',
	)

	finish({ exitCode: 0, output: 'ok' })
	await settle()

	const done = jobs.get(id)
	assert.strictEqual(done.status, 'done')
	assert.strictEqual(
		done.result.exitCode,
		0,
		'the workload result is carried through verbatim',
	)
})

test('a refused push stays FAILED and never becomes a done result', async () => {
	// The exact shape pushGuard throws. The synchronous route answers 502 for
	// this so a caller reading only the status cannot record a refused push as
	// a completed stage; the async path has to preserve that or the fence
	// becomes advisory.
	const refusal = Object.assign(
		new Error(
			'push refused: "README.md" is outside the scope this issue declared',
		),
		{
			name: 'PushRefused',
			code: 'scope_violation',
		},
	)

	const id = jobs.start(Promise.reject(refusal))
	await settle()

	const state = jobs.get(id)
	assert.strictEqual(
		state.status,
		'failed',
		'a refusal is a FAILED job, not a done one',
	)
	assert.strictEqual(
		state.code,
		'scope_violation',
		'the stable refusal code survives, so a consumer need not match prose',
	)
	assert.ok(
		state.result === undefined,
		'a failed job carries no result a caller could mistake for success',
	)
})

test('an id the process does not have is UNKNOWN, and terminal', async () => {
	const state = jobs.get('00000000-0000-4000-8000-000000000000')

	assert.strictEqual(
		state.status,
		'unknown',
		'a lost job must not read as running — a poller that cannot tell those apart waits forever',
	)
})

test('a collected job is forgotten, and asking twice says so', async () => {
	const id = jobs.start(Promise.resolve({ exitCode: 0 }))
	await settle()

	assert.strictEqual(jobs.get(id).status, 'done')
	assert.strictEqual(jobs.forget(id), true)
	assert.strictEqual(
		jobs.get(id).status,
		'unknown',
		'after collection the handle is spent, and says so terminally',
	)
})

test('a rejected job does not take the process down', async () => {
	// A detached promise whose rejection nobody handles kills the runner in
	// Node 22 — turning one failed stage into an outage for every other job in
	// flight. `start()` attaches the handler, so this must simply be recorded.
	const id = jobs.start(Promise.reject(new Error('boom')))
	await settle()

	assert.strictEqual(jobs.get(id).status, 'failed')
	assert.strictEqual(jobs.get(id).error, 'boom')
})

test('stats count what is in flight', async () => {
	let finish
	const running = jobs.start(
		new Promise((resolve) => {
			finish = resolve
		}),
	)
	const s = jobs.stats()

	assert.ok(s.running >= 1, 'a job in flight is visible in the heartbeat')

	finish({ exitCode: 0 })
	await settle()
	jobs.forget(running)
})

test('a caller-supplied key becomes the handle, so it can be rebuilt later', async () => {
	// The flow engine suspends a run exactly once — WaitNode passes straight
	// through on the way back in — so a run whose stage is still going has to
	// end and let a later tick collect. That tick starts from the issue, not
	// from the item, so a random uuid dies with the run that received it.
	//
	// A derivable key removes the need to store anything at all.
	const key = 'larpingapp-327-code-review'
	const id = jobs.start(Promise.resolve({ exitCode: 0 }), key)

	assert.strictEqual(id, key, 'the supplied key IS the handle')
	await new Promise((resolve) => setImmediate(resolve))
	assert.strictEqual(
		jobs.get(key).status,
		'done',
		'a later tick that rebuilds the same key finds the same job',
	)
	jobs.forget(key)
})

test('without a key the handle is still a uuid', async () => {
	const id = jobs.start(Promise.resolve({ exitCode: 0 }))
	assert.match(id, /^[0-9a-f-]{36}$/, 'the uuid path is unchanged for callers that supply nothing')
	await new Promise((resolve) => setImmediate(resolve))
	jobs.forget(id)
})
