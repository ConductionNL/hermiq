/**
 * AppAPI shared-secret authentication for the hermiq-llm-runner ExApp.
 *
 * Nextcloud AppAPI authenticates every request it proxies to an ExApp with the
 * shared secret it generated at registration time (the ExApp receives it as
 * APP_SECRET). AppAPI 34's `AppAPICommonService::buildAppAPIAuthHeaders()`
 * attaches these headers and NO request signature:
 *
 *   - `EX-APP-ID`             the target ExApp id — must equal our APP_ID.
 *   - `EX-APP-VERSION`        the ExApp version (not validated here).
 *   - `AA-VERSION`            the AppAPI version (not validated here).
 *   - `AUTHORIZATION-APP-API` base64(`userId:secret`). This carries BOTH the
 *                             acting user id and the shared secret; validating
 *                             the secret half against APP_SECRET is the whole
 *                             authentication check.
 *
 * NOTE: an earlier revision of this module required an `AA-SIGNATURE` HMAC of
 * the request body, on the assumption AppAPI signs every proxied request. It
 * does not — AppAPI 34 sends no signature — so that scheme rejected every real
 * AppAPI call (including the /enabled lifecycle call and /run dispatch) as
 * "missing AppAPI authentication headers". The secret still never travels in
 * cleartext outside the internal AppAPI network and never appears in a log line.
 *
 * ⚠️ THIS HAS BEEN REVERTED ONCE. `6cc6f176` ("wip: session-consolidation …,
 * NOT verified or tested") restored the HMAC scheme on 2026-07-22 along with
 * the comment above, and nobody noticed for nine days because the DEPLOYED
 * image was still the pre-revert build — the regression only existed in the
 * source. Anyone who rebuilt the image got an ExApp that could not be enabled
 * and could not serve a single turn. If a change here reintroduces
 * `AA-SIGNATURE`, it is that revert coming back, not a hardening.
 *
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @copyright 2026 Conduction B.V.
 */

'use strict'

const crypto = require('crypto')

const APP_ID = process.env.APP_ID || 'hermiq-llm-runner'
const APP_SECRET = process.env.APP_SECRET || ''

/**
 * Constant-time string comparison. Returns false on any length or value
 * mismatch without leaking timing information.
 *
 * @param {string} a First string.
 * @param {string} b Second string.
 * @returns {boolean} True when equal.
 */
function timingSafeEqualStr(a, b) {
	if (typeof a !== 'string' || typeof b !== 'string') {
		return false
	}
	const bufA = Buffer.from(a, 'utf8')
	const bufB = Buffer.from(b, 'utf8')
	if (bufA.length !== bufB.length) {
		return false
	}
	return crypto.timingSafeEqual(bufA, bufB)
}

/**
 * Extract the shared secret from an `AUTHORIZATION-APP-API` header value.
 * The header is base64(`userId:secret`); the user id may be empty (CLI-issued
 * requests carry no user) and the secret itself may legitimately contain a
 * colon, so split on the FIRST colon only.
 *
 * @param {string} authApp The raw header value.
 * @returns {string|null} The secret, or null when the header is malformed.
 */
function secretFromAuthHeader(authApp) {
	let decoded
	try {
		decoded = Buffer.from(authApp, 'base64').toString('utf8')
	} catch (e) {
		return null
	}
	const idx = decoded.indexOf(':')
	if (idx < 0) {
		return null
	}
	return decoded.slice(idx + 1)
}

/**
 * Validate an incoming request against the AppAPI shared-secret contract.
 *
 * @param {object} headers Lower-cased request headers.
 * @param {Buffer} _rawBody The raw request body bytes (unused — AppAPI 34 does
 *   not sign the body; retained so the call site stays uniform across routes).
 * @returns {{ok: boolean, status: number, reason: string}} Verdict. `ok:true`
 *   means the request is authorised; otherwise `status` is the HTTP code to
 *   return (401 for missing credentials, 403 for a present-but-invalid one).
 */
function verify(headers, _rawBody) {
	if (APP_SECRET === '') {
		// Fail closed: an unconfigured secret must never accept traffic.
		return {
			ok: false,
			status: 401,
			reason: 'runner APP_SECRET is not configured',
		}
	}

	const appId = headers['ex-app-id']
	const authApp = headers['authorization-app-api']

	if (!appId || !authApp) {
		return {
			ok: false,
			status: 401,
			reason: 'missing AppAPI authentication headers',
		}
	}
	if (appId !== APP_ID) {
		return {
			ok: false,
			status: 403,
			reason: 'EX-APP-ID does not match this runner',
		}
	}

	const secret = secretFromAuthHeader(authApp)
	if (secret === null) {
		return {
			ok: false,
			status: 403,
			reason: 'AUTHORIZATION-APP-API is not valid base64 user:secret',
		}
	}
	if (!timingSafeEqualStr(secret, APP_SECRET)) {
		return {
			ok: false,
			status: 403,
			reason: 'AUTHORIZATION-APP-API secret does not match',
		}
	}
	return { ok: true, status: 200, reason: 'ok' }
}

module.exports = { verify, timingSafeEqualStr, secretFromAuthHeader, APP_ID }
