// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// installer.js — installs one command line tool into an empty prefix and packs
// that prefix as a tool layer (tools-agent-workspace-and-installed-tools D4).
//
// The installer runs in its own container, on its own network, whose only way
// out is an egress proxy that allows the admin's registries and nothing else
// (deploy/docker-compose.yml). Code runs never share that network.
//
// No package's own code runs during an install:
//   - pip installs binary wheels only (`--only-binary=:all:`), so no setup.py
//     is ever executed. The requirement set is resolved first with a dry run,
//     then every resolved requirement is installed on its own with
//     `--no-deps`, so what lands in the layer is exactly what was resolved.
//   - npm installs with `--ignore-scripts`, so no lifecycle script runs.
//   - every command is spawned with an argument list, never through a shell.
//
// The layer is a gzipped tar of the prefix with fixed owners and times, so the
// same packages give the same SHA-256.
//
// @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-workspace/spec.md#requirement-an-agent-installs-command-line-tools-only-from-an-allowlisted-registry-without-install-scripts-req-wksp-004

'use strict'

const childProcess = require('child_process')
const zlib = require('zlib')
const crypto = require('crypto')
const path = require('path')
const os = require('os')
const fs = require('fs')

const MANAGERS = Object.freeze(['pip', 'npm'])

const LIMITS = Object.freeze({
	stepSeconds: 240,
	maxLayerBytes: 64 * 1024 * 1024,
	maxOutputBytes: 16 * 1024,
	maxRequirements: 200,
})

// A PyPI project name (PEP 508 name) and an npm package name, scoped or not.
const PACKAGE_PATTERNS = Object.freeze({
	pip: /^[A-Za-z0-9](?:[A-Za-z0-9._-]{0,98}[A-Za-z0-9])?$/,
	npm: /^(?:@[a-z0-9][a-z0-9._-]{0,99}\/)?[a-z0-9][a-z0-9._-]{0,113}$/,
})

// One exact version: no ranges, no spaces, nothing a resolver reads as a URL.
const VERSION_PATTERN = /^[A-Za-z0-9](?:[A-Za-z0-9.+!_-]{0,62})$/

/**
 * The managers this installer serves: INSTALLER_MANAGERS, or both.
 *
 * @param {object} env The environment.
 * @return {string[]}
 */
function allowedManagers(env = process.env) {
	const raw = String(env.INSTALLER_MANAGERS || MANAGERS.join(','))
	return raw.split(',').map((m) => m.trim()).filter((m) => MANAGERS.includes(m))
}

/**
 * Why a request's manager cannot be used, or null when it can.
 *
 * @param {object} request `{manager}`.
 * @param {string[]} allowed The managers this installer serves.
 * @return {string|null}
 */
function invalidManager(request, allowed) {
	if (!request || typeof request !== 'object' || !MANAGERS.includes(request.manager)) {
		return 'The installer installs with pip or npm.'
	}
	return allowed.includes(request.manager) ? null : request.manager + ' is not an allowed registry on this instance.'
}

/**
 * Whether a version is absent or one exact version.
 *
 * @param {*} version The requested version.
 * @return {boolean}
 */
function validVersion(version) {
	if (version === undefined || version === null || version === '') {
		return true
	}
	return typeof version === 'string' && VERSION_PATTERN.test(version)
}

/**
 * Why a request cannot be installed, or null when it can.
 *
 * @param {object} request `{manager, package, version}`.
 * @param {string[]} allowed The managers this installer serves.
 * @return {string|null}
 */
function invalid(request, allowed) {
	const manager = invalidManager(request, allowed)
	if (manager !== null) {
		return manager
	}
	if (typeof request.package !== 'string' || !PACKAGE_PATTERNS[request.manager].test(request.package)) {
		return 'The package name is not a valid ' + request.manager + ' package name.'
	}
	return validVersion(request.version) ? null : 'The version is one exact version, such as 2.1.0.'
}

/**
 * The requirement as the manager reads it.
 *
 * @param {object} request `{manager, package, version}`.
 * @return {string}
 */
function specifier(request) {
	if (!request.version) {
		return request.package
	}
	return request.manager === 'pip' ? request.package + '==' + request.version : request.package + '@' + request.version
}

/**
 * The pip dry run that resolves the requirement set into a JSON report.
 *
 * @param {string} python The interpreter.
 * @param {string} spec The requirement.
 * @param {string} report Where the report goes.
 * @return {{command: string, args: string[]}}
 */
function pipResolveStep(python, spec, report) {
	return {
		command: python,
		args: ['-m', 'pip', 'install', '--dry-run', '--ignore-installed', '--only-binary=:all:',
			'--disable-pip-version-check', '--no-input', '--quiet', '--report', report, '--', spec],
	}
}

/**
 * The pip install of one resolved requirement, without its dependencies.
 *
 * @param {string} python The interpreter.
 * @param {string} prefix The empty prefix.
 * @param {{name: string, version: string}} requirement The resolved requirement.
 * @return {{command: string, args: string[]}}
 */
function pipInstallStep(python, prefix, requirement) {
	return {
		command: python,
		args: ['-m', 'pip', 'install', '--no-deps', '--only-binary=:all:', '--no-compile',
			'--disable-pip-version-check', '--no-input', '--quiet', '--target', prefix, '--',
			requirement.name + '==' + requirement.version],
	}
}

/**
 * The npm install of the package into the prefix, without lifecycle scripts.
 *
 * @param {string} npm The npm binary.
 * @param {string} prefix The empty prefix.
 * @param {string} spec The requirement.
 * @return {{command: string, args: string[]}}
 */
function npmInstallStep(npm, prefix, spec) {
	return {
		command: npm,
		args: ['install', '--ignore-scripts', '--no-audit', '--no-fund', '--omit=dev', '--no-package-lock',
			'--prefix', prefix, '--', spec],
	}
}

/**
 * The requirements a pip report resolved.
 *
 * @param {string} text The report JSON.
 * @return {{name: string, version: string}[]}
 */
function parsePipReport(text) {
	const report = JSON.parse(text)
	const installs = Array.isArray(report && report.install) ? report.install : []
	const out = []
	for (const item of installs) {
		const metadata = (item && item.metadata) || {}
		const name = String(metadata.name || '')
		const version = String(metadata.version || '')
		if (!PACKAGE_PATTERNS.pip.test(name) || !VERSION_PATTERN.test(version)) {
			throw new Error('The resolver answered a requirement the installer does not take.')
		}
		out.push({ name, version })
	}
	if (out.length === 0 || out.length > LIMITS.maxRequirements) {
		throw new Error('The resolver answered no usable requirement set.')
	}
	return out
}

/**
 * The packages npm placed, from its hidden lockfile.
 *
 * @param {string} text The `node_modules/.package-lock.json` content.
 * @return {{name: string, version: string}[]}
 */
function parseNpmLock(text) {
	const lock = JSON.parse(text)
	const packages = (lock && typeof lock.packages === 'object' && lock.packages) || {}
	const out = []
	for (const [key, entry] of Object.entries(packages)) {
		const at = key.lastIndexOf('node_modules/')
		if (at < 0 || !entry || typeof entry.version !== 'string') {
			continue
		}
		out.push({ name: key.slice(at + 'node_modules/'.length), version: entry.version })
	}
	out.sort((a, b) => (a.name < b.name ? -1 : a.name > b.name ? 1 : 0))
	return out
}

/**
 * The executables a layer offers.
 *
 * @param {string} manager `pip` or `npm`.
 * @param {string} prefix The prefix.
 * @return {string[]}
 */
function binaries(manager, prefix) {
	const dir = manager === 'pip' ? path.join(prefix, 'bin') : path.join(prefix, 'node_modules', '.bin')
	let names = []
	try {
		names = fs.readdirSync(dir)
	} catch (e) {
		return []
	}
	return names.filter((name) => /^[A-Za-z0-9][A-Za-z0-9._-]*$/.test(name)).sort()
}

/**
 * Run one step with an argument list and no shell; reject on a non-zero exit.
 *
 * @param {{command: string, args: string[]}} step The step.
 * @param {object} options `{cwd, env, timeoutSeconds}`.
 * @return {Promise<void>}
 */
function runStep(step, options) {
	return new Promise((resolve, reject) => {
		const child = childProcess.spawn(step.command, step.args, {
			cwd: options.cwd,
			env: options.env,
			shell: false,
			stdio: ['ignore', 'ignore', 'pipe'],
		})
		let stderr = ''
		child.stderr.on('data', (chunk) => {
			if (stderr.length < LIMITS.maxOutputBytes) {
				stderr += chunk.toString('utf8')
			}
		})
		const timer = setTimeout(() => child.kill('SIGKILL'), (options.timeoutSeconds || LIMITS.stepSeconds) * 1000)
		child.on('error', (error) => {
			clearTimeout(timer)
			reject(error)
		})
		child.on('close', (code, signal) => {
			clearTimeout(timer)
			if (code === 0) {
				resolve()
				return
			}
			const error = new Error('The install failed' + (signal ? ' (stopped at the time limit).' : '.') + (stderr ? ' ' + stderr.trim().slice(-2000) : ''))
			error.status = 422
			reject(error)
		})
	})
}

/**
 * Pack the prefix as a gzipped tar with fixed owners and times.
 *
 * @param {string} prefix The prefix.
 * @param {function} step Runs one step.
 * @param {object} options `{cwd, env}`.
 * @return {Promise<Buffer>}
 */
async function pack(prefix, step, options) {
	const tarFile = path.join(options.cwd, 'layer.tar')
	await step({
		command: 'tar',
		args: ['--sort=name', '--mtime=@0', '--owner=0', '--group=0', '--numeric-owner',
			'-cf', tarFile, '-C', prefix, '.'],
	}, options)
	const archive = zlib.gzipSync(fs.readFileSync(tarFile))
	if (archive.length > LIMITS.maxLayerBytes) {
		const error = new Error('The installed tool is larger than a layer may be (limit 64 MB).')
		error.status = 413
		throw error
	}
	return archive
}

/**
 * The environment the installer's commands get: the proxy and nothing secret.
 *
 * @param {string} work The install's work directory.
 * @param {object} env The server's environment.
 * @return {object}
 */
function stepEnv(work, env = process.env) {
	const out = {
		PATH: env.PATH || '/usr/local/bin:/usr/bin:/bin',
		HOME: work,
		LANG: 'C.UTF-8',
		PIP_NO_CACHE_DIR: '1',
		PYTHONDONTWRITEBYTECODE: '1',
		npm_config_cache: path.join(work, '.npm'),
		npm_config_update_notifier: 'false',
	}
	for (const key of ['HTTPS_PROXY', 'HTTP_PROXY', 'https_proxy', 'http_proxy', 'PIP_INDEX_URL', 'npm_config_registry']) {
		if (env[key]) {
			out[key] = env[key]
		}
	}
	return out
}

/**
 * Resolve with pip, then install each resolved requirement on its own.
 *
 * @param {object} ctx `{env, step, options, work, prefix, spec}`.
 * @return {Promise<{name: string, version: string}[]>} The resolved requirements.
 */
async function installPip(ctx) {
	const python = ctx.env.INSTALLER_PYTHON || 'python3'
	const report = path.join(ctx.work, 'report.json')
	await ctx.step(pipResolveStep(python, ctx.spec, report), ctx.options)
	const versions = parsePipReport(fs.readFileSync(report, 'utf8'))
	for (const requirement of versions) {
		await ctx.step(pipInstallStep(python, ctx.prefix, requirement), ctx.options)
	}
	return versions
}

/**
 * Install with npm and read back what it placed.
 *
 * @param {object} ctx `{env, step, options, prefix, spec}`.
 * @return {Promise<{name: string, version: string}[]>} The placed packages.
 */
async function installNpm(ctx) {
	await ctx.step(npmInstallStep(ctx.env.INSTALLER_NPM || 'npm', ctx.prefix, ctx.spec), ctx.options)
	return parseNpmLock(fs.readFileSync(path.join(ctx.prefix, 'node_modules', '.package-lock.json'), 'utf8'))
}

/**
 * The resolved version of the requested package itself.
 *
 * @param {{name: string, version: string}[]} versions The resolved packages.
 * @param {object} request `{package, version}`.
 * @return {string}
 */
function ownVersion(versions, request) {
	const normal = (name) => name.toLowerCase().replace(/[._-]+/g, '-')
	const own = versions.find((v) => normal(v.name) === normal(request.package))
	return own ? own.version : (request.version || '')
}

/**
 * Install one tool and answer its layer.
 *
 * @param {object} request `{manager, package, version}`.
 * @param {object} deps `{step, env, workRoot}` for tests; defaults run for real.
 * @return {Promise<object>} `{manager, package, version, versions, binaries, layer: {sha256, size, archive}}`.
 */
async function install(request, deps = {}) {
	const env = deps.env || process.env
	const reason = invalid(request, allowedManagers(env))
	if (reason !== null) {
		const error = new Error(reason)
		error.status = 400
		throw error
	}

	const work = fs.mkdtempSync(path.join(deps.workRoot || env.INSTALLER_WORK_ROOT || os.tmpdir(), 'install-'))
	const prefix = path.join(work, 'prefix')
	fs.mkdirSync(prefix)
	const ctx = { env, step: deps.step || runStep, work, prefix, spec: specifier(request) }
	ctx.options = { cwd: work, env: stepEnv(work, env), timeoutSeconds: LIMITS.stepSeconds }

	try {
		const versions = await (request.manager === 'pip' ? installPip(ctx) : installNpm(ctx))
		const archive = await pack(prefix, ctx.step, ctx.options)

		return {
			manager: request.manager,
			package: request.package,
			version: ownVersion(versions, request),
			versions,
			binaries: binaries(request.manager, prefix),
			layer: {
				sha256: crypto.createHash('sha256').update(archive).digest('hex'),
				size: archive.length,
				archive: archive.toString('base64'),
			},
		}
	} finally {
		fs.rmSync(work, { recursive: true, force: true })
	}
}

module.exports = {
	MANAGERS,
	LIMITS,
	allowedManagers,
	invalid,
	specifier,
	pipResolveStep,
	pipInstallStep,
	npmInstallStep,
	parsePipReport,
	parseNpmLock,
	binaries,
	stepEnv,
	runStep,
	install,
}
