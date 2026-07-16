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

'use strict';

const { spawn } = require('child_process');
const fs = require('fs');
const os = require('os');
const path = require('path');

const DEFAULT_TIMEOUT_MS = Number(process.env.RUNNER_TIMEOUT_MS || '120000');
const MAX_OUTPUT_BYTES = Number(process.env.RUNNER_MAX_OUTPUT_BYTES || String(8 * 1024 * 1024));

// Non-credential env var NAMES the runner forwards from its own environment to
// the CLI child. This is how the network-layer egress-proxy option (see
// deploy/docker-compose.yml Option B) reaches the CLI — the proxy config lives
// in the runner's env, not in the per-call credentialEnv. Values here carry NO
// secrets. Defaults cover the standard proxy vars; extend via env.
const DEFAULT_PASSTHROUGH_ENV = 'HTTPS_PROXY,HTTP_PROXY,NO_PROXY,https_proxy,http_proxy,no_proxy';
const PASSTHROUGH_ENV = (process.env.RUNNER_PASSTHROUGH_ENV || DEFAULT_PASSTHROUGH_ENV)
    .split(',')
    .map((s) => s.trim())
    .filter((s) => s !== '');

/**
 * Build the single prompt string handed to a print-mode CLI from the assembled
 * turn. The system prompt (if any) is prepended; message history is flattened
 * in order. Content that is an array of blocks is reduced to its text parts.
 *
 * @param {Array<object>} messages Ordered message history ({role, content}).
 * @returns {string} The flattened prompt.
 */
function buildPrompt(messages) {
    const lines = [];
    for (const message of messages || []) {
        const role = (message.role || 'user').toUpperCase();
        const content = normaliseContent(message.content);
        if (content !== '') {
            lines.push(`${role}: ${content}`);
        }
    }
    return lines.join('\n\n');
}

/**
 * Reduce a message `content` (string or array of blocks) to plain text.
 *
 * @param {string|Array} content The message content.
 * @returns {string} Flattened text.
 */
function normaliseContent(content) {
    if (typeof content === 'string') {
        return content;
    }
    if (Array.isArray(content)) {
        return content
            .map((block) => (typeof block === 'string' ? block : block && block.text) || '')
            .filter((t) => t !== '')
            .join('\n');
    }
    return '';
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
    const selected = {};
    if (!credentialEnv || typeof credentialEnv !== 'object') {
        return selected;
    }
    for (const key of provider.credentialKeys) {
        if (typeof credentialEnv[key] === 'string' && credentialEnv[key] !== '') {
            selected[key] = credentialEnv[key];
        }
    }
    return selected;
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
    const toolsIdx = args.indexOf('--tools');
    // `--tools` must be present AND followed by the empty string (disable all built-ins).
    if (toolsIdx === -1 || args[toolsIdx + 1] !== '') {
        throw new Error('refusing to spawn: governed turn is missing `--tools ""`');
    }
    if (!args.includes('--strict-mcp-config')) {
        throw new Error('refusing to spawn: governed turn is missing `--strict-mcp-config`');
    }
    if (!args.includes('--mcp-config')) {
        throw new Error('refusing to spawn: governed turn is missing `--mcp-config`');
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
 * @returns {Promise<{text: string, toolCalls: Array, usage: object}>} Result.
 */
function run({ provider, model, messages, credentialEnv, mcpConfig }) {
    return new Promise((resolve, reject) => {
        const prompt = buildPrompt(messages);

        // Throwaway scratch dir — the only filesystem the child is pointed at.
        const scratch = fs.mkdtempSync(path.join(os.tmpdir(), 'llm-runner-'));

        // Governed turn: write the MCP config (with its live bearer token) to a 0600
        // file — never an inline argv string, which would put the token on the process
        // table. It is removed with the scratch dir by cleanup() in every exit path.
        let mcpConfigPath = null;
        if (mcpConfig && typeof mcpConfig === 'object') {
            mcpConfigPath = path.join(scratch, 'mcp.json');
            fs.writeFileSync(mcpConfigPath, JSON.stringify(mcpConfig), { mode: 0o600 });
            fs.chmodSync(mcpConfigPath, 0o600);
        }

        const args = provider.args(model, { mcpConfigPath });

        // A governed turn MUST carry the lockdown flags, or the boundary is gone —
        // refuse to spawn rather than run an ungoverned CLI holding a live token.
        if (mcpConfigPath !== null) {
            try {
                assertGovernedArgs(args);
            } catch (err) {
                cleanup(scratch);
                reject(err);
                return;
            }
        }

        // Minimal, sanitised env: keep PATH/HOME so the binary resolves, add the
        // provider credential(s), and NOTHING the caller supplied beyond that.
        const childEnv = {
            PATH: process.env.PATH,
            HOME: scratch,
            TMPDIR: scratch,
            LANG: process.env.LANG || 'C.UTF-8',
        };
        // Forward allowlisted non-credential env (proxy config, test hooks).
        for (const name of PASSTHROUGH_ENV) {
            if (typeof process.env[name] === 'string') {
                childEnv[name] = process.env[name];
            }
        }
        Object.assign(childEnv, selectCredentialEnv(provider, credentialEnv));

        let child;
        try {
            child = spawn(provider.bin, args, {
                cwd: scratch,
                env: childEnv,
                stdio: ['pipe', 'pipe', 'pipe'],
            });
        } catch (err) {
            cleanup(scratch);
            reject(new Error(`failed to spawn CLI: ${err.message}`));
            return;
        }

        let stdout = Buffer.alloc(0);
        let stderr = Buffer.alloc(0);
        let overflow = false;
        let settled = false;

        const timer = setTimeout(() => {
            if (!settled) {
                child.kill('SIGKILL');
            }
        }, DEFAULT_TIMEOUT_MS);

        child.stdout.on('data', (chunk) => {
            if (stdout.length + chunk.length > MAX_OUTPUT_BYTES) {
                overflow = true;
                child.kill('SIGKILL');
                return;
            }
            stdout = Buffer.concat([stdout, chunk]);
        });
        child.stderr.on('data', (chunk) => {
            stderr = Buffer.concat([stderr, chunk]);
        });

        child.on('error', (err) => {
            settled = true;
            clearTimeout(timer);
            cleanup(scratch);
            reject(new Error(`CLI process error: ${err.message}`));
        });

        child.on('close', (code) => {
            settled = true;
            clearTimeout(timer);
            cleanup(scratch);
            if (overflow) {
                reject(new Error('CLI output exceeded the maximum size'));
                return;
            }
            if (code !== 0) {
                // stderr may carry provider errors; it must not carry the token
                // (the token is never on argv/stdin, only env), so this is safe
                // to surface as a bounded, redacted message.
                reject(new Error(`CLI exited with code ${code}: ${redact(stderr.toString('utf8'))}`));
                return;
            }
            try {
                resolve(provider.parse(stdout.toString('utf8')));
            } catch (err) {
                reject(new Error(`failed to parse CLI output: ${err.message}`));
            }
        });

        // Deliver the prompt on STDIN so it never lands on the process table.
        child.stdin.write(prompt);
        child.stdin.end();
    });
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
        .replace(/\b(sk-[A-Za-z0-9_-]{8,}|xai-[A-Za-z0-9_-]{8,}|oat[_-][A-Za-z0-9_-]{8,})\b/g, '[REDACTED]')
        .slice(0, 2000);
}

/**
 * Remove a scratch directory, ignoring errors.
 *
 * @param {string} dir The directory to remove.
 * @returns {void}
 */
function cleanup(dir) {
    try {
        fs.rmSync(dir, { recursive: true, force: true });
    } catch (e) {
        // Non-fatal — the OS reaps tmp eventually.
    }
}

module.exports = { run, buildPrompt, selectCredentialEnv, redact, assertGovernedArgs };
