/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * ScheduleFormModal's "When should it run?" field
 * (scheduling-overview-and-start-choice D3): the typed words go to the
 * parse-when endpoint, the answer's rule lands in the form with its readback
 * and next three runs, an unreadable text shows the server's example, and the
 * saved payload carries the rule, never the words.
 *
 * @spec openspec/changes/scheduling-overview-and-start-choice/specs/agent-schedule/spec.md#requirement-a-schedule-can-be-set-in-plain-words-req-sched-003
 */

import { t } from '@nextcloud/l10n'
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../../src/api/schedules.js', () => ({ parseScheduleWhen: vi.fn() }))
vi.mock('../../src/api/budgets.js', () => ({ getBudgetEstimate: vi.fn(async () => null) }))
const scheduleStore = { registerObjectType: vi.fn(), saveObject: vi.fn(async (type, body) => body) }
vi.mock('../../src/store/store.js', () => ({ useScheduleStore: () => scheduleStore }))

const api = await import('../../src/api/schedules.js')
const { default: ScheduleFormModal } = await import('../../src/modals/ScheduleFormModal.vue')

/**
 * Mount the schedule form.
 *
 * @param {object|null} schedule The schedule being edited.
 * @return {Promise<object>} The wrapper.
 */
async function form(schedule = null) {
	const w = mount(ScheduleFormModal, { props: { show: true, agentId: 'a-1', schedule }, global: { mocks: { t } } })
	await flushPromises()
	return w
}

/**
 * The "When should it run?" text field's stand-in.
 *
 * @param {object} w The wrapper.
 * @return {object} The stand-in.
 */
function whenField(w) {
	return w.findAllComponents({ name: 'NcTextField' }).find((c) => c.vm.$attrs.label === 'When should it run?')
}

beforeEach(() => {
	vi.clearAllMocks()
})

describe('ScheduleFormModal plain words', () => {
	it('reads the words into a cron rule and shows the readback and next runs', async () => {
		api.parseScheduleWhen.mockResolvedValueOnce({
			kind: 'cron',
			cronExpr: '0 8 * * 1-5',
			readback: 'Every weekday at 08:00, Europe/Amsterdam',
			nextRuns: ['2026-11-16T08:00:00+01:00', '2026-11-17T08:00:00+01:00', '2026-11-18T08:00:00+01:00'],
			source: 'rules',
		})
		const w = await form()
		whenField(w).vm.pick('every weekday at eight')
		await w.vm.readWhen()
		await flushPromises()

		expect(api.parseScheduleWhen).toHaveBeenCalledWith('every weekday at eight', 'en')
		expect([w.vm.form.kind, w.vm.form.cronExpr]).toEqual(['cron', '0 8 * * 1-5'])
		const readback = w.find('[data-testid="schedule-when-readback"]')
		expect(readback.find('.schedule-form__readback-line').text()).toBe('Every weekday at 08:00, Europe/Amsterdam')
		expect(readback.findAll('li')).toHaveLength(3)
	})

	it('saves the rule, not the words', async () => {
		api.parseScheduleWhen.mockResolvedValueOnce({ kind: 'interval', intervalMinutes: 15, readback: 'Every 15 minutes, UTC', nextRuns: [] })
		const w = await form()
		w.vm.form.name = 'Quarterly'
		w.vm.whenText = 'every quarter of an hour'
		await w.vm.readWhen()
		await w.vm.save()

		const body = scheduleStore.saveObject.mock.calls[0][1]
		expect(body).toMatchObject({ kind: 'interval', intervalMinutes: 15 })
		expect(JSON.stringify(body)).not.toContain('every quarter of an hour')
	})

	it('puts a one-off rule into the date field as wall-clock time', async () => {
		api.parseScheduleWhen.mockResolvedValueOnce({ kind: 'once', runAt: '2026-11-15T09:30:00+01:00', readback: 'Once on 15 November at 09:30', nextRuns: ['2026-11-15T09:30:00+01:00'] })
		const w = await form()
		w.vm.whenText = 'tomorrow at half past nine'
		await w.vm.readWhen()

		expect([w.vm.form.kind, w.vm.form.runAt]).toEqual(['once', '2026-11-15T09:30'])
	})

	it('shows the server example for unreadable words and keeps the rule fields', async () => {
		api.parseScheduleWhen.mockRejectedValueOnce({ response: { status: 422, data: { message: 'I could not read that. Try for example: every weekday at 8:00.' } } })
		const w = await form()
		w.vm.whenText = 'whenever the moon is full'
		await w.vm.readWhen()
		await flushPromises()

		expect(w.find('.schedule-form__when-error').text()).toBe('I could not read that. Try for example: every weekday at 8:00.')
		expect(w.find('[data-testid="schedule-when-readback"]').exists()).toBe(false)
		expect([w.vm.form.kind, w.vm.form.cronExpr]).toEqual(['cron', '0 8 * * *'])
	})

	it('falls back to its own example when the server gives none', async () => {
		api.parseScheduleWhen.mockRejectedValueOnce(new Error('Network Error'))
		const w = await form()
		w.vm.whenText = 'soon'
		await w.vm.readWhen()

		expect(w.vm.whenError).toBe('I could not read that. Try for example: every weekday at 8:00.')
	})

	it('sends nothing for empty words', async () => {
		const w = await form()
		w.vm.whenText = '   '
		await w.vm.readWhen()

		expect(api.parseScheduleWhen).not.toHaveBeenCalled()
	})

	it('folds the raw rule fields for a new schedule and opens them for an existing one', async () => {
		const blank = await form()
		const existing = await form({ id: 's1', name: 'Daily', kind: 'cron', cronExpr: '0 7 * * *' })

		expect(blank.find('details.schedule-form__advanced').attributes('open')).toBeUndefined()
		expect(existing.find('details.schedule-form__advanced').attributes('open')).toBe('')
	})

	it('shows an unparseable run timestamp as given', async () => {
		const w = await form()

		expect(w.vm.formatRun('not a date')).toBe('not a date')
		expect(w.vm.formatRun('2026-11-16T08:00:00Z')).toContain('16')
	})
})
