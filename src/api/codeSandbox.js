// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Plain (non-Pinia) API helper for where agent and flow code runs
// (tools-code-sandbox): the admin's choice of the own sandbox ExApp or a hosted
// sandbox reached through an integriq source, and the "Run a test" call.
//
// Mirrors src/api/runRetention.js: stateless functions, axios from
// @nextcloud/axios so the CSRF requesttoken travels with the write.

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/** Hermiq code sandbox settings path. */
const CODE_SANDBOX = '/apps/hermiq/api/settings/code-sandbox'

/**
 * Read where code runs.
 *
 * @return {Promise<object>} { backend, hostedSource, reach, available }.
 * @spec openspec/changes/tools-code-sandbox/specs/code-sandbox/spec.md#requirement-the-admin-chooses-where-code-runs-req-sandbox-004
 */
export async function getCodeSandbox() {
	const response = await axios.get(generateUrl(CODE_SANDBOX))
	return response.data
}

/**
 * Save where code runs. Refused with a 422 for an unknown backend or a hosted
 * one without its source.
 *
 * @param {string} backend `exapp` or `hosted`.
 * @param {string|null} hostedSource The integriq source, for `hosted`.
 * @return {Promise<object>} The saved choice, as getCodeSandbox() answers it.
 * @spec openspec/changes/tools-code-sandbox/specs/code-sandbox/spec.md#requirement-the-admin-chooses-where-code-runs-req-sandbox-004
 */
export async function setCodeSandbox(backend, hostedSource) {
	const response = await axios.patch(generateUrl(CODE_SANDBOX), { backend, hostedSource })
	return response.data
}

/**
 * Run `print(1 + 1)` in the chosen sandbox.
 *
 * @return {Promise<object>} { passed, output, exitCode, timedOut, backend, hostedSource, reach }.
 * @spec openspec/changes/tools-code-sandbox/specs/code-sandbox/spec.md#scenario-an-admin-switches-to-a-hosted-sandbox
 */
export async function testCodeSandbox() {
	const response = await axios.post(generateUrl(CODE_SANDBOX + '/test'))
	return response.data
}
