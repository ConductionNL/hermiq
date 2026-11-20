/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The session user's subscription sign-in (models-provider-connections D5):
 * which sign-ins are offered, start one, finish one. The finish answers the
 * new credential's id and label; the token never reaches the browser.
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const BASE = '/apps/hermiq/api/subscription-login'

/**
 * Which subscription sign-ins this instance offers.
 *
 * @return {Promise<{available: boolean, providers: Array<{provider: string, mode: string}>}>} The offer.
 * @spec openspec/changes/models-provider-connections/specs/agent-credentials/spec.md#requirement-a-user-can-sign-in-with-a-claude-subscription-req-signin-001
 */
export async function fetchSignInOffer() {
	const response = await axios.get(generateUrl(BASE))
	return response.data
}

/**
 * Start a sign-in.
 *
 * @param {string} provider `anthropic` or `openai`.
 * @return {Promise<{loginId: string, url: string, mode: string, userCode: string}>} The sign-in.
 * @spec openspec/changes/models-provider-connections/specs/agent-credentials/spec.md#requirement-a-user-can-sign-in-with-a-claude-subscription-req-signin-001
 */
export async function startSignIn(provider) {
	const response = await axios.post(generateUrl(BASE), { provider })
	return response.data
}

/**
 * Finish a sign-in with the code the vendor's page showed.
 *
 * @param {string} loginId The login id.
 * @param {string} code The code ('' for a device sign-in).
 * @return {Promise<{id: string, label: string}>} The new personal credential.
 * @spec openspec/changes/models-provider-connections/specs/agent-credentials/spec.md#requirement-a-signed-in-token-is-kept-only-in-the-broker-req-signin-002
 */
export async function finishSignIn(loginId, code) {
	const response = await axios.post(
		generateUrl(`${BASE}/{id}/code`, { id: loginId }),
		{ code },
	)
	return response.data
}
