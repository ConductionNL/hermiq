/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * "Run on a schedule" on the eval run panel (reuse-scheduled-bias-evals,
 * REQ-BIASEVAL-003): the frequencies, the request body, the form and the panel.
 *
 * @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-dataset-can-run-against-an-agent-on-a-schedule-req-biaseval-003
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { FREQUENCY_CRONS, frequencyLabel, frequencyOf, scheduleBody } from '../../src/utils/evalSchedules.js'

vi.mock('../../src/store/store.js', () => ({
	useAgentStore: () => ({}),
	useEvalRunStore: () => ({}),
	useEvalDatasetStore: () => ({}),
}))
vi.mock('../../src/api/skills.js', () => ({ listSkills: vi.fn() }))
vi.mock('../../src/api/evals.js', () => ({
	runEval: vi.fn(),
	useEvalTemplate: vi.fn(),
	listEvalSchedules: vi.fn(),
	saveEvalSchedule: vi.fn(),
}))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))

const evalsApi = await import('../../src/api/evals.js')
const dialogs = await import('@nextcloud/dialogs')
const { default: EvalRunPanelWidget } = await import('../../src/widgets/EvalRunPanelWidget.vue')
const { default: EvalScheduleFormModal } = await import('../../src/modals/EvalScheduleFormModal.vue')

/**
 * Translate as the identity, filling {placeholders}.
 *
 * @param {string} app The app id.
 * @param {string} text The text.
 * @param {object} vars The placeholders.
 * @return {string} The filled text.
 */
function t(app, text, vars = {}) {
	return text.replace(/\{(\w+)\}/g, (match, key) => String(vars[key] ?? match))
}

describe('eval schedule frequencies', () => {
	it('reads a stored expression as a frequency, and an empty one as monthly', () => {
		expect(frequencyOf('0 6 1 * *')).toBe('monthly')
		expect(frequencyOf('')).toBe('monthly')
		expect(frequencyOf('0 6 * * 1')).toBe('weekly')
		expect(frequencyOf('0 6 * * *')).toBe('daily')
		expect(frequencyOf('*/5 * * * *')).toBe('custom')
	})

	it('labels a custom schedule with its expression', () => {
		expect(frequencyLabel(t, '0 6 1 * *')).toBe('Monthly, on the 1st at 06:00 UTC')
		expect(frequencyLabel(t, '15 3 * * 2')).toBe('Custom: 15 3 * * 2')
	})

	it('builds the request body, keeping a custom expression the form does not offer', () => {
		expect(scheduleBody({ agentId: 'a1', frequency: 'monthly', enabled: true, threshold: '10' })).toEqual({
			agentId: 'a1',
			cronExpr: FREQUENCY_CRONS.monthly,
			enabled: true,
			regressionThresholdPercent: 10,
		})
		expect(scheduleBody({ agentId: 'a1', frequency: 'custom', enabled: false, threshold: '' }, '15 3 * * 2')).toEqual({
			agentId: 'a1',
			cronExpr: '15 3 * * 2',
			enabled: false,
			regressionThresholdPercent: null,
		})
	})
})

describe('EvalScheduleFormModal', () => {
	it('starts from the schedule being changed', () => {
		const data = EvalScheduleFormModal.data.call({
			agentOptions: [{ label: 'Helper', value: 'a1' }],
			schedule: { agentId: 'a1', cronExpr: '0 6 * * 1', regressionThresholdPercent: 10, enabled: false },
		})
		expect(data).toEqual({ agent: { label: 'Helper', value: 'a1' }, frequency: 'weekly', threshold: 10, enabled: false })
	})

	it('hands the panel the request body', () => {
		const ctx = { agent: { value: 'a1' }, frequency: 'monthly', enabled: true, threshold: '', schedule: null, $emit: vi.fn() }
		EvalScheduleFormModal.methods.submit.call(ctx)
		expect(ctx.$emit).toHaveBeenCalledWith('save', { agentId: 'a1', cronExpr: '0 6 1 * *', enabled: true, regressionThresholdPercent: null })
	})

	it('offers keeping a custom expression only for a schedule that has one', () => {
		const options = EvalScheduleFormModal.computed.frequencyOptions
		expect(options.call({ t, schedule: null }).map((o) => o.value)).toEqual(['monthly', 'weekly', 'daily'])
		expect(options.call({ t, schedule: { cronExpr: '15 3 * * 2' } }).map((o) => o.value)).toEqual(['monthly', 'weekly', 'daily', 'custom'])
	})
})

describe('EvalRunPanelWidget schedules', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	/**
	 * A panel context on dataset ds-1.
	 *
	 * @param {object|null} schedule The schedule the form changes.
	 * @return {object} The context.
	 */
	function context(schedule = null) {
		return {
			...EvalRunPanelWidget.methods,
			t,
			datasetId: 'ds-1',
			schedules: [],
			scheduleForm: { open: true, schedule, busy: false },
			agentOptions: [{ label: 'Permit intake helper', value: 'a1' }],
		}
	}

	it('saves a new schedule, closes the form and shows it with its next run', async () => {
		const stored = { id: 's1', agentId: 'a1', cronExpr: '0 6 1 * *', enabled: true, nextRun: '2026-12-01T06:00:00+00:00' }
		evalsApi.saveEvalSchedule.mockResolvedValue(stored)
		evalsApi.listEvalSchedules.mockResolvedValue([stored])
		const ctx = context()

		await EvalRunPanelWidget.methods.saveSchedule.call(ctx, { agentId: 'a1', cronExpr: '0 6 1 * *', enabled: true })

		expect(evalsApi.saveEvalSchedule).toHaveBeenCalledWith('ds-1', { agentId: 'a1', cronExpr: '0 6 1 * *', enabled: true }, '')
		expect(ctx.scheduleForm.open).toBe(false)
		expect(ctx.schedules).toEqual([stored])
		expect(ctx.scheduleStateLabel(stored)).toBe('Next run: ' + new Date(stored.nextRun).toLocaleString())
		expect(ctx.agentName('a1')).toBe('Permit intake helper')
	})

	it('changes the schedule it was opened for', async () => {
		evalsApi.saveEvalSchedule.mockResolvedValue({})
		evalsApi.listEvalSchedules.mockResolvedValue([])
		const ctx = context({ id: 's1' })

		await EvalRunPanelWidget.methods.saveSchedule.call(ctx, { agentId: 'a1' })

		expect(evalsApi.saveEvalSchedule).toHaveBeenCalledWith('ds-1', { agentId: 'a1' }, 's1')
	})

	it('keeps the form open and shows the refusal', async () => {
		evalsApi.saveEvalSchedule.mockRejectedValue({ response: { data: { error: 'Not found' } } })
		const ctx = context()

		await EvalRunPanelWidget.methods.saveSchedule.call(ctx, { agentId: 'a2' })

		expect(dialogs.showError).toHaveBeenCalledWith('Not found')
		expect(ctx.scheduleForm.open).toBe(true)
		expect(ctx.scheduleForm.busy).toBe(false)
	})

	it('shows a switched-off schedule as off, and no schedules when the read fails', async () => {
		const ctx = context()
		expect(ctx.scheduleStateLabel({ enabled: false })).toBe('Off')

		evalsApi.listEvalSchedules.mockRejectedValue(new Error('404'))
		await EvalRunPanelWidget.methods.loadSchedules.call(ctx)
		expect(ctx.schedules).toEqual([])
	})
})
