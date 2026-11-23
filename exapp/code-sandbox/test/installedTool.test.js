// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// An installed tool's run (tools-agent-workspace-and-installed-tools D5): the
// layer cache and its hash check, the binary resolved inside the layer, and
// the argument list that reaches the binary without a shell.

'use strict'

const test = require('node:test')
const assert = require('node:assert/strict')
const childProcess = require('child_process')
const crypto = require('crypto')
const path = require('path')
const os = require('os')
const fs = require('fs')

const scratch = fs.mkdtempSync(path.join(os.tmpdir(), 'installed-tool-test-'))
process.env.SANDBOX_LAYER_ROOT = path.join(scratch, 'layers')
process.env.SANDBOX_WORK_ROOT = path.join(scratch, 'work')
fs.mkdirSync(process.env.SANDBOX_WORK_ROOT)
delete process.env.SANDBOX_RUN_UID

const installed = require('../src/installedTool.js')

test.after(() => fs.rmSync(scratch, { recursive: true, force: true }))

/**
 * A tool layer as the installer packs it: `bin/<tool>` as a node script that
 * prints each argument on its own line and writes the first to a file.
 *
 * @param {object} extra Extra files: path to content, or `{symlink}`.
 * @return {{sha256: string, archive: string}}
 */
function layer(extra = {}) {
	const prefix = fs.mkdtempSync(path.join(scratch, 'prefix-'))
	fs.mkdirSync(path.join(prefix, 'bin'))
	const script = '#!' + process.execPath + '\n'
		+ 'const fs = require("fs")\n'
		+ 'for (const arg of process.argv.slice(2)) console.log(arg)\n'
		+ 'fs.writeFileSync("seen.txt", process.argv[2] || "")\n'
	fs.writeFileSync(path.join(prefix, 'bin', 'echoargs'), script, { mode: 0o755 })
	for (const [name, content] of Object.entries(extra)) {
		if (typeof content === 'object') {
			fs.symlinkSync(content.symlink, path.join(prefix, name))
		} else {
			fs.writeFileSync(path.join(prefix, name), content, { mode: 0o755 })
		}
	}
	const tarFile = path.join(scratch, crypto.randomUUID() + '.tar.gz')
	childProcess.execFileSync('tar', ['-czf', tarFile, '-C', prefix, '.'])
	const bytes = fs.readFileSync(tarFile)
	return { sha256: crypto.createHash('sha256').update(bytes).digest('hex'), archive: bytes.toString('base64') }
}

test('a request names a layer by hash, a plain binary and a list of strings', () => {
	const ok = { installed: { sha256: 'a'.repeat(64), tool: 'csvstat', args: ['x.csv'] } }
	assert.equal(installed.invalid(ok), null)
	assert.match(installed.invalid({ installed: { sha256: 'nope', tool: 'csvstat' } }), /SHA-256/)
	for (const tool of ['../bin/sh', '/bin/sh', 'a b', '']) {
		assert.match(installed.invalid({ installed: { sha256: 'a'.repeat(64), tool } }), /plain name/, tool)
	}
	assert.match(installed.invalid({ installed: { sha256: 'a'.repeat(64), tool: 'x', args: 'x.csv; rm -rf .' } }), /list/)
	assert.match(installed.invalid({ installed: { sha256: 'a'.repeat(64), tool: 'x', args: [1] } }), /text/)
	assert.match(installed.invalid({ installed: { sha256: 'a'.repeat(64), tool: 'x' }, files: [{ name: '../x', content: '' }] }), /relative path/)
})

test('a layer the cache lacks is asked for, and a layer that does not match its hash is refused', () => {
	const sha = 'b'.repeat(64)
	assert.throws(() => installed.ensureLayer(sha, undefined), (e) => e.status === 409 && e.code === 'layer_missing')
	const { archive } = layer()
	assert.throws(() => installed.ensureLayer(sha, archive), (e) => e.status === 400 && e.code === 'layer_mismatch')
	assert.equal(fs.existsSync(path.join(process.env.SANDBOX_LAYER_ROOT, sha)), false)
})

test('shell syntax in an argument reaches the binary as one argument and nothing else runs', async () => {
	const { sha256, archive } = layer()
	const result = await installed.runInstalled({
		installed: { sha256, tool: 'echoargs', args: ['aanvragen.csv; rm -rf .', '$(touch pwned)'] },
		layer: archive,
		files: [{ name: 'keep.txt', content: 'still here' }],
	})

	assert.equal(result.exitCode, 0, result.stderr)
	assert.equal(result.stdout, 'aanvragen.csv; rm -rf .\n$(touch pwned)\n')
	assert.deepEqual(result.files.map((f) => f.name), ['seen.txt'])
	assert.equal(Buffer.from(result.files[0].content, result.files[0].encoding === 'base64' ? 'base64' : 'utf8').toString(), 'aanvragen.csv; rm -rf .')

	const again = await installed.runInstalled({ installed: { sha256, tool: 'echoargs', args: ['cached'] } })
	assert.equal(again.stdout, 'cached\n', 'the second run finds the layer in the cache')
})

test('a binary the layer lacks, or one that points out of the layer, is not run', async () => {
	const { sha256, archive } = layer({ 'bin/escape': { symlink: '/bin/sh' } })
	await assert.rejects(installed.runInstalled({ installed: { sha256, tool: 'csvstat' }, layer: archive }), (e) => e.status === 404 && e.code === 'tool_missing')
	await assert.rejects(installed.runInstalled({ installed: { sha256, tool: 'escape' } }), (e) => e.status === 404)
})
