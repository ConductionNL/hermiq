/**
 * AppAPI shared-secret authentication for the hermiq-llm-runner ExApp.
 *
 * Nextcloud AppAPI signs every request it proxies to an ExApp with the shared
 * secret it generated at registration time (the ExApp receives it as APP_SECRET).
 * This module validates that contract BEFORE the caller reaches any CLI:
 *
 *   - `EX-APP-ID`            must equal our APP_ID.
 *   - `AUTHORIZATION-APP-API` must be present (base64 `userId:secret` that
 *                             AppAPI attaches — carries the acting user).
 *   - `AA-SIGNATURE`         must be an HMAC-SHA256 of the raw request body
 *                             keyed by APP_SECRET, hex-encoded.
 *
 * The HMAC is computed over the raw body with the shared secret, mirroring
 * AppAPI's request-signing model (nc_py_api's AppAPIAuthMiddleware performs the
 * canonical-string HMAC on the Nextcloud side; the deployer sets APP_SECRET to
 * the value Nextcloud minted at registration). The secret never travels in the
 * clear and never appears in a log line.
 *
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @copyright 2026 Conduction B.V.
 */

'use strict';

const crypto = require('crypto');

const APP_ID = process.env.APP_ID || 'hermiq-llm-runner';
const APP_SECRET = process.env.APP_SECRET || '';

/**
 * Constant-time comparison of two hex strings. Returns false on any length or
 * value mismatch without leaking timing information.
 *
 * @param {string} a First hex string.
 * @param {string} b Second hex string.
 * @returns {boolean} True when equal.
 */
function timingSafeEqualHex(a, b) {
    if (typeof a !== 'string' || typeof b !== 'string') {
        return false;
    }
    const bufA = Buffer.from(a, 'utf8');
    const bufB = Buffer.from(b, 'utf8');
    if (bufA.length !== bufB.length) {
        return false;
    }
    return crypto.timingSafeEqual(bufA, bufB);
}

/**
 * Compute the expected AA-SIGNATURE for a raw request body.
 *
 * @param {Buffer|string} rawBody The exact bytes of the request body.
 * @returns {string} Hex-encoded HMAC-SHA256.
 */
function expectedSignature(rawBody) {
    return crypto.createHmac('sha256', APP_SECRET).update(rawBody).digest('hex');
}

/**
 * Validate an incoming request against the AppAPI shared-secret contract.
 *
 * @param {object} headers Lower-cased request headers.
 * @param {Buffer} rawBody The raw request body bytes.
 * @returns {{ok: boolean, status: number, reason: string}} Verdict. `ok:true`
 *   means the request is authorised; otherwise `status` is the HTTP code to
 *   return (401 for missing credentials, 403 for a present-but-invalid one).
 */
function verify(headers, rawBody) {
    if (APP_SECRET === '') {
        // Fail closed: an unconfigured secret must never accept traffic.
        return { ok: false, status: 401, reason: 'runner APP_SECRET is not configured' };
    }

    const appId = headers['ex-app-id'];
    const authApp = headers['authorization-app-api'];
    const signature = headers['aa-signature'];

    if (!appId || !authApp || !signature) {
        return { ok: false, status: 401, reason: 'missing AppAPI authentication headers' };
    }
    if (appId !== APP_ID) {
        return { ok: false, status: 403, reason: 'EX-APP-ID does not match this runner' };
    }
    if (!timingSafeEqualHex(signature, expectedSignature(rawBody))) {
        return { ok: false, status: 403, reason: 'AA-SIGNATURE verification failed' };
    }
    return { ok: true, status: 200, reason: 'ok' };
}

module.exports = { verify, expectedSignature, timingSafeEqualHex, APP_ID };
