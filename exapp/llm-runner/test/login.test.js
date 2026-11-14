/**
 * Subscription sign-in through the vendor's CLI (models-provider-connections
 * Task 4 and 6), against stub CLIs.
 *
 * Run: `node --test test/login.test.js`.
 *
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @copyright 2026 Conduction B.V.
 */

'use strict'

process.env.APP_SECRET = process.env.APP_SECRET || 'login-test-secret'
process.env.APP_ID = process.env.APP_ID || 'hermiq-llm-runner'
process.env.RUNNER_LOGIN_TTL_MS = '400'
process.env.RUNNER_LOGIN_URL_WAIT_MS = '3000'
process.env.EGRESS_PROXY_AUTHORITY = 'egress-proxy:3128'

const test = require('node:test')
const assert = require('node:assert')
const fs = require('fs')
const http = require('http')
const os = require('os')
const path = require('path')

const login = require('../src/login')
const { writeAuthFiles } = require('../src/runner')
const { PROVIDERS } = require('../src/providers')

const TOKEN = 'sk-ant-oat01-STUBTOKEN_do-not-log'

/**
 * A provider whose CLI is the given shell script, with the given login adapter.
 *
 * @param {string} body The script body.
 * @param {object} loginAdapter The login adapter.
 * @returns {object} The provider.
 */
function stubProvider(body, loginAdapter) {
	const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'login-stub-'))
	const bin = path.join(dir, 'cli.sh')
	fs.writeFileSync(bin, `#!/bin/sh\n${body}\n`, { mode: 0o755 })
	return { bin, login: loginAdapter }
}

const CODE_LOGIN = { ...PROVIDERS.anthropic.login }

// Prints the URL, reads the code, prints the token for the right code only; it
// leaves a marker in HOME so a test can see the home is removed afterwards.
const CODE_CLI = `
echo "$HOME" > "$HOME/../login-home-$$" 2>/dev/null || true
touch "$HOME/marker"
echo "Open https://claude.ai/oauth/authorize?code=true&state=abc to sign in"
read code
if [ "$code" = "good-code" ]; then echo "Your token: ${TOKEN}"; exit 0; fi
echo "invalid code" >&2
exit 1
`

test('a code sign-in returns its URL, then the token once, and removes its home', async () => {
	const provider = stubProvider(CODE_CLI, CODE_LOGIN)
	const started = await login.start(provider, 'run-token')

	assert.match(started.loginId, /^[0-9a-f-]{36}$/)
	assert.strictEqual(started.url, 'https://claude.ai/oauth/authorize?code=true&state=abc')
	assert.strictEqual(started.mode, 'code')
	assert.strictEqual(login.isOpen(started.loginId), true)

	const result = await login.finish(started.loginId, 'good-code')

	assert.deepStrictEqual(result, { token: TOKEN })
	assert.strictEqual(login.isOpen(started.loginId), false)
	await assert.rejects(login.finish(started.loginId, 'good-code'), { code: 'unknown_login', status: 404 })
})

test('the sign-in home is a fresh directory that is gone once the sign-in ends', async () => {
	const seen = path.join(fs.mkdtempSync(path.join(os.tmpdir(), 'login-seen-')), 'home.txt')
	const provider = stubProvider(`
printf '%s' "$HOME" > "${seen}"
touch "$HOME/marker"
echo "https://claude.ai/x"
read code
echo "${TOKEN}"
`, CODE_LOGIN)
	const started = await login.start(provider, '')
	const home = fs.readFileSync(seen, 'utf8')
	assert.match(path.basename(home), /^llm-login-/)
	assert.strictEqual(fs.existsSync(path.join(home, 'marker')), true)

	await login.finish(started.loginId, 'good-code')

	assert.strictEqual(fs.existsSync(home), false)
})

test('a wrong code ends the sign-in without a token', async () => {
	const provider = stubProvider(CODE_CLI, CODE_LOGIN)
	const started = await login.start(provider, '')

	await assert.rejects(login.finish(started.loginId, 'bad-code'), { code: 'not_signed_in', status: 502 })
	assert.strictEqual(login.isOpen(started.loginId), false)
})

test('a code sign-in refuses an empty or multi-line code and stays open', async () => {
	const provider = stubProvider(CODE_CLI, CODE_LOGIN)
	const started = await login.start(provider, '')

	await assert.rejects(login.finish(started.loginId, '  '), { code: 'code_required', status: 400 })
	await assert.rejects(login.finish(started.loginId, 'a\nb'), { code: 'code_required', status: 400 })
	assert.strictEqual(login.isOpen(started.loginId), true)
	login.discard(started.loginId)
})

test('a sign-in that is not finished is killed and forgotten after its time limit', async () => {
	const provider = stubProvider('echo "https://claude.ai/x"; sleep 30', CODE_LOGIN)
	const started = await login.start(provider, '')

	await new Promise((resolve) => setTimeout(resolve, 700))

	assert.strictEqual(login.isOpen(started.loginId), false)
	await assert.rejects(login.finish(started.loginId, 'good-code'), { code: 'unknown_login' })
})

test('a CLI that exits without a URL is refused and leaves nothing open', async () => {
	const provider = stubProvider('echo "no browser available"; exit 3', CODE_LOGIN)

	await assert.rejects(login.start(provider, ''), { code: 'cli_exited', status: 502 })
})

test('a provider without a sign-in is refused', async () => {
	await assert.rejects(login.start({ bin: 'true' }, ''), { code: 'unsupported', status: 400 })
})

test('a device sign-in reports the code and returns the auth file the CLI wrote', async () => {
	const provider = stubProvider(`
echo "Open https://auth.openai.com/codex/device and enter ABCD-12345"
sleep 0.2
mkdir -p "$HOME/.codex"
printf '{"tokens":{"refresh_token":"rt-stub"}}' > "$HOME/.codex/auth.json"
`, PROVIDERS.openai.login)
	const started = await login.start(provider, '')

	assert.strictEqual(started.mode, 'device')
	assert.strictEqual(started.url, 'https://auth.openai.com/codex/device')
	assert.strictEqual(started.userCode, 'ABCD-12345')

	const result = await login.finish(started.loginId, '')

	assert.deepStrictEqual(result, { token: '{"tokens":{"refresh_token":"rt-stub"}}' })
})

test('the sign-in child gets a throwaway home and the sign-in run token as its proxy identity, not the runner secrets', async () => {
	const out = path.join(fs.mkdtempSync(path.join(os.tmpdir(), 'login-env-')), 'env.txt')
	const provider = stubProvider(`env > "${out}"; echo "https://claude.ai/x"; read code; echo "${TOKEN}"`, CODE_LOGIN)
	const started = await login.start(provider, 'sign-in-token')
	await login.finish(started.loginId, 'good-code')

	const env = fs.readFileSync(out, 'utf8')
	assert.doesNotMatch(env, /APP_SECRET/)
	assert.match(env, /^HOME=.*llm-login-/m)
	assert.match(env, /^HTTPS_PROXY=http:\/\/run:sign-in-token@egress-proxy:3128$/m)
})

test('a ChatGPT credential reaches a turn as a 0600 auth file in the home, not the environment', () => {
	const scratch = fs.mkdtempSync(path.join(os.tmpdir(), 'auth-file-'))
	const written = writeAuthFiles(PROVIDERS.openai, { CODEX_AUTH_JSON: '{"a":1}', PATH: '/evil' }, scratch)

	const file = path.join(scratch, '.codex', 'auth.json')
	assert.deepStrictEqual(written, [file])
	assert.strictEqual(fs.readFileSync(file, 'utf8'), '{"a":1}')
	assert.strictEqual(fs.statSync(file).mode & 0o777, 0o600)
	assert.strictEqual(PROVIDERS.openai.credentialKeys.includes('CODEX_AUTH_JSON'), false)
	assert.deepStrictEqual(writeAuthFiles(PROVIDERS.anthropic, { CODEX_AUTH_JSON: 'x' }, scratch), [])
})

test('the sign-in routes refuse an unauthenticated caller before any CLI runs', async () => {
	const { server } = require('../src/server')
	await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve))
	const { port } = server.address()
	const send = (method, urlPath, body) => new Promise((resolve, reject) => {
		const req = http.request({ host: '127.0.0.1', port, method, path: urlPath, headers: { 'Content-Type': 'application/json' } }, (res) => {
			const chunks = []
			res.on('data', (c) => chunks.push(c))
			res.on('end', () => resolve({ status: res.statusCode, body: JSON.parse(Buffer.concat(chunks).toString('utf8')) }))
		})
		req.on('error', reject)
		req.end(body ? JSON.stringify(body) : undefined)
	})
	try {
		assert.strictEqual((await send('GET', '/login')).status, 401)
		assert.strictEqual((await send('POST', '/login', { provider: 'anthropic' })).status, 401)
		assert.strictEqual((await send('POST', '/login/00000000-0000-0000-0000-000000000000/code', { code: 'x' })).status, 401)
		assert.strictEqual((await send('POST', '/login/not-a-uuid/code', {})).status, 404)
	} finally {
		await new Promise((resolve) => server.close(resolve))
	}
})
