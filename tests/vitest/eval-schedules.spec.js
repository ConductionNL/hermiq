/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * reuse-scheduled-bias-evals: the eval schedule and bias check labels, the
 * schedule form's request, the three API calls, and the run panel's "Use for
 * this agent" and "Run on a schedule" actions.
 *
 * @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'

const axiosPost = vi.fn()
const axiosGet = vi.fn()
const showError = vi.fn()
const showSuccess = vi.fn()

vi.mock('@nextcloud/axios', () => ({ default: { get: (...args) => axiosGet(...args), post: (...args) => axiosPost(...args) } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => '/index.php' + path }))
vi.mock('@nextcloud/dialogs', () => ({ showError: (...args) => showError(...args), showSuccess: (...args) => showSuccess(...args) }))
vi.mock('@nextcloud/l10n', () => ({
	t: (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (match, key) => String(vars[key] ?? match)),
	n: (app, one, many, count) => (count === 1 ? one : many),
}))
vi.mock('../../src/store/store.js', () => ({
	useAgentStore: () => ({}),
	useEvalRunStore: () => ({}),
	useEvalDatasetStore: () => ({}),
}))
vi.mock('../../src/api/skills.js', () => ({ listSkills: vi.fn() }))

const labels = await import('../../src/widgets/evalSchedules.js')
const api = await import('../../src/api/evals.js')
const { schedulePayload } = await import('../../src/modals/EvalScheduleFormModal.vue')
const { default: EvalRunPanelWidget } = await import('../../src/widgets/EvalRunPanelWidget.vue')

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

beforeEach(() => {
	axiosPost.mockReset()
	axiosGet.mockReset()
	showError.mockReset()
	showSuccess.mockReset()
})

describe('eval schedule labels', () => {
	it('names the offered frequencies and falls back to the expression', () => {
		expect(labels.frequencyOptions()).toEqual([
			{ value: '0 6 1 * *', label: 'Every month' },
			{ value: '0 6 1 */3 *', label: 'Every three months' },
			{ value: '0 6 * * 1', label: 'Every Monday' },
		])
		expect(labels.cronLabel('0 6 1 * *')).toBe('Every month')
		expect(labels.cronLabel('15 3 * * *')).toBe('15 3 * * *')
	})

	it('summarises a schedule with its agent and next run', () => {
		const agents = [{ uuid: 'agent-1', name: 'Permit intake helper' }]
		expect(labels.scheduleSummary({ agentId: 'agent-1', cronExpr: '0 6 1 * *', enabled: true, nextRun: '2026-10-01T06:00:00+00:00' }, agents))
			.toBe('Every month for Permit intake helper, next run 2026-10-01')
		expect(labels.scheduleSummary({ agentId: 'agent-9', cronExpr: '0 6 * * 1', enabled: false }, agents))
			.toBe('Every Monday for agent-9, switched off')
		expect(labels.scheduleSummary({ agentId: 'agent-1', cronExpr: '0 6 1 * *', nextRun: 'soon' }, agents))
			.toBe('Every month for Permit intake helper, next run —')
	})

	it('reads the factsheet bias check as a rate and a day, or as none', () => {
		expect(labels.biasCheckLabel({ runId: 'r', datasetId: 'd', passRate: 0.95, date: '2026-09-01T06:00:00+00:00' })).toBe('Bias check: 95% on 2026-09-01')
		expect(labels.biasCheckLabel(null)).toBe('No bias check run')
		expect(labels.biasCheckLabel({ passRate: '0.9' })).toBe('No bias check run')
	})
})

describe('the schedule form', () => {
	it('sends the agent, frequency, threshold and switch', () => {
		expect(schedulePayload({ agent: { value: 'agent-1' }, frequency: { value: '0 6 * * 1' }, threshold: '15', enabled: true }))
			.toEqual({ agentId: 'agent-1', cronExpr: '0 6 * * 1', enabled: true, regressionThresholdPercent: 15 })
		expect(schedulePayload({ id: 'sched-1', agent: { value: 'agent-1' }, frequency: null, threshold: '', enabled: false }))
			.toEqual({ id: 'sched-1', agentId: 'agent-1', cronExpr: '0 6 1 * *', enabled: false, regressionThresholdPercent: null })
	})
})

describe('the eval schedule API', () => {
	it('posts the copy, reads and saves schedules on the owner-guarded endpoints', async () => {
		axiosPost.mockResolvedValueOnce({ data: { id: 'copy-1' } })
		expect(await api.useEvalTemplateForAgent('tpl-en', 'agent-1')).toEqual({ id: 'copy-1' })
		expect(axiosPost).toHaveBeenLastCalledWith('/index.php/apps/hermiq/api/evals/tpl-en/use-for-agent', { agentId: 'agent-1' })

		axiosGet.mockResolvedValueOnce({ data: { results: [{ id: 's' }] } })
		expect(await api.listEvalSchedules('ds-1')).toEqual([{ id: 's' }])
		expect(axiosGet).toHaveBeenLastCalledWith('/index.php/apps/hermiq/api/evals/ds-1/schedules')
		axiosGet.mockResolvedValueOnce({ data: 'odd' })
		expect(await api.listEvalSchedules('ds-1')).toEqual([])

		axiosPost.mockResolvedValueOnce({ data: { id: 's', nextRun: 'x' } })
		expect(await api.saveEvalSchedule('ds-1', { agentId: 'agent-1' })).toEqual({ id: 's', nextRun: 'x' })
		expect(axiosPost).toHaveBeenLastCalledWith('/index.php/apps/hermiq/api/evals/ds-1/schedules', { agentId: 'agent-1' })
	})
})

describe('the run panel', () => {
	/**
	 * A panel context on dataset ds-1.
	 *
	 * @param {object} extra Fields over the defaults.
	 * @return {object} The context.
	 */
	function panel(extra = {}) {
		return {
			...EvalRunPanelWidget.methods,
			t,
			datasetId: 'ds-1',
			agents: [{ uuid: 'agent-1', name: 'Permit intake helper' }],
			schedules: [],
			scheduleForm: { schedule: null },
			savingSchedule: false,
			copying: false,
			selectedAgent: { value: 'agent-1' },
			$router: { push: vi.fn() },
			...extra,
		}
	}

	it('knows a template', () => {
		expect(EvalRunPanelWidget.computed.isTemplate.call({ dataset: { template: true } })).toBe(true)
		expect(EvalRunPanelWidget.computed.isTemplate.call({ dataset: { template: 'yes' } })).toBe(false)
		expect(EvalRunPanelWidget.computed.isTemplate.call({ dataset: null })).toBe(false)
	})

	it('copies a template for the selected agent and opens the copy', async () => {
		axiosPost.mockResolvedValueOnce({ data: { id: 'copy-1', name: 'Bias check — Helper', caseCount: 20 } })
		const ctx = panel()
		await EvalRunPanelWidget.methods.useForAgent.call(ctx)

		expect(axiosPost).toHaveBeenCalledWith('/index.php/apps/hermiq/api/evals/ds-1/use-for-agent', { agentId: 'agent-1' })
		expect(showSuccess).toHaveBeenCalledWith('"Bias check — Helper" was created with 20 cases.')
		expect(ctx.$router.push).toHaveBeenCalledWith({ name: 'EvalDatasetDetail', params: { id: 'copy-1' } })
		expect(ctx.copying).toBe(false)
	})

	it('shows the server\'s refusal when the copy is refused', async () => {
		axiosPost.mockRejectedValueOnce({ response: { data: { error: 'Agent not found' } } })
		const ctx = panel()
		await EvalRunPanelWidget.methods.useForAgent.call(ctx)

		expect(showError).toHaveBeenCalledWith('Agent not found')
		expect(ctx.$router.push).not.toHaveBeenCalled()
	})

	it('saves a schedule, closes the form and shows it with its next run', async () => {
		axiosPost.mockResolvedValueOnce({ data: { id: 's', agentId: 'agent-1', cronExpr: '0 6 1 * *', enabled: true, nextRun: '2026-10-01T06:00:00+00:00' } })
		axiosGet.mockResolvedValueOnce({ data: { results: [{ id: 's' }] } })
		const ctx = panel()
		await EvalRunPanelWidget.methods.saveSchedule.call(ctx, { agentId: 'agent-1', cronExpr: '0 6 1 * *' })

		expect(ctx.scheduleForm).toBe(null)
		expect(showSuccess).toHaveBeenCalledWith('Every month for Permit intake helper, next run 2026-10-01')
		expect(ctx.schedules).toEqual([{ id: 's' }])
		expect(ctx.savingSchedule).toBe(false)
	})

	it('keeps the form open when a non-owner is refused, and shows no schedules', async () => {
		axiosPost.mockRejectedValueOnce({ response: { data: { error: 'Dataset not found' } } })
		const ctx = panel()
		await EvalRunPanelWidget.methods.saveSchedule.call(ctx, { agentId: 'agent-1' })
		expect(showError).toHaveBeenCalledWith('Dataset not found')
		expect(ctx.scheduleForm).toEqual({ schedule: null })

		axiosGet.mockRejectedValueOnce(new Error('404'))
		ctx.schedules = [{ id: 'old' }]
		await EvalRunPanelWidget.methods.loadSchedules.call(ctx)
		expect(ctx.schedules).toEqual([])
	})
})
