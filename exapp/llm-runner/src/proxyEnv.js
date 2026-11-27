/**
 * The per-run proxy environment shared by LLM turns (`runner.js`) and stages
 * (`stage.js`).
 *
 * The run token goes in the proxy URL's userinfo (`http://run:<token>@host:port`)
 * because that is the one channel every HTTP client, and git itself, already
 * forwards to a proxy as `Proxy-Authorization`, with no client-side support
 * needed. It lives in the child's ENVIRONMENT only — never on argv (the process
 * table is world-readable) and never in a log line.
 *
 * Each caller reads its own proxy authority at module load and passes it in, so
 * a test that reloads one module with a different environment still gets a
 * fresh value.
 *
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @copyright 2026 Conduction B.V.
 */

'use strict'

/**
 * Build the per-run proxy env vars for one proxy authority and run token.
 *
 * Returns an empty object when either half is missing: without a proxy authority
 * there is nothing to point at, and without a token the proxy would deny anyway.
 *
 * @param {string} authority The proxy's `host:port`, '' when ungoverned.
 * @param {string} runToken The per-run token minted by Hermiq.
 * @returns {object} Env map to merge into the child's environment.
 */
function proxyEnvFor(authority, runToken) {
	if (
		authority === ''
		|| typeof runToken !== 'string'
		|| runToken === ''
	) {
		return {}
	}

	return proxyVars(`http://run:${encodeURIComponent(runToken)}@${authority}`)
}

/**
 * The proxy variables that point every client in the child at one proxy URL.
 *
 * Both cases: some tools read the lowercase names, some the uppercase.
 * NO_PROXY is deliberately NOT set — an exemption list here would be a second
 * policy, and a hole in the only route out.
 *
 * @param {string} url The per-run proxy URL.
 * @returns {object} The env map.
 */
function proxyVars(url) {
	return {
		HTTPS_PROXY: url,
		https_proxy: url,
		HTTP_PROXY: url,
		http_proxy: url,
	}
}

module.exports = { proxyEnvFor }
