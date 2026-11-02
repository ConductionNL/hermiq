/**
 * The CLI child's proxy environment and its time limit.
 *
 * Both read configuration at module load, so each test loads `runner.js`
 * afresh under the environment it needs.
 *
 * Run: `node --test test/runner.env.test.js`.
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

/**
 * Load `runner.js` under the given environment.
 *
 * @param {object} env Variables to set before the load.
 * @returns {object} The module.
 */
function loadRunner(env) {
	const runnerPath = require.resolve('../src/runner')
	delete require.cache[runnerPath]
	Object.assign(process.env, env)
	return require('../src/runner')
}

/**
 * A provider whose CLI is the given shell script.
 *
 * @param {string} dir Where to write the script.
 * @param {string} body The script body.
 * @returns {object} The provider.
 */
function stubProvider(dir, body) {
	const bin = path.join(dir, 'cli.sh')
	fs.writeFileSync(bin, `#!/bin/sh\n${body}\n`, { mode: 0o755 })
	return {
		bin,
		credentialKeys: [],
		args: () => [],
		parse: (text) => ({ text }),
	}
}

test('a passed-through HTTPS_PROXY never shadows the per-run proxy', async () => {
	// The container's own HTTPS_PROXY has no run identity, and the PDP refuses
	// a request without one. Even when an operator lists HTTPS_PROXY in the
	// passthrough, the child must get the run-scoped URL.
	const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'runner-env-'))
	const saved = { ...process.env }
	try {
		const { run } = loadRunner({
			RUNNER_PASSTHROUGH_ENV: 'HTTPS_PROXY',
			HTTPS_PROXY: 'http://static-proxy.invalid:3128',
			EGRESS_PROXY_AUTHORITY: 'egress.invalid:3128',
			RUNNER_TIMEOUT_MS: '60000',
		})
		const result = await run({
			provider: stubProvider(dir, 'cat >/dev/null\nprintf %s "$HTTPS_PROXY"'),
			model: 'm',
			messages: [{ role: 'user', content: 'hi' }],
			runToken: 'run-token-1',
		})

		assert.strictEqual(result.text, 'http://run:run-token-1@egress.invalid:3128')
	} finally {
		process.env = saved
		fs.rmSync(dir, { recursive: true, force: true })
	}
})

test('a CLI that outlives the time limit is killed and the turn rejected', async () => {
	const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'runner-env-'))
	const pidFile = path.join(dir, 'pid')
	const saved = { ...process.env }
	try {
		const { run } = loadRunner({ RUNNER_TIMEOUT_MS: '200' })
		const started = Date.now()
		await assert.rejects(
			run({
				// `exec` so the sleeping process IS the child the runner kills.
				provider: stubProvider(dir, `echo $$ > ${pidFile}\nexec sleep 30`),
				model: 'm',
				messages: [{ role: 'user', content: 'hi' }],
			}),
			/CLI exited with code null/,
		)
		assert.ok(
			Date.now() - started < 10000,
			'the turn ended long before the CLI would have',
		)

		const pid = Number(fs.readFileSync(pidFile, 'utf8'))
		assert.throws(
			() => process.kill(pid, 0),
			{ code: 'ESRCH' },
			'the CLI process is gone',
		)
	} finally {
		process.env = saved
		fs.rmSync(dir, { recursive: true, force: true })
	}
})
