/**
 * CLI runner for the hermiq-llm-runner ExApp.
 *
 * Executes exactly ONE vendor LLM turn per call by spawning the matching CLI in
 * non-interactive/print mode. Hard rules enforced here:
 *
 *   - The provider credential is injected via the child process ENVIRONMENT
 *     ONLY. It is never placed on argv and never written to a log line.
 *   - Only credential env vars the provider adapter allowlists are forwarded;
 *     the caller cannot smuggle arbitrary env (PATH, LD_PRELOAD, ...) through
 *     `credentialEnv`.
 *   - The child runs in a throwaway temp scratch dir; it is given no host or
 *     Nextcloud paths and no tools to execute.
 *   - The assembled prompt is passed on STDIN (not argv), keeping large prompts
 *     off the process table.
 *
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @copyright 2026 Conduction B.V.
 */

'use strict'

const { spawn } = require('child_process')
const fs = require('fs')
const http = require('http')
const https = require('https')
const os = require('os')
const path = require('path')
const pool = require('./pool')

/**
 * Pool logger. The pool reports occupancy and reap reasons, never content.
 *
 * @param {string} level   Log level.
 * @param {string} message The message.
 * @returns {void}
 */
function poolLog(level, message) {
	// eslint-disable-next-line no-console
	console.log(`[hermiq-llm-runner] ${level}: ${message}`)
}

const DEFAULT_TIMEOUT_MS = Number(process.env.RUNNER_TIMEOUT_MS || '120000')
const MAX_OUTPUT_BYTES = Number(
	process.env.RUNNER_MAX_OUTPUT_BYTES || String(8 * 1024 * 1024),
)

// Non-credential env var NAMES the runner forwards from its own environment to
// the CLI child. Values here carry NO secrets. Defaults cover the standard proxy
// vars; extend via env.
const DEFAULT_PASSTHROUGH_ENV =
	'HTTPS_PROXY,HTTP_PROXY,NO_PROXY,https_proxy,http_proxy,no_proxy'
const PASSTHROUGH_ENV = (
	process.env.RUNNER_PASSTHROUGH_ENV || DEFAULT_PASSTHROUGH_ENV
)
	.split(',')
	.map((s) => s.trim())
	.filter((s) => s !== '')

// The governed egress proxy's base authority (`host:port`), e.g. `egress-proxy:3128`.
// When set, the runner builds a PER-RUN proxy URL carrying the run token, so the
// proxy can ask Hermiq's PDP "may THIS run reach that host?" rather than applying a
// static allowlist of its own. Unset ⇒ no proxy URL is injected and the standard
// passthrough vars (if any) apply unchanged — the Option A iptables jail.
const EGRESS_PROXY_AUTHORITY = (process.env.EGRESS_PROXY_AUTHORITY || '').trim()

/**
 * Build the per-run proxy env for the CLI child.
 *
 * The run token goes in the URL's userinfo (`http://run:<token>@host:port`) because
 * that is the one channel every HTTP client already forwards to a proxy as
 * `Proxy-Authorization`, with no client-side support needed. It lives in the child's
 * ENVIRONMENT only — never on argv (the process table is world-readable) and never
 * in a log line.
 *
 * Returns an empty object when either half is missing: without a proxy authority
 * there is nothing to point at, and without a token the proxy would deny anyway.
 *
 * @param {string} runToken The per-run token minted by Hermiq.
 * @returns {object} Env map to merge into the child's environment.
 */
function buildEgressProxyEnv(runToken) {
	if (
		EGRESS_PROXY_AUTHORITY === ''
		|| typeof runToken !== 'string'
		|| runToken === ''
	) {
		return {}
	}

	const url = `http://run:${encodeURIComponent(runToken)}@${EGRESS_PROXY_AUTHORITY}`

	// Both cases: some tools read the lowercase names, some the uppercase.
	// NO_PROXY is deliberately NOT set — an exemption list here would be a second
	// policy, and a hole in the only route out.
	return {
		HTTPS_PROXY: url,
		https_proxy: url,
		HTTP_PROXY: url,
		http_proxy: url,
	}
}

/**
 * Build the single prompt string handed to a print-mode CLI from the assembled
 * turn. The system prompt (if any) is prepended; message history is flattened
 * in order. Content that is an array of blocks is reduced to its text parts.
 *
 * @param {Array<object>} messages Ordered message history ({role, content}).
 * @returns {string} The flattened prompt.
 */
function buildPrompt(messages) {
	const lines = []
	for (const message of messages || []) {
		const role = (message.role || 'user').toUpperCase()
		const content = normaliseContent(message.content)
		if (content !== '') {
			lines.push(`${role}: ${content}`)
		}
	}
	return lines.join('\n\n')
}

/**
 * Reduce a message `content` (string or array of blocks) to plain text.
 *
 * @param {string|Array} content The message content.
 * @returns {string} Flattened text.
 */
function normaliseContent(content) {
	if (typeof content === 'string') {
		return content
	}
	if (Array.isArray(content)) {
		return content
			.map(
				(block) =>
					(typeof block === 'string' ? block : block && block.text) || '',
			)
			.filter((t) => t !== '')
			.join('\n')
	}
	return ''
}

/**
 * Select only the credential env vars this provider permits from the caller's
 * `credentialEnv`. Anything not on the allowlist is dropped silently.
 *
 * @param {object} provider The provider adapter.
 * @param {object} credentialEnv Caller-supplied credential map.
 * @returns {object} A sanitised env map with only allowlisted keys.
 */
function selectCredentialEnv(provider, credentialEnv) {
	const selected = {}
	if (!credentialEnv || typeof credentialEnv !== 'object') {
		return selected
	}
	for (const key of provider.credentialKeys) {
		if (typeof credentialEnv[key] === 'string' && credentialEnv[key] !== '') {
			selected[key] = credentialEnv[key]
		}
	}
	return selected
}

/**
 * Assert the governed-MCP lockdown flags are present on the assembled argv before
 * the CLI is spawned. A governed turn (one carrying an mcpConfig) MUST include
 * `--tools ""` and `--strict-mcp-config`, or the boundary is gone — the runner
 * refuses to spawn rather than run an ungoverned CLI with a live token in its
 * config file (cli-runner-governed-mcp-and-egress). Throws with the missing flag.
 *
 * @param {Array<string>} args The assembled CLI argv.
 * @returns {void}
 */
function assertGovernedArgs(args) {
	// The built-ins must be denied, so the model can only act through Hermiq's
	// governed MCP tools. NOTE: this deliberately asserts `--disallowedTools` and
	// NOT `--tools ""` — `--tools` excludes MCP tools as well, which would leave a
	// governed turn with no tools at all (verified against the real CLI).
	const disallowedIdx = args.indexOf('--disallowedTools')
	if (disallowedIdx === -1 || !args[disallowedIdx + 1]) {
		throw new Error(
			'refusing to spawn: governed turn is missing `--disallowedTools <builtins>`',
		)
	}
	// Belt-and-braces: a regression back to `--tools` would silently strip the MCP
	// tools this turn depends on, so refuse it outright rather than run tool-less.
	if (args.includes('--tools')) {
		throw new Error(
			'refusing to spawn: `--tools` excludes MCP tools; use `--disallowedTools`',
		)
	}
	if (!args.includes('--strict-mcp-config')) {
		throw new Error(
			'refusing to spawn: governed turn is missing `--strict-mcp-config`',
		)
	}
	if (!args.includes('--mcp-config')) {
		throw new Error('refusing to spawn: governed turn is missing `--mcp-config`')
	}
}

/**
 * How long the governed-MCP reachability preflight waits for a response.
 *
 * Deliberately short. This is a same-instance call — Hermiq is a container away —
 * so anything slower than a couple of seconds is a misconfiguration, not load,
 * and the turn is better refused than run tool-less.
 *
 * @type {number}
 */
const MCP_PREFLIGHT_TIMEOUT_MS = Number(
	process.env.RUNNER_MCP_PREFLIGHT_TIMEOUT_MS || '4000',
)

/**
 * Prove the governed MCP endpoint is actually reachable from INSIDE this
 * container before spawning the CLI.
 *
 * WHY THIS EXISTS (measured 2026-08-15, cost hours to diagnose)
 * ------------------------------------------------------------
 * `buildGovernedMcpConfig()` resolves the endpoint with
 * `linkToRouteAbsolute()`, which returns the URL Nextcloud publishes to
 * BROWSERS. On a stock dev instance that is `http://localhost:8080`, and inside
 * this container `localhost` is the container itself. The CLI then fails to
 * connect to the MCP server and — this is the whole problem — carries on:
 * `tools/list` never returns Hermiq's tools, the model reports "I don't have
 * that tool", and the run exits 0 with an empty stderr. Every layer looks
 * healthy. The observed symptom was a model confidently describing its own
 * built-in CLI tools while every Hermiq tool was silently absent.
 *
 * That is the same failure mode `assertGovernedArgs()` already refuses for a
 * wrong flag, arriving through a different door. A governed turn that cannot
 * reach its governance is not a degraded turn, it is an UNGOVERNED one, so it
 * is refused here rather than run.
 *
 * ANY HTTP response counts as reachable — including 401/403/405. The endpoint
 * authenticates the per-run bearer token, and this probe deliberately does not
 * carry it: what is under test is whether a connection can be established at
 * all, not whether this run is authorised. Only DNS failure, connection refusal
 * and timeout are treated as unreachable.
 *
 * @param {object} mcpConfig The governed `{mcpServers: {...}}` config.
 * @returns {Promise<void>} Resolves when reachable; rejects with an actionable message.
 */
function assertMcpEndpointReachable(mcpConfig) {
	const servers = (mcpConfig && mcpConfig.mcpServers) || {}
	const urls = Object.values(servers)
		.map((s) => (s && typeof s.url === 'string' ? s.url : ''))
		.filter((u) => u !== '')

	if (urls.length === 0) {
		return Promise.resolve()
	}

	return Promise.all(urls.map((url) => probeUrl(url))).then(() => undefined)
}

/**
 * Open one connection to `url` and resolve if the server answers at all.
 *
 * @param {string} url The endpoint to probe.
 * @returns {Promise<void>} Resolves when the host answers; rejects otherwise.
 */
function probeUrl(url) {
	return new Promise((resolve, reject) => {
		let parsed
		try {
			parsed = new URL(url)
		} catch {
			reject(
				new Error(
					`refusing to spawn: governed MCP endpoint "${url}" is not a valid URL`,
				),
			)
			return
		}

		const transport = parsed.protocol === 'https:' ? https : http
		const req = transport.request(
			url,
			{ method: 'HEAD', timeout: MCP_PREFLIGHT_TIMEOUT_MS },
			(res) => {
				res.resume()
				resolve()
			},
		)

		req.on('timeout', () => {
			req.destroy(
				new Error(
					`governed MCP endpoint ${url} did not answer within ${MCP_PREFLIGHT_TIMEOUT_MS}ms`,
				),
			)
		})

		req.on('error', (err) => {
			reject(
				new Error(
					`refusing to spawn: the governed MCP endpoint ${url} is unreachable from the `
						+ `runner container (${err.message}). The CLI would have started WITHOUT Hermiq's `
						+ "tools and answered as if they did not exist. This is usually Nextcloud's "
						+ 'published URL not resolving inside the container — set the `mcp_run_base_url` '
						+ 'app config to the container-facing origin (e.g. http://nextcloud).',
				),
			)
		})

		req.end()
	})
}

/**
 * Run one LLM turn through the given provider's CLI.
 *
 * @param {object} args Arguments.
 * @param {object} args.provider The resolved provider adapter.
 * @param {string} args.model Model id (may be empty).
 * @param {Array<object>} args.messages Assembled message history.
 * @param {object} args.credentialEnv Credential env map (allowlisted keys only).
 * @param {object} [args.mcpConfig] Governed MCP server config ({mcpServers:{...}}). When
 *        present the turn is GOVERNED: the config (which carries the per-run bearer token)
 *        is written to a 0600 file in the scratch dir, its path is passed via
 *        `--mcp-config`, and the CLI is locked down with `--tools "" --strict-mcp-config`.
 *        Never placed inline on argv. Absent ⇒ the unchanged text-only turn.
 * @param {string} [args.runToken] The per-run token. Used to build the CLI's proxy env
 *        so the governed egress proxy can identify the run to Hermiq's PDP. Sent on
 *        every turn, governed or not — the proxy is the container's only route out.
 * @returns {Promise<{text: string, toolCalls: Array, usage: object}>} Result.
 */
function run({
	provider,
	model,
	messages,
	credentialEnv,
	mcpConfig,
	runToken,
	poolKey,
	poolLifetimeSeconds,
	warmOnly,
}) {
	const cold = () =>
		spawnTurn({ provider, model, messages, credentialEnv, mcpConfig, runToken })

	// A governed turn proves it can REACH its governance before it spawns. See
	// assertMcpEndpointReachable() — an unreachable endpoint produces a run that
	// looks successful and is silently tool-less, which is worse than a refusal.
	// The pool does not change that: a pooled process talks to the same endpoint,
	// so the preflight gates both paths.
	const preflight = mcpConfig && typeof mcpConfig === 'object'
		? assertMcpEndpointReachable(mcpConfig)
		: Promise.resolve()

	return preflight.then(() => {
		if (!poolKey) {
			return cold()
		}
		// WARM ONLY: start the process and answer immediately. The caller is
		// pre-paying the init while the user types, not asking a question.
		if (warmOnly === true) {
			const verdict = tryPooled({
				provider,
				model,
				messages,
				credentialEnv,
				mcpConfig,
				runToken,
				poolKey,
				poolLifetimeSeconds,
				warmOnly: true,
			})
			return Promise.resolve({ text: '', toolCalls: [], usage: { warm: verdict } })
		}
		return tryPooled({
			provider,
			model,
			messages,
			credentialEnv,
			mcpConfig,
			runToken,
			poolKey,
			poolLifetimeSeconds,
		}).then((pooled) => pooled || cold())
	})
}

/**
 * Attempt to serve a turn from the process pool.
 *
 * Resolves to null for EVERY pooling failure so `run()` falls back to a one-shot.
 * A pooled turn must never be a new way for a request to fail — the worst it may
 * do is decline.
 *
 * @param {object} a Same shape as {@link run}, with pool fields required.
 * @returns {Promise<object|null>} A result, or null to take the cold path.
 */
function tryPooled({
	provider,
	model,
	messages,
	credentialEnv,
	mcpConfig,
	runToken,
	poolKey,
	poolLifetimeSeconds,
	warmOnly,
}) {
	let scratch
	try {
		// The scratch dir backs HOME/TMPDIR and holds the governed mcp.json, so it
		// must outlive the turn — a pooled process reads neither again, but it is
		// still its home. pool.js removes the process; the dir goes with the
		// container. Deliberately NOT cleaned per-turn: doing so would delete the
		// running process's HOME underneath it.
		scratch = fs.mkdtempSync(path.join(os.tmpdir(), 'llm-pool-'))

		let mcpConfigPath = null
		if (mcpConfig && typeof mcpConfig === 'object') {
			mcpConfigPath = path.join(scratch, 'mcp.json')
			fs.writeFileSync(mcpConfigPath, JSON.stringify(mcpConfig), { mode: 0o600 })
			fs.chmodSync(mcpConfigPath, 0o600)
		}

		const args = provider.args(model, { mcpConfigPath, streamJson: true })

		// Identical assertion to the cold path. A pooled process holds a live token
		// for its whole life, so an ungoverned argv here would be worse, not better.
		if (mcpConfigPath !== null) {
			assertGovernedArgs(args)
		}

		const childEnv = {
			PATH: process.env.PATH,
			HOME: scratch,
			TMPDIR: scratch,
			LANG: process.env.LANG || 'C.UTF-8',
		}
		for (const name of PASSTHROUGH_ENV) {
			if (typeof process.env[name] === 'string') {
				childEnv[name] = process.env[name]
			}
		}
		Object.assign(childEnv, buildEgressProxyEnv(runToken))
		Object.assign(childEnv, selectCredentialEnv(provider, credentialEnv))

		const lifetimeMs = Number(poolLifetimeSeconds) > 0
			? Number(poolLifetimeSeconds) * 1000
			: 600000

		// Warming uses the IDENTICAL argv, env and mcp config the real turn will,
		// because the pool is keyed on the assumption that a warmed process is
		// interchangeable with a freshly spawned one. A warm-up built any other
		// way would start a process the first turn cannot use.
		if (warmOnly === true) {
			return pool.warm({
				key: poolKey,
				provider,
				model,
				args,
				childEnv,
				lifetimeMs,
				log: poolLog,
			})
		}

		const all = buildPrompt(messages)
		const latest = latestUserText(messages)

		return pool
			.dispatch({
				key: poolKey,
				provider,
				model,
				args,
				childEnv,
				prompt: all,
				latestMessage: latest,
				lifetimeMs,
				log: poolLog,
			})
			.then((res) => {
				if (!res) {
					return null
				}
				return { text: res.text, toolCalls: [], usage: res.usage || {} }
			})
			.catch(() => null)
	} catch (err) {
		poolLog('warn', `pool setup failed: ${err.message} -- falling back`)
		return Promise.resolve(null)
	}
}

/**
 * The newest user message as plain text.
 *
 * A pooled process already holds every earlier turn of its conversation, so a
 * hit sends ONLY this. Sending the flattened history again would show the model
 * the same exchange twice.
 *
 * @param {Array<object>} messages Ordered history.
 * @returns {string} The last user message's text, or ''.
 */
function latestUserText(messages) {
	for (let i = messages.length - 1; i >= 0; i--) {
		const m = messages[i]
		if (!m || m.role !== 'user') {
			continue
		}
		if (typeof m.content === 'string') {
			return m.content
		}
		if (Array.isArray(m.content)) {
			return m.content
				.filter((b) => b && b.type === 'text' && typeof b.text === 'string')
				.map((b) => b.text)
				.join('\n')
		}
	}
	return ''
}

/**
 * Spawn the provider CLI for one turn. The unchanged body of `run()`; split out
 * so the governed preflight can gate it without nesting the whole executor.
 *
 * @param {object} args Same arguments as {@link run}.
 * @returns {Promise<{text: string, toolCalls: Array, usage: object}>} Result.
 */
function spawnTurn({
	provider,
	model,
	messages,
	credentialEnv,
	mcpConfig,
	runToken,
}) {
	return new Promise((resolve, reject) => {
		const prompt = buildPrompt(messages)

		// Throwaway scratch dir — the only filesystem the child is pointed at.
		const scratch = fs.mkdtempSync(path.join(os.tmpdir(), 'llm-runner-'))

		// Governed turn: write the MCP config (with its live bearer token) to a 0600
		// file — never an inline argv string, which would put the token on the process
		// table. It is removed with the scratch dir by cleanup() in every exit path.
		let mcpConfigPath = null
		if (mcpConfig && typeof mcpConfig === 'object') {
			mcpConfigPath = path.join(scratch, 'mcp.json')
			fs.writeFileSync(mcpConfigPath, JSON.stringify(mcpConfig), {
				mode: 0o600,
			})
			fs.chmodSync(mcpConfigPath, 0o600)
		}

		const args = provider.args(model, { mcpConfigPath })

		// A governed turn MUST carry the lockdown flags, or the boundary is gone —
		// refuse to spawn rather than run an ungoverned CLI holding a live token.
		if (mcpConfigPath !== null) {
			try {
				assertGovernedArgs(args)
			} catch (err) {
				cleanup(scratch)
				reject(err)
				return
			}
		}

		// Minimal, sanitised env: keep PATH/HOME so the binary resolves, add the
		// provider credential(s), and NOTHING the caller supplied beyond that.
		const childEnv = {
			PATH: process.env.PATH,
			HOME: scratch,
			TMPDIR: scratch,
			LANG: process.env.LANG || 'C.UTF-8',
		}
		// Forward allowlisted non-credential env (proxy config, test hooks).
		for (const name of PASSTHROUGH_ENV) {
			if (typeof process.env[name] === 'string') {
				childEnv[name] = process.env[name]
			}
		}
		// The per-run proxy URL is assigned AFTER the passthrough, so a stray static
		// HTTPS_PROXY in the container's own env can never shadow the run-scoped one —
		// that would send the CLI out through a proxy with no run identity, which the
		// PDP denies, and it would read as "the provider is down".
		Object.assign(childEnv, buildEgressProxyEnv(runToken))
		Object.assign(childEnv, selectCredentialEnv(provider, credentialEnv))

		let child
		let spawnedAt = 0
		try {
			if (process.env.RUNNER_DEBUG_ARGV === '1') {
				// eslint-disable-next-line no-console
				console.log(
					`[hermiq-llm-runner] DEBUG argv: ${provider.bin} ${JSON.stringify(args)}`,
				)
				if (mcpConfigPath !== null) {
					const raw = fs.readFileSync(mcpConfigPath, 'utf8')
					// Redact the bearer token before logging.
					console.log(
						`[hermiq-llm-runner] DEBUG mcp.json: ${raw.replace(/Bearer [^"]+/g, 'Bearer <redacted>')}`,
					)
				}
			}
			spawnedAt = Date.now()
			child = spawn(provider.bin, args, {
				cwd: scratch,
				env: childEnv,
				stdio: ['pipe', 'pipe', 'pipe'],
			})
		} catch (err) {
			cleanup(scratch)
			reject(new Error(`failed to spawn CLI: ${err.message}`))
			return
		}

		let stdout = Buffer.alloc(0)
		let stderr = Buffer.alloc(0)
		let overflow = false
		let settled = false

		const timer = setTimeout(() => {
			if (!settled) {
				child.kill('SIGKILL')
			}
		}, DEFAULT_TIMEOUT_MS)

		child.stdout.on('data', (chunk) => {
			if (stdout.length + chunk.length > MAX_OUTPUT_BYTES) {
				overflow = true
				child.kill('SIGKILL')
				return
			}
			stdout = Buffer.concat([stdout, chunk])
		})
		child.stderr.on('data', (chunk) => {
			stderr = Buffer.concat([stderr, chunk])
		})

		child.on('error', (err) => {
			settled = true
			clearTimeout(timer)
			cleanup(scratch)
			reject(new Error(`CLI process error: ${err.message}`))
		})

		child.on('close', (code) => {
			settled = true
			clearTimeout(timer)
			if (process.env.RUNNER_DEBUG_ARGV === '1') {
				const err = redact(stderr.toString('utf8')).trim()
				// eslint-disable-next-line no-console
				console.log(
					`[hermiq-llm-runner] DEBUG exit=${code} stderr=${err.slice(0, 900) || '(empty)'}`,
				)
			}
			cleanup(scratch)
			if (overflow) {
				reject(new Error('CLI output exceeded the maximum size'))
				return
			}
			if (code !== 0) {
				// stderr may carry provider errors; it must not carry the token
				// (the token is never on argv/stdin, only env), so this is safe
				// to surface as a bounded, redacted message.
				reject(
					new Error(
						`CLI exited with code ${code}: ${redact(stderr.toString('utf8'))}`,
					),
				)
				return
			}
			try {
				const result = provider.parse(stdout.toString('utf8'))

				// Our OWN spawn→close wall against the API time the CLI reports.
				// `overhead` is what the harness costs: process start, session
				// init, MCP handshake — invisible from outside, where it reads as
				// inference time. Measured 2026-08-17: api is near-constant
				// (~2.1-2.7s) while overhead swings 7x with machine load, so this
				// is the number to watch, not the total.
				//
				// NOTE: overhead is deliberately `wall - api`, NOT the CLI's own
				// `duration_ms - duration_api_ms`. `duration_api_ms` can EXCEED
				// `duration_ms`, which makes that difference negative and
				// meaningless. `cli` is logged for reference only.
				//
				// Numbers only; no prompt or credential material is logged.
				const wall = spawnedAt > 0 ? Date.now() - spawnedAt : -1
				const cliWall = result?.usage?.cliDurationMs ?? -1
				const api = result?.usage?.cliApiMs ?? -1
				const overhead = wall >= 0 && api >= 0 ? wall - api : -1
				// eslint-disable-next-line no-console
				console.log(
					`[hermiq-llm-runner] info: turn timing wall=${wall}ms `
						+ `cli=${cliWall}ms api=${api}ms overhead=${overhead}ms`,
				)

				resolve(result)
			} catch (err) {
				reject(new Error(`failed to parse CLI output: ${err.message}`))
			}
		})

		// Deliver the prompt on STDIN so it never lands on the process table.
		child.stdin.write(prompt)
		child.stdin.end()
	})
}

/**
 * Best-effort redaction of anything that looks like a bearer token / API key
 * from an error string before it is surfaced or logged.
 *
 * @param {string} text The text to redact.
 * @returns {string} Redacted text.
 */
function redact(text) {
	return (text || '')
		.replace(
			/\b(sk-[A-Za-z0-9_-]{8,}|xai-[A-Za-z0-9_-]{8,}|oat[_-][A-Za-z0-9_-]{8,})\b/g,
			'[REDACTED]',
		)
		.slice(0, 2000)
}

/**
 * Remove a scratch directory, ignoring errors.
 *
 * @param {string} dir The directory to remove.
 * @returns {void}
 */
function cleanup(dir) {
	try {
		fs.rmSync(dir, { recursive: true, force: true })
	} catch (e) {
		// Non-fatal — the OS reaps tmp eventually.
	}
}

module.exports = {
	run,
	buildPrompt,
	selectCredentialEnv,
	redact,
	assertGovernedArgs,
	assertMcpEndpointReachable,
	buildEgressProxyEnv,
}
