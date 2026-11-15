/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * errorMessage picks what the catch blocks it replaces picked:
 * `e?.response?.data?.error || e?.message || fallback`, for every shape of `e`.
 */

import { describe, expect, it } from 'vitest'
import { errorMessage } from '../../src/utils/errorMessage.js'

/**
 * The expression the catch blocks spelled out.
 *
 * @param {object} e What was thrown.
 * @param {string} fallback The fallback.
 * @return {string} The message.
 */
function inline(e, fallback) {
	return e?.response?.data?.error
		|| e?.message
		|| fallback
}

const shapes = [
	undefined,
	null,
	{},
	'a string',
	new Error(''),
	new Error('plain'),
	{ message: 'own message' },
	{ response: null, message: 'm' },
	{ response: {}, message: 'm' },
	{ response: { data: null }, message: 'm' },
	{ response: { data: { error: '' } }, message: 'm' },
	{ response: { data: { error: 'server' } }, message: 'm' },
	{ response: { data: { error: 0 } }, message: '' },
	{ response: { data: { error: { nested: true } } } },
]

describe('errorMessage', () => {
	it.each(shapes.map((shape, index) => [index, shape]))('matches the inline pick for shape %i', (index, shape) => {
		expect(errorMessage(shape, 'Fallback')).toEqual(inline(shape, 'Fallback'))
	})

	it('falls back last', () => {
		expect(errorMessage({}, 'Unknown error')).toBe('Unknown error')
		expect(errorMessage({ response: { data: { error: 'Denied' } }, message: 'x' }, 'Unknown error')).toBe('Denied')
	})
})
