// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The attempts of a run at a provider, as the Runs page shows them
// (models-several-models-per-turn REQ-MFAIL-005).
//
// @spec openspec/changes/models-several-models-per-turn/specs/tenant-model-policy/spec.md#requirement-every-attempt-is-on-the-run-record-req-mfail-005

/**
 * What an attempt came to: `answered`, `cooling`, `skipped`, or for a failure its kind
 * (`rate_limit`, `server`, `timeout`, `connection`, ...).
 *
 * @param {object} attempt The recorded attempt.
 * @return {string} The outcome key.
 * @spec openspec/changes/models-several-models-per-turn/specs/tenant-model-policy/spec.md#requirement-every-attempt-is-on-the-run-record-req-mfail-005
 */
export function attemptOutcome(attempt) {
	const outcome = attempt?.outcome || 'failed'
	if (outcome === 'failed' && attempt?.reason) {
		return attempt.reason
	}
	return outcome
}

/**
 * The providers and models that were sent the prompt, comma separated.
 *
 * @param {Array<object>} receivedBy The recorded list.
 * @return {string} The list in words.
 * @spec openspec/changes/models-several-models-per-turn/specs/tenant-model-policy/spec.md#requirement-every-attempt-is-on-the-run-record-req-mfail-005
 */
export function receivedByList(receivedBy) {
	return (Array.isArray(receivedBy) ? receivedBy : [])
		.filter((entry) => entry && entry.provider)
		.map((entry) => [entry.provider, entry.model].filter(Boolean).join(' '))
		.join(', ')
}
