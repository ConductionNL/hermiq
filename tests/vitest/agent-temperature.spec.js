/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The agent form's temperature range (spec-coverage-built-rows, REQ-SPCOV-002).
 */

import { describe, expect, it } from 'vitest'
import { MAX_TEMPERATURE, MIN_TEMPERATURE, temperatureInRange } from '../../src/utils/agentTemperature.js'

describe('temperatureInRange', () => {
	it('accepts a blank field, so an agent may leave the temperature to the provider', () => {
		expect(temperatureInRange('')).toBe(true)
		expect(temperatureInRange('  ')).toBe(true)
		expect(temperatureInRange(null)).toBe(true)
		expect(temperatureInRange(undefined)).toBe(true)
	})

	it('accepts 0 to 2, the bounds included', () => {
		expect([MIN_TEMPERATURE, MAX_TEMPERATURE]).toEqual([0, 2])
		for (const value of [0, '0', 0.2, '0.2', 1, '1.5', 2, '2']) {
			expect(temperatureInRange(value)).toBe(true)
		}
	})

	it('refuses a value outside 0 to 2, or one that is not a number', () => {
		for (const value of [3, '3', 2.01, -0.1, '-1', 'warm']) {
			expect(temperatureInRange(value)).toBe(false)
		}
	})
})
