/**
 * hermiq-egress-proxy — the governed CONNECT proxy for the llm-runner sidecar.
 *
 * This is the Policy ENFORCEMENT Point (PEP). It terminates every outbound
 * connection the runner container attempts and asks Hermiq's Policy DECISION
 * Point (PDP, `POST /api/egress/authorize`) whether that exact `host:port` is
 * permitted for that exact run. It holds NO allowlist of its own — a second
 * allowlist would be a second policy, and the whole point of routing through
 * the PDP is that `WebResearchEgressGuard` stays the ONE source of truth for
 * both the agent's `webFetch` tool and the network backstop.
 *
 * Why a proxy at all, when the model is already limited to governed MCP tools:
 * the two layers answer different questions. The MCP grant governs what the
 * AGENT is authorized to do; this proxy governs what the CONTAINER can reach —
 * including traffic the agent never asked for (a CLI auto-update check, a
 * built-in web fetch that a future flag fails to disable, anything a compromised
 * dependency tries). The container has NO default route, so this is the only way
 * out, and a capability that arrives un-denied in a future CLI release still
 * cannot reach a host policy forbids.
 *
 * Hard rules:
 *   - DEFAULT DENY. `allowed: true` from the PDP is the ONLY permit signal.
 *     A PDP that is unreachable, slow, erroring, or returns anything unexpected
 *     results in a denial. An egress proxy that fails open is not a control.
 *   - The run token identifies the run; it arrives in `Proxy-Authorization` and
 *     is forwarded to the PDP as a bearer token. It is NEVER logged.
 *   - Only CONNECT is served. Plain HTTP proxying would let the PEP see and
 *     mutate cleartext traffic; there is no reason for it to.
 *
 * Known limitation, stated plainly: CONNECT gives host:port granularity, not
 * URL. The PDP therefore decides on the host, and any path on an allowed host is
 * reachable. Narrowing that would mean terminating TLS (a MITM with the CLI's
 * credentials in scope), which is a worse trade than the granularity it buys.
 *
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @copyright 2026 Conduction B.V.
 */

'use strict';

const http = require('http');
const net = require('net');
const { URL } = require('url');

const PORT = Number(process.env.PROXY_PORT || '3128');

// The PDP endpoint — Hermiq's governed egress decision point. Required: with no
// PDP configured there is no policy to enforce, and a proxy with no policy that
// still forwards traffic is an open relay. Fail at boot instead.
const PDP_URL = process.env.EGRESS_PDP_URL || '';

// How long to wait for a verdict. A slow PDP denies (see DEFAULT DENY above) —
// this bounds how long a caller waits to be told "no".
const PDP_TIMEOUT_MS = Number(process.env.EGRESS_PDP_TIMEOUT_MS || '5000');

/**
 * Ask the PDP whether this run may reach this host:port.
 *
 * Every failure path returns false. The function cannot throw: a throw in the
 * CONNECT handler could otherwise be caught somewhere permissive and read as
 * "no objection".
 *
 * @param {string} host The requested host.
 * @param {number} port The requested port.
 * @param {string} token The run token from Proxy-Authorization.
 * @returns {Promise<{allowed: boolean, code: string, message: string}>} Verdict.
 */
function askPdp(host, port, token) {
    return new Promise((resolve) => {
        const deny = (code, message) => resolve({ allowed: false, code, message });

        if (token === '') {
            deny('no_run_token', 'no run token presented to the proxy');
            return;
        }

        let target;
        try {
            target = new URL(PDP_URL);
        } catch (e) {
            deny('pdp_misconfigured', 'the PDP URL is not a valid URL');
            return;
        }

        const body = JSON.stringify({ host, port });
        const transport = target.protocol === 'https:' ? require('https') : http;

        const req = transport.request(
            {
                protocol: target.protocol,
                hostname: target.hostname,
                port: target.port || (target.protocol === 'https:' ? 443 : 80),
                path: target.pathname + target.search,
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Content-Length': Buffer.byteLength(body),
                    // The run token authenticates the run to Hermiq. Never logged.
                    Authorization: `Bearer ${token}`,
                    'OCS-APIRequest': 'true',
                },
                timeout: PDP_TIMEOUT_MS,
            },
            (res) => {
                let raw = '';
                res.setEncoding('utf8');
                res.on('data', (c) => {
                    // Bound the read: a hostile/broken PDP must not exhaust memory.
                    if (raw.length < 64 * 1024) {
                        raw += c;
                    }
                });
                res.on('end', () => {
                    if (res.statusCode !== 200) {
                        deny('pdp_rejected', `the PDP answered ${res.statusCode}`);
                        return;
                    }
                    let parsed;
                    try {
                        parsed = JSON.parse(raw);
                    } catch (e) {
                        deny('pdp_unparseable', 'the PDP answer was not JSON');
                        return;
                    }
                    // STRICT: only a literal `true` permits. A truthy string, a
                    // missing key or a differently-shaped answer is a denial.
                    if (parsed.allowed === true) {
                        resolve({ allowed: true, code: 'allowed', message: '' });
                        return;
                    }
                    deny(String(parsed.code || 'denied'), String(parsed.message || 'denied by policy'));
                });
            }
        );

        req.on('timeout', () => {
            req.destroy();
            deny('pdp_timeout', 'the PDP did not answer in time');
        });
        req.on('error', () => deny('pdp_unreachable', 'the PDP could not be reached'));
        req.write(body);
        req.end();
    });
}

/**
 * Extract the run token from a `Proxy-Authorization: Basic base64(run:<token>)`
 * header. Basic is used because it is what every HTTP client (and the CLI's
 * `HTTPS_PROXY` handling) sends for a proxy URL carrying credentials.
 *
 * @param {object} headers The CONNECT request headers.
 * @returns {string} The token, or '' when absent/malformed.
 */
function tokenFromProxyAuth(headers) {
    const raw = headers['proxy-authorization'] || '';
    const m = /^Basic\s+(.+)$/i.exec(raw);
    if (m === null) {
        return '';
    }
    let decoded;
    try {
        decoded = Buffer.from(m[1], 'base64').toString('utf8');
    } catch (e) {
        return '';
    }
    // `run:<token>` — split on the FIRST colon; a token may contain colons.
    const i = decoded.indexOf(':');
    return i === -1 ? '' : decoded.slice(i + 1);
}

/**
 * Parse a CONNECT target (`host:port`).
 *
 * @param {string} target The request URL of a CONNECT.
 * @returns {{host: string, port: number}|null} Parsed target, or null.
 */
function parseTarget(target) {
    const i = (target || '').lastIndexOf(':');
    if (i <= 0) {
        return null;
    }
    const host = target.slice(0, i);
    const port = Number(target.slice(i + 1));
    if (host === '' || Number.isInteger(port) === false || port <= 0 || port > 65535) {
        return null;
    }
    return { host, port };
}

const server = http.createServer((req, res) => {
    // Plain HTTP proxying is not served — only CONNECT. Anything else is a 405.
    res.writeHead(405, { 'Content-Type': 'text/plain' });
    res.end('this proxy serves CONNECT only\n');
});

server.on('connect', async (req, clientSocket, head) => {
    const refuse = (code, reason) => {
        // eslint-disable-next-line no-console
        console.log(`[hermiq-egress-proxy] DENY ${req.url} (${code})`);
        clientSocket.write(`HTTP/1.1 403 Forbidden\r\nX-Egress-Deny-Code: ${code}\r\n\r\n${reason}\n`);
        clientSocket.destroy();
    };

    const target = parseTarget(req.url);
    if (target === null) {
        refuse('bad_target', 'malformed CONNECT target');
        return;
    }

    const verdict = await askPdp(target.host, target.port, tokenFromProxyAuth(req.headers));
    if (verdict.allowed !== true) {
        refuse(verdict.code, verdict.message);
        return;
    }

    const upstream = net.connect(target.port, target.host, () => {
        clientSocket.write('HTTP/1.1 200 Connection Established\r\n\r\n');
        if (head && head.length > 0) {
            upstream.write(head);
        }
        upstream.pipe(clientSocket);
        clientSocket.pipe(upstream);
    });

    upstream.on('error', () => {
        clientSocket.write('HTTP/1.1 502 Bad Gateway\r\n\r\n');
        clientSocket.destroy();
    });
    clientSocket.on('error', () => upstream.destroy());
    // eslint-disable-next-line no-console
    console.log(`[hermiq-egress-proxy] ALLOW ${target.host}:${target.port}`);
});

if (require.main === module) {
    if (PDP_URL === '') {
        // eslint-disable-next-line no-console
        console.error('[hermiq-egress-proxy] refusing to start: EGRESS_PDP_URL is not set — '
            + 'a proxy with no policy decision point would be an open relay');
        process.exit(1);
    }
    server.listen(PORT, () => {
        // eslint-disable-next-line no-console
        console.log(`[hermiq-egress-proxy] listening on ${PORT}, PDP=${PDP_URL}`);
    });
}

module.exports = { server, askPdp, tokenFromProxyAuth, parseTarget };
