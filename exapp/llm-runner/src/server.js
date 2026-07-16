/**
 * hermiq-llm-runner ExApp — HTTP entrypoint.
 *
 * A tiny, dependency-free Node HTTP service exposing a single work route,
 * `POST /run`, plus the AppAPI lifecycle stubs (`/heartbeat`). It is the LLM
 * transport half of the `llm-cli-runner-exapp` change: Hermiq POSTs a fully
 * assembled turn, the runner shells out to the matching vendor CLI in
 * non-interactive mode with the credential injected via env only, and returns
 * `{text, toolCalls, usage}`.
 *
 * Security posture (see README.md for the full hardening model):
 *   - Every /run request is authenticated against the AppAPI shared secret
 *     BEFORE any CLI is spawned (see auth.js).
 *   - The runner executes no tools, writes only to a throwaway temp scratch,
 *     reads no host/Nextcloud paths, and is stateless between calls.
 *   - Credentials arrive per-call and are never logged or persisted.
 *
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @copyright 2026 Conduction B.V.
 */

'use strict';

const http = require('http');

const auth = require('./auth');
const { getProvider } = require('./providers');
const { run } = require('./runner');

const HOST = process.env.RUNNER_HOST || '0.0.0.0';
const PORT = Number(process.env.RUNNER_PORT || process.env.APP_PORT || '9000');
const MAX_BODY_BYTES = Number(process.env.RUNNER_MAX_BODY_BYTES || String(4 * 1024 * 1024));

/**
 * Emit a terse, credential-free log line.
 *
 * @param {string} level Log level.
 * @param {string} message Message text.
 * @returns {void}
 */
function log(level, message) {
    // eslint-disable-next-line no-console
    console.log(`[hermiq-llm-runner] ${level}: ${message}`);
}

/**
 * Send a JSON response.
 *
 * @param {http.ServerResponse} res The response.
 * @param {number} status HTTP status.
 * @param {object} body JSON-serialisable body.
 * @returns {void}
 */
function sendJson(res, status, body) {
    const payload = JSON.stringify(body);
    res.writeHead(status, { 'Content-Type': 'application/json' });
    res.end(payload);
}

/**
 * Collect the raw request body up to a hard size cap.
 *
 * @param {http.IncomingMessage} req The request.
 * @returns {Promise<Buffer>} The raw body.
 */
function readBody(req) {
    return new Promise((resolve, reject) => {
        const chunks = [];
        let size = 0;
        req.on('data', (chunk) => {
            size += chunk.length;
            if (size > MAX_BODY_BYTES) {
                reject(new Error('request body too large'));
                req.destroy();
                return;
            }
            chunks.push(chunk);
        });
        req.on('end', () => resolve(Buffer.concat(chunks)));
        req.on('error', reject);
    });
}

/**
 * Handle `POST /run`. Authenticates first, then dispatches one CLI turn.
 *
 * @param {http.IncomingMessage} req The request.
 * @param {http.ServerResponse} res The response.
 * @param {Buffer} rawBody The raw request body.
 * @returns {Promise<void>} Resolves when the response has been sent.
 */
async function handleRun(req, res, rawBody) {
    // 1. AUTH — before any parsing or CLI invocation.
    const verdict = auth.verify(lowerHeaders(req.headers), rawBody);
    if (!verdict.ok) {
        log('warn', `/run rejected: ${verdict.reason}`);
        sendJson(res, verdict.status, { error: 'unauthorised' });
        return;
    }

    // 2. Parse the assembled turn.
    let payload;
    try {
        payload = JSON.parse(rawBody.toString('utf8') || '{}');
    } catch (e) {
        sendJson(res, 400, { error: 'invalid JSON body' });
        return;
    }

    const { provider: providerId, model, messages, credentialEnv, mcpConfig } = payload;
    const provider = getProvider(providerId);
    if (!provider) {
        sendJson(res, 400, { error: `unknown provider '${providerId}'` });
        return;
    }
    if (!Array.isArray(messages) || messages.length === 0) {
        sendJson(res, 400, { error: 'messages must be a non-empty array' });
        return;
    }

    // 3. Run exactly one turn. Custom tools reach the CLI ONLY via governed MCP
    //    (cli-runner-governed-mcp-and-egress): a tool-requiring turn carries `mcpConfig`
    //    — the {mcpServers:{hermiq:{type:"http",...}}} block Hermiq assembled, whose
    //    headers carry the per-run bearer token. run() writes it to a 0600 scratch file
    //    and locks the CLI down with `--tools "" --strict-mcp-config --mcp-config <path>`.
    //    A text-only turn omits `mcpConfig` and is served exactly as link 2 built it.
    log('info', `/run provider=${providerId} model=${model || '(default)'} messages=${messages.length} governed=${mcpConfig ? 'yes' : 'no'}`);
    try {
        const result = await run({ provider, model, messages, credentialEnv, mcpConfig });
        sendJson(res, 200, {
            text: result.text,
            toolCalls: result.toolCalls,
            usage: result.usage,
        });
    } catch (err) {
        // err.message is already redacted in runner.js.
        log('error', `/run failed: ${err.message}`);
        sendJson(res, 502, { error: 'runner execution failed', detail: err.message });
    }
}

/**
 * Handle `PUT /enabled?enabled=0|1` — the AppAPI enable/disable lifecycle call.
 *
 * AppAPI reads the response body's `error` key to decide success: a non-empty
 * `error` disables the ExApp and fails the enable (AppAPIService::enableExApp).
 * The generic 404 fallback returns `{error: 'not found'}`, so WITHOUT this
 * handler every `occ app_api:app:enable` fails with "Failed to enable ExApp".
 * The runner is a stateless transport — there is nothing to start or stop — so
 * it authenticates the call and acknowledges with no error.
 *
 * @param {http.IncomingMessage} req The request.
 * @param {http.ServerResponse} res The response.
 * @param {Buffer} rawBody The raw request body (empty for this call).
 * @returns {void}
 */
function handleEnabled(req, res, rawBody) {
    const verdict = auth.verify(lowerHeaders(req.headers), rawBody);
    if (!verdict.ok) {
        log('warn', `/enabled rejected: ${verdict.reason}`);
        sendJson(res, verdict.status, { error: 'unauthorised' });
        return;
    }
    const enabled = new URL(req.url, 'http://localhost').searchParams.get('enabled') === '1';
    log('info', `/enabled -> ${enabled ? 'enabled' : 'disabled'}`);
    // Empty/absent `error` = success. There is no `/init` handler on purpose:
    // AppAPI treats a 404/501 on POST /init as "nothing to initialise" and sets
    // init progress to 100 (AppAPIService::dispatchExAppInitInternal). A naive
    // 200 without the progress callback would instead leave init stuck at 0.
    sendJson(res, 200, {});
}

/**
 * Lower-case all header names for case-insensitive lookups.
 *
 * @param {object} headers Raw headers.
 * @returns {object} Header map with lower-cased keys.
 */
function lowerHeaders(headers) {
    const out = {};
    for (const key of Object.keys(headers)) {
        out[key.toLowerCase()] = headers[key];
    }
    return out;
}

const server = http.createServer((req, res) => {
    // AppAPI health probe — no auth, invokes no CLI.
    if (req.method === 'GET' && req.url === '/heartbeat') {
        sendJson(res, 200, { status: 'ok' });
        return;
    }

    if (req.method === 'POST' && req.url === '/run') {
        readBody(req)
            .then((rawBody) => handleRun(req, res, rawBody))
            .catch((err) => {
                log('warn', `request error: ${err.message}`);
                if (!res.headersSent) {
                    sendJson(res, 413, { error: err.message });
                }
            });
        return;
    }

    // AppAPI enable/disable lifecycle call.
    if (req.method === 'PUT' && req.url.split('?')[0] === '/enabled') {
        readBody(req)
            .then((rawBody) => handleEnabled(req, res, rawBody))
            .catch((err) => {
                log('warn', `request error: ${err.message}`);
                if (!res.headersSent) {
                    sendJson(res, 413, { error: err.message });
                }
            });
        return;
    }

    sendJson(res, 404, { error: 'not found' });
});

if (require.main === module) {
    server.listen(PORT, HOST, () => {
        log('info', `listening on ${HOST}:${PORT} (app-id=${auth.APP_ID})`);
    });
}

module.exports = { server };
