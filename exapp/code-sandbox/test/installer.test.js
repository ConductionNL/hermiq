// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The installer (tools-agent-workspace-and-installed-tools D4): what it takes,
// the commands it runs (no install scripts, no shell), the requirement set it
// reads back, and the layer it answers.

'use strict'

const test = require('node:test')
const assert = require('node:assert/strict')
const crypto = require('crypto')
const fs = require('fs')
const os = require('os')
const path = require('path')
const zlib = require('zlib')
const installer = require('../src/installer.js')

const BOTH = ['pip', 'npm']

test('only allowlisted managers are served', () => {
	assert.deepEqual(installer.allowedManagers({}), BOTH)
	assert.deepEqual(installer.allowedManagers({ INSTALLER_MANAGERS: 'pip' }), ['pip'])
	assert.deepEqual(installer.allowedManagers({ INSTALLER_MANAGERS: 'pip, cargo' }), ['pip'])
	assert.equal(installer.invalid({ manager: 'npm', package: 'cowsay' }, ['pip']), 'npm is not an allowed registry on this instance.')
	assert.equal(installer.invalid({ manager: 'cargo', package: 'ripgrep' }, BOTH), 'The installer installs with pip or npm.')
})

test('package names and versions that could steer the resolver are refused', () => {
	assert.equal(installer.invalid({ manager: 'pip', package: 'csvkit', version: '2.1.0' }, BOTH), null)
	assert.equal(installer.invalid({ manager: 'npm', package: '@scope/tool', version: '1.0.0-rc.1' }, BOTH), null)
	for (const name of ['', '-e', '--index-url=https://evil.example', 'git+https://x/y', 'a b', '../x', 'csvkit; rm -rf /']) {
		assert.notEqual(installer.invalid({ manager: 'pip', package: name }, BOTH), null, name)
		assert.notEqual(installer.invalid({ manager: 'npm', package: name }, BOTH), null, name)
	}
	for (const version of ['>=1', '1.0 || 2.0', 'https://x', '1.0;x', '*']) {
		assert.notEqual(installer.invalid({ manager: 'pip', package: 'csvkit', version }, BOTH), null, version)
	}
})

test('pip installs binary wheels only and each resolved requirement without its dependencies', () => {
	const resolve = installer.pipResolveStep('python3', 'csvkit==2.1.0', '/w/report.json')
	assert.ok(resolve.args.includes('--dry-run'))
	assert.ok(resolve.args.includes('--only-binary=:all:'))
	assert.deepEqual(resolve.args.slice(-2), ['--', 'csvkit==2.1.0'])

	const one = installer.pipInstallStep('python3', '/w/prefix', { name: 'agate', version: '1.9.1' })
	assert.ok(one.args.includes('--no-deps'))
	assert.ok(one.args.includes('--only-binary=:all:'))
	assert.deepEqual(one.args.slice(-4), ['--target', '/w/prefix', '--', 'agate==1.9.1'])
})

test('npm installs without lifecycle scripts', () => {
	const step = installer.npmInstallStep('npm', '/w/prefix', 'cowsay@1.6.0')
	assert.ok(step.args.includes('--ignore-scripts'))
	assert.deepEqual(step.args.slice(-2), ['--', 'cowsay@1.6.0'])
	assert.equal(installer.specifier({ manager: 'npm', package: 'cowsay', version: '1.6.0' }), 'cowsay@1.6.0')
	assert.equal(installer.specifier({ manager: 'pip', package: 'csvkit' }), 'csvkit')
})

test('the pip report and the npm lockfile give the resolved versions', () => {
	const report = JSON.stringify({ install: [{ metadata: { name: 'csvkit', version: '2.1.0' } }, { metadata: { name: 'agate', version: '1.9.1' } }] })
	assert.deepEqual(installer.parsePipReport(report), [{ name: 'csvkit', version: '2.1.0' }, { name: 'agate', version: '1.9.1' }])
	assert.throws(() => installer.parsePipReport(JSON.stringify({ install: [] })))
	assert.throws(() => installer.parsePipReport(JSON.stringify({ install: [{ metadata: { name: '--x', version: '1' } }] })))

	const lock = JSON.stringify({ packages: { '': {}, 'node_modules/cowsay': { version: '1.6.0' }, 'node_modules/cowsay/node_modules/get-stdin': { version: '8.0.0' } } })
	assert.deepEqual(installer.parseNpmLock(lock), [{ name: 'cowsay', version: '1.6.0' }, { name: 'get-stdin', version: '8.0.0' }])
})

test('the installer\'s commands get the proxy and nothing secret', () => {
	const env = installer.stepEnv('/w', { PATH: '/bin', APP_SECRET: 's', HTTPS_PROXY: 'http://p:3128' })
	assert.equal(env.HTTPS_PROXY, 'http://p:3128')
	assert.equal(env.APP_SECRET, undefined)
	assert.equal(env.HOME, '/w')
})

test('a step runs without a shell, so shell syntax stays one argument', async () => {
	const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'step-'))
	try {
		await installer.runStep({ command: process.execPath, args: ['-e', 'require("fs").writeFileSync(process.argv[1], process.argv[2])', path.join(dir, 'out'), 'a; touch ' + path.join(dir, 'pwned')] }, { cwd: dir, env: process.env })
		assert.equal(fs.readFileSync(path.join(dir, 'out'), 'utf8'), 'a; touch ' + path.join(dir, 'pwned'))
		assert.equal(fs.existsSync(path.join(dir, 'pwned')), false)
		await assert.rejects(installer.runStep({ command: process.execPath, args: ['-e', 'process.exit(3)'] }, { cwd: dir, env: process.env }), /The install failed/)
	} finally {
		fs.rmSync(dir, { recursive: true, force: true })
	}
})

test('a refused request runs nothing', async () => {
	const steps = []
	await assert.rejects(
		installer.install({ manager: 'npm', package: 'cowsay' }, { env: { INSTALLER_MANAGERS: 'pip' }, step: async (s) => steps.push(s) }),
		(error) => error.status === 400 && error.message === 'npm is not an allowed registry on this instance.',
	)
	assert.deepEqual(steps, [])
})

test('a pip install answers the versions, the binaries and a layer whose hash matches its bytes', async () => {
	const root = fs.mkdtempSync(path.join(os.tmpdir(), 'install-test-'))
	const steps = []
	const fake = async (step, options) => {
		steps.push(step)
		if (step.args.includes('--dry-run')) {
			const report = step.args[step.args.indexOf('--report') + 1]
			fs.writeFileSync(report, JSON.stringify({ install: [{ metadata: { name: 'csvkit', version: '2.1.0' } }, { metadata: { name: 'agate', version: '1.9.1' } }] }))
			return
		}
		if (step.args.includes('--target')) {
			const prefix = step.args[step.args.indexOf('--target') + 1]
			fs.mkdirSync(path.join(prefix, 'bin'), { recursive: true })
			fs.writeFileSync(path.join(prefix, 'bin', 'csvstat'), '#!/usr/bin/env python3\n')
			return
		}
		return installer.runStep(step, options)
	}
	try {
		const result = await installer.install({ manager: 'pip', package: 'csvkit', version: '2.1.0' }, { env: { PATH: process.env.PATH }, step: fake, workRoot: root })
		assert.equal(result.version, '2.1.0')
		assert.deepEqual(result.versions.map((v) => v.name), ['csvkit', 'agate'])
		assert.deepEqual(result.binaries, ['csvstat'])
		const archive = Buffer.from(result.layer.archive, 'base64')
		assert.equal(result.layer.sha256, crypto.createHash('sha256').update(archive).digest('hex'))
		assert.equal(result.layer.size, archive.length)
		assert.ok(zlib.gunzipSync(archive).includes(Buffer.from('csvstat')))
		assert.equal(steps.filter((s) => s.args.includes('--no-deps')).length, 2)
		assert.deepEqual(fs.readdirSync(root), [], 'the work directory is removed')
	} finally {
		fs.rmSync(root, { recursive: true, force: true })
	}
})
