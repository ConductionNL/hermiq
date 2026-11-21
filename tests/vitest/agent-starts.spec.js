/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * "How this agent starts" on the agent page
 * (scheduling-overview-and-start-choice D2): by hand, on a schedule and on an
 * event, each saying whether it is active; a run by hand without a schedule;
 * and "Add event" creating an inactive draft flow with an object trigger and
 * this agent's step, opened on the canvas.
 *
 * @spec openspec/changes/scheduling-overview-and-start-choice/specs/agent-schedule/spec.md#requirement-an-agents-page-offers-one-choice-of-how-it-starts-req-sched-002
 */

import axios from '@nextcloud/axios'
import { t } from '@nextcloud/l10n'
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const bus = []
vi.mock('@nextcloud/event-bus', () => ({ emit: (name) => bus.push(name), subscribe: vi.fn(), unsubscribe: vi.fn() }))
const toasts = []
vi.mock('@nextcloud/dialogs', () => ({ showError: (m) => toasts.push(['error', m]), showSuccess: (m) => toasts.push(['success', m]) }))
vi.mock('../../src/api/agents.js', () => ({ runScheduleNow: vi.fn(), dryRunSchedule: vi.fn() }))
vi.mock('../../src/api/budgets.js', () => ({ getBudgetEstimate: vi.fn(async () => null), getBudgetStatus: vi.fn(async () => null) }))
vi.mock('../../src/api/webhooks.js', () => ({ createWebhookSecret: vi.fn(), getWebhookStatus: vi.fn(), revokeWebhookSecret: vi.fn(), rotateWebhookSecret: vi.fn() }))
vi.mock('../../src/api/schedules.js', () => ({ listSchedules: vi.fn(), runAgentByHand: vi.fn(), parseScheduleWhen: vi.fn() }))
const scheduleStore = { registerObjectType: vi.fn(), fetchCollection: vi.fn(async () => []), saveObject: vi.fn() }
vi.mock('../../src/store/store.js', () => ({ useScheduleStore: () => scheduleStore }))

const webhooks = await import('../../src/api/webhooks.js')
const schedules = await import('../../src/api/schedules.js')
const { eventDraft, runsAgent } = await import('../../src/api/flows.js')
const { default: Operations } = await import('../../src/widgets/AgentRunOperationsWidget.vue')

const router = { push: vi.fn() }

/**
 * Mount the widget on agent a1's page.
 *
 * @return {Promise<object>} The wrapper.
 */
async function widget() {
	const w = mount(Operations, { global: { mocks: { t, $route: { params: { id: 'a1' } }, $router: router } } })
	await flushPromises()
	return w
}

/**
 * The text of one start row's state badge.
 *
 * @param {object} w The wrapper.
 * @param {string} row The row's test id suffix.
 * @return {string} The badge text.
 */
function badge(w, row) {
	return w.find(`[data-testid="agent-start-${row}"] .agent-run-ops-widget__badge`).text()
}

beforeEach(() => {
	vi.clearAllMocks()
	bus.length = 0
	toasts.length = 0
	schedules.listSchedules.mockResolvedValue([])
	webhooks.getWebhookStatus.mockResolvedValue({ configured: false })
	axios.get = vi.fn(async () => ({ data: { results: [] } }))
	axios.post = vi.fn()
})

describe('How this agent starts', () => {
	it('shows a schedule, a flow step and a webhook as three active rows', async () => {
		schedules.listSchedules.mockResolvedValue([{ id: 's1', name: 'Digest', when: 'Every weekday at 08:00', enabled: true }])
		webhooks.getWebhookStatus.mockResolvedValue({ configured: true, enabled: false })
		axios.get = vi.fn(async () => ({
			data: {
				results: [
					{ uuid: 'f1', name: 'On new objection', enabled: true, nodes: [{ type: 'hermiq.agent-step', config: { agentId: 'a1' } }] },
					{ uuid: 'f2', name: 'Other agent', enabled: true, nodes: [{ type: 'hermiq.agent-step', config: { agentId: 'a2' } }] },
				],
			},
		}))
		const w = await widget()

		expect(schedules.listSchedules).toHaveBeenCalledWith({ agentId: 'a1' })
		expect(axios.get.mock.calls[0][1]).toEqual({ params: { app: 'hermiq' } })
		expect([badge(w, 'manual'), badge(w, 'schedule'), badge(w, 'event')]).toEqual(['Active', 'Active', 'Active'])
		expect(w.find('[data-testid="agent-start-schedule"] li').text()).toContain('Digest')
		const events = w.findAll('[data-testid="agent-start-event"] li').map((li) => li.text())
		expect(events).toHaveLength(2)
		expect(events[0]).toContain('On new objection')
		expect(events[1]).toContain('Revoked')
	})

	it('marks the schedule and event rows not active when nothing is on', async () => {
		schedules.listSchedules.mockResolvedValue([{ id: 's1', name: 'Old', enabled: false }])
		const w = await widget()

		expect([badge(w, 'schedule'), badge(w, 'event')]).toEqual(['Not active', 'Not active'])
	})

	it('runs the agent by hand with the prompt and refreshes the run history', async () => {
		schedules.runAgentByHand.mockResolvedValueOnce({ status: 'ok', trigger: 'manual', output: 'Done' })
		const w = await widget()
		w.vm.manualPrompt = '  Summarise the open objections '
		await w.vm.runByHand()

		expect(schedules.runAgentByHand).toHaveBeenCalledWith('a1', 'Summarise the open objections')
		expect(scheduleStore.saveObject).not.toHaveBeenCalled()
		expect(w.vm.manualMessage).toBe('The run finished. It is in the run history.')
		expect(bus).toContain('cn:page:refresh')
	})

	it('says which gate held a run by hand, and the server error otherwise', async () => {
		const w = await widget()
		w.vm.manualPrompt = 'Go'
		schedules.runAgentByHand.mockRejectedValueOnce({ response: { status: 423, data: { status: 'skipped_killswitch' } } })
		await w.vm.runByHand()
		expect(w.vm.manualMessage).toBe('The emergency stop is on for this organisation, so the agent did not run.')

		schedules.runAgentByHand.mockRejectedValueOnce({ response: { status: 404, data: { error: 'Agent not found' } } })
		await w.vm.runByHand()
		expect(w.vm.manualMessage).toBe('Agent not found')

		schedules.runAgentByHand.mockRejectedValueOnce(new Error('offline'))
		await w.vm.runByHand()
		expect(w.vm.manualMessage).toBe('The run failed.')
	})

	it('runs nothing for an empty prompt', async () => {
		const w = await widget()
		w.vm.manualPrompt = '   '
		await w.vm.runByHand()

		expect(schedules.runAgentByHand).not.toHaveBeenCalled()
	})

	it('creates an inactive draft flow with a trigger and this agent step and opens it on the canvas', async () => {
		axios.post = vi.fn(async (url, body) => ({ data: { uuid: 'new-flow', ...body } }))
		const w = await widget()
		await w.vm.addObjectEvent()

		const body = axios.post.mock.calls[0][1]
		expect(body.enabled).toBe(false)
		expect(body.nodes.map((n) => n.type)).toEqual(['openregister.trigger-object', 'hermiq.agent-step', 'openregister.end'])
		expect(body.nodes[1].config.agentId).toBe('a1')
		expect(body.edges.map((e) => [e.from, e.to])).toEqual([['trigger', 'agent'], ['agent', 'end']])
		expect(router.push).toHaveBeenCalledWith({ name: 'FlowDetail', params: { id: 'new-flow' } })
	})

	it('reports a refused draft and stays on the page', async () => {
		axios.post = vi.fn(async () => {
			throw { response: { data: { error: 'You may not create flows.' } } }
		})
		const w = await widget()
		await w.vm.addObjectEvent()

		expect(toasts).toEqual([['error', 'You may not create flows.']])
		expect(router.push).not.toHaveBeenCalled()
		expect(w.vm.draftBusy).toBe(false)
	})

	it('adds a new schedule without losing the attached one', async () => {
		scheduleStore.fetchCollection.mockResolvedValueOnce([{ id: 's1', agentId: 'a1', kind: 'cron', cronExpr: '0 8 * * *' }])
		const w = await widget()
		w.vm.addSchedule()
		await flushPromises()

		const modal = w.findComponent({ name: 'ScheduleFormModal' })
		expect([w.vm.showScheduleForm, modal.props('schedule')]).toEqual([true, null])
		w.vm.closeScheduleForm()
		await flushPromises()
		expect([w.vm.showScheduleForm, w.vm.schedule.id, modal.props('schedule').id]).toEqual([false, 's1', 's1'])
	})
})

describe('the event draft', () => {
	it('subscribes to nothing until the person names a register and schema', () => {
		const trigger = eventDraft('a1', 'Draft').nodes[0]

		expect(trigger.config).toEqual({ event: 'object.created', register: '', schema: '' })
	})

	it('knows which flows run an agent', () => {
		expect(runsAgent({ nodes: [{ type: 'hermiq.agent-step', config: { agentId: 'a1' } }] }, 'a1')).toBe(true)
		expect(runsAgent({ nodes: [{ type: 'openregister.end', config: { agentId: 'a1' } }] }, 'a1')).toBe(false)
		expect(runsAgent({}, 'a1')).toBe(false)
	})
})
