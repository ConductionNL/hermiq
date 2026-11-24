// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// installedTool.js — one run of an installed command line tool
// (tools-agent-workspace-and-installed-tools D5).
//
// A request names the tool layer by its SHA-256, the binary and its argument
// list. The layer is unpacked once into the layer cache (SANDBOX_LAYER_ROOT,
// a tmpfs the runner may read but not write); a hash the cache lacks is
// answered with 409 `layer_missing`, and Hermiq sends the bytes with the next
// request, which are checked against the hash before anything is unpacked.
//
// The binary runs with an argument list and no shell, under the same prlimit
// caps, runner UID and missing network as any code run, with the workspace
// copy as its working directory; the files it creates or changes are answered
// so Hermiq can write them back.
//
// @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-workspace/spec.md#requirement-an-installed-tool-runs-with-an-argument-list-and-no-shell-req-wksp-005

'use strict'

const crypto = require('crypto')
const childProcess = require('child_process')
const path = require('path')
const os = require('os')
const fs = require('fs')
const runner = require('./run.js')

const LIMITS = Object.freeze({
	maxArgs: 64,
	maxArgBytes: 4096,
	maxLayerBytes: 64 * 1024 * 1024,
})

const HASH_PATTERN = /^[0-9a-f]{64}$/
const TOOL_PATTERN = /^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/

/**
 * An error with an HTTP status and an optional machine code.
 *
 * @param {number} status The status.
 * @param {string} message The message.
 * @param {string} [code] The machine code.
 * @return {Error}
 */
function failure(status, message, code) {
	const error = new Error(message)
	error.status = status
	if (code) {
		error.code = code
	}
	return error
}

/**
 * Why the arguments cannot be passed, or null when they can.
 *
 * @param {*} args The `args` list.
 * @return {string|null}
 */
function invalidArgs(args) {
	if (args === undefined) {
		return null
	}
	if (!Array.isArray(args) || args.length > LIMITS.maxArgs) {
		return 'args is a list of at most ' + LIMITS.maxArgs + ' strings.'
	}
	const bad = args.find((arg) => typeof arg !== 'string' || arg.includes('\0') || Buffer.byteLength(arg) > LIMITS.maxArgBytes)
	return bad === undefined ? null : 'Every argument is text of at most 4 KB.'
}

/**
 * Why the named tool cannot be run, or null when it can.
 *
 * @param {*} installed The `installed` entry: `{sha256, tool, args}`.
 * @return {string|null}
 */
function invalidTool(installed) {
	if (!installed || typeof installed !== 'object') {
		return 'The request does not name an installed tool.'
	}
	if (typeof installed.sha256 !== 'string' || !HASH_PATTERN.test(installed.sha256)) {
		return 'The tool layer is named by its SHA-256.'
	}
	if (typeof installed.tool !== 'string' || !TOOL_PATTERN.test(installed.tool)) {
		return 'The tool is the plain name of an installed binary.'
	}
	return invalidArgs(installed.args)
}

/**
 * Why an installed-tool request cannot run, or null when it can.
 *
 * @param {object} request `{installed: {sha256, tool, args}, layer, files, stdin, limits}`.
 * @return {string|null}
 */
function invalid(request) {
	const tool = invalidTool(request && request.installed)
	if (tool !== null) {
		return tool
	}
	if (request.stdin !== undefined && typeof request.stdin !== 'string') {
		return 'stdin is text.'
	}
	if (request.layer !== undefined && typeof request.layer !== 'string') {
		return 'The layer is sent as base64 text.'
	}
	return runner.invalidFiles(request.files, true)
}

/**
 * The layer cache.
 *
 * @return {string}
 */
function layerRoot() {
	return process.env.SANDBOX_LAYER_ROOT || path.join(os.tmpdir(), 'hermiq-layers')
}

/**
 * Unpack a layer into the cache, once its bytes match its hash.
 *
 * @param {string} sha256 The hash.
 * @param {string} archive The layer, base64.
 * @param {string} dir The layer's directory in the cache.
 * @return {void}
 */
function unpack(sha256, archive, dir) {
	const bytes = Buffer.from(archive, 'base64')
	if (bytes.length > LIMITS.maxLayerBytes || crypto.createHash('sha256').update(bytes).digest('hex') !== sha256) {
		throw failure(400, 'The tool layer does not match its hash.', 'layer_mismatch')
	}
	const staging = fs.mkdtempSync(path.join(layerRoot(), '.staging-'))
	try {
		const result = childProcess.spawnSync('tar', ['-xzf', '-', '-C', staging, '--no-same-owner', '--no-same-permissions'], { input: bytes, shell: false })
		if (result.status !== 0) {
			throw failure(400, 'The tool layer could not be unpacked.', 'layer_unreadable')
		}
		fs.renameSync(staging, dir)
	} finally {
		fs.rmSync(staging, { recursive: true, force: true })
	}
}

/**
 * The layer's directory in the cache, unpacking the sent bytes on a miss.
 *
 * @param {string} sha256 The hash.
 * @param {string|undefined} archive The layer, base64, when Hermiq sent it.
 * @return {string}
 */
function ensureLayer(sha256, archive) {
	fs.mkdirSync(layerRoot(), { recursive: true, mode: 0o755 })
	const dir = path.join(layerRoot(), sha256)
	if (fs.existsSync(dir)) {
		return dir
	}
	if (typeof archive !== 'string' || archive === '') {
		throw failure(409, 'The sandbox does not hold this tool layer yet.', 'layer_missing')
	}
	unpack(sha256, archive, dir)
	return dir
}

/**
 * The binary in a layer: a pip script under `bin/` or an npm binary under
 * `node_modules/.bin/`, resolved inside the layer.
 *
 * @param {string} dir The layer's directory.
 * @param {string} tool The binary's name.
 * @return {{binary: string, env: object}} The path to run and the environment it needs.
 */
function resolveBinary(dir, tool) {
	const candidates = [
		{ binary: path.join(dir, 'bin', tool), env: { PYTHONPATH: dir } },
		{ binary: path.join(dir, 'node_modules', '.bin', tool), env: { NODE_PATH: path.join(dir, 'node_modules') } },
	]
	const root = fs.realpathSync(dir) + path.sep
	for (const candidate of candidates) {
		if (fs.existsSync(candidate.binary) && fs.realpathSync(candidate.binary).startsWith(root)) {
			return candidate
		}
	}
	throw failure(404, 'The tool layer has no binary named ' + tool + '.', 'tool_missing')
}

/**
 * Run an installed tool on a workspace copy.
 *
 * @param {object} request `{installed: {sha256, tool, args}, layer, files, stdin, limits}`.
 * @return {Promise<object>} `{exitCode, stdout, stderr, durationMs, timedOut, files, filesTruncated, limits}`.
 * @throws {Error} With a status: 400 for a bad request, 404 for an unknown binary, 409 `layer_missing`.
 */
async function runInstalled(request) {
	const reason = invalid(request)
	if (reason !== null) {
		throw failure(400, reason)
	}

	const { binary, env } = resolveBinary(ensureLayer(request.installed.sha256, request.layer), request.installed.tool)
	const limits = runner.clampLimits(request.limits)
	const dir = fs.mkdtempSync(path.join(process.env.SANDBOX_WORK_ROOT || os.tmpdir(), 'run-'))
	fs.chmodSync(dir, 0o755)
	try {
		const { work, before } = runner.placeWorkspace(dir, request.files)
		const cmd = runner.limitedCommand([binary, ...(request.installed.args || [])], limits, false)
		const outcome = await runner.executeCommand(cmd, limits, { dir: work, stdin: request.stdin || '', env })
		const changed = runner.changedFiles(work, before)
		return { ...outcome, files: changed.files, filesTruncated: changed.truncated, limits }
	} finally {
		fs.rmSync(dir, { recursive: true, force: true })
	}
}

module.exports = { LIMITS, invalid, ensureLayer, resolveBinary, runInstalled }
