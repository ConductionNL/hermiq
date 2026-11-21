/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The agent version history dialog, the eval dataset and context forms, the
 * web research settings and the approval inbox, mounted with their API and
 * stores doubled.
 */

import axios from '@nextcloud/axios'
import { flushPromises, shallowMount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const toasts = []
const user = { current: { uid: 'owner' } }
vi.mock('@nextcloud/auth', () => ({ getCurrentUser: () => user.current }))
vi.mock('@nextcloud/dialogs', () => ({
	showError: (m) => toasts.push(['error', m]),
	showSuccess: (m) => toasts.push(['success', m]),
}))
vi.mock('@conduction/nextcloud-vue', () => ({ CnMarkdownEditor: { name: 'CnMarkdownEditor', render: () => null } }))
vi.mock('../../src/api/agents.js', () => ({ listAgentVersions: vi.fn(), rollbackAgentVersion: vi.fn() }))
vi.mock('../../src/api/webResearch.js', () => ({ getWebResearchSettings: vi.fn(), patchWebResearchSettings: vi.fn() }))
vi.mock('../../src/api/approvals.js', () => ({ approveApproval: vi.fn(), listPendingApprovals: vi.fn() }))
const agentStore = { registerObjectType: vi.fn(), fetchObject: vi.fn(), fetchCollection: vi.fn() }
const datasetStore = { registerObjectType: vi.fn(), saveObject: vi.fn(), errors: {} }
const contextStore = { registerObjectType: vi.fn(), saveObject: vi.fn() }
const scheduleStore = { registerObjectType: vi.fn(), fetchCollection: vi.fn() }
vi.mock('../../src/store/store.js', () => ({
	useAgentStore: () => agentStore,
	useEvalDatasetStore: () => datasetStore,
	useContextStore: () => contextStore,
	useScheduleStore: () => scheduleStore,
}))

const agents = await import('../../src/api/agents.js')
const web = await import('../../src/api/webResearch.js')
const approvals = await import('../../src/api/approvals.js')
const { default: VersionHistory } = await import('../../src/dialogs/agents/AgentVersionHistoryDialog.vue')
const { default: EvalDatasetFormModal } = await import('../../src/modals/EvalDatasetFormModal.vue')
const { default: ContextFormModal } = await import('../../src/modals/ContextFormModal.vue')
const { default: WebResearchSettingsModal } = await import('../../src/modals/WebResearchSettingsModal.vue')
const { default: ApprovalInbox } = await import('../../src/views/ApprovalInbox.vue')

/**
 * Translate as the identity, filling {placeholders}.
 *
 * @param {string} app The app id.
 * @param {string} text The text.
 * @param {object} vars The placeholders.
 * @return {string} The filled text.
 */
const t = (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m))

/**
 * Mount a component and let its loads settle.
 *
 * @param {object} component The component.
 * @param {object} props Its props.
 * @return {Promise<object>} The wrapper.
 */
async function mountIt(component, props = {}) {
	const w = shallowMount(component, { props, global: { mocks: { t, $route: { params: { id: 'route-agent' } } } } })
	await flushPromises()
	return w
}

beforeEach(() => {
	toasts.length = 0
	vi.clearAllMocks()
	user.current = { uid: 'owner' }
	datasetStore.errors = {}
})

describe('AgentVersionHistoryDialog', () => {
	const versions = [{ id: 'v3', action: 'update' }, { id: 'v2', action: 'update' }, { id: 'v1', action: 'create' }]

	beforeEach(() => {
		agents.listAgentVersions.mockResolvedValue(versions)
		agentStore.fetchObject.mockResolvedValue({ owner: 'owner' })
	})

	it('loads the versions of the route agent when opened, and lets its owner roll back', async () => {
		const w = await mountIt(VersionHistory, { show: true })
		expect(agents.listAgentVersions).toHaveBeenCalledWith('route-agent')
		expect(w.vm.versions).toEqual(versions)
		expect(agentStore.fetchObject).toHaveBeenCalledWith('agent', 'route-agent')
		expect(w.vm.resolvedCanRollback).toBe(true)
		user.current = { uid: 'someone-else' }
		w.vm.agent = { owner: 'owner' }
		expect(w.vm.resolvedCanRollback).toBe(false)
	})

	it('takes an explicit rollback permission without asking the store', async () => {
		const w = await mountIt(VersionHistory, { show: true, agentId: 'a1', canRollback: false })
		expect(agents.listAgentVersions).toHaveBeenCalledWith('a1')
		expect(agentStore.fetchObject).not.toHaveBeenCalled()
		expect(w.vm.resolvedCanRollback).toBe(false)
	})

	it('denies rollback when the agent cannot be read, and shows a failed load', async () => {
		agentStore.fetchObject.mockRejectedValueOnce(new Error('x'))
		agents.listAgentVersions.mockRejectedValueOnce({ response: { data: { error: 'No access' } } })
		const w = await mountIt(VersionHistory, { show: true })
		expect([w.vm.agent, w.vm.resolvedCanRollback]).toEqual([null, false])
		expect([w.vm.error, w.vm.versions]).toEqual(['No access', []])
	})

	it('keeps at most two versions selected and compares the older against the newer', async () => {
		const w = await mountIt(VersionHistory, { show: true })
		w.vm.compare()
		expect(w.emitted('compare')).toBeUndefined()
		w.vm.toggleSelected('v3')
		w.vm.toggleSelected('v1')
		w.vm.toggleSelected('v2')
		expect(w.vm.selected).toEqual(['v1', 'v2'])
		expect(w.vm.isSelected('v3')).toBe(false)
		w.vm.compare()
		expect(w.emitted('compare')).toEqual([[{ from: 'v1', to: 'v2' }]])
		w.vm.toggleSelected('v1')
		expect(w.vm.selected).toEqual(['v2'])
	})

	it('rolls back to a version and reloads, or says it could not', async () => {
		const w = await mountIt(VersionHistory, { show: true, agentId: 'a1' })
		w.vm.confirmingId = 'v1'
		await w.vm.performRollback({ id: 'v1' })
		expect(agents.rollbackAgentVersion).toHaveBeenCalledWith('a1', 'v1')
		expect(w.vm.confirmingId).toBeNull()
		expect(w.emitted('rolled-back')).toHaveLength(1)
		agents.rollbackAgentVersion.mockRejectedValueOnce(new Error('x'))
		await w.vm.performRollback({ id: 'v1' })
		expect(toasts.at(-1)).toEqual(['error', 'Could not roll back the agent.'])
		expect(w.vm.rollingBackId).toBeNull()
	})

	it('labels actions and dates and closes when the dialog closes', async () => {
		const w = await mountIt(VersionHistory, { show: false })
		expect(agents.listAgentVersions).not.toHaveBeenCalled()
		expect([w.vm.actionLabel('create'), w.vm.actionLabel('update')]).toEqual(['Created', 'Updated'])
		expect([w.vm.formatDate(''), w.vm.formatDate('nope')]).toEqual(['—', '—'])
		w.vm.onUpdateOpen(true)
		expect(w.emitted('close')).toBeUndefined()
		w.vm.onUpdateOpen(false)
		expect(w.emitted('close')).toHaveLength(1)
	})
})

describe('EvalDatasetFormModal', () => {
	/**
	 * Mount the form closed, then open it.
	 *
	 * @param {object|null} dataset The dataset being edited.
	 * @return {Promise<object>} The wrapper.
	 */
	async function openForm(dataset = null) {
		const w = shallowMount(EvalDatasetFormModal, { props: { show: false, dataset }, global: { mocks: { t } } })
		await w.setProps({ show: true })
		return w
	}

	it('opens with one blank case, or with the cases of the dataset', async () => {
		const blank = await openForm()
		expect(blank.vm.heading).toBe('Create dataset')
		expect(blank.vm.form.cases).toHaveLength(1)
		const edit = await openForm({ id: 'd1', name: 'Set', cases: [{ prompt: 'P', expectationType: 'rubric', rubric: 'R' }] })
		expect(edit.vm.heading).toBe('Edit dataset')
		expect(edit.vm.form.cases[0]).toMatchObject({ prompt: 'P', expectationType: 'rubric', rubric: 'R', jsonPath: '' })
		const empty = await openForm({ name: 'Empty', cases: [] })
		expect(empty.vm.form.cases).toHaveLength(1)
	})

	it('adds and removes cases, always keeping one', async () => {
		const w = await openForm()
		w.vm.addCase()
		expect(w.vm.form.cases).toHaveLength(2)
		w.vm.removeCase(0)
		w.vm.removeCase(0)
		expect(w.vm.form.cases).toHaveLength(1)
	})

	it('picks the expectation option of a case, defaulting a cleared one to contains', async () => {
		const w = await openForm()
		const evalCase = w.vm.form.cases[0]
		w.vm.setExpectation(evalCase, { value: 'jsonPathEquals' })
		expect(w.vm.expectationOptionFor(evalCase).value).toBe('jsonPathEquals')
		w.vm.setExpectation(evalCase, null)
		expect(evalCase.expectationType).toBe('contains')
		expect(w.vm.expectationOptionFor({ expectationType: 'x' })).toBeNull()
	})

	it('sends only the fields each expectation needs, without the envelope', async () => {
		const w = await openForm({ id: 'd1', '@self': { x: 1 }, owner: 'o' })
		w.vm.form = {
			name: 'Set',
			cases: [
				{ prompt: 'a', expectationType: 'contains', expectedSubstring: 'x', rubric: 'ignored' },
				{ prompt: 'b', expectationType: 'notContains', expectedSubstring: 'y' },
				{ prompt: 'c', expectationType: 'jsonPathEquals', jsonPath: '$.a', expectedValue: '1' },
				{ prompt: 'd', expectationType: 'rubric', rubric: 'R' },
				{ prompt: 'e', expectationType: 'other' },
			],
		}
		expect(w.vm.buildPayload()).toEqual({
			id: 'd1', owner: 'o', name: 'Set',
			cases: [
				{ prompt: 'a', expectationType: 'contains', expectedSubstring: 'x' },
				{ prompt: 'b', expectationType: 'notContains', expectedSubstring: 'y' },
				{ prompt: 'c', expectationType: 'jsonPathEquals', jsonPath: '$.a', expectedValue: '1' },
				{ prompt: 'd', expectationType: 'rubric', rubric: 'R' },
				{ prompt: 'e', expectationType: 'other' },
			],
		})
	})

	it('saves and closes, or shows why not', async () => {
		const w = await openForm()
		datasetStore.saveObject.mockResolvedValueOnce({ id: 'n' })
		await w.vm.save()
		expect(datasetStore.saveObject.mock.calls[0][0]).toBe('evaldataset')
		expect(w.emitted('saved')).toEqual([[{ id: 'n' }]])
		datasetStore.saveObject.mockResolvedValueOnce(null)
		datasetStore.errors = { evaldataset: { message: 'Invalid' } }
		await w.vm.save()
		expect(w.vm.error).toBe('Invalid')
		datasetStore.saveObject.mockResolvedValueOnce(null)
		datasetStore.errors = {}
		await w.vm.save()
		expect(w.vm.error).toBe('Could not save dataset')
		datasetStore.saveObject.mockRejectedValueOnce(new Error('Network'))
		await w.vm.save()
		expect(w.vm.error).toBe('Network')
		expect(w.emitted('close')).toHaveLength(1)
	})
})

describe('ContextFormModal', () => {
	const item = { id: 'c1', '@self': {}, name: 'Ctx', documents: [{ name: 'Doc', body: 'B' }, {}], files: [{ path: ' /a.pdf ' }, { path: '' }], objectQueries: [1, 2] }

	it('opens the form from the item, keyed per row', async () => {
		const w = await mountIt(ContextFormModal, { item })
		expect(w.vm.heading).toBe('Edit context')
		expect(w.vm.queryCount).toBe(2)
		expect(w.vm.form.name).toBe('Ctx')
		expect(w.vm.form.documents.map((d) => [d.name, d.body])).toEqual([['Doc', 'B'], ['', '']])
		const keys = [...w.vm.form.documents, ...w.vm.form.files].map((r) => r.key)
		expect(new Set(keys).size).toBe(4)
		const blank = await mountIt(ContextFormModal)
		expect([blank.vm.heading, blank.vm.queryCount, blank.vm.form.documents]).toEqual(['New context', 0, []])
	})

	it('drops empty documents and files from the payload, without the row keys', async () => {
		const w = await mountIt(ContextFormModal, { item })
		w.vm.addDocument()
		w.vm.form.name = ' Named '
		const payload = w.vm.buildPayload()
		expect(payload).toMatchObject({ id: 'c1', name: 'Named', objectQueries: [1, 2] })
		expect(payload).not.toHaveProperty('@self')
		expect(payload.documents).toEqual([{ format: 'markdown', name: 'Doc', body: 'B' }])
		expect(payload.files).toEqual([{ path: '/a.pdf' }])
		w.vm.removeDocument(0)
		expect(w.vm.form.documents).toHaveLength(2)
	})

	it('reloads the form when reopened or given another item', async () => {
		const w = await mountIt(ContextFormModal, { item, show: false })
		w.vm.form.name = 'changed'
		await w.setProps({ show: true })
		expect(w.vm.form.name).toBe('Ctx')
		await w.setProps({ item: { name: 'Other' } })
		expect(w.vm.form.name).toBe('Other')
	})

	it('saves and closes through the slot close, or shows the refusal', async () => {
		const close = vi.fn()
		const w = await mountIt(ContextFormModal, { item, close })
		contextStore.saveObject.mockResolvedValueOnce({ id: 'c1' })
		await w.vm.save()
		expect(contextStore.saveObject.mock.calls[0][0]).toBe('context')
		expect(w.emitted('saved')).toEqual([[{ id: 'c1' }]])
		expect(close).toHaveBeenCalledTimes(1)
		contextStore.saveObject.mockResolvedValueOnce(null)
		await w.vm.save()
		expect(w.vm.error).toBe('The server did not accept the context.')
		contextStore.saveObject.mockRejectedValueOnce({})
		await w.vm.save()
		expect(w.vm.error).toBe('The server did not accept the context.')
		expect(close).toHaveBeenCalledTimes(1)
	})
})

describe('WebResearchSettingsModal', () => {
	beforeEach(() => {
		vi.spyOn(axios, 'get').mockResolvedValue({ data: { results: [{ id: 'c1', name: 'Search key' }, { id: 'c2' }] } })
	})

	it('loads the configuration and the broker credentials when shown', async () => {
		web.getWebResearchSettings.mockResolvedValueOnce({
			searchProvider: 'searxng', searchEndpoint: 'https://s', allowInsecureHttp: true,
			searchFieldMapping: { resultsPath: 'hits' }, fetchAllowlist: ['a.nl', 'b.nl'], maxResponseBytes: 1000, searchCredentialConfigured: true,
		})
		const w = await mountIt(WebResearchSettingsModal, { show: true })
		expect(w.vm.selectedProvider.value).toBe('searxng')
		expect(w.vm.providerValue).toBe('searxng')
		expect(w.vm.form).toEqual({ searchEndpoint: 'https://s', allowInsecureHttp: true, searchFieldMapping: { resultsPath: 'hits', titleField: 'title', urlField: 'url', snippetField: 'content' } })
		expect([w.vm.allowlistText, w.vm.denylistText, w.vm.maxResponseBytesText, w.vm.timeoutSecondsText]).toEqual(['a.nl\nb.nl', '', '1000', '10'])
		expect(w.vm.credentialOptions).toEqual([{ label: 'Search key', value: 'c1' }, { label: 'c2', value: 'c2' }])
	})

	it('falls back to no provider, no credentials, and shows a failed load', async () => {
		axios.get.mockRejectedValueOnce(new Error('x'))
		web.getWebResearchSettings.mockResolvedValueOnce({ searchProvider: 'gone' })
		const w = await mountIt(WebResearchSettingsModal, { show: true })
		expect([w.vm.selectedProvider.value, w.vm.credentials]).toEqual(['', []])
		web.getWebResearchSettings.mockRejectedValueOnce(new Error('x'))
		await w.setProps({ show: false })
		await w.setProps({ show: true })
		await flushPromises()
		expect(w.vm.error).toBe('Could not load the current web-research configuration.')
		w.vm.selectedProvider = null
		expect(w.vm.providerValue).toBeNull()
	})

	it('saves the parsed lists and numbers, with the chosen credential', async () => {
		web.getWebResearchSettings.mockResolvedValueOnce({})
		const w = await mountIt(WebResearchSettingsModal, { show: true })
		w.vm.selectedProvider = { value: 'generic-json' }
		w.vm.allowlistText = ' a.nl \n\n b.nl'
		w.vm.maxResponseBytesText = 'lots'
		w.vm.timeoutSecondsText = '30'
		w.vm.searchCredential = { value: 'c1' }
		await w.vm.save()
		expect(web.patchWebResearchSettings).toHaveBeenCalledWith(expect.objectContaining({
			searchProvider: 'generic-json', fetchAllowlist: ['a.nl', 'b.nl'], fetchDenylist: [], maxResponseBytes: 500000, timeoutSeconds: 30, searchCredentialId: 'c1',
		}))
		expect(w.emitted('saved')).toEqual([['generic-json']])
		expect(w.emitted('close')).toHaveLength(1)
		web.patchWebResearchSettings.mockRejectedValueOnce(new Error('x'))
		w.vm.searchCredential = null
		await w.vm.save()
		expect(w.vm.error).toBe('Could not save the web-research configuration.')
		expect(web.patchWebResearchSettings.mock.calls[1][0]).not.toHaveProperty('searchCredentialId')
	})
})

describe('ApprovalInbox', () => {
	beforeEach(() => {
		approvals.listPendingApprovals.mockResolvedValue([{ id: 'p1', scheduleId: 's1', agentId: 'a1' }, { id: 'p2', scheduleId: 'gone', agentId: '' }])
		scheduleStore.fetchCollection.mockResolvedValue([{ uuid: 's1', name: 'Daily' }, {}])
		agentStore.fetchCollection.mockResolvedValue([{ id: 'a1', name: 'Bot' }])
	})

	it('loads the pending approvals with the names of their schedules and agents', async () => {
		const w = await mountIt(ApprovalInbox)
		expect(w.vm.approvals).toHaveLength(2)
		expect(Object.keys(w.vm.scheduleMap)).toEqual(['s1'])
		const [one, two] = w.vm.approvals
		expect([w.vm.scheduleName(one), w.vm.agentName(one)]).toEqual(['Daily', 'Bot'])
		expect([w.vm.scheduleName(two), w.vm.agentName(two)]).toEqual(['gone', '—'])
	})

	it('treats unreadable lists as empty, and shows a failed load', async () => {
		approvals.listPendingApprovals.mockResolvedValueOnce(null)
		scheduleStore.fetchCollection.mockResolvedValueOnce(null)
		agentStore.fetchCollection.mockResolvedValueOnce(null)
		const w = await mountIt(ApprovalInbox)
		expect([w.vm.approvals, w.vm.scheduleMap, w.vm.agentMap]).toEqual([[], {}, {}])
		approvals.listPendingApprovals.mockRejectedValueOnce(new Error('Inbox down'))
		await w.vm.load()
		expect(w.vm.error).toBe('Inbox down')
	})

	it('approves from the preview and reloads; a refusal stays on the page', async () => {
		const w = await mountIt(ApprovalInbox)
		w.vm.previewTarget = { id: 'p1' }
		await w.vm.approveFromPreview()
		expect(approvals.approveApproval).toHaveBeenCalledWith('p1')
		expect([w.vm.previewTarget, w.vm.actioningId]).toEqual([null, ''])
		approvals.approveApproval.mockRejectedValueOnce({ response: { data: { error: 'Expired' } } })
		await w.vm.approve({ id: 'p2' })
		expect(w.vm.error).toBe('Expired')
	})

	it('opens the deny dialog from the preview and reloads once decided', async () => {
		const w = await mountIt(ApprovalInbox)
		w.vm.previewTarget = { id: 'p1' }
		w.vm.denyFromPreview()
		expect([w.vm.previewTarget, w.vm.denyTarget, w.vm.showDeny]).toEqual([null, { id: 'p1' }, true])
		approvals.listPendingApprovals.mockClear()
		await w.vm.onDecided()
		expect(approvals.listPendingApprovals).toHaveBeenCalledTimes(1)
		expect([w.vm.formatDate(''), w.vm.formatDate('nope')]).toEqual(['—', 'nope'])
	})
})
