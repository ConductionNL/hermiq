/**
 * Golden test for one CLI turn, `run()` without a governed endpoint to probe.
 *
 * Runs generated turns against a stub CLI that reports its argv, the
 * environment it was given, its stdin, its working directory and the MCP config
 * file it was pointed at, and that can exit non-zero, write too much, write
 * stderr, print output the provider cannot parse, or not exist at all. Turns
 * vary the provider's arguments (with and without the governed lockdown
 * flags), the MCP config, the per-run token, the credential map, LANG, the
 * passthrough variable and the debug switch. The result or error, every
 * console line and whether the scratch tree was removed are compared with a
 * recorded fixture. GOLDEN_RECORD=1 rewrites it.
 *
 * Run: `node --test test/runner.spawn.golden.test.js`.
 *
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @copyright 2026 Conduction B.V.
 */

'use strict'

// Read at module load by runner.js.
process.env.RUNNER_PASSTHROUGH_ENV = 'GOLDEN_PASS'
process.env.RUNNER_MAX_OUTPUT_BYTES = '3000'
process.env.EGRESS_PROXY_AUTHORITY = '127.0.0.1:9'

const test = require('node:test')
const assert = require('node:assert')
const fs = require('fs')
const os = require('os')
const path = require('path')

const { run } = require('../src/runner')

const FIXTURE = path.join(__dirname, 'fixtures', 'runner-spawn-golden.json')
const CASES = 160

const STUB = `#!/usr/bin/env node
const fs = require('fs')
const mode = process.argv[2]
const input = fs.readFileSync(0, 'utf8')
const cfgAt = process.argv.indexOf('--mcp-config')
let mcp = null
if (cfgAt !== -1) {
	const file = process.argv[cfgAt + 1]
	mcp = { mode: (fs.statSync(file).mode & 0o777).toString(8), body: fs.readFileSync(file, 'utf8'), inScratch: file.startsWith(process.env.HOME) }
}
const keys = Object.keys(process.env).filter((k) => !k.startsWith('npm_') && k !== '_').sort()
const report = {
	argv: process.argv.slice(2),
	env: Object.fromEntries(keys.map((k) => [k, k === 'PATH' ? 'set' : k === 'HOME' || k === 'TMPDIR' ? (process.env[k] === process.cwd() ? 'cwd' : 'other') : process.env[k]])),
	stdin: input,
	mcp,
}
if (mode === 'stderr') process.stderr.write('provider says no: Bearer sk-SECRET-123 and api_key=abc\\n')
if (mode === 'big') process.stdout.write('x'.repeat(5000))
else if (mode === 'garbage') process.stdout.write('not json')
else process.stdout.write(JSON.stringify(report))
process.exit(mode === 'exit2' || mode === 'stderr' ? 2 : 0)
`

/**
 * A small seeded generator.
 *
 * @param {number} seed The seed.
 * @returns {Function} pick(list)
 */
function picker(seed) {
	let state = seed * 0x9e3779b9
	return (list) => {
		state = (state + 0x6d2b79f5) | 0
		let t = Math.imul(state ^ (state >>> 15), 1 | state)
		t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t
		return list[
			Math.floor((((t ^ (t >>> 14)) >>> 0) / 4294967296) * list.length)
		]
	}
}

/**
 * One case's turn and ambient environment.
 *
 * @param {number} index The case.
 * @param {string} stub The stub CLI path.
 * @returns {object} The case.
 */
function makeCase(index, stub) {
	const pick = picker(index + 1)
	const flags = pick([
		'governed',
		'governed',
		'no-disallowed',
		'tools',
		'no-strict',
		'no-config',
		'none',
	])
	const label = {
		bin: pick([stub, stub, stub, stub, stub, '/nonexistent/cli']),
		mode: pick(['ok', 'ok', 'ok', 'ok', 'exit2', 'stderr', 'big', 'garbage']),
		flags,
		parse: pick(['json', 'json', 'json', 'throws']),
	}
	const provider = {
		bin: label.bin,
		credentialKeys: ['TOKEN_A', 'TOKEN_B'],
		args: (model, { mcpConfigPath }) => {
			const args = [model]
			if (
				flags === 'governed'
				|| flags === 'no-strict'
				|| flags === 'no-config'
			) {
				args.push('--disallowedTools', 'Bash,Edit')
			}
			if (flags === 'no-disallowed' || flags === 'tools') {
				args.push('--disallowedTools')
			}
			if (flags === 'tools') {
				args.push('Bash', '--tools', '')
			}
			if (flags !== 'no-strict' && flags !== 'none') {
				args.push('--strict-mcp-config')
			}
			if (mcpConfigPath && flags !== 'no-config') {
				args.push('--mcp-config', mcpConfigPath)
			}
			return args
		},
		parse: (text) => {
			if (label.parse === 'throws') {
				throw new Error('unparseable')
			}
			return JSON.parse(text)
		},
	}
	const turn = {
		provider,
		model: label.mode,
		messages: pick([
			[{ role: 'user', content: 'Hello' }],
			[
				{ role: 'system', content: 'Be brief.' },
				{ role: 'user', content: 'Twee' },
			],
			[],
		]),
		credentialEnv: pick([
			undefined,
			{ TOKEN_A: 'a-1', PATH: '/evil', LD_PRELOAD: 'x' },
			{ TOKEN_B: 'b-2' },
		]),
		mcpConfig: pick([
			undefined,
			undefined,
			null,
			'cfg',
			{ mcpServers: {} },
			{ mcpServers: {}, note: 'kept' },
		]),
		runToken: pick([undefined, '', 'rt 1']),
	}
	for (const key of Object.keys(turn)) {
		if (turn[key] === undefined) {
			delete turn[key]
		}
	}
	const ambient = {
		LANG: pick([undefined, 'nl_NL.UTF-8']),
		GOLDEN_PASS: pick([undefined, 'passed']),
		HTTPS_PROXY: pick([undefined, 'http://static.invalid:1']),
		RUNNER_DEBUG_ARGV: pick([undefined, undefined, '1']),
	}
	return { label, turn, ambient }
}

/**
 * Replace run-specific values with stable tokens.
 *
 * @param {string} text The text.
 * @param {string} stub The stub CLI path.
 * @returns {string} The text with placeholders.
 */
function normalise(text, stub) {
	return String(text)
		.split(stub)
		.join('<STUB>')
		.replace(/\/[\w./-]*llm-runner-[\w]+/g, '<SCRATCH>')
}

test('every generated turn gives the recorded result', async () => {
	const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'runner-golden-'))
	const stub = path.join(dir, 'cli.js')
	fs.writeFileSync(stub, STUB, { mode: 0o755 })

	const actual = []
	const originalLog = console.log
	const saved = { ...process.env }
	try {
		for (let index = 0; index < CASES; index++) {
			const c = makeCase(index, stub)
			for (const [name, value] of Object.entries(c.ambient)) {
				delete process.env[name]
				if (value !== undefined) {
					process.env[name] = value
				}
			}
			const lines = []
			console.log = (line) => lines.push(String(line))
			const before = new Set(
				fs
					.readdirSync(os.tmpdir())
					.filter((n) => n.startsWith('llm-runner-')),
			)
			let outcome
			try {
				outcome = { result: await run(c.turn) }
			} catch (e) {
				outcome = { error: e.message }
			} finally {
				console.log = originalLog
			}
			const left = fs
				.readdirSync(os.tmpdir())
				.filter((n) => n.startsWith('llm-runner-') && !before.has(n))
			actual.push(
				JSON.parse(
					normalise(
						JSON.stringify({
							case: index,
							label: c.label,
							turn: { ...c.turn, provider: undefined },
							ambient: c.ambient,
							...outcome,
							log: lines,
							scratchLeft: left.length,
						}),
						stub,
					),
				),
			)
		}
	} finally {
		console.log = originalLog
		for (const name of Object.keys(process.env)) {
			if (!(name in saved)) {
				delete process.env[name]
			}
		}
		Object.assign(process.env, saved)
		fs.rmSync(dir, { recursive: true, force: true })
	}

	if (process.env.GOLDEN_RECORD === '1') {
		fs.mkdirSync(path.dirname(FIXTURE), { recursive: true })
		fs.writeFileSync(FIXTURE, JSON.stringify(actual, null, 1) + '\n')
		return
	}

	const expected = JSON.parse(fs.readFileSync(FIXTURE, 'utf8'))
	assert.strictEqual(actual.length, expected.length)
	for (let index = 0; index < expected.length; index++) {
		assert.deepStrictEqual(actual[index], expected[index], `case ${index}`)
	}
})
