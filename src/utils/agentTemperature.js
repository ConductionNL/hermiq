// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The agent's temperature on the agent form (spec-coverage-built-rows,
// REQ-SPCOV-002). Providers accept 0 to 2; the server refuses anything else
// (AgentTemperatureGuardListener), this lets the form say so before it saves.

/** The lowest temperature a provider accepts. */
export const MIN_TEMPERATURE = 0

/** The highest temperature a provider accepts. */
export const MAX_TEMPERATURE = 2

/**
 * Whether a typed temperature may be saved: blank, or a number from 0 to 2.
 *
 * @param {string|number|null|undefined} raw The field's value.
 * @return {boolean} True when the form may save it.
 * @spec openspec/specs/agent-management-ui/spec.md#REQ-SPCOV-002
 */
export function temperatureInRange(raw) {
	if (raw === null || raw === undefined || String(raw).trim() === '') {
		return true
	}
	const value = Number(raw)
	return (
		!Number.isNaN(value) && value >= MIN_TEMPERATURE && value <= MAX_TEMPERATURE
	)
}
