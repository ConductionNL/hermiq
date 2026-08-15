/**
 * Governed-MCP reachability preflight tests for hermiq-llm-runner.
 *
 * A governed turn whose MCP endpoint cannot be reached from inside the runner
 * container does not degrade — it runs UNGOVERNED and silently tool-less. The
 * CLI connects to nothing, `tools/list` never yields Hermiq's tools, the model
 * answers "I don't have that tool", and the process exits 0 with an empty
 * stderr. Every layer looks healthy while the entire governance boundary is
 * absent.
 *
 * That was observed on a real instance: `linkToRouteAbsolute()` published
 * `http://localhost:8080`, which inside the container is the container itself.
 * The model confidently described its own built-in CLI tools while every Hermiq
 * tool was missing, and nothing anywhere reported an error.
 *
 * These tests pin the refusal, and — as importantly — pin that an
 * AUTHENTICATED-BUT-REJECTING endpoint (401/403/405) still counts as reachable.
 * The probe carries no bearer token on purpose: it asks "can a connection be
 * made at all", not "is this run authorised". A probe that demanded 200 would
 * refuse every correctly-configured instance.
 *
 * Run: `node --test test/runner.mcp-preflight.test.js`.
 *
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @copyright 2026 Conduction B.V.
 */

'use strict'

const test = require('node:test')
const assert = require('node:assert')
const http = require('http')

const { assertMcpEndpointReachable } = require('../src/runner')

/**
 * Start a throwaway HTTP server answering every request with `status`.
 *
 * @param {number} status The status code to answer with.
 * @returns {Promise<{url: string, close: Function}>} The server's URL and a closer.
 */
function serverAnswering(status) {
	return new Promise((resolve) => {
		const server = http.createServer((req, res) => {
			res.writeHead(status)
			res.end()
		})
		server.listen(0, '127.0.0.1', () => {
			const { port } = server.address()
			resolve({
				url: `http://127.0.0.1:${port}/index.php/apps/hermiq/api/mcp-run`,
				close: () => new Promise((done) => server.close(done)),
			})
		})
	})
}

/**
 * Build a governed config pointing at one URL.
 *
 * @param {string} url The endpoint URL.
 * @returns {object} The `{mcpServers:{...}}` config.
 */
function config(url) {
	return {
		mcpServers: {
			hermiq: {
				type: 'http',
				url,
				headers: { Authorization: 'Bearer oat-TESTTOKEN-0123456789abcdef' },
			},
		},
	}
}

test('a reachable endpoint passes the preflight', async () => {
	const srv = await serverAnswering(200)
	try {
		await assertMcpEndpointReachable(config(srv.url))
	} finally {
		await srv.close()
	}
})

test('an endpoint that REJECTS the probe is still reachable (401/403/405)', async () => {
	// The probe deliberately carries no bearer token. Demanding 200 here would
	// refuse every correctly-configured instance, because the endpoint
	// authenticates the per-run token this probe does not have.
	for (const status of [401, 403, 405]) {
		const srv = await serverAnswering(status)
		try {
			await assertMcpEndpointReachable(config(srv.url))
		} finally {
			await srv.close()
		}
	}
})

test('a refused connection is a REFUSAL to spawn, not a degraded turn', async () => {
	// Bind a port, learn it, then close it — nothing is listening, which is
	// exactly what `http://localhost` looks like from inside the container.
	const srv = await serverAnswering(200)
	const deadUrl = srv.url
	await srv.close()

	await assert.rejects(
		() => assertMcpEndpointReachable(config(deadUrl)),
		(err) => {
			assert.match(err.message, /refusing to spawn/)
			assert.match(err.message, /unreachable from the runner container/)
			// The message must name the fix, or the next person debugs the model
			// instead of the network — which is what happened the first time.
			assert.match(err.message, /mcp_run_base_url/)
			return true
		},
	)
})

test('an unresolvable host is refused rather than silently tool-less', async () => {
	await assert.rejects(
		() => assertMcpEndpointReachable(config('http://no-such-host.invalid/api/mcp-run')),
		/refusing to spawn/,
	)
})

test('a malformed endpoint URL is refused', async () => {
	await assert.rejects(
		() => assertMcpEndpointReachable(config('not-a-url')),
		/not a valid URL/,
	)
})

test('a config with no servers is a no-op, so a text-only turn is unaffected', async () => {
	await assertMcpEndpointReachable({ mcpServers: {} })
	await assertMcpEndpointReachable({})
})

test('EVERY declared server must be reachable, not just the first', async () => {
	// A config naming two servers where only one answers must still refuse: the
	// model would otherwise lose exactly the half of its tools that lives on the
	// dead one, which is the silent-degradation this preflight exists to stop.
	const live = await serverAnswering(200)
	const dead = await serverAnswering(200)
	const deadUrl = dead.url
	await dead.close()

	try {
		await assert.rejects(
			() =>
				assertMcpEndpointReachable({
					mcpServers: {
						hermiq: { type: 'http', url: live.url },
						other: { type: 'http', url: deadUrl },
					},
				}),
			/refusing to spawn/,
		)
	} finally {
		await live.close()
	}
})
