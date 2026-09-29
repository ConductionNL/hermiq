// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * An agent's own credential per provider (operations-a-credential-per-agent).
 *
 * Plain functions without Vue imports, so `tests/agent-credentials.spec.js` runs
 * them under node. The form stores only broker credential uuids: the broker keeps
 * the secret.
 *
 * @spec openspec/changes/operations-a-credential-per-agent/specs/agent-credentials/spec.md#requirement-an-agent-can-carry-its-own-credential-per-provider-req-agcred-001
 */

/**
 * The providers whose turns take a broker credential, and so can be pinned.
 *
 * @type {Array<string>}
 */
export const PINNABLE_PROVIDERS = ['openai', 'fireworks']

/**
 * The credentials the owner may pin for one provider, as select options.
 *
 * Only the provider's own credentials, and only those allowed for hermiq when the
 * broker lists the apps a credential is allowed for.
 *
 * @param {Array<object>} credentials The broker's list (`/apps/openregister/api/credentials`).
 * @param {string} provider The provider.
 * @return {Array<{label: string, value: string}>} The options.
 *
 * @spec openspec/changes/operations-a-credential-per-agent/specs/agent-credentials/spec.md#requirement-an-agent-can-carry-its-own-credential-per-provider-req-agcred-001
 */
export function credentialOptions(credentials, provider) {
	return (credentials || [])
		.filter((credential) => credential.provider === provider)
		.filter(
			(credential) =>
				!Array.isArray(credential.allowedApps)
				|| credential.allowedApps.includes('hermiq'),
		)
		.map((credential) => ({
			label: credential.name || credential.id,
			value: credential.id,
		}))
}

/**
 * Set or clear one provider's pin, returning a new map.
 *
 * Clearing removes the provider rather than storing an empty string, so an agent
 * without a pin carries no `credentialIds` entry for it and resolves as before.
 *
 * @param {object|null} credentialIds The current map.
 * @param {string} provider The provider.
 * @param {string|null} credentialId The chosen credential uuid, or null to clear.
 * @return {object} The new map.
 *
 * @spec openspec/changes/operations-a-credential-per-agent/specs/agent-credentials/spec.md#requirement-an-agent-can-carry-its-own-credential-per-provider-req-agcred-001
 */
export function setPin(credentialIds, provider, credentialId) {
	const next = { ...(credentialIds || {}) }
	if (credentialId) {
		next[provider] = credentialId
	} else {
		delete next[provider]
	}
	return next
}
