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
// A workspace run (`workspace: true`, tools-agent-workspace-and-installed-tools
// D2) gets a copy of the agent's workspace as relative paths in a `work`
// folder, runs with that folder as its working directory, the program kept
// beside it, and answers every file it created or changed there, so hermiq
// can write those back. The sandbox itself stays stateless and mount-free.
//
// No network is the container's job: its network is `internal: true` with no
// egress proxy (deploy/docker-compose.yml). This module adds nothing there, so
// a run outside the container is NOT network-isolated.
//
// @spec openspec/changes/tools-code-sandbox/specs/code-sandbox/spec.md#requirement-the-sandbox-runs-code-without-network-without-data-mounts-and-within-limits-req-sandbox-001

'use strict'

const { spawn, execFile } = require('child_process')
const crypto = require('crypto')
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
	maxWorkspaceFiles: 5000,
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
	if (request.workspace !== undefined && typeof request.workspace !== 'boolean') {
		return 'workspace is true or false.'
	}
	return invalidFiles(request.files, request.workspace === true)
}

/**
 * Why the input files cannot be placed, or null when they can.
 *
 * @param {*} files The `files` list.
 * @return {string|null}
 */
function invalidFiles(files, workspace = false) {
	if (files === undefined) {
		return null
	}
	const most = workspace ? LIMITS.maxWorkspaceFiles : LIMITS.maxFiles
	if (!Array.isArray(files) || files.length > most) {
		return 'files is a list of at most ' + most + ' files.'
	}
	let total = 0
	for (const file of files) {
		if (!file || typeof file.name !== 'string' || typeof file.content !== 'string') {
			return 'Every file has a name and a content.'
		}
		if (workspace ? !isWorkspacePath(file.name) : !isPlainName(file.name)) {
			return (workspace ? 'A file name is a relative path inside the workspace: ' : 'A file name is a plain name: ') + file.name
		}
		total += Buffer.byteLength(file.content, file.encoding === 'base64' ? 'base64' : 'utf8')
	}
	if (total > LIMITS.maxFilesBytes) {
		return 'The input files are larger than 10 MB together.'
	}
	return null
}

/**
 * Whether a name is a plain file name a run may hold next to its program.
 *
 * @param {string} name The name.
 * @return {boolean}
 */
function isPlainName(name) {
	return name === path.basename(name) && name !== '' && !name.startsWith('.') && name !== 'main.py' && name !== 'main.js'
}

/**
 * Whether a name is a relative path that stays inside the work folder: no
 * absolute path, no empty, `.` or `..` segment, no backslash and nothing in
 * `.git`.
 *
 * @param {string} name The relative path.
 * @return {boolean}
 */
function isWorkspacePath(name) {
	if (name === '' || name.startsWith('/') || name.includes('\\') || name.includes('\0')) {
		return false
	}
	const segments = name.split('/')
	if (segments[0] === '.git') {
		return false
	}
	return segments.every((segment) => segment !== '' && segment !== '.' && segment !== '..')
}

/**
 * The command a run starts: the interpreter under prlimit, as the run UID when set.
 *
 * @param {object} language The language entry.
 * @param {{wallSeconds: number, memoryMb: number}} limits The limits.
 * @return {{command: string, args: string[]}}
 */
function commandFor(language, limits, program = language.file) {
	const languageArgs = language.args(limits.memoryMb).map((arg) => (arg === language.file ? program : arg))
	return limitedCommand([language.command, ...languageArgs], limits, language.addressSpace)
}

/**
 * An argument list under prlimit, as the run UID when set: no shell anywhere,
 * so every argument reaches the program as itself.
 *
 * @param {string[]} argv The program and its arguments.
 * @param {{wallSeconds: number, memoryMb: number}} limits The limits.
 * @param {boolean} addressSpace Whether memory is capped as address space.
 * @return {{command: string, args: string[]}}
 */
function limitedCommand(argv, limits, addressSpace) {
	const prlimit = [
		...(addressSpace ? ['--as=' + (limits.memoryMb * 1024 * 1024)] : []),
		'--nproc=' + LIMITS.maxProcesses,
		'--fsize=' + LIMITS.maxFilesBytes,
		'--cpu=' + limits.wallSeconds,
		'--core=0',
		'--',
	]
	let args = [...prlimit, ...argv]
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
 * The files a workspace run created or changed in its work folder, within the caps.
 *
 * @param {string} work The work folder.
 * @param {Map<string, string>} before The SHA-256 of every file placed, by relative path.
 * @return {{files: Array<{name: string, size: number, content: string, encoding: string}>, truncated: boolean}}
 */
function changedFiles(work, before) {
	const out = []
	let total = 0
	let truncated = false
	const walk = (folder, prefix) => {
		for (const entry of fs.readdirSync(folder, { withFileTypes: true }).sort((a, b) => (a.name < b.name ? -1 : 1))) {
			const relative = prefix === '' ? entry.name : prefix + '/' + entry.name
			const full = path.join(folder, entry.name)
			if (entry.isDirectory()) {
				if (relative !== '.git') {
					walk(full, relative)
				}
				continue
			}
			if (!entry.isFile()) {
				continue
			}
			const bytes = fs.readFileSync(full)
			if (before.get(relative) === crypto.createHash('sha256').update(bytes).digest('hex')) {
				continue
			}
			if (total + bytes.length > LIMITS.maxFilesBytes || out.length >= LIMITS.maxWorkspaceFiles) {
				truncated = true
				continue
			}
			total += bytes.length
			out.push({ name: relative, size: bytes.length, content: bytes.toString('base64'), encoding: 'base64' })
		}
	}
	walk(work, '')
	return { files: out, truncated }
}

/**
 * Place the program beside a work folder holding the workspace copy.
 *
 * @param {object} request The run request.
 * @param {object} language The language entry.
 * @return {{dir: string, work: string, before: Map<string, string>}}
 */
function prepareWorkspace(request, language) {
	const root = process.env.SANDBOX_WORK_ROOT || os.tmpdir()
	const dir = fs.mkdtempSync(path.join(root, 'run-'))
	fs.chmodSync(dir, 0o755)
	fs.writeFileSync(path.join(dir, language.file), request.code)
	return { dir, ...placeWorkspace(dir, request.files) }
}

/**
 * Place the workspace files as relative paths in a `work` folder the run may write.
 *
 * @param {string} dir The run's directory.
 * @param {Array<{name: string, content: string, encoding: string}>|undefined} files The workspace files.
 * @return {{work: string, before: Map<string, string>}} The folder and each file's hash before the run.
 */
function placeWorkspace(dir, files) {
	const work = path.join(dir, 'work')
	fs.mkdirSync(work, { mode: 0o777 })
	fs.chmodSync(work, 0o777)
	const before = new Map()
	for (const file of files || []) {
		const bytes = Buffer.from(file.content, file.encoding === 'base64' ? 'base64' : 'utf8')
		const full = path.join(work, ...file.name.split('/'))
		let folder = work
		for (const segment of file.name.split('/').slice(0, -1)) {
			folder = path.join(folder, segment)
			if (!fs.existsSync(folder)) {
				fs.mkdirSync(folder, { mode: 0o777 })
				fs.chmodSync(folder, 0o777)
			}
		}
		fs.writeFileSync(full, bytes)
		fs.chmodSync(full, 0o666)
		before.set(file.name, crypto.createHash('sha256').update(bytes).digest('hex'))
	}
	return { work, before }
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
 * Remove a run's directory, whatever the program left in it.
 *
 * A folder the program made belongs to the runner uid, and the server's uid
 * cannot unlink what is inside it (nor read a folder the program closed with
 * chmod). So, when runs use their own uid, that uid first opens and deletes
 * what it owns; the server then removes the rest, its own files included.
 *
 * @param {string} dir The run's directory, from mkdtemp under the work root.
 * @return {Promise<void>}
 *
 * @spec openspec/changes/tools-code-sandbox/tasks.md#task-1-the-code-sandbox-exapp
 */
async function removeRunDir(dir) {
	const uid = process.env.SANDBOX_RUN_UID
	if (uid && /^\d+$/.test(uid)) {
		const asRunner = ['--reuid=' + uid, '--regid=' + uid, '--clear-groups', '--no-new-privs', '--']
		// Each step reports entries it may not touch (the server's own); those go below.
		await settle('setpriv', [...asRunner, 'chmod', '-R', 'u+rwX', '--', dir])
		await settle('setpriv', [...asRunner, 'find', dir, '-mindepth', '1', '-depth', '-delete'])
	}
	fs.rmSync(dir, { recursive: true, force: true })
}

/**
 * Run a command to its end, ignoring its exit status.
 *
 * @param {string} command The command.
 * @param {string[]} args Its arguments, no shell.
 * @return {Promise<void>}
 */
function settle(command, args) {
	return new Promise((resolve) => {
		execFile(command, args, { timeout: 30000, env: { PATH: '/usr/local/bin:/usr/bin:/bin' } }, () => resolve())
	})
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
	if (request.workspace === true) {
		const { dir, work, before } = prepareWorkspace(request, language)
		try {
			const outcome = await execute(language, limits, work, request.stdin || '', '../' + language.file)
			const changed = changedFiles(work, before)
			return { ...outcome, files: changed.files, filesTruncated: changed.truncated, limits }
		} finally {
			await removeRunDir(dir)
		}
	}
	const { dir, inputs } = prepare(request, language)
	try {
		const outcome = await execute(language, limits, dir, request.stdin || '')
		return { ...outcome, files: createdFiles(dir, inputs), limits }
	} finally {
		await removeRunDir(dir)
	}
}

/**
 * Start the child and wait for it, killing its group at the wall time.
 *
 * @param {object} language The language entry.
 * @param {{wallSeconds: number, memoryMb: number}} limits The limits.
 * @param {string} dir The work directory.
 * @param {string} stdin The input.
 * @param {string} program The program's path from the working directory.
 * @return {Promise<object>} `{exitCode, stdout, stderr, durationMs, timedOut, stdoutTruncated, stderrTruncated}`.
 */
function execute(language, limits, dir, stdin, program = language.file) {
	return executeCommand(commandFor(language, limits, program), limits, { dir, stdin })
}

/**
 * Start a command and wait for it, killing its group at the wall time.
 *
 * @param {{command: string, args: string[]}} cmd The command, from limitedCommand().
 * @param {{wallSeconds: number, memoryMb: number}} limits The limits.
 * @param {{dir: string, stdin: string, env: object}} where The working directory, the input, and
 *   variables added to the run's bare environment.
 * @return {Promise<object>} `{exitCode, stdout, stderr, durationMs, timedOut, stdoutTruncated, stderrTruncated}`.
 */
function executeCommand(cmd, limits, where) {
	const { command, args } = cmd
	const { dir, stdin } = where
	const extraEnv = where.env || {}
	const started = Date.now()
	const stdout = capped()
	const stderr = capped()

	return new Promise((resolve) => {
		const child = spawn(command, args, {
			cwd: dir,
			detached: true,
			env: { PATH: '/usr/local/bin:/usr/bin:/bin', HOME: dir, LANG: 'C.UTF-8', PYTHONDONTWRITEBYTECODE: '1', OPENBLAS_NUM_THREADS: '1', OMP_NUM_THREADS: '1', ...extraEnv },
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

module.exports = { run, removeRunDir, clampLimits, invalid, invalidFiles, commandFor, limitedCommand, executeCommand, placeWorkspace, changedFiles, isWorkspacePath, LIMITS, LANGUAGES }
