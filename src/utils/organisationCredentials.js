// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The organisation picker of the organisation-credential form
 * (claude-provider-for-every-member, Ruben 2026-10-10: show and choose).
 *
 * Plain functions without Vue imports, so `tests/llm-credentials.spec.js` runs them under
 * node. OpenRegister's `GET /api/credentials/organisations` lists the organisations the
 * caller may manage, the active one flagged. The server re-checks every choice on create,
 * so this list is a convenience, never the authority.
 *
 * @spec openspec/changes/claude-provider-for-every-member/specs/claude-provider-for-every-member/spec.md#requirement-the-organisation-credential-form-shows-and-chooses-its-organisation
 */

/**
 * Turn the endpoint's results into select options.
 *
 * @param {Array<object>|null} results `[{uuid, name, active}]`.
 * @return {Array<{label: string, value: string, active: boolean}>} The options.
 */
export function organisationOptions(results) {
	return (results || [])
		.filter((organisation) => organisation && organisation.uuid)
		.map((organisation) => ({
			label: organisation.name || organisation.uuid,
			value: organisation.uuid,
			active: organisation.active === true,
		}))
}

/**
 * The option selected first: the active organisation, else the first one.
 *
 * @param {Array<{value: string, active: boolean}>} options The options.
 * @return {object|null} The default option, or null when there is none.
 */
export function defaultOrganisation(options) {
	return options.find((option) => option.active) || options[0] || null
}

/**
 * Load the organisations the caller may manage; a failure yields an empty list.
 *
 * @param {function(string): Promise<{data: {results: Array<object>}}>} get An axios-style `get(url)`.
 * @param {function(string): string} urlFor Builds an app URL from a path (`generateUrl`).
 * @return {Promise<Array<object>>} The options.
 */
export async function loadManageableOrganisations(get, urlFor) {
	try {
		const { data } = await get(
			urlFor('/apps/openregister/api/credentials/organisations'),
		)
		return organisationOptions(data && data.results)
	} catch {
		return []
	}
}
