/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * How the API modules read a list response and build a GitHub search query.
 * Hermiq's endpoints answer a list either bare or in a `results` envelope; a
 * few pass through OpenRegister's `data.results` envelope as well.
 */

/**
 * Normalise the Hermiq list envelope to a plain array.
 *
 * @param {object} data The response body.
 * @return {Array<object>} The extracted list (empty array on miss).
 */
export function listOrResults(data) {
	if (Array.isArray(data)) {
		return data
	}
	if (Array.isArray(data?.results)) {
		return data.results
	}
	return []
}

/**
 * Normalise the Hermiq list envelope, or OpenRegister's nested `data.results` one, to a plain array.
 *
 * @param {object} data The response body.
 * @return {Array<object>} The extracted list (empty array on miss).
 */
export function listOrNestedResults(data) {
	if (Array.isArray(data) || Array.isArray(data?.results)) {
		return listOrResults(data)
	}
	if (Array.isArray(data?.data?.results)) {
		return data.data.results
	}
	return []
}

/**
 * The query of a GitHub search: the term and the broker credential, each only when given.
 *
 * @param {string} query Optional free-text term narrowing the search.
 * @param {string|null} credentialId Optional broker `github` credential UUID.
 * @return {object} The query parameters.
 */
export function githubSearchParams(query, credentialId) {
	const params = {}
	if (query) {
		params.q = query
	}
	if (credentialId) {
		params.credentialId = credentialId
	}
	return params
}
