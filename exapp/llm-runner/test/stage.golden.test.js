/**
 * Golden test for `runStage()`.
 *
 * Runs one stage per generated case over real git remotes (a plain path and an
 * HTTP remote that demands a credential), with every combination of tool tree,
 * forge credential, caller env, per-run proxy token, ambient proxy variables,
 * vendor credential, collected files and push declaration a case can draw. A
 * probe script prints the environment its command child saw, where it ran and
 * which tree it came from, and the whole result (or the error) is compared with
 * a recorded fixture.
 *
 * Set GOLDEN_RECORD=1 to (re)write the fixture; a run in record mode compares
 * nothing.
 *
 * Run: `node --test test/stage.golden.test.js`.
 *
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @copyright 2026 Conduction B.V.
 */

'use strict'

// Read at module load by stage.js.
process.env.RUNNER_STAGE_COMMANDS = './probe.sh,scripts/probe.sh,bin/claude'
process.env.EGRESS_PROXY_AUTHORITY = '127.0.0.1:9'

const test = require('node:test')
const assert = require('node:assert')
const fs = require('fs')
const os = require('os')
const path = require('path')
const { execFileSync } = require('child_process')

const { startGitServer } = require('./helpers/gitServer')
const { runStage } = require('../src/stage')

const FIXTURE = path.join(__dirname, 'fixtures', 'stage-golden.json')
const CASES = 60
const FIXED = 10
const PASSWORD = 'golden-forge-token'

const PROBE = [
	'#!/bin/sh',
	'echo "TREE=$(cat "$(dirname "$0")/../whoami.txt" 2>/dev/null || cat "$(dirname "$0")/whoami.txt")"',
	'echo "CWD=$(cat whoami.txt)"',
	'echo "ARGS=$*"',
	'[ "$HOME" = "$(dirname "$PWD")" ] && echo "HOME=scratch" || echo "HOME=$HOME"',
	'[ -n "$PATH" ] && echo "PATH=set" || echo "PATH=unset"',
	'echo "PROMPT=${GIT_TERMINAL_PROMPT:-unset}"',
	'echo "TOKEN=${GIT_FORGE_TOKEN:-unset}"',
	'echo "ASKPASS=$(basename "${GIT_ASKPASS:-unset}")"',
	'echo "HTTPS_PROXY=${HTTPS_PROXY:-unset} https_proxy=${https_proxy:-unset}"',
	'echo "HTTP_PROXY=${HTTP_PROXY:-unset} http_proxy=${http_proxy:-unset}"',
	'echo "OAT=${CLAUDE_CODE_OAUTH_TOKEN:-unset} KEY=${ANTHROPIC_API_KEY:-unset}"',
	'echo "EXTRA=${EXTRA:-unset} EVIL=${EVIL:-unset}"',
	'echo "ORIGIN=$(git remote get-url origin)"',
	'mkdir -p out && echo "{\\"tree\\": \\"$(cat whoami.txt)\\", \\"n\\": 3}" > out/result.json',
	'exit 3',
	'',
].join('\n')

/**
 * Run git in a directory with a fixed identity and no ambient configuration.
 *
 * @param {Array<string>} args Arguments.
 * @param {string} cwd Working directory.
 * @returns {string} stdout.
 */
function git(args, cwd) {
	return execFileSync('git', args, {
		cwd,
		encoding: 'utf8',
		env: {
			PATH: process.env.PATH,
			HOME: cwd,
			GIT_AUTHOR_NAME: 'golden',
			GIT_AUTHOR_EMAIL: 'golden@example.invalid',
			GIT_COMMITTER_NAME: 'golden',
			GIT_COMMITTER_EMAIL: 'golden@example.invalid',
			GIT_CONFIG_GLOBAL: '/dev/null',
			GIT_CONFIG_SYSTEM: '/dev/null',
		},
	})
}

/**
 * Create a bare repository with `main` and `development`, both carrying the probe.
 *
 * @param {string} root Where to create it.
 * @param {string} name Repository name, also written to whoami.txt.
 * @returns {string} The bare repository path.
 */
function seedRepo(root, name) {
	const bare = path.join(root, `${name}.git`)
	const work = path.join(root, `${name}-seed`)
	git(['init', '--bare', bare], root)
	git(['symbolic-ref', 'HEAD', 'refs/heads/main'], bare)
	git(['init', work], root)
	git(['symbolic-ref', 'HEAD', 'refs/heads/main'], work)
	fs.writeFileSync(path.join(work, 'whoami.txt'), `${name}-main\n`)
	for (const file of ['probe.sh', 'scripts/probe.sh', 'bin/claude']) {
		fs.mkdirSync(path.join(work, path.dirname(file)), { recursive: true })
		fs.writeFileSync(path.join(work, file), PROBE, { mode: 0o755 })
	}

	git(['add', '-A'], work)
	git(['commit', '-m', 'main'], work)
	git(['checkout', '-b', 'development'], work)
	fs.writeFileSync(path.join(work, 'whoami.txt'), `${name}-development\n`)
	git(['commit', '-am', 'development'], work)
	git(['remote', 'add', 'origin', bare], work)
	git(['push', 'origin', 'main', 'development'], work)
	// The tool repository's default branch is development, so a tool clone
	// with no ref visibly lands on the remote's HEAD rather than on main.
	if (name === 'tool') {
		git(['symbolic-ref', 'HEAD', 'refs/heads/development'], bare)
	}

	return bare
}

/**
 * The tool tree as a forge tarball: everything under one wrapper directory.
 *
 * @param {string} root Fixture root.
 * @param {string} bare The tool repository.
 * @returns {string} The archive, base64.
 */
function toolTarball(root, bare) {
	const staging = path.join(root, 'tar')
	const checkout = path.join(staging, 'owner-tool-abc1234')
	fs.mkdirSync(staging)
	git(['clone', '--quiet', '--branch', 'development', bare, checkout], root)
	fs.writeFileSync(path.join(checkout, 'whoami.txt'), 'tool-archive\n')
	fs.rmSync(path.join(checkout, '.git'), { recursive: true, force: true })
	const archive = path.join(staging, 'tool.tar.gz')
	execFileSync('tar', ['-czf', archive, '-C', staging, 'owner-tool-abc1234'])
	return fs.readFileSync(archive).toString('base64')
}

/**
 * A small seeded generator, so every case draws the same values on every run.
 *
 * @param {number} seed The seed.
 * @returns {Function} pick(list) returning one element.
 */
function picker(seed) {
	let state = seed * 0x9e3779b9
	return (list) => {
		state = (state + 0x6d2b79f5) | 0
		let t = Math.imul(state ^ (state >>> 15), 1 | state)
		t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t
		const unit = ((t ^ (t >>> 14)) >>> 0) / 4294967296
		return list[Math.floor(unit * list.length)]
	}
}

/**
 * Build one case's stage arguments and ambient proxy variables.
 *
 * @param {number} index The case number.
 * @param {object} world `{root, target, tool, url, tarball}`.
 * @returns {{args: object, ambient: object}} The case.
 */
function makeCase(index, world) {
	if (index >= CASES) {
		return fixedCase(index - CASES, world)
	}

	const pick = picker(index + 1)
	const http = pick([false, false, true])
	const repo = http === true ? world.url('target') : world.target
	const args = {
		repo: pick([...Array(18).fill(repo), '', undefined, 7]),
		ref: pick([
			...Array(12).fill('development'),
			...Array(5).fill('main'),
			'',
			undefined,
			'no-such-ref',
		]),
		command: pick([
			['./probe.sh', 'a', 'b'],
			['./probe.sh'],
			['./probe.sh'],
			['scripts/probe.sh'],
			['scripts/probe.sh', '--x'],
			['scripts/probe.sh'],
			['bin/claude', '-p'],
			['bin/claude'],
			['bin/claude'],
			['rm', '-rf'],
			'./probe.sh',
			[],
		]),
		toolRepo: pick([
			undefined,
			undefined,
			undefined,
			'',
			world.tool,
			world.tool,
			world.url('tool'),
			path.join(world.root, 'missing.git'),
		]),
		toolRef: pick([
			undefined,
			undefined,
			'',
			'main',
			'development',
			'no-such-ref',
		]),
		toolTarball: pick([
			undefined,
			undefined,
			undefined,
			undefined,
			undefined,
			'',
			world.tarball,
			world.tarball,
			'bm90IGEgdGFyYmFsbA==',
		]),
		collect: pick([
			undefined,
			undefined,
			undefined,
			['out/result.json'],
			{ res: 'out/result.json', who: 'whoami.txt' },
			['out/result.json', 'whoami.txt'],
			['../escape'],
			'out/result.json',
		]),
		forgeToken: pick([undefined, '', PASSWORD, PASSWORD, 'wrong-token']),
		forgeUser: pick([undefined, '', 'x-access-token', 'x-access-token']),
		timeoutMs: pick([undefined, 0, '60000', 60000, -5]),
		env: pick([
			undefined,
			{ EXTRA: 'one' },
			'not-an-object',
			{ EXTRA: 'two', GIT_TERMINAL_PROMPT: '1', HOME: '/override' },
			null,
		]),
		runToken: pick([undefined, undefined, '', 'run token/1']),
		push: pick([
			undefined,
			undefined,
			null,
			'yes',
			{
				branch: 'main',
				issue: 1,
				scope: ['out'],
				allowedRepo: repo,
				message: 'm',
			},
			{
				branch: `feature/${index}/golden`,
				issue: index,
				scope: ['out'],
				allowedRepo: repo,
				message: 'feat: golden',
			},
		]),
		credentialEnv: pick([
			undefined,
			{
				CLAUDE_CODE_OAUTH_TOKEN: 'oat-1',
				ANTHROPIC_API_KEY: 'key-1',
				EVIL: 'x',
				PATH: '/evil',
			},
			{ ANTHROPIC_API_KEY: 'key-2' },
		]),
	}
	for (const key of Object.keys(args)) {
		if (args[key] === undefined) {
			delete args[key]
		}
	}

	const ambient = pick([
		{},
		{},
		{ HTTPS_PROXY: 'http://127.0.0.1:9', http_proxy: 'http://127.0.0.1:9' },
	])
	return { args, ambient }
}

/**
 * Hand-picked cases that reach the push, the vendor credential and the tool trees.
 *
 * @param {number} index The fixed case number.
 * @param {object} world `{root, target, tool, url, tarball}`.
 * @returns {{args: object, ambient: object}} The case.
 */
function fixedCase(index, world) {
	const url = world.url('target')
	const http = {
		repo: url,
		ref: 'development',
		forgeToken: PASSWORD,
		forgeUser: 'x-access-token',
		timeoutMs: 60000,
	}
	const credentialEnv = {
		CLAUDE_CODE_OAUTH_TOKEN: 'oat-1',
		ANTHROPIC_API_KEY: 'key-1',
		EVIL: 'x',
	}
	const push = (branch) => ({
		branch,
		issue: 7,
		scope: ['out'],
		allowedRepo: url,
		message: 'feat: golden',
	})
	return [
		{
			args: {
				...http,
				command: ['bin/claude', '-p'],
				credentialEnv,
				push: push('feature/7/golden'),
				collect: ['out/result.json'],
			},
			ambient: {},
		},
		{
			args: {
				...http,
				command: ['scripts/probe.sh'],
				toolRepo: world.url('tool'),
				toolRef: 'main',
				env: { EXTRA: 'tool' },
				credentialEnv,
			},
			ambient: {},
		},
		{
			args: {
				repo: world.target,
				ref: 'main',
				command: ['scripts/probe.sh', 'x'],
				toolTarball: world.tarball,
				runToken: 'run token/1',
				credentialEnv,
				collect: { res: 'out/result.json' },
			},
			ambient: { HTTPS_PROXY: 'http://127.0.0.1:9' },
		},
		{
			args: { ...http, command: ['./probe.sh'], push: push('main') },
			ambient: {},
		},
		{
			args: {
				...http,
				command: ['./probe.sh'],
				push: { ...push('feature/8/golden'), scope: ['lib'] },
			},
			ambient: {},
		},
		{
			args: {
				...http,
				command: ['bin/claude'],
				push: {
					...push('feature/9/golden'),
					allowedRepo: world.url('tool'),
				},
			},
			ambient: {},
		},
		{
			args: {
				repo: world.target,
				ref: 'development',
				command: ['./probe.sh'],
				toolRepo: world.tool,
				toolTarball: world.tarball,
				forgeToken: 'tok',
				env: { EXTRA: 'both' },
			},
			ambient: { http_proxy: 'http://127.0.0.1:9' },
		},
		{
			args: {
				...http,
				forgeUser: '',
				forgeToken: undefined,
				command: ['./probe.sh'],
			},
			ambient: {},
		},
		{
			args: {
				...http,
				forgeUser: '',
				command: ['./probe.sh'],
				toolRepo: world.url('tool'),
			},
			ambient: {},
		},
		{
			args: {
				repo: world.target,
				ref: 'main',
				command: ['scripts/probe.sh'],
				toolRepo: world.tool,
			},
			ambient: {},
		},
	][index]
}

/**
 * Replace every run-specific value (paths, ports, shas) with a stable token.
 *
 * @param {string} text The text.
 * @param {object} world `{root, port}`.
 * @returns {string} The text with placeholders.
 */
function normalise(text, world) {
	return String(text)
		.split(world.root)
		.join('<ROOT>')
		.split(`127.0.0.1:${world.port}`)
		.join('<SERVER>')
		.replace(/\/[\w./-]*hydra-stage-[\w]+/g, '<SCRATCH>')
		.replace(/\b[0-9a-f]{40}\b/g, '<SHA>')
		.replace(/\b[0-9a-f]{7,12}\.\.[0-9a-f]{7,12}\b/g, '<RANGE>')
}

/**
 * Run one case and describe what came back.
 *
 * @param {number} index The case number.
 * @param {object} world The fixture world.
 * @returns {Promise<object>} The recorded shape of the case.
 */
async function runCase(index, world) {
	const { args, ambient } = makeCase(index, world)
	const saved = {}
	for (const name of ['HTTPS_PROXY', 'HTTP_PROXY', 'https_proxy', 'http_proxy']) {
		saved[name] = process.env[name]
		delete process.env[name]
	}
	Object.assign(process.env, ambient)

	let outcome
	try {
		const result = await runStage(args)
		outcome = { result: JSON.parse(normalise(JSON.stringify(result), world)) }
	} catch (err) {
		outcome = {
			error: {
				name: err.name,
				code: err.code,
				message: normalise(err.message, world),
			},
		}
	} finally {
		for (const [name, value] of Object.entries(saved)) {
			delete process.env[name]
			if (value !== undefined) {
				process.env[name] = value
			}
		}
	}

	const recorded = { ...args }
	if (recorded.toolTarball === world.tarball) {
		recorded.toolTarball = '<TARBALL>'
	}

	return JSON.parse(
		normalise(
			JSON.stringify({ case: index, args: recorded, ambient, ...outcome }),
			world,
		),
	)
}

test('every generated stage gives the recorded result', async () => {
	const root = fs.mkdtempSync(path.join(os.tmpdir(), 'stage-golden-'))
	const target = seedRepo(root, 'target')
	const tool = seedRepo(root, 'tool')
	const server = await startGitServer({ root, password: PASSWORD })
	const world = {
		root,
		target,
		tool,
		port: server.port,
		url: (name) => server.url(name),
		tarball: toolTarball(root, tool),
	}

	const actual = []
	try {
		for (let index = 0; index < CASES + FIXED; index++) {
			actual.push(await runCase(index, world))
		}
	} finally {
		await server.close()
		fs.rmSync(root, { recursive: true, force: true })
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
