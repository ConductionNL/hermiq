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
const os = require('os')
const path = require('path')

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
function run({ provider, model, messages, credentialEnv, mcpConfig, runToken }) {
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
				resolve(provider.parse(stdout.toString('utf8')))
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
	buildEgressProxyEnv,
}
