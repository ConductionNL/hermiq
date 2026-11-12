/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The tenant operations page (budgets, model policies, access review) and the
 * run list (filters, paging, comparison selection, labels), mounted with their
 * API doubled.
 */

import { state } from '@nextcloud/initial-state'
import { flushPromises, shallowMount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const toasts = []
vi.mock('@nextcloud/dialogs', () => ({
	showError: (m) => toasts.push(['error', m]),
	showSuccess: (m) => toasts.push(['success', m]),
}))
vi.mock('@conduction/nextcloud-vue', () => ({ CnDataTable: { name: 'CnDataTable', render: () => null } }))
vi.mock('../../src/api/budgets.js', () => ({ deleteBudget: vi.fn(), getBudgetStatus: vi.fn(), listBudgets: vi.fn() }))
vi.mock('../../src/api/modelPolicy.js', () => ({ listModelPoliciesWithDataUse: vi.fn(), updateModelPolicy: vi.fn() }))
vi.mock('../../src/api/tenantOps.js', () => ({ attestReviewed: vi.fn(), getAccessReview: vi.fn(), reassignAgent: vi.fn() }))
vi.mock('../../src/api/analytics.js', () => ({ listRuns: vi.fn() }))
const store = { registerObjectType: vi.fn(), fetchCollection: vi.fn() }
vi.mock('../../src/store/store.js', () => ({ useAgentStore: () => store }))

const budgets = await import('../../src/api/budgets.js')
const policy = await import('../../src/api/modelPolicy.js')
const ops = await import('../../src/api/tenantOps.js')
const analytics = await import('../../src/api/analytics.js')
const { default: TenantOps } = await import('../../src/views/TenantOps.vue')
const { default: Runs } = await import('../../src/views/Runs.vue')

/**
 * Translate as the identity, filling {placeholders}.
 *
 * @param {string} app The app id.
 * @param {string} text The text.
 * @param {object} vars The placeholders.
 * @return {string} The filled text.
 */
const t = (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m))
globalThis.t = t

beforeEach(() => {
	toasts.length = 0
	vi.clearAllMocks()
	for (const key of Object.keys(state)) {
		delete state[key]
	}
	budgets.listBudgets.mockResolvedValue([{ id: 'b1', agentId: 'a1' }, { id: 'b2' }])
	budgets.getBudgetStatus.mockImplementation(async (org, agentId) => {
		if (agentId === '') {
			throw new Error('no status')
		}
		return { tokens: { used: 10, limit: 100, percent: 10 } }
	})
	policy.listModelPoliciesWithDataUse.mockResolvedValue({ policies: [{ id: 'p1' }], dataUse: { openai: 'no-training' } })
	ops.getAccessReview.mockResolvedValue({ agents: [{ uuid: 'u1', name: 'Bot' }] })
})

/**
 * Mount the tenant page as a manager of the given organisations.
 *
 * @param {Array} organisations The managed organisations.
 * @param {boolean} canManage Whether the viewer may manage.
 * @return {Promise<object>} The wrapper.
 */
async function mountOps(organisations = [{ id: 'org1', label: 'Org one' }, { id: 'org2' }], canManage = true) {
	state.managed_organisations = organisations
	state.can_manage_killswitch = canManage
	const w = shallowMount(TenantOps, { global: { mocks: { t } } })
	await flushPromises()
	return w
}

describe('TenantOps loading', () => {
	it('loads review, budgets with their status, and policies for a manager', async () => {
		const w = await mountOps()
		expect(w.vm.selectedOrg).toBe('org1')
		expect(budgets.listBudgets).toHaveBeenCalledWith('org1')
		expect(w.vm.budgets).toEqual([
			{ id: 'b1', agentId: 'a1', status: { tokens: { used: 10, limit: 100, percent: 10 } } },
			{ id: 'b2', status: null },
		])
		expect(w.vm.modelPolicies).toEqual([{ id: 'p1' }])
		expect(w.vm.reviewAgents).toEqual([{ uuid: 'u1', name: 'Bot' }])
		expect(w.vm.reassignDrafts).toEqual({ u1: '' })
		expect(w.vm.orgOptions).toEqual([{ label: 'Org one', value: 'org1' }, { label: 'org2', value: 'org2' }])
		expect(w.vm.orgOption).toEqual({ label: 'Org one', value: 'org1' })
	})

	it('loads nothing for a viewer who may not manage, and copes with a non-list of organisations', async () => {
		const w = await mountOps('nope', false)
		expect(w.vm.organisations).toEqual([])
		expect(budgets.listBudgets).not.toHaveBeenCalled()
		expect(ops.getAccessReview).not.toHaveBeenCalled()
		expect(policy.listModelPoliciesWithDataUse).not.toHaveBeenCalled()
	})

	it('reloads budgets for another organisation, and none without one', async () => {
		const w = await mountOps()
		w.vm.orgOption = { value: 'org2' }
		await flushPromises()
		expect(budgets.listBudgets).toHaveBeenLastCalledWith('org2')
		w.vm.orgOption = null
		await flushPromises()
		expect(w.vm.budgets).toEqual([])
	})

	it('shows load errors from the server, the exception, or a fallback', async () => {
		budgets.listBudgets.mockRejectedValueOnce({ response: { data: { error: 'Budget store down' } } })
		policy.listModelPoliciesWithDataUse.mockRejectedValueOnce(new Error('Policies down'))
		ops.getAccessReview.mockRejectedValueOnce({})
		const w = await mountOps()
		expect(w.vm.budgetError).toBe('Budget store down')
		expect(w.vm.policyError).toBe('Policies down')
		expect(w.vm.reviewError).toBe('Unknown error')
		expect(w.vm.reviewLoading).toBe(false)
	})
})

describe('TenantOps budgets', () => {
	it('labels usage, limits and the absence of a limit', async () => {
		const w = await mountOps()
		expect(w.vm.budgetUsageLabel({ status: { tokens: { used: 5, limit: 50, percent: 10 } } })).toBe('5 / 50 tokens (10%)')
		expect(w.vm.budgetUsageLabel({ tokenLimit: 900 })).toBe('Limit: 900 tokens')
		expect(w.vm.budgetUsageLabel({ eurLimit: 20 })).toBe('Limit: €20')
		expect(w.vm.budgetUsageLabel({ status: { tokens: {} } })).toBe('No limit configured')
	})

	it('opens the form for a new or an existing budget and reloads after a save', async () => {
		const w = await mountOps()
		w.vm.openEditBudget({ id: 'b1' })
		expect([w.vm.editingBudget, w.vm.showBudgetForm]).toEqual([{ id: 'b1' }, true])
		w.vm.openCreateBudget()
		expect(w.vm.editingBudget).toBeNull()
		budgets.listBudgets.mockClear()
		await w.vm.onBudgetSaved()
		expect(budgets.listBudgets).toHaveBeenCalledTimes(1)
	})

	it('deletes a budget, or says it could not', async () => {
		const w = await mountOps()
		await w.vm.removeBudget({ id: 'b1' })
		expect(budgets.deleteBudget).toHaveBeenCalledWith('b1')
		expect(toasts).toContainEqual(['success', 'Budget deleted.'])
		budgets.deleteBudget.mockRejectedValueOnce(new Error('x'))
		await w.vm.removeBudget({ id: 'b1' })
		expect(toasts).toContainEqual(['error', 'Could not delete the budget.'])
	})
})

describe('TenantOps model policies', () => {
	it('summarises a policy, failing closed when nothing is allowed', async () => {
		const w = await mountOps()
		expect(w.vm.policySummary({})).toBe('No providers allowed (fail closed).')
		expect(w.vm.policySummary({ allowed: [{ provider: 'openai', models: ['gpt-a', 'gpt-b'] }, { provider: 'local' }], defaultModel: 'gpt-a', requireNoTraining: true }))
			.toBe('openai: gpt-a, gpt-b · local — default: gpt-a · only providers that never train on our data')
	})

	it('edits a policy as text, and closes the editor on a second toggle', async () => {
		const w = await mountOps()
		const p = { id: 'p1', allowed: [{ provider: 'openai', models: ['m1'] }, { provider: 'mistral', models: [] }], defaultModel: 'm1' }
		w.vm.togglePolicyEdit(p)
		expect(w.vm.policyDraft).toEqual({ allowedText: 'openai: m1\nmistral', defaultModel: 'm1', requireNoTraining: false })
		expect(w.vm.editingPolicyId).toBe('p1')
		expect(w.vm.refusedProviders).toEqual(['mistral'])
		w.vm.togglePolicyEdit(p)
		expect(w.vm.editingPolicyId).toBeNull()
		w.vm.togglePolicyEdit({ id: 'p2' })
		expect(w.vm.policyDraft.allowedText).toBe('')
	})

	it('saves the parsed allowlist and reloads', async () => {
		const w = await mountOps()
		w.vm.policyDraft = { allowedText: ' openai: a, b ,\n\nlocal\n', defaultModel: '', requireNoTraining: true }
		policy.listModelPoliciesWithDataUse.mockClear()
		await w.vm.savePolicy({ id: 'p1' })
		expect(policy.updateModelPolicy).toHaveBeenCalledWith('p1', {
			allowed: [{ provider: 'openai', models: ['a', 'b'] }, { provider: 'local', models: [] }],
			defaultModel: null,
			requireNoTraining: true,
		})
		expect(policy.listModelPoliciesWithDataUse).toHaveBeenCalledTimes(1)
		expect(w.vm.policySaving).toBe(false)
	})

	it('shows why a policy could not be saved', async () => {
		const w = await mountOps()
		policy.updateModelPolicy.mockRejectedValueOnce({ response: { data: { error: 'Invalid model' } } })
		await w.vm.savePolicy({ id: 'p1' })
		policy.updateModelPolicy.mockRejectedValueOnce({})
		await w.vm.savePolicy({ id: 'p1' })
		expect(toasts).toEqual([['error', 'Invalid model'], ['error', 'Could not save the model policy.']])
	})
})

describe('TenantOps access review', () => {
	it('lays out the review rows', async () => {
		ops.getAccessReview.mockResolvedValueOnce({ agents: [
			{ uuid: 'u1', tools: ['a', 'b'], enableRag: true, reassignmentFlag: true },
			{ uuid: 'u2', name: 'Two', owner: 'o', actingUser: 'x', lastRunAt: '2026-01-01T00:00:00Z', reviewedAt: '2026-02-01T00:00:00Z', reviewedBy: 'admin', enableRag: true, ragSearchMode: 'hybrid' },
		] })
		const w = await mountOps()
		const [one, two] = w.vm.reviewRows
		expect(one).toMatchObject({ name: 'u1', owner: '—', actingUser: '—', lastRunAt: 'Never', capabilities: 'a, b — RAG (default)', reviewState: 'Not yet reviewed', reassignmentFlag: true })
		expect(two.capabilities).toBe('No tools — RAG (hybrid)')
		expect(two.reviewState).toMatch(/\(admin\)$/)
		expect(two.reassignmentFlag).toBe(false)
		expect(w.vm.capabilitySummary({})).toBe('No tools')
		expect(w.vm.reviewColumns.map((c) => c.key)).toEqual(['name', 'owner', 'actingUser', 'lastRunAt', 'capabilities', 'reviewState', 'reassignmentFlag'])
	})

	it('attests a review and reloads, or shows the refusal', async () => {
		const w = await mountOps()
		await w.vm.markReviewed({ uuid: 'u1' })
		expect(ops.attestReviewed).toHaveBeenCalledWith('u1')
		expect(toasts).toContainEqual(['success', 'Agent marked as reviewed.'])
		ops.attestReviewed.mockRejectedValueOnce({ response: { data: { error: 'Not yours' } } })
		await w.vm.markReviewed({ uuid: 'u1' })
		ops.attestReviewed.mockRejectedValueOnce({})
		await w.vm.markReviewed({ uuid: 'u1' })
		expect(toasts.slice(-2)).toEqual([['error', 'Not yours'], ['error', 'Could not record the review.']])
		expect(w.vm.reviewBusyUuid).toBe('')
	})

	it('reassigns only to a named user, clearing the draft afterwards', async () => {
		const w = await mountOps()
		w.vm.reassignDrafts.u1 = '  '
		await w.vm.reassign({ uuid: 'u1' })
		expect(ops.reassignAgent).not.toHaveBeenCalled()
		w.vm.reassignDrafts.u1 = ' bob '
		await w.vm.reassign({ uuid: 'u1' })
		expect(ops.reassignAgent).toHaveBeenCalledWith('u1', 'bob')
		expect(w.vm.reassignDrafts.u1).toBe('')
		w.vm.reassignDrafts.u1 = 'eve'
		ops.reassignAgent.mockRejectedValueOnce({})
		await w.vm.reassign({ uuid: 'u1' })
		expect(toasts.at(-1)).toEqual(['error', 'Could not reassign the agent.'])
		w.vm.reassignDrafts.u1 = 'eve'
		ops.reassignAgent.mockRejectedValueOnce({ response: { data: { error: 'Unknown user' } } })
		await w.vm.reassign({ uuid: 'u1' })
		expect(toasts.at(-1)).toEqual(['error', 'Unknown user'])
	})
})

describe('Runs', () => {
	/**
	 * Mount the run list on a route.
	 *
	 * @param {object} query The route query.
	 * @return {Promise<object>} The wrapper and its router double.
	 */
	async function mountRuns(query = {}) {
		const router = { push: vi.fn(async () => {}), replace: vi.fn(async () => {}) }
		const w = shallowMount(Runs, { global: { mocks: { t, $route: { query }, $router: router } } })
		await flushPromises()
		return { w, router }
	}

	beforeEach(() => {
		analytics.listRuns.mockResolvedValue({ results: [{ id: 'r1', status: 'ok', objectId: 's1' }, { id: 'r2', status: 'failed', objectId: 's2' }, { id: 'r3' }], total: 120 })
		store.fetchCollection.mockResolvedValue([{ uuid: 'a1', name: 'One' }, { id: 'a2' }])
	})

	it('loads the first page and the agents, keeping a compare target from the route', async () => {
		const { w } = await mountRuns({ compareWith: 'r9', schedule: 's1' })
		expect(analytics.listRuns).toHaveBeenCalledWith({ agentId: '', status: '', limit: 50, offset: 0 })
		expect(w.vm.selected).toEqual(['r9'])
		expect(w.vm.visibleRuns.map((r) => r.id)).toEqual(['r1'])
		expect(w.vm.agentOptions).toEqual([{ label: 'One', value: 'a1' }, { label: 'a2', value: 'a2' }])
		expect(w.vm.statusOptions).toEqual([{ label: 'ok', value: 'ok' }, { label: 'failed', value: 'failed' }])
		expect(w.vm.rangeLabel).toBe('1 to 3 of 120')
		expect(w.vm.hasNextPage).toBe(true)
	})

	it('pages forward and back, never before the first page', async () => {
		const { w } = await mountRuns()
		w.vm.nextPage()
		await flushPromises()
		expect(analytics.listRuns).toHaveBeenLastCalledWith(expect.objectContaining({ offset: 50 }))
		w.vm.previousPage()
		w.vm.previousPage()
		await flushPromises()
		expect(w.vm.offset).toBe(0)
		w.vm.offset = 100
		expect(w.vm.hasNextPage).toBe(false)
	})

	it('filters by agent and status from the first page', async () => {
		const { w } = await mountRuns()
		w.vm.offset = 50
		w.vm.agentFilter = { value: 'a1' }
		await flushPromises()
		expect(analytics.listRuns).toHaveBeenLastCalledWith({ agentId: 'a1', status: '', limit: 50, offset: 0 })
		w.vm.statusFilter = { value: 'failed' }
		await flushPromises()
		expect(analytics.listRuns).toHaveBeenLastCalledWith({ agentId: 'a1', status: 'failed', limit: 50, offset: 0 })
		w.vm.reload()
		await flushPromises()
		expect(analytics.listRuns).toHaveBeenCalledTimes(4)
	})

	it('shows the load error and an empty list', async () => {
		analytics.listRuns.mockRejectedValueOnce({ response: { data: { error: 'Forbidden' } } })
		store.fetchCollection.mockRejectedValueOnce(new Error('x'))
		const { w } = await mountRuns()
		expect([w.vm.error, w.vm.runs, w.vm.total, w.vm.agents]).toEqual(['Forbidden', [], 0, []])
		expect(w.vm.rangeLabel).toBe('')
		analytics.listRuns.mockRejectedValueOnce(new Error('Network'))
		await w.vm.load()
		expect(w.vm.error).toBe('Network')
		analytics.listRuns.mockResolvedValueOnce({})
		await w.vm.load()
		expect([w.vm.runs, w.vm.total]).toEqual([[], 0])
	})

	it('selects two runs to compare, refuses a third, and opens the comparison', async () => {
		const { w, router } = await mountRuns()
		w.vm.toggleCompare('r1')
		w.vm.toggleCompare('r2')
		w.vm.toggleCompare('r3')
		expect([w.vm.selected, w.vm.selectionRefused]).toEqual([['r1', 'r2'], true])
		w.vm.openComparison()
		expect(router.push).toHaveBeenCalledWith({ path: '/runs/compare', query: { left: 'r1', right: 'r2' } })
		w.vm.openFlowComparison()
		expect(router.push).toHaveBeenLastCalledWith({ path: '/flow-runs/compare' })
	})

	it('clears the schedule filter and the query', async () => {
		const { w, router } = await mountRuns({ schedule: 's1' })
		w.vm.clearScheduleFilter()
		expect(w.vm.scheduleFilter).toBe('')
		expect(router.replace).toHaveBeenCalledWith({ query: {} })
		expect(w.vm.visibleRuns).toHaveLength(3)
	})

	it('formats times, durations, triggers, data use and tones', async () => {
		const { w } = await mountRuns()
		expect(w.vm.formatWhen('')).toBe('—')
		expect(w.vm.formatWhen('nope')).toBe('—')
		expect(w.vm.formatWhen('2026-01-01T00:00:00Z')).toBe(new Date('2026-01-01T00:00:00Z').toLocaleString())
		expect(w.vm.formatDuration(null)).toBe('—')
		expect(w.vm.formatDuration('x')).toBe('—')
		expect(w.vm.formatDuration(1530)).toBe('1.5s')
		expect([w.vm.triggerLabel('flow'), w.vm.triggerLabel('cron')]).toEqual(['Flow', 'Schedule'])
		expect(['zero-retention', 'no-training', 'may-train', undefined].map(w.vm.dataUseLabel))
			.toEqual(['Keeps nothing it is sent', 'Never trains on your data', 'May train on your data', 'Not declared'])
		expect(['ok', 'dead_letter', 'running'].map(w.vm.statusTone)).toEqual(['ok', 'error', 'neutral'])
	})
})
