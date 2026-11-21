/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The AI feature register (rows, publish readiness, lifecycle actions) and the
 * two agent run widgets (history with traces and replays; operations with
 * dry runs, run-now, budgets and webhooks), mounted with their API doubled.
 */

import { state } from '@nextcloud/initial-state'
import { flushPromises, shallowMount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const toasts = []
const bus = []
vi.mock('@nextcloud/dialogs', () => ({
	showError: (m) => toasts.push(['error', m]),
	showSuccess: (m) => toasts.push(['success', m]),
}))
vi.mock('@nextcloud/event-bus', () => ({
	emit: (name, payload) => bus.push(['emit', name, payload]),
	subscribe: (name, fn) => bus.push(['subscribe', name, fn]),
	unsubscribe: (name, fn) => bus.push(['unsubscribe', name, fn]),
}))
vi.mock('@conduction/nextcloud-vue', () => ({ CnDataTable: { name: 'CnDataTable', render: () => null } }))
vi.mock('../../src/api/aiFeatures.js', () => ({
	acknowledgeAiFeature: vi.fn(),
	disableAiFeature: vi.fn(),
	enableAiFeature: vi.fn(),
	listAiFeatures: vi.fn(),
	listFeatureResidency: vi.fn(),
	publishAiFeature: vi.fn(),
	withdrawAiFeature: vi.fn(),
}))
vi.mock('../../src/api/agents.js', () => ({ getRunTrace: vi.fn(), listRuns: vi.fn(), replayRun: vi.fn(), runScheduleNow: vi.fn(), dryRunSchedule: vi.fn() }))
vi.mock('../../src/api/budgets.js', () => ({ getBudgetEstimate: vi.fn(), getBudgetStatus: vi.fn() }))
vi.mock('../../src/api/webhooks.js', () => ({ createWebhookSecret: vi.fn(), getWebhookStatus: vi.fn(), revokeWebhookSecret: vi.fn(), rotateWebhookSecret: vi.fn() }))
const scheduleStore = { registerObjectType: vi.fn(), fetchCollection: vi.fn() }
vi.mock('../../src/store/store.js', () => ({ useScheduleStore: () => scheduleStore }))

const features = await import('../../src/api/aiFeatures.js')
const agents = await import('../../src/api/agents.js')
const budgets = await import('../../src/api/budgets.js')
const webhooks = await import('../../src/api/webhooks.js')
const { default: AiFeatureRegister } = await import('../../src/views/AiFeatureRegister.vue')
const { default: History } = await import('../../src/widgets/AgentRunHistoryWidget.vue')
const { default: Operations } = await import('../../src/widgets/AgentRunOperationsWidget.vue')

/**
 * Translate as the identity, filling {placeholders}.
 *
 * @param {string} app The app id.
 * @param {string} text The text.
 * @param {object} vars The placeholders.
 * @return {string} The filled text.
 */
const t = (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m))

const READY = {
	uuid: 'f1', slug: 'summary', name: 'Summary', riskCategory: 'high', lifecycle: 'enabled', dpoAckAt: '2026-01-01',
	doel: 'x', statutoryBasis: 'x', impactAssessments: ['dpia'], dataBronnen: ['x'], humanIntervention: 'x', responsible: 'x', publicatiecategorie: 'x',
}

beforeEach(() => {
	toasts.length = 0
	bus.length = 0
	vi.clearAllMocks()
	for (const key of Object.keys(state)) {
		delete state[key]
	}
	features.listAiFeatures.mockResolvedValue([READY, { slug: 'bare' }])
	features.listFeatureResidency.mockResolvedValue([{ slug: 'summary', provider: 'mistral', model: 'm', residency: 'eu', source: 'binding' }])
})

describe('AiFeatureRegister', () => {
	/**
	 * Mount the register.
	 *
	 * @return {Promise<object>} The wrapper.
	 */
	async function mountRegister() {
		state.is_admin = true
		const w = shallowMount(AiFeatureRegister, { global: { mocks: { t } } })
		await flushPromises()
		return w
	}

	it('lays out a row per feature with where it runs', async () => {
		const w = await mountRegister()
		expect(w.vm.isAdmin).toBe(true)
		expect(w.vm.opencatalogiAvailable).toBe(false)
		const [ready, bare] = w.vm.rows
		expect(ready).toMatchObject({ id: 'f1', name: 'Summary', riskCategory: 'high', lifecycle: 'enabled', acknowledged: true, provider: 'mistral', residency: 'eu', source: 'binding' })
		expect(bare).toMatchObject({ id: 'bare', name: 'bare', riskCategory: 'minimal', lifecycle: 'disabled', acknowledged: false, algoritmeregisterStatus: 'niet-gepubliceerd', provider: '', source: 'instance', residency: 'undeclared' })
		expect(w.vm.columns).toHaveLength(6)
	})

	it('names everything a feature still misses before it can be published', async () => {
		const w = await mountRegister()
		const [ready, bare] = w.vm.rows
		expect(w.vm.publishReady(ready)).toBe(true)
		expect(w.vm.missingConditions({ ...ready, feature: { ...READY, impactAssessments: [] } })).toEqual(['impactAssessments'])
		expect(w.vm.missingConditions(bare)).toEqual([
			'risk category must be high', 'feature must be enabled', 'DPO acknowledgement',
			'doel', 'statutoryBasis', 'impactAssessments', 'dataBronnen', 'humanIntervention', 'responsible', 'publicatiecategorie',
		])
		expect(w.vm.missingConditions({ riskCategory: 'high', lifecycle: 'enabled', acknowledged: true })).toHaveLength(7)
		expect(w.vm.publishBlockedReason({ ...ready, lifecycle: 'disabled' })).toBe('Cannot publish yet — missing: feature must be enabled')
	})

	it('labels providers, residency, risk, state and register status, passing unknown values through', async () => {
		const w = await mountRegister()
		expect(w.vm.providerLabel({ provider: '' })).toBe('The instance default')
		expect(w.vm.providerLabel({ provider: 'p', model: 'm' })).toBe('p · m')
		expect(['on-premise', 'eu', 'outside-eu', 'undeclared', 'mars'].map(w.vm.residencyLabel)).toEqual(['On premise', 'In the EU', 'Outside the EU', 'Not stated', 'mars'])
		expect(['minimal', 'limited', 'high', 'unacceptable', 'x'].map(w.vm.riskLabel)).toEqual(['Minimal', 'Limited', 'High', 'Unacceptable', 'x'])
		expect(['disabled', 'enabled', 'x'].map(w.vm.lifecycleLabel)).toEqual(['Disabled', 'Enabled', 'x'])
		expect(['niet-gepubliceerd', 'gepubliceerd', 'ingetrokken', 'x'].map(w.vm.algoritmeregisterLabel)).toEqual(['Not published', 'Published', 'Withdrawn', 'x'])
	})

	it.each([
		['doPublish', 'publishAiFeature', 'f1'],
		['doWithdraw', 'withdrawAiFeature', 'f1'],
		['doAcknowledge', 'acknowledgeAiFeature', 'summary'],
		['doEnable', 'enableAiFeature', 'f1'],
		['doDisable', 'disableAiFeature', 'f1'],
	])('%s calls %s and reloads, and shows a failure', async (method, call, id) => {
		const w = await mountRegister()
		features.listAiFeatures.mockClear()
		await w.vm[method](READY)
		expect(features[call]).toHaveBeenCalledWith(id)
		expect(features.listAiFeatures).toHaveBeenCalledTimes(1)
		features[call].mockRejectedValueOnce({ response: { data: { error: 'Refused' } } })
		await w.vm[method](READY)
		expect(w.vm.error).toBe('Refused')
		expect(w.vm.busy).toBe(false)
	})

	it('names the missing conditions the server reports on a refused publish', async () => {
		const w = await mountRegister()
		features.publishAiFeature.mockRejectedValueOnce({ response: { data: { missing: ['doel', 'responsible'] } } })
		await w.vm.doPublish(READY)
		expect(w.vm.error).toBe('Cannot publish yet — missing: doel, responsible')
		features.publishAiFeature.mockRejectedValueOnce({ response: { data: { missing: [] } } })
		await w.vm.doPublish(READY)
		expect(w.vm.error).toBe('Unknown error')
	})

	it('shows a load failure and reloads after a binding is saved', async () => {
		features.listAiFeatures.mockRejectedValueOnce(new Error('Register down'))
		const w = await mountRegister()
		expect(w.vm.error).toBe('Register down')
		w.vm.bindingRow = { id: 'f1' }
		await w.vm.onBindingSaved()
		expect(w.vm.bindingRow).toBeNull()
		expect(w.vm.error).toBe('')
		expect(w.vm.features).toHaveLength(2)
	})
})

/**
 * Mount a run widget for agent a1.
 *
 * @param {object} component The widget.
 * @return {Promise<object>} The wrapper and its router double.
 */
async function mountWidget(component) {
	const router = { push: vi.fn(async () => {}) }
	const w = shallowMount(component, { global: { mocks: { t, $route: { params: { id: 'a1' } }, $router: router } } })
	await flushPromises()
	return { w, router }
}

describe('AgentRunHistoryWidget', () => {
	beforeEach(() => {
		scheduleStore.fetchCollection.mockResolvedValue([{ id: 's0', agentId: 'other' }, { id: 's1', agentId: 'a1' }])
		agents.listRuns.mockResolvedValue([{ id: 'r1' }, { id: 'r2' }])
	})

	it('finds the agent\'s schedule, loads its runs and listens for page refreshes', async () => {
		const { w } = await mountWidget(History)
		expect(w.vm.schedule).toEqual({ id: 's1', agentId: 'a1' })
		expect(agents.listRuns).toHaveBeenCalledWith('s1')
		expect(w.vm.runs).toEqual([{ id: 'r1' }, { id: 'r2' }])
		const subscribed = bus.find((e) => e[0] === 'subscribe')
		expect(subscribed[1]).toBe('cn:page:refresh')
		agents.listRuns.mockClear()
		subscribed[2]()
		await flushPromises()
		expect(agents.listRuns).toHaveBeenCalledTimes(1)
		w.unmount()
		expect(bus.at(-1)).toEqual(['unsubscribe', 'cn:page:refresh', subscribed[2]])
	})

	it('has no runs without a schedule, and flags a failed run list', async () => {
		scheduleStore.fetchCollection.mockRejectedValueOnce(new Error('x'))
		const { w } = await mountWidget(History)
		expect([w.vm.schedule, w.vm.runs]).toEqual([null, []])
		w.vm.schedule = { id: 's1' }
		agents.listRuns.mockRejectedValueOnce(new Error('x'))
		await w.vm.loadRuns()
		expect([w.vm.runsError, w.vm.runs]).toEqual([true, []])
	})

	it('expands a run trace once, collapses it, and flags a failed trace', async () => {
		agents.getRunTrace.mockResolvedValueOnce({ steps: [1] })
		const { w } = await mountWidget(History)
		await w.vm.toggleRunTrace({ id: 'r1' })
		expect(w.vm.runTraces).toEqual({ r1: { steps: [1] } })
		await w.vm.toggleRunTrace({ id: 'r1' })
		expect(w.vm.expandedRunId).toBeNull()
		await w.vm.toggleRunTrace({ id: 'r1' })
		expect(agents.getRunTrace).toHaveBeenCalledTimes(1)
		agents.getRunTrace.mockRejectedValueOnce(new Error('x'))
		await w.vm.toggleRunTrace({ id: 'r2' })
		expect([w.vm.traceError, w.vm.traceLoading]).toEqual([true, false])
	})

	it('downloads a loaded trace as JSON, and nothing for an unloaded one', async () => {
		const { w } = await mountWidget(History)
		URL.createObjectURL = vi.fn(() => 'blob:x')
		URL.revokeObjectURL = vi.fn()
		const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {})
		w.vm.downloadTrace({ id: 'r1' })
		expect(click).not.toHaveBeenCalled()
		w.vm.runTraces = { r1: { a: 1 } }
		w.vm.downloadTrace({ id: 'r1' })
		expect(click).toHaveBeenCalledTimes(1)
		expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:x')
		click.mockRestore()
	})

	it('re-runs the schedule, reporting an engine error, a failure or a start', async () => {
		const { w } = await mountWidget(History)
		agents.runScheduleNow.mockResolvedValueOnce({ status: 'ok' })
		await w.vm.reRun()
		agents.runScheduleNow.mockResolvedValueOnce({ status: 'error', error: 'Model offline' })
		await w.vm.reRun()
		agents.runScheduleNow.mockResolvedValueOnce({ status: 'error' })
		await w.vm.reRun()
		agents.runScheduleNow.mockRejectedValueOnce({ response: { data: { message: 'Locked' } } })
		await w.vm.reRun()
		agents.runScheduleNow.mockRejectedValueOnce({})
		await w.vm.reRun()
		expect(toasts).toEqual([
			['success', 'Agent run started.'], ['error', 'Model offline'], ['error', 'The agent run reported an error.'],
			['error', 'Locked'], ['error', 'The agent run failed.'],
		])
		expect(bus.filter((e) => e[0] === 'emit')).toHaveLength(5)
		w.vm.schedule = null
		await w.vm.reRun()
		expect(agents.runScheduleNow).toHaveBeenCalledTimes(5)
	})

	it('replays a run without side effects and shows its steps', async () => {
		const { w } = await mountWidget(History)
		agents.replayRun.mockResolvedValueOnce({ replay: { steps: [{ type: 'llm' }] } })
		await w.vm.replay({ id: 'r1' })
		expect(w.vm.replayResultRunId).toBe('r1')
		expect(w.vm.replaySteps).toEqual([{ type: 'llm' }])
		w.vm.replayResult = { steps: 'x' }
		expect(w.vm.replaySteps).toEqual([])
		w.vm.dismissReplay()
		expect(w.vm.replaySteps).toEqual([])
		agents.replayRun.mockRejectedValueOnce(new Error('Replay down'))
		await w.vm.replay({ id: 'r1' })
		expect(toasts.at(-1)).toEqual(['error', 'Replay down'])
		await w.vm.replay(null)
		expect(agents.replayRun).toHaveBeenCalledTimes(2)
	})

	it('opens the run list to compare with a run', async () => {
		const { w, router } = await mountWidget(History)
		w.vm.compareWith({ id: 'r1' })
		expect(router.push).toHaveBeenCalledWith({ path: '/runs', query: { compareWith: 'r1' } })
	})

	it('labels durations, versions and statuses', async () => {
		const { w } = await mountWidget(History)
		expect([null, 'x', 420.4, 2500].map(w.vm.stepDurationLabel)).toEqual(['—', '—', '420ms', '2.5s'])
		expect([null, 'abc-def'].map(w.vm.shortVersionLabel)).toEqual(['—', 'abc'])
		expect([null, 2600].map(w.vm.durationLabel)).toEqual(['—', '3s'])
		expect(['error', 'skipped_budget', 'ok'].map(w.vm.statusBadgeClass)).toEqual([
			'agent-run-history-widget__badge--error', 'agent-run-history-widget__badge--warning', 'agent-run-history-widget__badge--ok',
		])
		expect(['skipped_killswitch', 'paused_circuit_breaker', 'custom', ''].map(w.vm.statusLabel)).toEqual(['Halted (kill-switch)', 'Paused (circuit breaker)', 'custom', '—'])
	})
})

describe('AgentRunOperationsWidget', () => {
	beforeEach(() => {
		scheduleStore.fetchCollection.mockResolvedValue([{ id: 's1', agentId: 'a1', kind: 'cron', cronExpr: '0 * * * *' }])
		budgets.getBudgetEstimate.mockResolvedValue({ tokens: 10 })
		budgets.getBudgetStatus.mockRejectedValue(new Error('none'))
		webhooks.getWebhookStatus.mockResolvedValue({ active: true })
	})

	it('loads the schedule, the budget estimate and the webhook status', async () => {
		const { w } = await mountWidget(Operations)
		expect(w.vm.schedule.id).toBe('s1')
		expect(budgets.getBudgetStatus).toHaveBeenCalledWith('', 'a1')
		expect([w.vm.estimate, w.vm.budgetStatus, w.vm.webhookStatus]).toEqual([{ tokens: 10 }, null, { active: true }])
		expect(w.vm.triggerLabel).toBe('Cron: 0 * * * *')
	})

	it('describes interval, once and missing triggers', async () => {
		const { w } = await mountWidget(Operations)
		w.vm.schedule = { kind: 'interval', intervalMinutes: 15 }
		expect(w.vm.triggerLabel).toBe('Every 15 minutes')
		w.vm.schedule = { kind: 'once', runAt: '' }
		expect(w.vm.triggerLabel).toMatch(/^Once: /)
		w.vm.schedule = null
		expect(w.vm.triggerLabel).toBe('—')
	})

	it('copes with no schedule and no webhook', async () => {
		scheduleStore.fetchCollection.mockRejectedValueOnce(new Error('x'))
		webhooks.getWebhookStatus.mockRejectedValueOnce(new Error('x'))
		const { w } = await mountWidget(Operations)
		expect([w.vm.schedule, w.vm.webhookStatus]).toEqual([null, null])
		await w.vm.dryRun()
		await w.vm.runNow()
		expect(agents.dryRunSchedule).not.toHaveBeenCalled()
		expect(agents.runScheduleNow).not.toHaveBeenCalled()
	})

	it('creates and rotates a webhook, showing the secret once', async () => {
		const { w } = await mountWidget(Operations)
		webhooks.createWebhookSecret.mockResolvedValueOnce({ secret: 'abc' })
		await w.vm.createWebhook()
		expect([w.vm.revealedSecret, w.vm.showWebhookSecretDialog]).toEqual(['abc', true])
		w.vm.closeWebhookSecretDialog()
		expect([w.vm.revealedSecret, w.vm.showWebhookSecretDialog]).toEqual(['', false])
		webhooks.rotateWebhookSecret.mockResolvedValueOnce({})
		await w.vm.rotateWebhook()
		expect(w.vm.revealedSecret).toBe('')
		await w.vm.revokeWebhook()
		expect(webhooks.revokeWebhookSecret).toHaveBeenCalledWith('a1')
		expect(toasts).toEqual([['success', 'Webhook created.'], ['success', 'Webhook secret rotated.'], ['success', 'Webhook revoked.']])
	})

	it('says so when a webhook action fails', async () => {
		const { w } = await mountWidget(Operations)
		webhooks.createWebhookSecret.mockRejectedValueOnce(new Error('x'))
		webhooks.rotateWebhookSecret.mockRejectedValueOnce(new Error('x'))
		webhooks.revokeWebhookSecret.mockRejectedValueOnce(new Error('x'))
		await w.vm.createWebhook()
		await w.vm.rotateWebhook()
		await w.vm.revokeWebhook()
		expect(toasts.map((e) => e[1])).toEqual(['Could not create the webhook.', 'Could not rotate the webhook secret.', 'Could not revoke the webhook.'])
		expect(w.vm.webhookBusy).toBe(false)
	})

	it('dry-runs the schedule and shows its steps, or the failure', async () => {
		const { w } = await mountWidget(Operations)
		agents.dryRunSchedule.mockResolvedValueOnce({ steps: [{ type: 'tool' }] })
		await w.vm.dryRun()
		expect(w.vm.previewSteps).toEqual([{ type: 'tool' }])
		w.vm.previewResult = {}
		expect(w.vm.previewSteps).toEqual([])
		agents.dryRunSchedule.mockRejectedValueOnce({ response: { data: { error: 'No prompt' } } })
		await w.vm.dryRun()
		expect([w.vm.runError, w.vm.previewSteps]).toEqual(['No prompt', []])
		expect(toasts.at(-1)).toEqual(['error', 'The dry run failed.'])
	})

	it('runs now, keeping the engine error or the failure on the widget', async () => {
		const { w } = await mountWidget(Operations)
		agents.runScheduleNow.mockResolvedValueOnce({ status: 'error', error: 'Quota' })
		await w.vm.runNow()
		expect(w.vm.runError).toBe('Quota')
		agents.runScheduleNow.mockResolvedValueOnce({ status: 'error' })
		await w.vm.runNow()
		expect(w.vm.runError).toBe('The agent run failed.')
		agents.runScheduleNow.mockRejectedValueOnce({ response: { data: { error: 'Locked' } } })
		await w.vm.runNow()
		expect(w.vm.runError).toBe('Locked')
		agents.runScheduleNow.mockResolvedValueOnce({ status: 'ok' })
		await w.vm.runNow()
		expect(w.vm.runError).toBe('')
		expect(toasts.at(-1)).toEqual(['success', 'Agent run started.'])
		expect(bus.filter((e) => e[0] === 'emit')).toHaveLength(4)
	})

	it('reloads and refreshes the page after a schedule is saved', async () => {
		const { w } = await mountWidget(Operations)
		scheduleStore.fetchCollection.mockClear()
		await w.vm.onScheduleSaved()
		expect(scheduleStore.fetchCollection).toHaveBeenCalledTimes(1)
		expect(bus.at(-1)).toEqual(['emit', 'cn:page:refresh', {}])
	})
})
