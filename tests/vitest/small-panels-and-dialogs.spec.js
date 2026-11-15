/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Ten smaller panels and dialogs: analytics breakdown, provider declarations,
 * skill learnings, agent export, kill switch, incident creation, step result,
 * skill import, version diff and run comparison, mounted with their API and
 * stores doubled.
 */

import { state } from '@nextcloud/initial-state'
import { flushPromises, shallowMount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const toasts = []
vi.mock('@nextcloud/dialogs', () => ({ showSuccess: (m) => toasts.push(['success', m]) }))
vi.mock('@conduction/nextcloud-vue', () => ({ SAFE_MARKDOWN_DOMPURIFY_CONFIG: {} }))
vi.mock('../../src/api/analytics.js', () => ({ getAnalytics: vi.fn(), compareRuns: vi.fn() }))
vi.mock('../../src/api/llm.js', () => ({ declareProviderDataUse: vi.fn(), declareProviderResidency: vi.fn(), getProviderDataUse: vi.fn(), getProviderResidency: vi.fn() }))
vi.mock('../../src/api/agentTemplates.js', () => ({ exportAgentToTemplate: vi.fn(), saveAgentAsTemplate: vi.fn() }))
vi.mock('../../src/api/approvals.js', () => ({ getKillSwitch: vi.fn(), toggleKillSwitch: vi.fn() }))
vi.mock('../../src/api/tenantOps.js', () => ({ createIncident: vi.fn() }))
vi.mock('../../src/api/skills.js', () => ({ importSkill: vi.fn(), installFromSource: vi.fn() }))
vi.mock('../../src/api/agents.js', () => ({ diffAgentVersions: vi.fn() }))
const agentStore = { registerObjectType: vi.fn(), fetchCollection: vi.fn() }
const skillStore = { registerObjectType: vi.fn(), fetchObject: vi.fn() }
vi.mock('../../src/store/store.js', () => ({ useAgentStore: () => agentStore, useSkillStore: () => skillStore }))

const analytics = await import('../../src/api/analytics.js')
const llm = await import('../../src/api/llm.js')
const templates = await import('../../src/api/agentTemplates.js')
const approvals = await import('../../src/api/approvals.js')
const ops = await import('../../src/api/tenantOps.js')
const skills = await import('../../src/api/skills.js')
const agents = await import('../../src/api/agents.js')
const { default: AnalyticsBreakdownWidget } = await import('../../src/widgets/AnalyticsBreakdownWidget.vue')
const { default: ProviderDeclarations } = await import('../../src/components/ProviderDeclarations.vue')
const { default: SkillLearnings } = await import('../../src/widgets/SkillLearnings.vue')
const { default: AgentExportModal } = await import('../../src/modals/AgentExportModal.vue')
const { default: KillSwitchToggle } = await import('../../src/components/KillSwitchToggle.vue')
const { default: CreateIncidentDialog } = await import('../../src/dialogs/CreateIncidentDialog.vue')
const { default: StepResultDialog } = await import('../../src/dialogs/StepResultDialog.vue')
const { default: SkillImportModal } = await import('../../src/modals/SkillImportModal.vue')
const { default: AgentVersionDiffDialog } = await import('../../src/dialogs/agents/AgentVersionDiffDialog.vue')
const { default: RunCompare } = await import('../../src/views/RunCompare.vue')

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

/**
 * Mount a component and let it load.
 *
 * @param {object} component The component.
 * @param {object} [props] Its props.
 * @param {object} [mocks] Extra global mocks.
 * @return {Promise<object>} The wrapper.
 */
async function mountIt(component, props = {}, mocks = {}) {
	const w = shallowMount(component, { props, global: { mocks: { t, $route: { params: { id: 'x1' }, query: {} }, ...mocks } } })
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
	vi.clearAllMocks()
	for (const key of Object.keys(state)) {
		delete state[key]
	}
})

describe('AnalyticsBreakdownWidget', () => {
	it('sorts the status breakdown by count and names agents from the row or the agent list', async () => {
		analytics.getAnalytics.mockResolvedValueOnce({ statusBreakdown: { ok: 2, error: 5, skipped: 1 } })
		agentStore.fetchCollection.mockResolvedValueOnce([{ uuid: 'a1', name: 'Bot' }])
		const w = await mountIt(AnalyticsBreakdownWidget)
		expect(analytics.getAnalytics).toHaveBeenCalledWith('')
		expect(w.vm.statusRows).toEqual([{ status: 'error', count: 5 }, { status: 'ok', count: 2 }, { status: 'skipped', count: 1 }])
		expect(w.vm.agentName({ name: 'Given' })).toBe('Given')
		expect(w.vm.agentName({ agentId: 'a1' })).toBe('Bot')
		expect(w.vm.agentName({ agentId: 'zz' })).toBe('zz')
		expect(w.vm.agentName(null)).toBe('—')
	})

	it('keeps going without agents, and shows a failed load', async () => {
		analytics.getAnalytics.mockResolvedValueOnce({})
		agentStore.fetchCollection.mockRejectedValueOnce(new Error('x'))
		const w = await mountIt(AnalyticsBreakdownWidget)
		expect([w.vm.agents, w.vm.statusRows]).toEqual([[], []])
		agentStore.fetchCollection.mockResolvedValueOnce([])
		analytics.getAnalytics.mockRejectedValueOnce(new Error('Analytics down'))
		await w.vm.load()
		expect([w.vm.error, w.vm.loading]).toEqual(['Analytics down', false])
	})
})

describe('ProviderDeclarations', () => {
	beforeEach(() => {
		llm.getProviderResidency.mockResolvedValue({ residencies: { mistral: { residency: 'eu', location: 'Paris' } } })
		llm.getProviderDataUse.mockResolvedValue({ dataUse: { mistral: { dataUse: 'no-training', termsReference: 'DPA 3', declaredBy: 'dpo', declaredAt: '2026-04-02T10:00:00Z' } } })
	})

	it('fills the declarations of the provider and refills when it changes', async () => {
		const w = await mountIt(ProviderDeclarations, { provider: 'mistral' })
		expect([w.vm.residency.value, w.vm.location, w.vm.dataUse.value, w.vm.termsReference, w.vm.declaredBy, w.vm.declaredOn]).toEqual(['eu', 'Paris', 'no-training', 'DPA 3', 'dpo', '2026-04-02'])
		await w.setProps({ provider: 'other' })
		expect([w.vm.residency, w.vm.location, w.vm.dataUse, w.vm.declaredOn]).toEqual([null, '', null, ''])
	})

	it('saves only what is declared, and nothing without a provider', async () => {
		const w = await mountIt(ProviderDeclarations, { provider: 'mistral' })
		await w.vm.save()
		expect(llm.declareProviderResidency).toHaveBeenCalledWith('mistral', { residency: 'eu', location: 'Paris' })
		expect(llm.declareProviderDataUse).toHaveBeenCalledWith('mistral', { dataUse: 'no-training', termsReference: 'DPA 3' })
		w.vm.dataUse = null
		await w.vm.save()
		expect(llm.declareProviderDataUse).toHaveBeenCalledTimes(1)
		await w.setProps({ provider: null })
		await w.vm.save()
		expect(llm.declareProviderResidency).toHaveBeenCalledTimes(2)
	})

	it('treats an unreadable declaration list as empty', async () => {
		llm.getProviderResidency.mockRejectedValueOnce(new Error('x'))
		const w = await mountIt(ProviderDeclarations, { provider: 'mistral' })
		expect([w.vm.loaded, w.vm.residencies, w.vm.residency]).toEqual([true, {}, null])
	})
})

describe('SkillLearnings', () => {
	it('shows the learnings file and the promotion evidence', async () => {
		skillStore.fetchObject.mockResolvedValueOnce({ files: [{ name: 'learnings.md', content: '# Learned' }], levelEvidence: { l6: { lastCaptureAt: '2026-01-01' } } })
		const w = await mountIt(SkillLearnings)
		expect(w.vm.learningsMarkdown).toBe('# Learned')
		expect(w.vm.l6).toEqual({ lastCaptureAt: '2026-01-01' })
		expect(w.vm.hasLearningsActivity).toBe(true)
		expect(w.vm.renderMarkdown('# Learned')).toContain('<h1')
		expect(w.vm.renderMarkdown(null)).toBe('')
	})

	it('counts candidates alone as activity, and nothing as none', async () => {
		skillStore.fetchObject.mockResolvedValueOnce({ files: [{ name: 'learning-candidates.md' }] })
		const w = await mountIt(SkillLearnings)
		expect([w.vm.learningsMarkdown, w.vm.hasLearningsActivity]).toEqual(['', true])
		w.vm.skill = { levelEvidence: { l6: 'x' } }
		expect([w.vm.l6, w.vm.files, w.vm.hasLearningsActivity]).toEqual([{}, [], false])
		w.vm.skill = { levelEvidence: { l6: { lastPromotedAt: '2026-01-01' } } }
		expect(w.vm.hasLearningsActivity).toBe(true)
	})

	it('shows a failed load and formats counts and dates', async () => {
		skillStore.fetchObject.mockRejectedValueOnce({})
		const w = await mountIt(SkillLearnings)
		expect(w.vm.error).toBe('Could not load the skill.')
		expect([w.vm.countLabel(3), w.vm.countLabel('3'), w.vm.formatDate(''), w.vm.formatDate('nope')]).toEqual(['3', '—', '—', 'nope'])
	})
})

describe('AgentExportModal', () => {
	it('downloads the exported package under a file name from the agent name', async () => {
		const click = stubDownload()
		templates.exportAgentToTemplate.mockResolvedValueOnce('{"name":"My Bot"}')
		const w = await mountIt(AgentExportModal, { agentId: 'a1' })
		let downloadName = ''
		click.mockImplementation(function () { downloadName = this.download })
		await w.vm.run()
		expect(templates.exportAgentToTemplate).toHaveBeenCalledWith('a1')
		expect([downloadName, w.vm.done]).toEqual(['my-bot.hermiq-agent.json', 'The file is downloaded.'])
		w.vm.download('not json')
		expect(downloadName).toBe('agent.hermiq-agent.json')
		click.mockRestore()
	})

	it('saves the routed agent as a template', async () => {
		templates.saveAgentAsTemplate.mockResolvedValueOnce({ name: 'Bot template' })
		const w = await mountIt(AgentExportModal, { mode: 'save' })
		expect(w.vm.saveMode).toBe(true)
		await w.vm.run()
		expect(templates.saveAgentAsTemplate).toHaveBeenCalledWith('x1')
		expect(w.vm.done).toBe('Saved as the template Bot template. You find it in the Store.')
	})

	it('explains a refusal to a non-owner and other failures per mode', async () => {
		const save = await mountIt(AgentExportModal, { mode: 'save' })
		templates.saveAgentAsTemplate.mockRejectedValueOnce({ response: { status: 403 } })
		await save.vm.run()
		expect(save.vm.error).toBe('Only the owner of this agent can save it as a template.')
		templates.saveAgentAsTemplate.mockRejectedValueOnce({})
		await save.vm.run()
		expect(save.vm.error).toBe('Could not save the agent as a template')
		const exp = await mountIt(AgentExportModal)
		templates.exportAgentToTemplate.mockRejectedValueOnce({})
		await exp.vm.run()
		expect([exp.vm.error, exp.vm.done, exp.vm.busy]).toEqual(['Could not export the agent', '', false])
	})
})

describe('KillSwitchToggle', () => {
	it('reads the switch of the first managed organisation', async () => {
		state.can_manage_killswitch = true
		state.managed_organisations = [{ id: 'o1', label: 'One' }, { id: 'o2' }]
		approvals.getKillSwitch.mockResolvedValueOnce({ engaged: true, reason: 'Incident' })
		const w = await mountIt(KillSwitchToggle)
		expect(approvals.getKillSwitch).toHaveBeenCalledWith('o1')
		expect([w.vm.engaged, w.vm.reason, w.vm.orgOption]).toEqual([true, 'Incident', { label: 'One', value: 'o1' }])
		approvals.getKillSwitch.mockResolvedValueOnce({})
		w.vm.orgOption = { value: 'o2' }
		await flushPromises()
		expect(approvals.getKillSwitch).toHaveBeenLastCalledWith('o2')
		expect([w.vm.engaged, w.vm.reason]).toEqual([false, ''])
	})

	it('does nothing for a viewer who may not manage', async () => {
		state.managed_organisations = 'x'
		const w = await mountIt(KillSwitchToggle)
		expect([w.vm.organisations, w.vm.canManage]).toEqual([[], false])
		await w.vm.onToggle(true)
		await w.vm.load()
		expect(approvals.toggleKillSwitch).not.toHaveBeenCalled()
		expect(approvals.getKillSwitch).not.toHaveBeenCalled()
	})

	it('engages the switch, and rereads it after a refusal', async () => {
		state.can_manage_killswitch = true
		state.managed_organisations = [{ id: 'o1' }]
		approvals.getKillSwitch.mockResolvedValue({ engaged: false })
		const w = await mountIt(KillSwitchToggle)
		approvals.toggleKillSwitch.mockResolvedValueOnce({ engaged: true, reason: 'Manual' })
		await w.vm.onToggle(true)
		expect(approvals.toggleKillSwitch).toHaveBeenCalledWith('o1', true, '')
		expect([w.vm.engaged, w.vm.reason]).toEqual([true, 'Manual'])
		approvals.toggleKillSwitch.mockRejectedValueOnce(new Error('Not allowed'))
		await w.vm.onToggle(false)
		expect([w.vm.error, w.vm.engaged, w.vm.saving]).toEqual(['', false, false])
		approvals.toggleKillSwitch.mockRejectedValueOnce(new Error('Not allowed'))
		approvals.getKillSwitch.mockRejectedValueOnce(new Error('Read failed'))
		await w.vm.onToggle(false)
		expect(w.vm.error).toBe('Read failed')
	})
})

describe('CreateIncidentDialog', () => {
	it('opens with the defaults, saves only a complete incident, with the parsed run ids', async () => {
		const w = shallowMount(CreateIncidentDialog, { props: { show: false, defaultAgentId: 'a1', defaultRunIds: ['r1', 'r2'] }, global: { mocks: { t } } })
		await w.setProps({ show: true })
		expect([w.vm.form.linkedAgentId, w.vm.linkedRunIdsText, w.vm.canSave]).toEqual(['a1', 'r1, r2', false])
		Object.assign(w.vm.form, { description: 'Leak', impact: 'High', actionsTaken: 'Revoked' })
		expect(w.vm.canSave).toBe(true)
		w.vm.linkedRunIdsText = 'r1, , r3 '
		ops.createIncident.mockResolvedValueOnce({ id: 'i1' })
		await w.vm.save()
		expect(ops.createIncident).toHaveBeenCalledWith({ description: 'Leak', impact: 'High', actionsTaken: 'Revoked', linkedAgentId: 'a1', linkedRunIds: ['r1', 'r3'] })
		expect(w.emitted('created')).toEqual([[{ id: 'i1' }]])
		expect(w.emitted('close')).toHaveLength(1)
	})

	it('links no agent when none is given, and shows a refusal', async () => {
		const w = await mountIt(CreateIncidentDialog, { show: false })
		ops.createIncident.mockRejectedValueOnce({ response: { data: { error: 'Missing impact' } } })
		await w.vm.save()
		expect(ops.createIncident.mock.calls[0][0].linkedAgentId).toBeNull()
		expect([w.vm.error, w.vm.saving]).toEqual(['Missing impact', false])
	})
})

describe('StepResultDialog', () => {
	it('shows what the step produced and passed on, and whether it halted', async () => {
		const w = await mountIt(StepResultDialog, { result: { produced: { a: 1 }, continue: false } })
		expect(w.vm.producedPretty).toBe('{\n  "a": 1\n}')
		expect(w.vm.passedPretty).toBe('(nothing)')
		expect(w.vm.halted).toBe(true)
		expect(w.vm.hintText).toContain('{{key}}')
		expect(w.vm.passedHint).toContain('whole run state')
		const cyclic = {}
		cyclic.self = cyclic
		expect(w.vm.stringify(cyclic)).toBe('[object Object]')
	})

	it('copies the result to the clipboard, and stays quiet when it cannot', async () => {
		const writeText = vi.fn(async () => {})
		Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true })
		const w = await mountIt(StepResultDialog, { result: { ok: true } })
		expect(w.vm.halted).toBe(false)
		await w.vm.copy()
		expect(writeText).toHaveBeenCalledWith('{\n  "ok": true\n}')
		expect(toasts).toEqual([['success', 'Copied.']])
		writeText.mockRejectedValueOnce(new Error('denied'))
		await w.vm.copy()
		expect(toasts).toHaveLength(1)
	})
})

describe('SkillImportModal', () => {
	it('imports a package directly or from the hub, and nothing blank', async () => {
		const w = await mountIt(SkillImportModal)
		await w.vm.run('paste')
		expect(skills.importSkill).not.toHaveBeenCalled()
		w.vm.importText = ' PKG '
		await w.vm.run('paste')
		expect(skills.importSkill).toHaveBeenCalledWith('PKG')
		await w.vm.run('hub')
		expect(skills.installFromSource).toHaveBeenCalledWith('PKG', 'hub')
		expect(w.emitted('imported')).toHaveLength(2)
		skills.importSkill.mockRejectedValueOnce({})
		await w.vm.run('paste')
		expect([w.vm.error, w.vm.busy]).toEqual(['Could not import the skill', false])
	})
})

describe('AgentVersionDiffDialog', () => {
	it('loads the diff of the two versions when opened', async () => {
		agents.diffAgentVersions.mockResolvedValueOnce({ prompt: { from: 'a', to: 'b' }, custom: {} })
		const w = shallowMount(AgentVersionDiffDialog, { props: { show: false, agentId: 'a1', fromId: 'v1', toId: 'v2' }, global: { mocks: { t } } })
		await w.setProps({ show: true })
		await flushPromises()
		expect(agents.diffAgentVersions).toHaveBeenCalledWith('a1', 'v1', 'v2')
		expect(w.vm.fields).toEqual(['prompt', 'custom'])
		expect([w.vm.fieldLabel('skillInstalls'), w.vm.fieldLabel('custom')]).toEqual(['Skills', 'custom'])
	})

	it('loads nothing without both versions, and shows a failure', async () => {
		const w = await mountIt(AgentVersionDiffDialog, { agentId: 'a1', fromId: 'v1' })
		await w.vm.load()
		expect(agents.diffAgentVersions).not.toHaveBeenCalled()
		await w.setProps({ toId: 'v2' })
		agents.diffAgentVersions.mockRejectedValueOnce(new Error('Diff down'))
		await w.vm.load()
		expect([w.vm.error, w.vm.diff]).toEqual(['Diff down', {}])
	})

	it('formats values and closes with the dialog', async () => {
		const w = await mountIt(AgentVersionDiffDialog, { agentId: 'a1' })
		const cyclic = {}
		cyclic.self = cyclic
		expect([null, '', 0, 'x', { a: 1 }, cyclic].map(w.vm.formatValue)).toEqual(['—', '—', '0', 'x', '{"a":1}', '[object Object]'])
		w.vm.onUpdateOpen(true)
		w.vm.onUpdateOpen(false)
		expect(w.emitted('close')).toHaveLength(1)
	})
})

describe('RunCompare', () => {
	const result = { left: { status: 'ok' }, right: { status: 'ok' }, comparison: { steps: [] } }

	it('compares the two runs in the route and summarises them', async () => {
		analytics.compareRuns.mockResolvedValueOnce(result)
		const router = { push: vi.fn(async () => {}) }
		const w = await mountIt(RunCompare, {}, { $route: { query: { left: 'r1', right: 'r2' } }, $router: router })
		expect(analytics.compareRuns).toHaveBeenCalledWith('r1', 'r2')
		expect(w.vm.summary).toBe('Same outcome.')
		expect(w.vm.sides.map((s) => s.label)).toEqual(['Run A', 'Run B'])
		w.vm.back()
		expect(router.push).toHaveBeenCalledWith('/runs')
	})

	it('explains a run that is missing or hidden, and other failures', async () => {
		analytics.compareRuns.mockRejectedValueOnce({ response: { status: 404 } })
		const w = await mountIt(RunCompare, {}, { $route: {} })
		expect(analytics.compareRuns).toHaveBeenCalledWith('', '')
		expect(w.vm.error).toBe('One of these runs was not found, or you may not see it.')
		analytics.compareRuns.mockRejectedValueOnce(new Error('Network'))
		const other = await mountIt(RunCompare)
		expect([other.vm.error, other.vm.loading]).toEqual(['Network', false])
	})

	it('labels recorded values, durations, steps and marks', async () => {
		analytics.compareRuns.mockResolvedValueOnce(result)
		const w = await mountIt(RunCompare)
		expect([w.vm.recorded('gpt'), w.vm.recorded('')]).toEqual(['gpt', 'Not recorded for this run'])
		expect([w.vm.duration(1530), w.vm.duration(null)]).toEqual(['1.5 s', 'Not recorded for this run'])
		expect([w.vm.stepText(null), w.vm.stepText({ name: 'fetch' }), w.vm.stepText({ name: 'fetch', outcome: 'ok' })]).toEqual(['-', 'fetch', 'fetch (ok)'])
		expect(['same', 'only-left', 'only-right', 'different-outcome', 'x'].map(w.vm.markLabel)).toEqual(['Same', 'Only in run A', 'Only in run B', 'Different outcome', 'x'])
	})
})
