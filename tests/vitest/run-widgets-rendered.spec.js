/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The two agent run widgets fully mounted, so the rendered states — a failed
 * run list, an expanded trace, a replay preview, a run error, a dry-run
 * preview, a configured webhook, a crossed budget threshold — and the buttons
 * inside them are exercised through the template rather than through the
 * component instance.
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const toasts = []
vi.mock('@nextcloud/dialogs', () => ({
	showError: (m) => toasts.push(['error', m]),
	showSuccess: (m) => toasts.push(['success', m]),
}))
vi.mock('@nextcloud/event-bus', () => ({ emit: vi.fn(), subscribe: vi.fn(), unsubscribe: vi.fn() }))
vi.mock('../../src/api/agents.js', () => ({ getRunTrace: vi.fn(), listRuns: vi.fn(), replayRun: vi.fn(), runScheduleNow: vi.fn(), dryRunSchedule: vi.fn() }))
vi.mock('../../src/api/budgets.js', () => ({ getBudgetEstimate: vi.fn(), getBudgetStatus: vi.fn() }))
vi.mock('../../src/api/webhooks.js', () => ({ createWebhookSecret: vi.fn(), getWebhookStatus: vi.fn(), revokeWebhookSecret: vi.fn(), rotateWebhookSecret: vi.fn() }))
const scheduleStore = { registerObjectType: vi.fn(), fetchCollection: vi.fn() }
vi.mock('../../src/store/store.js', () => ({ useScheduleStore: () => scheduleStore }))

const agents = await import('../../src/api/agents.js')
const budgets = await import('../../src/api/budgets.js')
const webhooks = await import('../../src/api/webhooks.js')
const { default: History } = await import('../../src/widgets/AgentRunHistoryWidget.vue')
const { default: Operations } = await import('../../src/widgets/AgentRunOperationsWidget.vue')

/**
 * Translate as the identity.
 *
 * @param {string} app The app id.
 * @param {string} text The text.
 * @return {string} The text.
 */
const t = (app, text) => text

/**
 * Fully mount a widget for agent a1.
 *
 * @param {object} component The widget.
 * @return {Promise<object>} The wrapper and the router double.
 */
async function mountWidget(component) {
	const router = { push: vi.fn(async () => {}) }
	const w = mount(component, {
		global: {
			mocks: { t, $route: { params: { id: 'a1' } }, $router: router },
			stubs: { ScheduleFormModal: true, WebhookSecretDialog: true, Replay: true, BeakerOutline: true, Play: true },
		},
	})
	await flushPromises()
	return { w, router }
}

/**
 * The stand-in button whose text contains the given label.
 *
 * @param {object} w The wrapper.
 * @param {string} label The label.
 * @param {number} index Which match to take.
 * @return {object} The button component wrapper.
 */
function button(w, label, index = 0) {
	const found = w.findAllComponents({ name: 'NcButton' }).filter((b) => b.text().includes(label))
	expect(found.length).toBeGreaterThan(index)
	return found[index]
}

/**
 * Click a stand-in button and let the handler settle.
 *
 * @param {object} w The wrapper.
 * @param {string} label The label.
 * @param {number} index Which match to take.
 * @return {Promise<void>}
 */
async function click(w, label, index = 0) {
	button(w, label, index).vm.$emit('click')
	await flushPromises()
}

beforeEach(() => {
	toasts.length = 0
	vi.clearAllMocks()
})

describe('AgentRunHistoryWidget, rendered', () => {
	beforeEach(() => {
		scheduleStore.fetchCollection.mockResolvedValue([{ id: 's1', agentId: 'a1' }])
		agents.listRuns.mockResolvedValue([
			{ id: 'r1', status: 'dead_letter', attempt: 2, durationMs: 1200 },
			{ id: 'r2', status: 'ok' },
		])
	})

	it('shows the failure note when the run list cannot be loaded', async () => {
		agents.listRuns.mockRejectedValueOnce(new Error('x'))
		const { w } = await mountWidget(History)
		expect(w.find('nc-notecard').text()).toBe('Could not load run history.')
		expect(w.find('table').exists()).toBe(false)
	})

	it('expands a trace from its Details button, lists its steps and downloads it', async () => {
		agents.getRunTrace.mockResolvedValueOnce({
			toolStepsAvailable: false,
			steps: [{ seq: 1, type: 'tool', name: 'search', durationMs: 50, outcome: 'error' }],
		})
		const { w } = await mountWidget(History)
		await click(w, 'Details')
		expect(agents.getRunTrace).toHaveBeenCalledWith('s1', 'r1')
		expect(button(w, 'Hide details').exists()).toBe(true)
		expect(w.find('.agent-run-history-widget__trace-hint').exists()).toBe(true)
		expect(w.find('.agent-run-history-widget__trace-step-name').text()).toBe('search')
		expect(w.find('.agent-run-history-widget__trace-step .agent-run-history-widget__badge--error').text()).toBe('error')

		URL.createObjectURL = vi.fn(() => 'blob:x')
		URL.revokeObjectURL = vi.fn()
		const anchor = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {})
		await click(w, 'Download trace (JSON)')
		expect(anchor).toHaveBeenCalledTimes(1)
		anchor.mockRestore()
	})

	it('shows a warning when an expanded trace cannot be loaded', async () => {
		agents.getRunTrace.mockRejectedValueOnce(new Error('x'))
		const { w } = await mountWidget(History)
		await click(w, 'Details', 1)
		expect(w.find('.agent-run-history-widget__trace-cell').text()).toBe('Could not load this run\'s trace.')
	})

	it('offers Re-run only on a dead-lettered run and runs the schedule from it', async () => {
		agents.runScheduleNow.mockResolvedValueOnce({ status: 'ok' })
		const { w } = await mountWidget(History)
		expect(w.findAllComponents({ name: 'NcButton' }).filter((b) => b.text() === 'Re-run')).toHaveLength(1)
		await click(w, 'Re-run')
		expect(agents.runScheduleNow).toHaveBeenCalledWith('s1')
		expect(toasts).toEqual([['success', 'Agent run started.']])
	})

	it('renders a replay preview with its outcome and steps, and dismisses it', async () => {
		let resolve
		agents.replayRun.mockReturnValueOnce(new Promise((r) => { resolve = r }))
		const { w } = await mountWidget(History)
		button(w, 'Replay', 1).vm.$emit('click')
		await w.vm.$nextTick()
		expect(button(w, 'Replay', 1).findComponent({ name: 'NcLoadingIcon' }).exists()).toBe(true)
		resolve({ diff: { changed: true }, replay: { steps: [{ seq: 1, type: 'tool', name: 'mail', outcome: 'would-have-called' }] } })
		await flushPromises()

		const preview = w.findAll('.agent-run-history-widget__trace-cell').at(-1)
		expect(preview.text()).toContain('Replay preview')
		expect(preview.text()).toContain('The replay produced a DIFFERENT outcome than the original run.')
		expect(preview.find('.agent-run-history-widget__badge--warn').text()).toBe('would have called')
		await click(w, 'Dismiss')
		expect(w.text()).not.toContain('Replay preview')
	})

	it('says when a replay produced the same outcome and recorded no steps', async () => {
		agents.replayRun.mockResolvedValueOnce({ diff: { changed: false }, steps: [] })
		const { w } = await mountWidget(History)
		await click(w, 'Replay')
		expect(w.text()).toContain('The replay produced the same outcome as the original run.')
		expect(w.text()).toContain('No step detail recorded for this preview.')
	})

	it('opens the run list to compare with the clicked run', async () => {
		const { w, router } = await mountWidget(History)
		await click(w, 'Compare with…', 1)
		expect(router.push).toHaveBeenCalledWith({ path: '/runs', query: { compareWith: 'r2' } })
	})
})

describe('AgentRunOperationsWidget, rendered', () => {
	beforeEach(() => {
		scheduleStore.fetchCollection.mockResolvedValue([{ id: 's1', agentId: 'a1', kind: 'cron', cronExpr: '0 * * * *' }])
		budgets.getBudgetEstimate.mockRejectedValue(new Error('none'))
		budgets.getBudgetStatus.mockResolvedValue({ configured: true, softThresholdReached: true, tokens: { used: 5, limit: 10, percent: 50 } })
		webhooks.getWebhookStatus.mockResolvedValue({ configured: true, enabled: true, secretPrefix: 'whs_ab' })
	})

	it('treats a failed budget estimate as none and shows a crossed soft threshold', async () => {
		const { w } = await mountWidget(Operations)
		expect(w.vm.estimate).toBeNull()
		expect(w.text()).toContain('Soft threshold crossed for the current period.')
		expect(w.text()).toContain('5 / 10 (50%)')
	})

	it('opens the schedule form from its button', async () => {
		const { w } = await mountWidget(Operations)
		await click(w, 'Edit schedule')
		expect(w.findComponent({ name: 'ScheduleFormModal' }).props('show')).toBe(true)
		w.findComponent({ name: 'ScheduleFormModal' }).vm.$emit('close')
		await flushPromises()
		expect(w.vm.showScheduleForm).toBe(false)
	})

	it('shows a run error and dismisses it', async () => {
		agents.runScheduleNow.mockResolvedValueOnce({ status: 'error', error: 'Model offline' })
		const { w } = await mountWidget(Operations)
		// The first "Run now" is the by-hand start above; the schedule's own is the second.
		await click(w, 'Run now', 1)
		expect(agents.runScheduleNow).toHaveBeenCalledTimes(1)
		const note = w.findAllComponents({ name: 'NcNoteCard' }).find((n) => n.text().includes('Model offline'))
		expect(note).toBeTruthy()
		await click(w, 'Dismiss')
		expect(w.vm.runError).toBe('')
		expect(w.text()).not.toContain('Model offline')
	})

	it('renders a dry-run preview and dismisses it', async () => {
		agents.dryRunSchedule.mockResolvedValueOnce({ steps: [{ seq: 1, type: 'tool', name: 'mail', outcome: 'would-have-called' }] })
		const { w } = await mountWidget(Operations)
		await click(w, 'Dry run')
		expect(w.text()).toContain('Nothing was changed — side-effecting tools were reported, not executed.')
		expect(w.find('.agent-run-ops-widget__badge--warn').text()).toBe('would have called')
		await click(w, 'Dismiss')
		expect(w.vm.previewResult).toBeNull()
		expect(w.text()).not.toContain('Dry-run preview')
	})

	it('rotates and revokes a configured webhook from its buttons', async () => {
		webhooks.rotateWebhookSecret.mockResolvedValueOnce({ secret: 'new' })
		webhooks.revokeWebhookSecret.mockResolvedValueOnce({})
		const { w } = await mountWidget(Operations)
		expect(w.text()).toContain('whs_ab…')
		await click(w, 'Rotate secret')
		expect(webhooks.rotateWebhookSecret).toHaveBeenCalledWith('a1')
		await click(w, 'Revoke')
		expect(webhooks.revokeWebhookSecret).toHaveBeenCalledWith('a1')
	})

	it('shows a spinner on Create webhook while one is being created', async () => {
		webhooks.getWebhookStatus.mockResolvedValue({ configured: false })
		let resolve
		webhooks.createWebhookSecret.mockReturnValueOnce(new Promise((r) => { resolve = r }))
		const { w } = await mountWidget(Operations)
		button(w, 'Create webhook').vm.$emit('click')
		await w.vm.$nextTick()
		expect(button(w, 'Create webhook').findComponent({ name: 'NcLoadingIcon' }).exists()).toBe(true)
		resolve({ secret: 's' })
		await flushPromises()
		expect(webhooks.createWebhookSecret).toHaveBeenCalledWith('a1')
	})
})
