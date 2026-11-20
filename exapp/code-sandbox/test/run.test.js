// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// One run: output, limits, files, and the work directory that is gone afterwards.
//
// The network case needs the container's network (internal, no route out): it
// runs only where SANDBOX_IN_CONTAINER=1, which the image sets. Outside the
// container this module does not isolate the network, and the test says so.

'use strict'

const test = require('node:test')
const assert = require('node:assert/strict')
const fs = require('fs')
const os = require('os')
const path = require('path')

const root = fs.mkdtempSync(path.join(os.tmpdir(), 'sandbox-test-'))
process.env.SANDBOX_WORK_ROOT = root
const { run, clampLimits, commandFor, LANGUAGES } = require('../src/run.js')

const left = () => fs.readdirSync(root)

test('python prints 1 + 1', async () => {
	const out = await run({ language: 'python', code: 'print(1 + 1)' })
	assert.equal(out.exitCode, 0)
	assert.equal(out.stdout, '2\n')
	assert.equal(out.timedOut, false)
	assert.deepEqual(out.limits, { wallSeconds: 30, memoryMb: 512 })
	assert.deepEqual(left(), [])
})

test('node reads stdin and prints JSON', async () => {
	const code = 'let s="";process.stdin.on("data",d=>s+=d).on("end",()=>{const i=JSON.parse(s);i.postcode=i.postcode.toUpperCase().replace(" ","");console.log(JSON.stringify(i))})'
	const out = await run({ language: 'node', code, stdin: '{"postcode":"1234 ab"}' })
	assert.equal(out.exitCode, 0)
	assert.deepEqual(JSON.parse(out.stdout), { postcode: '1234AB' })
})

test('an endless loop is stopped at its wall time', async () => {
	const out = await run({ language: 'python', code: 'while True: pass', limits: { wallSeconds: 2 } })
	assert.equal(out.timedOut, true)
	assert.notEqual(out.exitCode, 0)
	assert.ok(out.durationMs < 5000, 'stopped near the limit')
	assert.deepEqual(left(), [])
})

test('a memory hog is stopped by its memory limit', async () => {
	const python = await run({ language: 'python', code: 'x = bytearray(900 * 1024 * 1024)', limits: { memoryMb: 256 } })
	assert.notEqual(python.exitCode, 0)
	assert.match(python.stderr, /MemoryError/)

	const node = await run({ language: 'node', code: 'const a = []; while (true) a.push(new Array(1e6).fill(1))', limits: { memoryMb: 128, wallSeconds: 20 } })
	assert.notEqual(node.exitCode, 0)
	assert.equal(node.timedOut, false, 'the heap limit stopped it, not the clock')
})

test('a fork bomb runs under a process cap and is stopped', async () => {
	assert.ok(commandFor(LANGUAGES.python, clampLimits({})).args.includes('--nproc=64'))
	const out = await run({ language: 'python', code: 'import os\nwhile True:\n    os.fork()', limits: { wallSeconds: 3 } })
	assert.notEqual(out.exitCode, 0)
	assert.deepEqual(left(), [])
})

test('output is capped at 64 KB per stream', async () => {
	const out = await run({ language: 'python', code: 'import sys\nprint("x" * 200000)\nsys.stderr.write("y" * 100000)' })
	assert.equal(out.stdout.length, 64 * 1024)
	assert.equal(out.stdoutTruncated, true)
	assert.equal(out.stderr.length, 64 * 1024)
})

test('input files are placed and created files come back', async () => {
	const out = await run({
		language: 'python',
		code: 'open("out.txt", "w").write(open("in.csv").read().upper())',
		files: [{ name: 'in.csv', content: 'a,b' }],
	})
	assert.equal(out.exitCode, 0)
	assert.deepEqual(out.files, [{ name: 'out.txt', size: 3, content: Buffer.from('A,B').toString('base64'), encoding: 'base64' }])
	assert.deepEqual(left(), [])
})

test('the run sees none of the server environment', async () => {
	process.env.APP_SECRET = 'must-not-leak'
	const out = await run({ language: 'python', code: 'import os\nprint(sorted(os.environ))' })
	assert.doesNotMatch(out.stdout, /APP_SECRET|PROXY/i)
})

test('a bad request is refused before anything runs', async () => {
	for (const [request, message] of [
		[{ language: 'bash', code: 'ls' }, /python or node/],
		[{ language: 'python', code: ' ' }, /required/],
		[{ language: 'python', code: 'x', files: [{ name: '../etc', content: '' }] }, /plain name/],
		[{ language: 'python', code: 'x', files: [{ name: 'main.py', content: '' }] }, /plain name/],
		[{ language: 'python', code: 'x', stdin: 7 }, /stdin/],
		[{ language: 'python', code: 'x', files: 'a' }, /at most/],
	]) {
		await assert.rejects(run(request), (error) => error.status === 400 && message.test(error.message))
	}
	assert.deepEqual(left(), [])
})

test('limits are clamped to their ceilings', () => {
	assert.deepEqual(clampLimits({ wallSeconds: 999, memoryMb: 99999 }), { wallSeconds: 300, memoryMb: 2048 })
	assert.deepEqual(clampLimits({ wallSeconds: 0, memoryMb: '1' }), { wallSeconds: 1, memoryMb: 512 })
})

test('code cannot reach the internet', { skip: process.env.SANDBOX_IN_CONTAINER !== '1' && 'needs the container network' }, async () => {
	const out = await run({ language: 'python', code: 'import urllib.request\nurllib.request.urlopen("https://example.org", timeout=5)', limits: { wallSeconds: 15 } })
	assert.notEqual(out.exitCode, 0)
	assert.match(out.stderr, /URLError|gaierror|Network is unreachable|Name or service not known|Temporary failure/)
})

test.after(() => fs.rmSync(root, { recursive: true, force: true }))

// Workspace runs (tools-agent-workspace-and-installed-tools D2).

test('a workspace run changes one file and answers only that file', async () => {
	const files = [
		{ name: 'aanvragen.csv', content: 'id,dagen\n1,3\n2,5\n', encoding: 'utf-8' },
		{ name: 'notities/leesmij.txt', content: 'blijft gelijk', encoding: 'utf-8' },
	]
	const code = [
		'import csv',
		'rows = list(csv.reader(open("aanvragen.csv")))',
		'rows[0].append("doorlooptijd")',
		'for r in rows[1:]: r.append(str(int(r[1]) * 24))',
		'csv.writer(open("aanvragen.csv", "w", newline="")).writerows(rows)',
	].join('\n')
	const out = await run({ language: 'python', code, files, workspace: true })
	assert.equal(out.exitCode, 0, out.stderr)
	assert.deepEqual(out.files.map((f) => f.name), ['aanvragen.csv'])
	assert.match(Buffer.from(out.files[0].content, 'base64').toString('utf8'), /^id,dagen,doorlooptijd\r?\n1,3,72/)
	assert.equal(out.filesTruncated, false)
	assert.deepEqual(left(), [])
})

test('a workspace run answers a file it created in a subfolder, and not its program', async () => {
	const out = await run({ language: 'python', code: 'import os\nos.mkdir("uit")\nopen("uit/totaal.txt", "w").write("8")', files: [{ name: 'main.py', content: 'het eigen main.py van de workspace' }], workspace: true })
	assert.equal(out.exitCode, 0, out.stderr)
	assert.deepEqual(out.files.map((f) => f.name), ['uit/totaal.txt'])
})

test('a workspace run keeps a workspace file named like the program', async () => {
	const out = await run({ language: 'python', code: 'print(open("main.py").read())', files: [{ name: 'main.py', content: 'workspace' }], workspace: true })
	assert.equal(out.stdout, 'workspace\n')
	assert.deepEqual(out.files, [])
})

test('a workspace path that leaves the work folder is refused before anything runs', async () => {
	for (const name of ['../buiten.txt', '/etc/passwd', 'a/../../b', '.git/config', 'a//b', 'a\\b', '']) {
		await assert.rejects(run({ language: 'python', code: 'print(1)', files: [{ name, content: 'x' }], workspace: true }), (error) => error.status === 400, name)
	}
	assert.deepEqual(left(), [])
})

test('outside a workspace run a relative path is still refused', async () => {
	await assert.rejects(run({ language: 'python', code: 'print(1)', files: [{ name: 'map/bestand.txt', content: 'x' }] }), (error) => error.status === 400)
})
