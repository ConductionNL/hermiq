// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// run.js — one run of code in a fresh work directory (tools-code-sandbox D2).
//
// Each run:
//   - gets its own work directory under SANDBOX_WORK_ROOT (a tmpfs in the
//     container), holding the program and its input files, removed when the
//     run ends, whatever happened;
//   - runs as a child process in its own process group, with an environment
//     that carries nothing of the server's (no proxy, no secret), under
//     `prlimit` caps on memory, processes, file size and CPU time, and as a
//     separate UID when SANDBOX_RUN_UID is set (the image sets it);
//   - is killed with its whole process group at the wall-time limit and then
//     answers `timedOut: true`;
//   - keeps at most 64 KB of stdout and of stderr.
//
// No network is the container's job: its network is `internal: true` with no
// egress proxy (deploy/docker-compose.yml). This module adds nothing there, so
// a run outside the container is NOT network-isolated.
//
// @spec openspec/changes/tools-code-sandbox/specs/code-sandbox/spec.md#requirement-the-sandbox-runs-code-without-network-without-data-mounts-and-within-limits-req-sandbox-001

'use strict'

const { spawn } = require('child_process')
const fs = require('fs')
const os = require('os')
const path = require('path')

const LIMITS = Object.freeze({
	defaultWallSeconds: 30,
	maxWallSeconds: 300,
	defaultMemoryMb: 512,
	maxMemoryMb: 2048,
	maxProcesses: 64,
	maxOutputBytes: 64 * 1024,
	maxFilesBytes: 10 * 1024 * 1024,
	maxFiles: 50,
})

// Python's memory is capped as address space. V8 reserves far more address
// space than it uses and cannot start under such a cap, so Node's is capped as
// its heap; the container's own memory limit backs both.
const LANGUAGES = Object.freeze({
	python: { file: 'main.py', command: process.env.SANDBOX_PYTHON || 'python3', args: () => ['-I', 'main.py'], addressSpace: true },
	node: { file: 'main.js', command: process.env.SANDBOX_NODE || 'node', args: (memoryMb) => ['--max-old-space-size=' + memoryMb, 'main.js'], addressSpace: false },
})

/**
 * A limit: the default when absent or not a whole number, else within 1..max.
 *
 * @param {*} value The requested value.
 * @param {number} fallback The default.
 * @param {number} max The ceiling.
 * @return {number}
 */
function clamp(value, fallback, max) {
	if (!Number.isInteger(value)) {
		return fallback
	}
	return Math.max(1, Math.min(max, value))
}

/**
 * The limits a run gets.
 *
 * @param {object} limits The requested `{wallSeconds, memoryMb}`.
 * @return {{wallSeconds: number, memoryMb: number}}
 */
function clampLimits(limits) {
	const asked = (limits && typeof limits === 'object') ? limits : {}
	return {
		wallSeconds: clamp(asked.wallSeconds, LIMITS.defaultWallSeconds, LIMITS.maxWallSeconds),
		memoryMb: clamp(asked.memoryMb, LIMITS.defaultMemoryMb, LIMITS.maxMemoryMb),
	}
}

/**
 * Why a request cannot run, or null when it can.
 *
 * @param {object} request The run request.
 * @return {string|null}
 */
function invalid(request) {
	if (!request || typeof request !== 'object') {
		return 'The request is not a run.'
	}
	if (!Object.prototype.hasOwnProperty.call(LANGUAGES, request.language)) {
		return 'The sandbox runs python or node.'
	}
	if (typeof request.code !== 'string' || request.code.trim() === '') {
		return 'The code to run is required.'
	}
	if (request.stdin !== undefined && typeof request.stdin !== 'string') {
		return 'stdin is text.'
	}
	return invalidFiles(request.files)
}

/**
 * Why the input files cannot be placed, or null when they can.
 *
 * @param {*} files The `files` list.
 * @return {string|null}
 */
function invalidFiles(files) {
	if (files === undefined) {
		return null
	}
	if (!Array.isArray(files) || files.length > LIMITS.maxFiles) {
		return 'files is a list of at most ' + LIMITS.maxFiles + ' files.'
	}
	let total = 0
	for (const file of files) {
		if (!file || typeof file.name !== 'string' || typeof file.content !== 'string') {
			return 'Every file has a name and a content.'
		}
		if (file.name !== path.basename(file.name) || file.name === '' || file.name.startsWith('.') || file.name === 'main.py' || file.name === 'main.js') {
			return 'A file name is a plain name: ' + file.name
		}
		total += Buffer.byteLength(file.content, file.encoding === 'base64' ? 'base64' : 'utf8')
	}
	if (total > LIMITS.maxFilesBytes) {
		return 'The input files are larger than 10 MB together.'
	}
	return null
}

/**
 * The command a run starts: the interpreter under prlimit, as the run UID when set.
 *
 * @param {object} language The language entry.
 * @param {{wallSeconds: number, memoryMb: number}} limits The limits.
 * @return {{command: string, args: string[]}}
 */
function commandFor(language, limits) {
	const prlimit = [
		...(language.addressSpace ? ['--as=' + (limits.memoryMb * 1024 * 1024)] : []),
		'--nproc=' + LIMITS.maxProcesses,
		'--fsize=' + LIMITS.maxFilesBytes,
		'--cpu=' + limits.wallSeconds,
		'--core=0',
		'--',
	]
	let args = [...prlimit, language.command, ...language.args(limits.memoryMb)]
	const uid = process.env.SANDBOX_RUN_UID
	if (uid && /^\d+$/.test(uid)) {
		args = ['--reuid=' + uid, '--regid=' + uid, '--clear-groups', '--no-new-privs', '--', 'prlimit', ...args]
		return { command: 'setpriv', args }
	}
	return { command: 'prlimit', args }
}

/**
 * A collector for one output stream, keeping at most the cap.
 *
 * @return {{push: function(Buffer): void, text: function(): string, truncated: function(): boolean}}
 */
function capped() {
	const chunks = []
	let size = 0
	let cut = false
	return {
		push(chunk) {
			const room = LIMITS.maxOutputBytes - size
			if (room <= 0) {
				cut = true
				return
			}
			const piece = chunk.length > room ? chunk.subarray(0, room) : chunk
			cut = cut || chunk.length > room
			chunks.push(piece)
			size += piece.length
		},
		text: () => Buffer.concat(chunks).toString('utf8'),
		truncated: () => cut,
	}
}

/**
 * The files a run created next to its inputs, within the caps.
 *
 * @param {string} dir The work directory.
 * @param {Set<string>} inputs The names placed before the run.
 * @return {Array<{name: string, size: number, content: string, encoding: string}>}
 */
function createdFiles(dir, inputs) {
	const out = []
	let total = 0
	for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
		if (!entry.isFile() || inputs.has(entry.name)) {
			continue
		}
		const full = path.join(dir, entry.name)
		const size = fs.statSync(full).size
		if (total + size > LIMITS.maxFilesBytes || out.length >= LIMITS.maxFiles) {
			break
		}
		total += size
		out.push({ name: entry.name, size, content: fs.readFileSync(full).toString('base64'), encoding: 'base64' })
	}
	return out
}

/**
 * Place the program and the input files in a fresh work directory.
 *
 * @param {object} request The run request.
 * @param {object} language The language entry.
 * @return {{dir: string, inputs: Set<string>}}
 */
function prepare(request, language) {
	const root = process.env.SANDBOX_WORK_ROOT || os.tmpdir()
	const dir = fs.mkdtempSync(path.join(root, 'run-'))
	fs.chmodSync(dir, 0o777)
	const inputs = new Set([language.file])
	fs.writeFileSync(path.join(dir, language.file), request.code)
	for (const file of request.files || []) {
		fs.writeFileSync(path.join(dir, file.name), Buffer.from(file.content, file.encoding === 'base64' ? 'base64' : 'utf8'))
		inputs.add(file.name)
	}
	return { dir, inputs }
}

/**
 * Run one request.
 *
 * @param {object} request `{language, code, stdin, files: [{name, content, encoding}], limits}`.
 * @return {Promise<object>} `{exitCode, stdout, stderr, durationMs, timedOut, files, limits}`.
 * @throws {Error} With `status: 400` when the request cannot run.
 */
async function run(request) {
	const reason = invalid(request)
	if (reason !== null) {
		const error = new Error(reason)
		error.status = 400
		throw error
	}

	const language = LANGUAGES[request.language]
	const limits = clampLimits(request.limits)
	const { dir, inputs } = prepare(request, language)
	try {
		const outcome = await execute(language, limits, dir, request.stdin || '')
		return { ...outcome, files: createdFiles(dir, inputs), limits }
	} finally {
		fs.rmSync(dir, { recursive: true, force: true })
	}
}

/**
 * Start the child and wait for it, killing its group at the wall time.
 *
 * @param {object} language The language entry.
 * @param {{wallSeconds: number, memoryMb: number}} limits The limits.
 * @param {string} dir The work directory.
 * @param {string} stdin The input.
 * @return {Promise<object>} `{exitCode, stdout, stderr, durationMs, timedOut, stdoutTruncated, stderrTruncated}`.
 */
function execute(language, limits, dir, stdin) {
	const { command, args } = commandFor(language, limits)
	const started = Date.now()
	const stdout = capped()
	const stderr = capped()

	return new Promise((resolve) => {
		const child = spawn(command, args, {
			cwd: dir,
			detached: true,
			env: { PATH: '/usr/local/bin:/usr/bin:/bin', HOME: dir, LANG: 'C.UTF-8', PYTHONDONTWRITEBYTECODE: '1', OPENBLAS_NUM_THREADS: '1', OMP_NUM_THREADS: '1' },
			stdio: ['pipe', 'pipe', 'pipe'],
		})
		let timedOut = false
		const kill = () => {
			try {
				process.kill(-child.pid, 'SIGKILL')
			} catch (e) {
				// The group is already gone.
			}
		}
		const timer = setTimeout(() => {
			timedOut = true
			kill()
		}, limits.wallSeconds * 1000)

		child.stdout.on('data', (chunk) => stdout.push(chunk))
		child.stderr.on('data', (chunk) => stderr.push(chunk))
		child.stdin.on('error', () => {})
		child.stdin.end(stdin)
		child.on('error', (error) => {
			clearTimeout(timer)
			resolve({ exitCode: 127, stdout: '', stderr: 'The sandbox could not start the program: ' + error.message, durationMs: Date.now() - started, timedOut: false, stdoutTruncated: false, stderrTruncated: false })
		})
		child.on('close', (code, signal) => {
			clearTimeout(timer)
			// Children the program left behind go with it.
			kill()
			let exitCode = code
			if (exitCode === null) {
				exitCode = 128 + (os.constants.signals[signal] || 9)
			}
			resolve({
				exitCode,
				stdout: stdout.text(),
				stderr: stderr.text(),
				durationMs: Date.now() - started,
				timedOut,
				stdoutTruncated: stdout.truncated(),
				stderrTruncated: stderr.truncated(),
			})
		})
	})
}

module.exports = { run, clampLimits, invalid, commandFor, LIMITS, LANGUAGES }
