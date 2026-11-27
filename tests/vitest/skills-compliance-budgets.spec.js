/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The skill row actions, the skill link panel and agent skills widget, the
 * compliance operations widget and dashboard, and the budget form, mounted
 * with their API and stores doubled.
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
vi.mock('@nextcloud/event-bus', () => ({ emit: (name, payload) => bus.push([name, payload]) }))
vi.mock('@conduction/nextcloud-vue', () => ({ CnDataTable: { name: 'CnDataTable', render: () => null } }))
vi.mock('../../src/api/skills.js', () => ({
	approveSkill: vi.fn(), exportSkill: vi.fn(), publishSkill: vi.fn(), qualifySkill: vi.fn(),
	listSkills: vi.fn(), installSkill: vi.fn(), uninstallSkill: vi.fn(),
}))
vi.mock('../../src/api/tenantOps.js', () => ({ getAuditExport: vi.fn(), getIncidents: vi.fn(), getRetention: vi.fn(), setRetention: vi.fn() }))
vi.mock('../../src/api/compliance.js', () => ({ getComplianceDashboard: vi.fn(), getComplianceExport: vi.fn() }))
vi.mock('../../src/api/budgets.js', () => ({ createBudget: vi.fn(), updateBudget: vi.fn() }))
const datasetStore = { registerObjectType: vi.fn(), fetchObject: vi.fn(), saveObject: vi.fn() }
const agentStore = { registerObjectType: vi.fn(), fetchObject: vi.fn() }
vi.mock('../../src/store/store.js', () => ({ useEvalDatasetStore: () => datasetStore, useAgentStore: () => agentStore }))

const skills = await import('../../src/api/skills.js')
const ops = await import('../../src/api/tenantOps.js')
const compliance = await import('../../src/api/compliance.js')
const budgets = await import('../../src/api/budgets.js')
const { default: SkillRowActions } = await import('../../src/widgets/SkillRowActions.vue')
const { default: SkillLinkPanel } = await import('../../src/widgets/SkillLinkPanel.vue')
const { default: AgentSkillsWidget } = await import('../../src/widgets/AgentSkillsWidget.vue')
const { default: ComplianceOperations } = await import('../../src/widgets/ComplianceOperations.vue')
const { default: ComplianceDashboard } = await import('../../src/views/ComplianceDashboard.vue')
const { default: BudgetFormModal } = await import('../../src/modals/BudgetFormModal.vue')

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
 * Mount a component on the route of object x1 and let it load.
 *
 * @param {object} component The component.
 * @param {object} [props] Its props.
 * @return {Promise<object>} The wrapper.
 */
async function mountIt(component, props = {}) {
	const w = shallowMount(component, { props, global: { mocks: { t, $route: { params: { id: 'x1' } } } } })
	await flushPromises()
	return w
}

/**
 * Stub the browser download machinery and return the click spy.
 *
 * @return {object} The click spy.
 */
function stubDownload() {
	URL.createObjectURL = vi.fn(() => 'blob:x')
	URL.revokeObjectURL = vi.fn()
	return vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {})
}

beforeEach(() => {
	toasts.length = 0
	bus.length = 0
	vi.clearAllMocks()
	for (const key of Object.keys(state)) {
		delete state[key]
	}
})

describe('SkillRowActions', () => {
	it('acts on the row by uuid, else id, refreshing the page after a change', async () => {
		skills.qualifySkill.mockResolvedValueOnce({ level: 3 })
		const w = await mountIt(SkillRowActions, { row: { id: 'k-id' } })
		await w.vm.doApprove()
		expect(skills.approveSkill).toHaveBeenCalledWith('k-id')
		await w.setProps({ row: { uuid: 'k-uuid', id: 'k-id' } })
		await w.vm.doQualify()
		expect(skills.qualifySkill).toHaveBeenCalledWith('k-uuid')
		expect(w.vm.scorecardResult).toEqual({ level: 3 })
		expect(bus).toHaveLength(2)
	})

	it('exports a package into the export dialog', async () => {
		skills.exportSkill.mockResolvedValueOnce('PKG')
		const w = await mountIt(SkillRowActions, { row: { id: 'k' } })
		await w.vm.doExport()
		expect([w.vm.exportedPackage, w.vm.showExport]).toEqual(['PKG', true])
	})

	it('reports a publish the hub refused, with or without a reason', async () => {
		const w = await mountIt(SkillRowActions, { row: { id: 'k' } })
		skills.publishSkill.mockResolvedValueOnce({ error: { message: 'No hub' } })
		await w.vm.doPublish()
		expect(w.vm.error).toBe('No hub')
		skills.publishSkill.mockResolvedValueOnce({ error: {} })
		await w.vm.doPublish()
		expect(w.vm.error).toBe('Publishing is not available.')
		skills.publishSkill.mockResolvedValueOnce({ ok: true })
		await w.vm.doPublish()
		expect(w.vm.error).toBe('')
	})

	it.each(['doApprove', 'doQualify', 'doExport', 'doPublish'])('%s shows its failure', async (method) => {
		const call = { doApprove: 'approveSkill', doQualify: 'qualifySkill', doExport: 'exportSkill', doPublish: 'publishSkill' }[method]
		const w = await mountIt(SkillRowActions, { row: { id: 'k' } })
		skills[call].mockRejectedValueOnce(new Error(`${call} down`))
		await w.vm[method]()
		expect(w.vm.error).toBe(`${call} down`)
		expect(w.vm.busy).toBe(false)
		expect(bus).toEqual([])
	})

	it('opens and closes the GitHub publish and install dialogs', async () => {
		const w = await mountIt(SkillRowActions, { row: { id: 'k' } })
		w.vm.githubPublishNotice = 'old'
		w.vm.openGithubPublishModal()
		expect([w.vm.githubPublishNotice, w.vm.showGithubPublish]).toEqual(['', true])
		w.vm.onGithubPublished('https://github.com/o/r')
		expect([w.vm.githubPublishNotice, w.vm.showGithubPublish]).toEqual(['Published to https://github.com/o/r', false])
		w.vm.openInstallModal()
		expect(w.vm.showInstall).toBe(true)
		w.vm.onInstalled()
		expect(w.vm.showInstall).toBe(false)
		expect(bus).toHaveLength(2)
	})

	it('flags a GitHub copy that is older than the last accepted version', async () => {
		const w = await mountIt(SkillRowActions, { row: { githubRepo: 'r', publishedAt: '2026-01-01', lastAcceptedVersionAt: '2026-02-01' } })
		expect(w.vm.publishedCopyBehind).toBe(true)
		await w.setProps({ row: { githubRepo: 'r', publishedAt: '2026-03-01', lastAcceptedVersionAt: '2026-02-01' } })
		expect(w.vm.publishedCopyBehind).toBe(false)
		await w.setProps({ row: { publishedAt: '2026-01-01', lastAcceptedVersionAt: '2026-02-01' } })
		expect(w.vm.publishedCopyBehind).toBe(false)
	})
})

describe('SkillLinkPanel', () => {
	beforeEach(() => {
		datasetStore.fetchObject.mockResolvedValue({ id: 'x1', skillRefs: ['k1', '', 'k-gone'] })
		skills.listSkills.mockResolvedValue([{ uuid: 'k1', name: 'One' }, { id: 'k2', name: 'Two' }, { uuid: 'k3', state: 'retired' }, { uuid: 'k4' }])
	})

	it('shows the linked skills by name and offers the active unlinked ones', async () => {
		const w = await mountIt(SkillLinkPanel)
		expect(datasetStore.fetchObject).toHaveBeenCalledWith('evaldataset', 'x1')
		expect(w.vm.linkedSkillIds).toEqual(['k1', 'k-gone'])
		expect(w.vm.linkedSkills).toEqual([{ label: 'One', value: 'k1' }, { label: 'k-gone', value: 'k-gone' }])
		expect(w.vm.linkableSkillOptions).toEqual([{ label: 'Two', value: 'k2' }, { label: 'k4', value: 'k4' }])
	})

	it('links and unlinks by saving the dataset refs', async () => {
		const w = await mountIt(SkillLinkPanel)
		await w.vm.linkSkill()
		expect(datasetStore.saveObject).not.toHaveBeenCalled()
		w.vm.skillToLink = { value: 'k2' }
		await w.vm.linkSkill()
		expect(datasetStore.saveObject).toHaveBeenLastCalledWith('evaldataset', { id: 'x1', skillRefs: ['k1', 'k-gone', 'k2'] })
		expect(w.vm.skillToLink).toBeNull()
		await w.vm.unlinkSkill('k1')
		expect(datasetStore.saveObject).toHaveBeenLastCalledWith('evaldataset', { id: 'x1', skillRefs: ['k-gone'] })
		expect(toasts).toEqual([['success', 'Skill linked.'], ['success', 'Skill unlinked.']])
	})

	it('says so when a link change fails, and does nothing without a dataset', async () => {
		const w = await mountIt(SkillLinkPanel)
		datasetStore.saveObject.mockRejectedValueOnce(new Error('x'))
		w.vm.skillToLink = { value: 'k2' }
		await w.vm.linkSkill()
		datasetStore.saveObject.mockRejectedValueOnce(new Error('x'))
		await w.vm.unlinkSkill('k1')
		expect(toasts).toEqual([['error', 'Could not link the skill.'], ['error', 'Could not unlink the skill.']])
		w.vm.dataset = null
		await w.vm.unlinkSkill('k1')
		expect(datasetStore.saveObject).toHaveBeenCalledTimes(2)
	})

	it('loads without skills when listing fails, and shows a failed dataset load', async () => {
		skills.listSkills.mockRejectedValueOnce(new Error('x'))
		datasetStore.fetchObject.mockResolvedValueOnce(null)
		const w = await mountIt(SkillLinkPanel)
		expect([w.vm.skills, w.vm.dataset, w.vm.linkedSkillIds]).toEqual([[], null, []])
		datasetStore.fetchObject.mockRejectedValueOnce({})
		await w.vm.load()
		expect(w.vm.error).toBe('Could not load the dataset.')
	})
})

describe('AgentSkillsWidget', () => {
	beforeEach(() => {
		agentStore.fetchObject.mockResolvedValue({ skillInstalls: ['k1', 'k9'] })
		skills.listSkills.mockResolvedValue([{ uuid: 'k1', name: 'One' }, { id: 'k2' }])
	})

	it('lists the installed skills and offers the others', async () => {
		const w = await mountIt(AgentSkillsWidget)
		expect(w.vm.installedSkills).toEqual([{ label: 'One', value: 'k1' }, { label: 'k9', value: 'k9' }])
		expect(w.vm.attachableSkillOptions).toEqual([{ label: 'k2', value: 'k2' }])
		w.vm.agent = null
		expect([w.vm.installedSkills, w.vm.attachableSkillOptions.length]).toEqual([[], 2])
	})

	it('attaches and detaches skills on the routed agent', async () => {
		const w = await mountIt(AgentSkillsWidget)
		await w.vm.attachSkill()
		expect(skills.installSkill).not.toHaveBeenCalled()
		w.vm.skillToAttach = { value: 'k2' }
		await w.vm.attachSkill()
		expect(skills.installSkill).toHaveBeenCalledWith('k2', 'x1')
		await w.vm.detachSkill('k1')
		expect(skills.uninstallSkill).toHaveBeenCalledWith('k1', 'x1')
		expect(toasts).toEqual([['success', 'Skill attached.'], ['success', 'Skill detached.']])
	})

	it('says so when attaching or detaching fails', async () => {
		skills.listSkills.mockResolvedValueOnce('nope')
		const w = await mountIt(AgentSkillsWidget)
		expect(w.vm.skills).toEqual([])
		skills.installSkill.mockRejectedValueOnce(new Error('x'))
		skills.uninstallSkill.mockRejectedValueOnce(new Error('x'))
		w.vm.skillToAttach = { value: 'k2' }
		await w.vm.attachSkill()
		await w.vm.detachSkill('k1')
		expect(toasts).toEqual([['error', 'Could not attach the skill.'], ['error', 'Could not detach the skill.']])
		expect(w.vm.skillBusy).toBe(false)
	})
})

describe('ComplianceOperations', () => {
	beforeEach(() => {
		ops.getIncidents.mockResolvedValue({ incidents: [{ id: 'i1' }] })
		ops.getRetention.mockResolvedValue({ retentionMonths: 12 })
	})

	it('loads incidents and retention only for a manager', async () => {
		const viewer = await mountIt(ComplianceOperations)
		expect(viewer.vm.canManage).toBe(false)
		expect(ops.getIncidents).not.toHaveBeenCalled()
		state.can_manage_killswitch = true
		const w = await mountIt(ComplianceOperations)
		expect([w.vm.incidents, w.vm.retentionDraft]).toEqual([[{ id: 'i1' }], 12])
	})

	it('downloads the audit export and remembers its record count', async () => {
		const click = stubDownload()
		ops.getAuditExport.mockResolvedValueOnce({ recordCount: 42 })
		const w = await mountIt(ComplianceOperations)
		await w.vm.exportAudit()
		expect(w.vm.lastExportCount).toBe(42)
		expect(click).toHaveBeenCalledTimes(1)
		ops.getAuditExport.mockResolvedValueOnce({})
		await w.vm.exportAudit()
		expect(w.vm.lastExportCount).toBe(0)
		ops.getAuditExport.mockRejectedValueOnce({ response: { data: { error: 'Too big' } } })
		await w.vm.exportAudit()
		expect([w.vm.auditError, w.vm.exporting]).toEqual(['Too big', false])
		click.mockRestore()
	})

	it('shows failed incident and retention loads', async () => {
		state.can_manage_killswitch = true
		ops.getIncidents.mockRejectedValueOnce(new Error('Incidents down'))
		ops.getRetention.mockRejectedValueOnce({})
		const w = await mountIt(ComplianceOperations)
		expect([w.vm.incidentError, w.vm.retentionError]).toEqual(['Incidents down', 'Unknown error'])
		ops.getIncidents.mockResolvedValueOnce({})
		await w.vm.onIncidentCreated()
		expect(w.vm.incidents).toEqual([])
		expect(toasts).toContainEqual(['success', 'Incident recorded.'])
	})

	it('saves the retention period, and on a refusal reloads the stored one', async () => {
		state.can_manage_killswitch = true
		const w = await mountIt(ComplianceOperations)
		ops.setRetention.mockResolvedValueOnce({ retentionMonths: 24 })
		w.vm.retentionDraft = '24'
		await w.vm.saveRetention()
		expect(ops.setRetention).toHaveBeenCalledWith(24)
		expect(w.vm.retentionDraft).toBe(24)
		ops.setRetention.mockRejectedValueOnce({})
		w.vm.retentionDraft = 2
		await w.vm.saveRetention()
		expect(w.vm.retentionDraft).toBe(12)
		expect(w.vm.retentionSaving).toBe(false)
		ops.setRetention.mockRejectedValueOnce({ response: { data: { error: 'Locked' } } })
		ops.getRetention.mockRejectedValueOnce({ response: { data: { error: 'Locked' } } })
		await w.vm.saveRetention()
		expect(w.vm.retentionError).toBe('Locked')
	})
})

describe('ComplianceDashboard', () => {
	it('loads frameworks and gaps, and marks a refusal as forbidden', async () => {
		compliance.getComplianceDashboard.mockResolvedValueOnce({ frameworks: [{ id: 'ai-act' }], gaps: 'x' })
		const w = await mountIt(ComplianceDashboard)
		expect([w.vm.frameworks, w.vm.gaps, w.vm.forbidden]).toEqual([[{ id: 'ai-act' }], [], false])
		expect(w.vm.controlColumns.map((c) => c.key)).toEqual(['controlId', 'title', 'status', 'detail', 'sourceUrl'])
		compliance.getComplianceDashboard.mockRejectedValueOnce({ response: { status: 403 } })
		await w.vm.load()
		expect([w.vm.forbidden, w.vm.error]).toEqual([true, ''])
		compliance.getComplianceDashboard.mockRejectedValueOnce({ response: { status: 500, data: { error: 'Broken' } } })
		await w.vm.load()
		expect([w.vm.forbidden, w.vm.error]).toEqual([false, 'Broken'])
		compliance.getComplianceDashboard.mockResolvedValueOnce({})
		await w.vm.load()
		expect(w.vm.frameworks).toEqual([])
	})

	it('downloads the auditor pack, or shows why not', async () => {
		const click = stubDownload()
		compliance.getComplianceDashboard.mockResolvedValue({})
		compliance.getComplianceExport.mockResolvedValueOnce({ a: 1 })
		const w = await mountIt(ComplianceDashboard)
		await w.vm.exportPack()
		expect(click).toHaveBeenCalledTimes(1)
		compliance.getComplianceExport.mockRejectedValueOnce(new Error('Export down'))
		await w.vm.exportPack()
		expect([w.vm.error, w.vm.exporting]).toEqual(['Export down', false])
		expect(['satisfied', 'partial', 'unevidenced', 'other'].map(w.vm.statusLabel)).toEqual(['Satisfied', 'Partial', 'Unevidenced', 'other'])
		click.mockRestore()
	})
})

describe('BudgetFormModal', () => {
	/**
	 * Mount the form closed, then open it.
	 *
	 * @param {object|null} budget The budget being edited.
	 * @return {Promise<object>} The wrapper.
	 */
	async function openForm(budget = null) {
		const w = shallowMount(BudgetFormModal, { props: { show: false, organisation: 'org1', budget }, global: { mocks: { t } } })
		await w.setProps({ show: true })
		return w
	}

	it('opens blank and saves only with a limit, and an agent for an agent budget', async () => {
		const w = await openForm()
		expect([w.vm.isEdit, w.vm.heading, w.vm.canSave]).toEqual([false, 'Create budget', false])
		w.vm.form.tokenLimit = '1000'
		expect(w.vm.canSave).toBe(true)
		w.vm.scopeOption = { value: 'agent' }
		expect(w.vm.canSave).toBe(false)
		w.vm.form.agentId = 'a1'
		expect(w.vm.canSave).toBe(true)
		w.vm.form.tokenLimit = null
		w.vm.form.eurLimit = 5
		expect(w.vm.canSave).toBe(true)
	})

	it('maps the select options with fallbacks', async () => {
		const w = await openForm()
		expect([w.vm.scopeOption.value, w.vm.periodOption.value]).toEqual(['organisation', 'monthly'])
		w.vm.periodOption = { value: 'weekly' }
		expect(w.vm.form.period).toBe('weekly')
		w.vm.periodOption = null
		w.vm.scopeOption = null
		expect([w.vm.form.period, w.vm.form.scope]).toEqual(['monthly', 'organisation'])
		w.vm.form.scope = 'x'
		w.vm.form.period = 'x'
		expect([w.vm.scopeOption.value, w.vm.periodOption.value]).toEqual(['organisation', 'monthly'])
	})

	it('opens an existing budget and updates it with only the set limits', async () => {
		const w = await openForm({ id: 'b1', scope: 'agent', agentId: 'a1', period: 'daily', tokenLimit: 0, eurLimit: null, softThresholdPercent: 0, enabled: false })
		expect(w.vm.heading).toBe('Edit budget')
		expect(w.vm.form).toEqual({ scope: 'agent', agentId: 'a1', period: 'daily', tokenLimit: 0, eurLimit: '', softThresholdPercent: 0, enabled: false })
		budgets.updateBudget.mockResolvedValueOnce({ id: 'b1' })
		await w.vm.save()
		expect(budgets.updateBudget).toHaveBeenCalledWith('b1', { scope: 'agent', agentId: 'a1', period: 'daily', softThresholdPercent: 80, enabled: false, tokenLimit: 0 })
		expect(w.emitted('saved')).toEqual([[{ id: 'b1' }]])
		expect(w.emitted('close')).toHaveLength(1)
	})

	it('creates an organisation budget under the organisation, and shows a failure', async () => {
		const w = await openForm()
		Object.assign(w.vm.form, { eurLimit: '25', softThresholdPercent: '90' })
		budgets.createBudget.mockResolvedValueOnce({ id: 'n' })
		await w.vm.save()
		expect(budgets.createBudget).toHaveBeenCalledWith({ scope: 'organisation', period: 'monthly', softThresholdPercent: 90, enabled: true, eurLimit: 25, organisation: 'org1' })
		budgets.createBudget.mockRejectedValueOnce({ response: { data: { error: 'Duplicate' } } })
		await w.vm.save()
		expect([w.vm.error, w.vm.saving]).toEqual(['Duplicate', false])
	})
})
