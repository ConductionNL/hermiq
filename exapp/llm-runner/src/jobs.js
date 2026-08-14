/**
 * In-flight stage jobs, so a caller does not have to hold the connection open.
 *
 * WHY THIS EXISTS
 * ---------------
 * `POST /stage` runs the workload and answers when it is finished. A build is
 * six to twenty-five minutes, and OpenRegister's `FlowRunWorker` advances
 * queued runs SERIALLY in one PHP process:
 *
 *     foreach ($this->mapper->findQueued(limit: 25) as $run) { $this->advance($run); }
 *
 * so a synchronous stage does not merely block its own run — it blocks every
 * other flow in that pass, including the lock reaper whose whole job is to
 * clean up after stuck work. It also makes hydra's slot pool decorative: four
 * slots cannot produce four agents when the thing holding a slot occupies the
 * only worker.
 *
 * With a job handle the flow dispatches, suspends (releasing the worker), and
 * collects on a later tick. The pool then bounds real concurrency instead of
 * bounding a queue of one.
 *
 * WHAT THIS DELIBERATELY IS NOT
 * -----------------------------
 * Not a queue and not durable. Jobs live in this process's memory because the
 * runtime filesystem is read-only and a second store would be a second thing to
 * get wrong. The consequence is stated rather than hidden: if the runner
 * restarts, its jobs are GONE, and `get()` answers `unknown` — a distinct,
 * TERMINAL answer, never `running`. A poller that cannot tell "lost" from "not
 * finished yet" waits forever, which is the failure this module is careful to
 * make impossible.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

'use strict'

const crypto = require('crypto')

/**
 * How long a FINISHED job is kept so it can still be collected.
 *
 * Long enough that a flow suspended for a few minutes always finds its result,
 * short enough that a runner serving a busy fleet does not accumulate
 * megabytes of stage output forever. Only terminal jobs age out; a running one
 * is never pruned, however long it takes.
 */
const RESULT_TTL_MS = Number(process.env.RUNNER_JOB_TTL_MS || String(60 * 60 * 1000))

/** Terminal job states. `running` is the only non-terminal one. */
const RUNNING = 'running'
const DONE = 'done'
const FAILED = 'failed'
const UNKNOWN = 'unknown'

/** @type {Map<string, {status: string, startedAt: number, endedAt: number|null, result: object|null, error: string|null, code: string|null}>} */
const jobs = new Map()

/**
 * Drop finished jobs whose result nobody collected in time.
 *
 * Called on every registry touch rather than on a timer: a timer would keep the
 * process alive and would run when there is nothing to do, and the registry is
 * small enough that a sweep costs nothing.
 *
 * @returns {void}
 */
function prune() {
	const cutoff = Date.now() - RESULT_TTL_MS
	for (const [id, job] of jobs) {
		if (job.status !== RUNNING && job.endedAt !== null && job.endedAt < cutoff) {
			jobs.delete(id)
		}
	}
}

/**
 * Start a job and return its handle immediately.
 *
 * The promise is deliberately NOT awaited here — that is the entire point — and
 * its rejection is captured onto the record rather than escaping. An unhandled
 * rejection in a detached promise takes the whole runner down in Node 22, which
 * would turn one failed stage into an outage for every other job in flight.
 *
 * @param {Promise<object>} promise The work already started by the caller.
 * @param {string} [key] A caller-supplied id, so the handle can be rebuilt
 *                       later without having been stored. Falls back to a uuid.
 *
 * @returns {string} The job id to poll with.
 */
function start(promise, key) {
	prune()

	// A CALLER-SUPPLIED KEY MAKES THE HANDLE DERIVABLE, which is what lets a
	// flow collect a stage it did not dispatch.
	//
	// The flow engine suspends a run exactly once: `WaitNode` passes straight
	// through on the way back in, so a run that finds its stage still running
	// cannot wait again — it has to end and let a later tick collect. That tick
	// starts from the issue, not from the item, so a random uuid would be lost
	// with the run that received it. A key the tick can rebuild needs nothing
	// stored, and so cannot go stale or be missed by a write that failed.
	//
	// So the key is `<repo>-<issue>-<stage>`, which any tick can rebuild from
	// the issue it is looking at. Same stage, same key, no storage.
	const id = (typeof key === 'string' && key.trim() !== '') ? key.trim() : crypto.randomUUID()
	const job = {
		status: RUNNING,
		startedAt: Date.now(),
		endedAt: null,
		result: null,
		error: null,
		code: null,
	}
	jobs.set(id, job)

	promise.then(
		(result) => {
			job.status = DONE
			job.result = result
			job.endedAt = Date.now()
		},
		(err) => {
			// FAILED, not "done with an error field". The synchronous endpoint
			// answers 502 for a stage that could not be carried out — including
			// a REFUSED PUSH — precisely so a caller reading only the outcome
			// cannot record it as a completed stage. The async path has to
			// preserve that distinction or the fence becomes advisory.
			job.status = FAILED
			job.error = err && err.message ? err.message : String(err)
			job.code = err && err.code ? err.code : null
			job.endedAt = Date.now()
		},
	)

	return id
}

/**
 * Read a job's current state.
 *
 * @param {string} id The job id.
 *
 * @returns {object} `{status}` plus `result` / `error` / `code` when terminal.
 */
function get(id) {
	prune()

	const job = jobs.get(id)
	if (!job) {
		// TERMINAL, and named so. An id this process never had — or had before
		// it restarted, or whose result aged out — is not "still running", and
		// answering as though it were would hang the caller forever.
		return { status: UNKNOWN }
	}

	if (job.status === RUNNING) {
		return { status: RUNNING, startedAt: job.startedAt }
	}

	if (job.status === FAILED) {
		return { status: FAILED, error: job.error, code: job.code }
	}

	return { status: DONE, result: job.result }
}

/**
 * Forget a job once its result has been collected.
 *
 * Optional: the TTL would get there anyway. A caller that collects promptly
 * keeps the registry small on a busy runner.
 *
 * @param {string} id The job id.
 *
 * @returns {boolean} Whether anything was removed.
 */
function forget(id) {
	return jobs.delete(id)
}

/**
 * How many jobs are held, by state — for the heartbeat.
 *
 * @returns {object} Counts keyed by status.
 */
function stats() {
	prune()

	const out = { running: 0, done: 0, failed: 0 }
	for (const job of jobs.values()) {
		out[job.status] = (out[job.status] || 0) + 1
	}

	return out
}

module.exports = { start, get, forget, stats, RUNNING, DONE, FAILED, UNKNOWN }
