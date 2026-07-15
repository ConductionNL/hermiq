/**
 * Provider adapter registry for the hermiq-llm-runner ExApp.
 *
 * Each adapter maps a Hermiq provider id (`anthropic` / `openai` / `grok`) to:
 *   - the vendor CLI binary to invoke (overridable via env for tests),
 *   - the non-interactive/print-mode arguments,
 *   - the credential env-var names the runner is allowed to inject for it
 *     (an ALLOWLIST — the caller may not smuggle arbitrary env such as PATH or
 *     LD_PRELOAD through `credentialEnv`),
 *   - the provider API host (the only host egress hardening should permit), and
 *   - a parser that maps the CLI's structured stdout to `{text, toolCalls, usage}`.
 *
 * The runner is only the LLM transport. It never executes tools — tool-call
 * requests the model emits are parsed and handed straight back to Hermiq's
 * governed ToolLoop. The parsers therefore extract tool-call requests but never
 * act on them.
 *
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @copyright 2026 Conduction B.V.
 */

'use strict';

/**
 * Parse the JSON emitted by `claude -p --output-format json`.
 *
 * The Claude Code CLI print mode emits a result object of the shape
 * `{ "type": "result", "result": "<text>", "usage": {...} }`. Any tool_use the
 * model requested surfaces under a `tool_calls` / `toolCalls` field when
 * present; the runner returns those verbatim without executing them.
 *
 * @param {string} stdout Raw CLI stdout.
 * @returns {{text: string, toolCalls: Array, usage: object}} Normalised result.
 */
function parseClaudeJson(stdout) {
    const parsed = tryParse(stdout);
    if (parsed === null) {
        return { text: stdout.trim(), toolCalls: [], usage: {} };
    }
    const text = pickText(parsed, ['result', 'text', 'completion']);
    return {
        text,
        toolCalls: pickToolCalls(parsed),
        usage: parsed.usage || {},
    };
}

/**
 * Parse a generic JSON completion envelope `{text, toolCalls, usage}`. Used for
 * providers whose CLI already emits that shape (and for the test stub CLIs).
 * Falls back to treating stdout as plain text.
 *
 * @param {string} stdout Raw CLI stdout.
 * @returns {{text: string, toolCalls: Array, usage: object}} Normalised result.
 */
function parseGenericJson(stdout) {
    const parsed = tryParse(stdout);
    if (parsed === null) {
        return { text: stdout.trim(), toolCalls: [], usage: {} };
    }
    return {
        text: pickText(parsed, ['text', 'result', 'completion', 'output']),
        toolCalls: pickToolCalls(parsed),
        usage: parsed.usage || {},
    };
}

/**
 * Attempt to JSON.parse a string, tolerating trailing whitespace and a stream
 * of newline-delimited JSON objects (take the last complete object).
 *
 * @param {string} stdout Raw CLI stdout.
 * @returns {object|null} Parsed object, or null when not JSON.
 */
function tryParse(stdout) {
    const trimmed = (stdout || '').trim();
    if (trimmed === '') {
        return null;
    }
    try {
        return JSON.parse(trimmed);
    } catch (e) {
        // Fall through to newline-delimited handling.
    }
    const lines = trimmed.split('\n').filter((l) => l.trim() !== '');
    for (let i = lines.length - 1; i >= 0; i -= 1) {
        try {
            return JSON.parse(lines[i]);
        } catch (e) {
            // Try the next earlier line.
        }
    }
    return null;
}

/**
 * Pull a text field from a parsed object by trying candidate keys in order.
 *
 * @param {object} parsed Parsed CLI object.
 * @param {Array<string>} keys Candidate keys.
 * @returns {string} The first string value found, or ''.
 */
function pickText(parsed, keys) {
    for (const key of keys) {
        if (typeof parsed[key] === 'string') {
            return parsed[key];
        }
    }
    return '';
}

/**
 * Pull tool-call requests from a parsed object, accepting either `toolCalls` or
 * `tool_calls`. Always returns an array; never executes anything.
 *
 * @param {object} parsed Parsed CLI object.
 * @returns {Array} Tool-call requests to hand back to Hermiq.
 */
function pickToolCalls(parsed) {
    const raw = parsed.toolCalls || parsed.tool_calls;
    return Array.isArray(raw) ? raw : [];
}

const PROVIDERS = {
    anthropic: {
        // Installed via `npm i -g @anthropic-ai/claude-code`.
        bin: process.env.RUNNER_ANTHROPIC_BIN || 'claude',
        args: (model) => {
            const base = ['-p', '--output-format', 'json'];
            return model ? base.concat(['--model', model]) : base;
        },
        credentialKeys: ['CLAUDE_CODE_OAUTH_TOKEN', 'ANTHROPIC_API_KEY'],
        apiHost: 'api.anthropic.com',
        parse: parseClaudeJson,
    },
    openai: {
        // Installed via `npm i -g @openai/codex` (the OpenAI Codex CLI).
        // `codex exec` is its non-interactive / print mode.
        bin: process.env.RUNNER_OPENAI_BIN || 'codex',
        args: (model) => {
            const base = ['exec', '--json'];
            return model ? base.concat(['--model', model]) : base;
        },
        credentialKeys: ['OPENAI_API_KEY'],
        apiHost: 'api.openai.com',
        parse: parseGenericJson,
    },
    grok: {
        // PLACEHOLDER: xAI ships no verified official CLI on npm at build time.
        // The deployer supplies a Grok CLI binary and points RUNNER_GROK_BIN at
        // it (its print/non-interactive flag may differ from the default below).
        bin: process.env.RUNNER_GROK_BIN || 'grok',
        args: (model) => {
            const base = ['--print', '--format', 'json'];
            return model ? base.concat(['--model', model]) : base;
        },
        credentialKeys: ['XAI_API_KEY', 'GROK_API_KEY'],
        apiHost: 'api.x.ai',
        parse: parseGenericJson,
    },
};

/**
 * Resolve a provider adapter by id.
 *
 * @param {string} provider Provider id.
 * @returns {object|null} The adapter, or null when unknown.
 */
function getProvider(provider) {
    return PROVIDERS[provider] || null;
}

module.exports = { getProvider, parseClaudeJson, parseGenericJson, PROVIDERS };
