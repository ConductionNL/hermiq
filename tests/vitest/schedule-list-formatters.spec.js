/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The Schedules page's cell formatters (scheduling-overview-and-start-choice
 * D1): a failed last result names its error, a run that never ran says so, a
 * next run reads as a date and an off schedule reads as paused.
 *
 * @spec openspec/changes/scheduling-overview-and-start-choice/specs/agent-schedule/spec.md#requirement-every-schedule-a-person-may-see-is-on-one-page-req-sched-001
 */

import { t } from '@nextcloud/l10n'
import { describe, expect, it } from 'vitest'
import manifest from '../../src/manifest.json'
import { scheduleListFormatters } from '../../src/utils/scheduleList.js'

const f = scheduleListFormatters(t)

describe('scheduleListFormatters', () => {
	it('names the error of a failed last result', () => {
		expect(f.scheduleLastResult('error', { failed: true, lastError: 'Provider down' })).toBe('Failed: Provider down')
		expect(f.scheduleLastResult('dead_letter', { failed: true, lastError: null })).toBe('Failed')
	})

	it('reads a success, a run that never ran and any other status', () => {
		expect(f.scheduleLastResult('ok', { failed: false })).toBe('Succeeded')
		expect(f.scheduleLastResult(null, {})).toBe('Not run yet')
		expect(f.scheduleLastResult('skipped_budget', { failed: false })).toBe('skipped_budget')
	})

	it('shows a next run as a date and none as a dash', () => {
		expect(f.scheduleNextRun('2026-11-16T08:00:00Z')).toMatch(/2026/)
		expect(f.scheduleNextRun(null)).toBe('—')
		expect(f.scheduleNextRun('soon')).toBe('—')
	})

	it('reads an off schedule as paused and anything else as on', () => {
		expect([f.scheduleEnabled(false), f.scheduleEnabled(true)]).toEqual(['Paused', 'On'])
	})
})

describe('the Schedules page', () => {
	it('reads the access-filtered endpoint, not the schedule objects, and uses these formatters', () => {
		const page = manifest.pages.find((p) => p.id === 'Schedules')
		expect(page.config.entitySource).toBe('endpoint')
		expect(page.config.sourceConfig.url).toBe('/apps/hermiq/api/schedules')
		expect(page.config.register).toBeUndefined()
		for (const column of page.config.columns.filter((c) => c.formatter)) {
			expect(typeof f[column.formatter]).toBe('function')
		}
		expect(page.config.quickFilters.map((q) => q.label)).toEqual(['All', 'Failed', 'Paused'])
		expect(manifest.menu.find((m) => m.id === 'Schedules').route).toBe('Schedules')
	})
})
