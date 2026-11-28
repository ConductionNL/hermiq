/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The small helpers under src/utils that had no spec of their own: the busy/error
 * wrapper, the cost-estimate line, the date-or-dash formatter and the organisation
 * label, each asserted on what it returns or sets for a given input.
 */

import { describe, expect, it } from 'vitest'
import { BUSY_FIELDS, busyAction, BusyFields, LOADING_FIELDS, SAVING_FIELDS } from '../../src/utils/busyAction.js'
import { estimateLine } from '../../src/utils/costEstimate.js'
import { dateOrDash } from '../../src/utils/displayDate.js'
import { organisationLabel } from '../../src/utils/organisationLabel.js'

describe('busyAction', () => {
	it('names the busy and error fields and freezes them', () => {
		expect([BUSY_FIELDS.busy, BUSY_FIELDS.error]).toEqual(['busy', 'error'])
		expect([LOADING_FIELDS.busy, LOADING_FIELDS.error]).toEqual(['loading', 'error'])
		expect([SAVING_FIELDS.busy, SAVING_FIELDS.error]).toEqual(['saving', 'error'])
		expect(Object.isFrozen(new BusyFields('a', 'b'))).toBe(true)
	})

	it('is busy while the attempt runs, clears a stale error and ends not busy', async () => {
		const vm = { loading: false, error: 'stale' }
		const seen = []
		const run = busyAction(vm, LOADING_FIELDS)

		await run(async () => {
			seen.push([vm.loading, vm.error])
		}, () => 'unused')

		expect(seen).toEqual([[true, '']])
		expect(vm).toEqual({ loading: false, error: '' })
	})

	it('sets the server error, the thrown message or the fallback when the attempt fails', async () => {
		const vm = { saving: false, error: '' }
		const run = busyAction(vm, SAVING_FIELDS)
		let fallbacks = 0
		const fallback = () => {
			fallbacks++
			return 'Could not save'
		}

		await run(async () => { throw { response: { data: { error: 'Denied' } } } }, fallback)
		expect(vm).toEqual({ saving: false, error: 'Denied' })

		await run(async () => { throw new Error('offline') }, fallback)
		expect(vm.error).toBe('offline')

		await run(async () => { throw {} }, fallback)
		expect(vm.error).toBe('Could not save')
		expect(fallbacks).toBe(3)
	})

	it('does not ask for the fallback when the attempt succeeds', async () => {
		const vm = {}
		let asked = false
		await busyAction(vm, BUSY_FIELDS)(async () => {}, () => { asked = true })

		expect(asked).toBe(false)
		expect(vm).toEqual({ busy: false, error: '' })
	})
})

describe('estimateLine', () => {
	it('words the average tokens and the sample size when an estimate is available', () => {
		expect(estimateLine({ available: true, avgTotalTokens: 1234, sampleSize: 5 }))
			.toBe('Estimate: ~1234 tokens per run (average of the last 5 runs)')
	})

	it('says there is not enough history otherwise', () => {
		expect(estimateLine({ available: false, avgTotalTokens: 99, sampleSize: 1 }))
			.toBe('Not enough run history yet for a cost estimate.')
	})
})

describe('dateOrDash', () => {
	it('dashes an empty or unparseable value', () => {
		for (const value of [undefined, null, '', 0, 'not a date']) {
			expect(dateOrDash(value)).toBe('—')
		}
	})

	it('formats a parseable value in the browser locale', () => {
		const iso = '2026-03-04T05:06:07Z'
		expect(dateOrDash(iso)).toBe(new Date(iso).toLocaleString())
		expect(dateOrDash(iso)).not.toBe('—')
	})
})

describe('organisationLabel', () => {
	const organisations = [null, { id: 'o1', label: 'Gemeente A' }, { id: 'o2', label: '' }]

	it('returns the matching organisation label', () => {
		expect(organisationLabel('o1', organisations)).toBe('Gemeente A')
	})

	it('falls back to the id when the match has no label', () => {
		expect(organisationLabel('o2', organisations)).toBe('o2')
	})

	it('returns the id itself for an unknown id or a missing list', () => {
		expect(organisationLabel('o9', organisations)).toBe('o9')
		expect(organisationLabel('o1', undefined)).toBe('o1')
		expect(organisationLabel('o1', { o1: 'x' })).toBe('o1')
	})
})
