/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The agent form's retrieval scope (file filters and validated mode) read from
 * and written to the stored agent, and the scope an answer was given as the Chat
 * page reads it (memory-retrieval-scope).
 *
 * @spec openspec/changes/memory-retrieval-scope/specs/retrieval-scope/spec.md
 */

import { describe, expect, it } from 'vitest'
import {
	fileFiltersOf,
	retrievalScopeFields,
	retrievalScopePayload,
	scopeDisplay,
	similarityText,
} from '../../src/utils/retrievalScope.js'

describe('retrievalScopeFields', () => {
	it('reads owners by type, the pattern, the dates and the validated mode', () => {
		const fields = retrievalScopeFields({
			ragFileFilters: {
				owners: [
					{ type: 'group', id: 'juridische-zaken' },
					{ type: 'user', id: 'anna' },
					{ id: 'bob' },
					{ type: 'group', id: '' },
					null,
				],
				namePattern: 'besluit*',
				modifiedAfter: '2025-01-01',
				modifiedBefore: 'yesterday',
			},
			answerOnlyFromValidated: true,
			ragMinSimilarity: 0.55,
			abstentionMessage: 'Neem contact op.',
		})

		expect(fields).toEqual({
			fileOwnerUsers: [
				{ label: 'anna', value: 'anna' },
				{ label: 'bob', value: 'bob' },
			],
			fileOwnerGroups: [{ label: 'juridische-zaken', value: 'juridische-zaken' }],
			fileNamePattern: 'besluit*',
			fileModifiedAfter: '2025-01-01',
			fileModifiedBefore: '',
			answerOnlyFromValidated: true,
			ragMinSimilarity: 0.55,
			abstentionMessage: 'Neem contact op.',
		})
	})

	it('reads an agent without a scope as empty fields and validated mode off', () => {
		expect(retrievalScopeFields({ ragFileFilters: 'wrong', answerOnlyFromValidated: 'yes' })).toEqual({
			fileOwnerUsers: [],
			fileOwnerGroups: [],
			fileNamePattern: '',
			fileModifiedAfter: '',
			fileModifiedBefore: '',
			answerOnlyFromValidated: false,
			ragMinSimilarity: '',
			abstentionMessage: '',
		})
	})
})

describe('fileFiltersOf', () => {
	it('holds only the filters that are set, owners as {type, id}', () => {
		expect(
			fileFiltersOf({
				fileOwnerUsers: [{ label: 'Anna', value: 'anna' }],
				fileOwnerGroups: [{ label: 'Juridische Zaken', value: 'juridische-zaken' }],
				fileNamePattern: '  besluit*  ',
				fileModifiedAfter: '2025-01-01',
				fileModifiedBefore: '',
			}),
		).toEqual({
			owners: [
				{ type: 'user', id: 'anna' },
				{ type: 'group', id: 'juridische-zaken' },
			],
			namePattern: 'besluit*',
			modifiedAfter: '2025-01-01',
		})
	})

	it('is empty when nothing is set and drops a date that is not one', () => {
		expect(fileFiltersOf({ fileNamePattern: '   ', fileModifiedBefore: '1 jan' })).toEqual({})
	})
})

describe('retrievalScopePayload', () => {
	const blank = retrievalScopeFields({})

	it('writes nothing on an agent that never had a scope and sets none', () => {
		expect(retrievalScopePayload(blank, {})).toEqual({
			ragFileFilters: undefined,
			answerOnlyFromValidated: undefined,
			ragMinSimilarity: undefined,
			abstentionMessage: undefined,
		})
	})

	it('writes the validated mode, a clamped floor and the trimmed message', () => {
		const form = {
			...blank,
			fileOwnerGroups: [{ label: 'informatiebeheer', value: 'informatiebeheer' }],
			answerOnlyFromValidated: true,
			ragMinSimilarity: '1.4',
			abstentionMessage: '  Dit kan ik niet beantwoorden.  ',
		}

		expect(retrievalScopePayload(form, {})).toEqual({
			ragFileFilters: { owners: [{ type: 'group', id: 'informatiebeheer' }] },
			answerOnlyFromValidated: true,
			ragMinSimilarity: 1,
			abstentionMessage: 'Dit kan ik niet beantwoorden.',
		})
		expect(retrievalScopePayload({ ...form, ragMinSimilarity: '-2' }, {}).ragMinSimilarity).toBe(0)
		expect(retrievalScopePayload({ ...form, ragMinSimilarity: 'high' }, {}).ragMinSimilarity).toBeUndefined()
	})

	it('clears what the stored agent held when the form clears it', () => {
		const stored = {
			ragFileFilters: { namePattern: 'x*' },
			answerOnlyFromValidated: true,
			abstentionMessage: 'Old',
		}

		expect(retrievalScopePayload(blank, stored)).toEqual({
			ragFileFilters: {},
			answerOnlyFromValidated: false,
			ragMinSimilarity: undefined,
			abstentionMessage: '',
		})
	})
})

describe('scopeDisplay', () => {
	it('reads an abstained validated turn with its floor and best match', () => {
		expect(
			scopeDisplay({
				fileFilters: null,
				validated: true,
				floor: 0.55,
				passed: 0,
				bestSimilarity: 0.41,
				abstained: true,
			}),
		).toEqual({ validated: true, abstained: true, filtered: false, floor: 0.55, passed: 0, best: 0.41 })
	})

	it('reads a filtered turn outside validated mode', () => {
		expect(
			scopeDisplay({ fileFilters: { namePattern: 'x*' }, validated: false, passed: 3, bestSimilarity: 'n/a' }),
		).toEqual({ validated: false, abstained: false, filtered: true, floor: null, passed: 3, best: null })
	})

	it('shows nothing for a turn without a record or without a scope', () => {
		expect(scopeDisplay(null)).toBeNull()
		expect(scopeDisplay('wrong')).toBeNull()
		expect(scopeDisplay({ fileFilters: {}, validated: false, passed: 2 })).toBeNull()
	})
})

describe('similarityText', () => {
	it('gives two decimals, or a dash when unknown', () => {
		expect(similarityText(0.4123)).toBe('0.41')
		expect(similarityText(0.5)).toBe('0.50')
		expect(similarityText(null)).toBe('–')
	})

	it('writes the decimal the way the reader\'s locale does', () => {
		expect(similarityText(0.4123, 'nl-NL')).toBe('0,41')
		expect(similarityText(0.4123, 'en-US')).toBe('0.41')
	})

	it('gives a dash for a value that is not a number', () => {
		expect(similarityText('abc')).toBe('–')
		expect(similarityText(Number.NaN)).toBe('–')
		expect(similarityText(undefined)).toBe('–')
	})
})
