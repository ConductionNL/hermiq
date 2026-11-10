/**
 * Golden test for the AppAPI request authentication of the runner.
 *
 * Pins the exact verdict `verify()` gives for every branch, in the order the
 * checks run: no configured secret (fail closed), missing headers, a foreign
 * EX-APP-ID, a malformed AUTHORIZATION-APP-API, a wrong secret, and the accepted
 * request. The module reads its secret at load time, so each case loads it fresh
 * with its own environment.
 *
 * Run: `node --test test/auth.verify.golden.test.js`.
 *
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @copyright 2026 Conduction B.V.
 */

'use strict'

const test = require('node:test')
const assert = require('node:assert')

/**
 * Load auth.js afresh under the given secret.
 *
 * @param {string} secret The APP_SECRET to load with ('' for none).
 * @returns {object} The module.
 */
function loadAuth(secret) {
	const path = require.resolve('../src/auth')
	delete require.cache[path]
	process.env.APP_ID = 'hermiq-llm-runner'
	process.env.APP_SECRET = secret
	return require('../src/auth')
}

/**
 * An AUTHORIZATION-APP-API value for user:secret.
 *
 * @param {string} pair The user:secret pair.
 * @returns {string} The base64 header value.
 */
const header = (pair) => Buffer.from(pair).toString('base64')

test('every verdict of verify, in check order', () => {
	const unconfigured = loadAuth('')
	assert.deepStrictEqual(
		unconfigured.verify({ 'ex-app-id': 'hermiq-llm-runner', 'authorization-app-api': header('u:s') }),
		{ ok: false, status: 401, reason: 'runner APP_SECRET is not configured' },
	)

	const auth = loadAuth('s3cret')
	const cases = [
		[{}, { ok: false, status: 401, reason: 'missing AppAPI authentication headers' }],
		[{ 'ex-app-id': 'hermiq-llm-runner' }, { ok: false, status: 401, reason: 'missing AppAPI authentication headers' }],
		[{ 'ex-app-id': 'other', 'authorization-app-api': header('u:s3cret') }, { ok: false, status: 403, reason: 'EX-APP-ID does not match this runner' }],
		[{ 'ex-app-id': 'hermiq-llm-runner', 'authorization-app-api': header('no-colon') }, { ok: false, status: 403, reason: 'AUTHORIZATION-APP-API is not valid base64 user:secret' }],
		[{ 'ex-app-id': 'hermiq-llm-runner', 'authorization-app-api': header('u:wrong') }, { ok: false, status: 403, reason: 'AUTHORIZATION-APP-API secret does not match' }],
		[{ 'ex-app-id': 'hermiq-llm-runner', 'authorization-app-api': header('u:s3cret') }, { ok: true, status: 200, reason: 'ok' }],
	]
	for (const [headers, expected] of cases) {
		assert.deepStrictEqual(auth.verify(headers, ''), expected, JSON.stringify(headers))
	}
})
