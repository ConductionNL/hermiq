// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The broker credentials the AI provider dialog offers (claude-provider-for-every-member).
 *
 * Plain functions without Vue imports, so `tests/llm-credentials.spec.js` runs them under
 * node. OpenRegister lists a caller's PERSONAL credentials at `/api/credentials` and their
 * active organisation's credentials at `/api/credentials?scope=organisation`. The dialog
 * used to read only the first list, so an organisation key (the one key every member can
 * use) could not be picked at all.
 *
 * @spec openspec/changes/claude-provider-for-every-member/specs/claude-provider-for-every-member/spec.md#requirement-the-ai-provider-dialog-offers-organisation-credentials
 */

/**
 * Merge the personal and organisation lists into one, marking each entry's scope.
 *
 * An id that appears in both lists is kept once, as the organisation entry, because the
 * organisation list is the authority on a credential's scope.
 *
 * @param {Array<object>|null} personal The personal list.
 * @param {Array<object>|null} organisation The organisation list.
 * @return {Array<object>} The merged list, personal entries first.
 *
 * @spec openspec/changes/claude-provider-for-every-member/specs/claude-provider-for-every-member/spec.md#requirement-the-ai-provider-dialog-offers-organisation-credentials
 */
export function mergeCredentialLists(personal, organisation) {
	const orgList = (organisation || []).map((credential) => ({
		...credential,
		scope: 'organisation',
	}))
	const orgIds = new Set(orgList.map((credential) => credential.id))
	const personalList = (personal || [])
		.filter((credential) => !orgIds.has(credential.id))
		.map((credential) => ({
			...credential,
			scope: credential.scope || 'personal',
		}))
	return [...personalList, ...orgList]
}

/**
 * Load both lists; a failure of one still returns the other.
 *
 * @param {function(string): Promise<{data: {results: Array<object>}}>} get An axios-style `get(url)`.
 * @param {function(string): string} urlFor Builds an app URL from a path (`generateUrl`).
 * @return {Promise<Array<object>>} The merged list.
 *
 * @spec openspec/changes/claude-provider-for-every-member/specs/claude-provider-for-every-member/spec.md#requirement-the-ai-provider-dialog-offers-organisation-credentials
 */
export async function loadLlmCredentials(get, urlFor) {
	const results = async (path) => {
		try {
			const { data } = await get(urlFor(path))
			return (data && data.results) || []
		} catch {
			return []
		}
	}
	const [personal, organisation] = await Promise.all([
		results('/apps/openregister/api/credentials'),
		results('/apps/openregister/api/credentials?scope=organisation'),
	])
	return mergeCredentialLists(personal, organisation)
}
