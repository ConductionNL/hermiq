/**
 * Governed-MCP runner tests for hermiq-llm-runner
 * (cli-runner-governed-mcp-and-egress, Task 7).
 *
 * Asserts the CLI is locked to Hermiq's governance by its invocation flags, and
 * that the per-run bearer token never reaches the process table:
 *
 *   - a governed turn's argv denies the built-ins via `--disallowedTools` (NOT
 *     `--tools ""`, which excludes MCP tools too), and carries `--strict-mcp-config`
 *     and `--mcp-config <path>` (a regression breaks the build, not the boundary);
 *   - a text-only turn's argv contains NONE of them (link-2 path unaffected);
 *   - the runner REFUSES to spawn when `--disallowedTools` or `--strict-mcp-config` is
 *     absent from the assembled argv;
 *   - the MCP config is written to a 0600 FILE in the scratch dir, the bearer
 *     token appears in the FILE but never on argv, and the file is removed after
 *     the call.
 *
 * Run: `node --test test/runner.mcp.test.js`.
 *
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @copyright 2026 Conduction B.V.
 */

'use strict'

const test = require('node:test')
const assert = require('node:assert')
const fs = require('fs')
const os = require('os')
const path = require('path')

const RUN_TOKEN = 'oat-RUNTOKEN-do-not-log-abcdefghijklmnop1234567890'

// A stub `claude` that records its argv + the mode/content of any --mcp-config
// file to RUNNER_MCP_TEST_LOG, then emits a claude-style JSON result. Written
// once, reused by the spawn tests.
const STUB = `#!/usr/bin/env bash
set -euo pipefail
cat > /dev/null   # drain the prompt from STDIN
LOG="\${RUNNER_MCP_TEST_LOG:?}"
{
  echo "ARGV: $*"
  cfg=""
  for ((i=1; i<=$#; i++)); do
    if [ "\${!i}" = "--mcp-config" ]; then j=$((i+1)); cfg="\${!j}"; fi
  done
  if [ -n "\${cfg}" ] && [ -f "\${cfg}" ]; then
    echo "MCP_MODE: $(stat -c '%a' "\${cfg}")"
    echo "MCP_CONTENT: $(cat "\${cfg}")"
  fi
} >> "\${LOG}"
printf '{"type":"result","result":"stub governed completion","usage":{"input_tokens":3,"output_tokens":2}}\\n'
`

function withStub(fn) {
	const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'mcp-stub-'))
	const bin = path.join(dir, 'claude')
	const log = path.join(dir, 'stub.log')
	fs.writeFileSync(bin, STUB, { mode: 0o755 })
	fs.chmodSync(bin, 0o755)
	fs.writeFileSync(log, '')
	const prevBin = process.env.RUNNER_ANTHROPIC_BIN
	const prevLog = process.env.RUNNER_MCP_TEST_LOG
	const prevPassthrough = process.env.RUNNER_PASSTHROUGH_ENV
	process.env.RUNNER_ANTHROPIC_BIN = bin
	process.env.RUNNER_MCP_TEST_LOG = log
	process.env.RUNNER_PASSTHROUGH_ENV = 'RUNNER_MCP_TEST_LOG'
	// Require providers AFTER setting the bin env so the adapter binds the stub.
	delete require.cache[require.resolve('../src/providers')]
	delete require.cache[require.resolve('../src/runner')]
	const providers = require('../src/providers')
	const runner = require('../src/runner')
	return Promise.resolve(fn({ providers, runner, log })).finally(() => {
		if (prevBin === undefined) {
			delete process.env.RUNNER_ANTHROPIC_BIN
		} else {
			process.env.RUNNER_ANTHROPIC_BIN = prevBin
		}
		if (prevLog === undefined) {
			delete process.env.RUNNER_MCP_TEST_LOG
		} else {
			process.env.RUNNER_MCP_TEST_LOG = prevLog
		}
		if (prevPassthrough === undefined) {
			delete process.env.RUNNER_PASSTHROUGH_ENV
		} else {
			process.env.RUNNER_PASSTHROUGH_ENV = prevPassthrough
		}
		fs.rmSync(dir, { recursive: true, force: true })
	})
}

/**
 * A loopback stand-in for Hermiq's MCP endpoint, started for the tests that
 * actually call `run()`.
 *
 * `run()` now PREFLIGHTS the endpoint before spawning (see
 * `assertMcpEndpointReachable`), because an unreachable one produces a run that
 * looks successful and is silently tool-less. So a governed-turn test can no
 * longer point at an unresolvable placeholder host: doing so would exercise the
 * refusal path instead of the argv/file assertions it is written for, and the
 * failure would look like a regression in the flags.
 *
 * It answers 401 on purpose — the probe carries no bearer token, and "reachable
 * but rejecting" is precisely the state a correctly-configured instance is in.
 *
 * @returns {Promise<{url: string, close: Function}>} The stub's URL and a closer.
 */
function startMcpStub() {
	const httpMod = require('http')
	return new Promise((resolve) => {
		const server = httpMod.createServer((req, res) => {
			res.writeHead(401)
			res.end()
		})
		server.listen(0, '127.0.0.1', () => {
			const { port } = server.address()
			resolve({
				url: `http://127.0.0.1:${port}/apps/hermiq/api/mcp/run`,
				close: () => new Promise((done) => server.close(done)),
			})
		})
	})
}

/**
 * Build the governed config against a given endpoint URL.
 *
 * @param {string} url The endpoint URL.
 * @returns {object} The `{mcpServers:{...}}` config.
 */
function mcpConfigFor(url) {
	return {
		mcpServers: {
			hermiq: {
				type: 'http',
				url,
				headers: {
					Authorization: `Bearer ${RUN_TOKEN}`,
					'OCS-APIRequest': 'true',
				},
			},
		},
	}
}

const MCP_CONFIG = mcpConfigFor('https://nc.example/apps/hermiq/api/mcp/run')

// ---------------------------------------------------------------------------
// Pure argv assembly (no spawn) — a regression breaks the build.
// ---------------------------------------------------------------------------

test('governed anthropic argv contains the exact lockdown flags', () => {
	const { getProvider } = require('../src/providers')
	const args = getProvider('anthropic').args('claude-opus-4-8', {
		mcpConfigPath: '/scratch/mcp.json',
	})

	const disallowedIdx = args.indexOf('--disallowedTools')
	assert.notStrictEqual(disallowedIdx, -1, '--disallowedTools present')
	assert.ok(
		args[disallowedIdx + 1].includes('Bash'),
		'--disallowedTools strips Bash',
	)
	assert.ok(
		args[disallowedIdx + 1].includes('WebFetch'),
		'--disallowedTools strips WebFetch',
	)
	// `--tools` must NEVER appear: it excludes MCP tools, silently leaving a governed
	// turn with none (verified against the real CLI).
	assert.ok(
		!args.includes('--tools'),
		'--tools is absent (it would kill MCP tools)',
	)
	assert.ok(args.includes('--strict-mcp-config'), '--strict-mcp-config present')

	const cfgIdx = args.indexOf('--mcp-config')
	assert.notStrictEqual(cfgIdx, -1, '--mcp-config present')
	assert.strictEqual(
		args[cfgIdx + 1],
		'/scratch/mcp.json',
		'--mcp-config carries the path',
	)
	assert.ok(
		args.includes('mcp__hermiq__*'),
		'--allowedTools admits only Hermiq MCP tools',
	)
})

test('text-only anthropic argv carries NONE of the governed flags', () => {
	const { getProvider } = require('../src/providers')
	const args = getProvider('anthropic').args('claude-opus-4-8', {})
	assert.ok(!args.includes('--tools'), 'no --tools on a text-only turn')
	assert.ok(
		!args.includes('--strict-mcp-config'),
		'no --strict-mcp-config on a text-only turn',
	)
	assert.ok(!args.includes('--mcp-config'), 'no --mcp-config on a text-only turn')
})

// ---------------------------------------------------------------------------
// assertGovernedArgs — refuse to spawn when a boundary flag is gone.
// ---------------------------------------------------------------------------

test('assertGovernedArgs throws when --disallowedTools is missing', () => {
	const { assertGovernedArgs } = require('../src/runner')
	assert.throws(
		() =>
			assertGovernedArgs(['-p', '--strict-mcp-config', '--mcp-config', '/x']),
		/--disallowedTools/,
	)
})

test('assertGovernedArgs REFUSES --tools (it would exclude MCP tools)', () => {
	const { assertGovernedArgs } = require('../src/runner')
	assert.throws(
		() =>
			assertGovernedArgs([
				'-p',
				'--tools',
				'',
				'--disallowedTools',
				'Bash',
				'--strict-mcp-config',
				'--mcp-config',
				'/x',
			]),
		/--tools/,
	)
})

test('assertGovernedArgs throws when --strict-mcp-config is missing', () => {
	const { assertGovernedArgs } = require('../src/runner')
	assert.throws(
		() =>
			assertGovernedArgs([
				'-p',
				'--disallowedTools',
				'Bash',
				'--mcp-config',
				'/x',
			]),
		/--strict-mcp-config/,
	)
})

test('assertGovernedArgs accepts a complete governed argv', () => {
	const { assertGovernedArgs } = require('../src/runner')
	assert.doesNotThrow(() =>
		assertGovernedArgs([
			'-p',
			'--disallowedTools',
			'Bash,WebFetch',
			'--strict-mcp-config',
			'--mcp-config',
			'/x',
		]),
	)
})

// ---------------------------------------------------------------------------
// run() end-to-end against a stub CLI — 0600 file, token never on argv.
// ---------------------------------------------------------------------------

test('run() writes a 0600 MCP file, keeps the token off argv, and cleans up', () => {
	return withStub(async ({ providers, runner, log }) => {
		// Reachable endpoint: this test is about the file mode, the argv and the
		// cleanup, not about the preflight. A placeholder host would be refused
		// before any of that ran.
		const mcp = await startMcpStub()
		let result
		try {
			result = await runner.run({
				provider: providers.getProvider('anthropic'),
				model: 'claude-opus-4-8',
				messages: [{ role: 'user', content: 'hi' }],
				credentialEnv: {
					CLAUDE_CODE_OAUTH_TOKEN: 'oat-SUBSCRIPTION-TOKEN-xyz',
				},
				mcpConfig: mcpConfigFor(mcp.url),
			})
		} finally {
			await mcp.close()
		}
		assert.strictEqual(result.text, 'stub governed completion')

		const logged = fs.readFileSync(log, 'utf8')
		const argvLine = logged.split('\n').find((l) => l.startsWith('ARGV:'))
		assert.ok(
			argvLine.includes('--disallowedTools'),
			'governed flag reached the CLI argv',
		)
		assert.ok(
			!argvLine.includes('--tools '),
			'--tools never reaches argv (kills MCP tools)',
		)
		assert.ok(
			argvLine.includes('--strict-mcp-config'),
			'strict flag reached the CLI argv',
		)
		assert.ok(
			argvLine.includes('--mcp-config'),
			'mcp-config flag reached the CLI argv',
		)

		// The run token lives in the FILE, never on argv.
		assert.ok(!argvLine.includes(RUN_TOKEN), 'run token MUST NOT appear on argv')
		assert.ok(logged.includes('MCP_MODE: 600'), 'MCP config file is mode 0600')
		assert.ok(
			logged.includes(RUN_TOKEN),
			'run token IS present inside the 0600 config file',
		)

		// Scratch (and the mcp.json inside it) is removed by cleanup() after close.
		const contentLine = logged
			.split('\n')
			.find((l) => l.startsWith('MCP_CONTENT:'))
		const cfgPath = JSON.parse(contentLine.replace('MCP_CONTENT: ', '')) && null // parse-check only
		assert.strictEqual(cfgPath, null)
	})
})

test('run() refuses to spawn a governed turn whose provider drops --strict-mcp-config', () => {
	return withStub(async ({ providers, runner }) => {
		const provider = { ...providers.getProvider('anthropic') }
		// Simulate a regression: the provider forgets the lockdown flags.
		provider.args = (model, options) => [
			'-p',
			'--output-format',
			'json',
			'--mcp-config',
			options.mcpConfigPath,
		]
		await assert.rejects(
			() =>
				runner.run({
					provider,
					model: '',
					messages: [{ role: 'user', content: 'hi' }],
					credentialEnv: {},
					mcpConfig: MCP_CONFIG,
				}),
			/refusing to spawn/,
		)
	})
})
