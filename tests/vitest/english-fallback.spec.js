/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

import { describe, expect, it, vi } from 'vitest'
import { registerEnglishFallback } from '../../src/utils/englishFallback.js'

describe('registerEnglishFallback', () => {
	const english = {
		Approvals: 'Approvals',
		'app-availability.title': 'Available in your apps',
	}

	it('keeps the Dutch that Nextcloud registered and fills the gaps with English', () => {
		const register = vi.fn()
		registerEnglishFallback(register, 'hermiq', english, {
			hermiq: { Approvals: 'Goedkeuringen' },
		})

		expect(register).toHaveBeenCalledWith('hermiq', {
			Approvals: 'Goedkeuringen',
			'app-availability.title': 'Available in your apps',
		})
	})

	it('registers the English bundle when nothing is loaded yet', () => {
		const register = vi.fn()
		registerEnglishFallback(register, 'hermiq', english, {})

		expect(register).toHaveBeenCalledWith('hermiq', english)
	})
})
