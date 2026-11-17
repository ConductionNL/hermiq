/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The eval run panel, the compliance operations widget and the GitHub template
 * store fully mounted, so their rendered states (load errors, an expanded paired
 * run, an export in progress, install notices, unparseable cards) and the
 * controls inside them are driven through the template.
 */

import axios from '@nextcloud/axios'
import { state } from '@nextcloud/initial-state'
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const toasts = []
vi.mock('@nextcloud/dialogs', () => ({
	showError: (m) => toasts.push(['error', m]),
	showSuccess: (m) => toasts.push(['success', m]),
}))
vi.mock('@nextcloud/event-bus', () => ({ emit: vi.fn() }))
vi.mock('../../src/api/evals.js', () => ({ runEval: vi.fn() }))
vi.mock('../../src/api/skills.js', () => ({ listSkills: vi.fn(), searchGithubSkills: vi.fn(), installGithubSkill: vi.fn() }))
vi.mock('../../src/api/agentTemplates.js', () => ({ searchGithubTemplates: vi.fn(), installGithubTemplate: vi.fn() }))
vi.mock('../../src/api/tenantOps.js', () => ({ getAuditExport: vi.fn(), getIncidents: vi.fn(), getRetention: vi.fn(), setRetention: vi.fn() }))
const agentStore = { registerObjectType: vi.fn(), fetchCollection: vi.fn() }
const runStore = { registerObjectType: vi.fn(), fetchCollection: vi.fn() }
const datasetStore = { registerObjectType: vi.fn(), fetchObject: vi.fn() }
vi.mock('../../src/store/store.js', () => ({
	useAgentStore: () => agentStore,
	useEvalRunStore: () => runStore,
	useEvalDatasetStore: () => datasetStore,
}))

const evals = await import('../../src/api/evals.js')
const skills = await import('../../src/api/skills.js')
const templates = await import('../../src/api/agentTemplates.js')
const ops = await import('../../src/api/tenantOps.js')
const { default: EvalRunPanelWidget } = await import('../../src/widgets/EvalRunPanelWidget.vue')
const { default: ComplianceOperations } = await import('../../src/widgets/ComplianceOperations.vue')
const { default: Store } = await import('../../src/widgets/AgentTemplateGithubStore.vue')

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
 * Pluralise with the singular for one and the plural otherwise.
 *
 * @param {string} app The app id.
 * @param {string} one The singular.
 * @param {string} many The plural.
 * @param {number} count The count.
 * @return {string} The text.
 */
const n = (app, one, many, count) => (count === 1 ? one : many).replace('%n', String(count))

/**
 * Fully mount a component on the route of object x1 and let it load.
 *
 * @param {object} component The component.
 * @return {Promise<object>} The wrapper.
 */
async function mountIt(component) {
	const w = mount(component, {
		global: {
			mocks: { t, n, $route: { params: { id: 'x1' } } },
			stubs: { CreateIncidentDialog: true, ShieldIcon: true },
		},
	})
	await flushPromises()
	return w
}

/**
 * The stand-in component of a name whose text contains the label.
 *
 * @param {object} w The wrapper.
 * @param {string} name The component name.
 * @param {string} label The label.
 * @return {object} The component wrapper.
 */
function find(w, name, label) {
	const found = w.findAllComponents({ name }).find((c) => c.text().includes(label))
	expect(found).toBeTruthy()
	return found
}

beforeEach(() => {
	toasts.length = 0
	vi.clearAllMocks()
	for (const key of Object.keys(state)) {
		delete state[key]
	}
})

describe('EvalRunPanelWidget, rendered', () => {
	beforeEach(() => {
		agentStore.fetchCollection.mockResolvedValue([{ uuid: 'a1', name: 'Bot' }])
		datasetStore.fetchObject.mockResolvedValue({ id: 'x1', skillRefs: ['k1'] })
		skills.listSkills.mockResolvedValue([{ uuid: 'k1', name: 'Summarise' }])
		runStore.fetchCollection.mockResolvedValue([
			{
				id: 'r1', datasetId: 'x1', startedAt: '2026-02-01', passRate: 0.5, baselineMode: true, attributionMode: 'per-skill',
				regressionGateResult: 'failed', status: 'completed',
				skillResults: [{ skillId: 'k1', passRateWith: 0.5, passRateWithout: 0.25, baselineDelta: 0.25, baselineResults: [{ caseIndex: 0, passed: false, prompt: 'Without' }] }],
				results: [{ caseIndex: 0, passed: true, prompt: 'With' }],
			},
			{ uuid: 'r2', datasetId: 'x1', startedAt: '2026-01-01', status: 'failed' },
			{ id: 'r3', datasetId: 'other' },
		])
	})

	it('shows a load failure as an error card', async () => {
		agentStore.fetchCollection.mockImplementationOnce(() => {
			throw new Error('Store offline')
		})
		const w = await mountIt(EvalRunPanelWidget)
		expect(find(w, 'NcNoteCard', 'Store offline').attributes('data-type')).toBe('error')
	})

	it('treats every failed read as empty', async () => {
		agentStore.fetchCollection.mockRejectedValueOnce(new Error('x'))
		runStore.fetchCollection.mockRejectedValueOnce(new Error('x'))
		datasetStore.fetchObject.mockRejectedValueOnce(new Error('x'))
		skills.listSkills.mockRejectedValueOnce(new Error('x'))
		const w = await mountIt(EvalRunPanelWidget)
		expect([w.vm.agents, w.vm.runs, w.vm.dataset, w.vm.skills, w.vm.error]).toEqual([[], [], null, [], ''])
		expect(w.text()).toContain('Link a skill to this dataset to enable paired baseline runs.')
		expect(w.text()).toContain('No runs yet — pick an agent and run this dataset.')
	})

	it('lists only this dataset\'s runs and expands a paired run into its halves', async () => {
		const w = await mountIt(EvalRunPanelWidget)
		expect(w.findAll('.eval-run-panel-widget__table > tbody > tr')).toHaveLength(2)
		find(w, 'NcButton', 'Details').vm.$emit('click')
		await flushPromises()
		expect(w.vm.expandedRunId).toBe('r1')
		const details = w.find('.eval-run-panel-widget__details')
		expect(details.find('h4').text()).toBe('Paired baseline — per-skill marginals')
		expect(details.find('.eval-run-panel-widget__skill-table tbody').text()).toContain('Summarise')
		expect(details.findAll('.eval-run-panel-widget__case-list li').map((li) => li.text())).toEqual(['passed — With', 'failed — Without'])
		expect(find(w, 'NcButton', 'Hide details').exists()).toBe(true)
	})

	it('picks an agent, switches on the baseline and runs it with a spinner', async () => {
		let resolve
		evals.runEval.mockReturnValueOnce(new Promise((r) => { resolve = r }))
		const w = await mountIt(EvalRunPanelWidget)
		w.findComponent({ name: 'NcSelect' }).vm.pick({ label: 'Bot', value: 'a1' })
		w.findComponent({ name: 'NcCheckboxRadioSwitch' }).vm.pick(true)
		await flushPromises()
		expect(w.vm.selectedAgent).toEqual({ label: 'Bot', value: 'a1' })
		expect(w.vm.baseline).toBe(true)
		expect(w.find('.eval-run-panel-widget__hint').text()).toBe(w.vm.costNote)

		const run = find(w, 'NcButton', 'Run')
		run.vm.$emit('click')
		await w.vm.$nextTick()
		expect(find(w, 'NcButton', 'Run').findComponent({ name: 'NcLoadingIcon' }).exists()).toBe(true)
		resolve({ passRate: 1 })
		await flushPromises()
		expect(evals.runEval).toHaveBeenCalledWith('x1', 'a1', { baseline: true })
		expect(toasts).toEqual([['success', 'Eval run complete: 100% passed.']])
	})
})

describe('ComplianceOperations, rendered', () => {
	beforeEach(() => {
		state.can_manage_killswitch = true
		ops.getIncidents.mockResolvedValue({ incidents: [{ uuid: 'i1', description: 'Leak', impact: 'Low', actionsTaken: 'Rotated', createdBy: 'ann' }] })
		ops.getRetention.mockResolvedValue({ retentionMonths: 12 })
	})

	it('shows the admins-only notice to a viewer', async () => {
		state.can_manage_killswitch = false
		const w = await mountIt(ComplianceOperations)
		expect(w.findComponent({ name: 'NcEmptyContent' }).attributes('data-name')).toBe('Organisation admins only')
		expect(ops.getIncidents).not.toHaveBeenCalled()
	})

	it('lists incidents and opens the incident dialog from its button', async () => {
		const w = await mountIt(ComplianceOperations)
		expect(w.find('.compliance-operations__incident-description').text()).toBe('Leak')
		find(w, 'NcButton', 'Open incident').vm.$emit('click')
		await flushPromises()
		const dialog = w.findComponent({ name: 'CreateIncidentDialog' })
		expect(w.vm.showIncidentDialog).toBe(true)
		expect(dialog.attributes('show')).toBe('true')
		dialog.vm.$emit('close')
		await flushPromises()
		expect(w.vm.showIncidentDialog).toBe(false)
	})

	it('shows incident, audit and retention errors in their cards', async () => {
		ops.getIncidents.mockRejectedValueOnce(new Error('Incidents down'))
		ops.getRetention.mockRejectedValueOnce(new Error('Retention down'))
		ops.getAuditExport.mockRejectedValueOnce(new Error('Too big'))
		const w = await mountIt(ComplianceOperations)
		find(w, 'NcButton', 'Export AI Act audit trail').vm.$emit('click')
		await flushPromises()
		const errors = w.findAllComponents({ name: 'NcNoteCard' }).map((c) => c.text())
		expect(errors).toEqual(['Incidents down', 'Too big', 'Retention down'])
	})

	it('shows a spinner while exporting and the exported count after', async () => {
		URL.createObjectURL = vi.fn(() => 'blob:x')
		URL.revokeObjectURL = vi.fn()
		const anchor = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {})
		let resolve
		ops.getAuditExport.mockReturnValueOnce(new Promise((r) => { resolve = r }))
		const w = await mountIt(ComplianceOperations)
		find(w, 'NcButton', 'Export AI Act audit trail').vm.$emit('click')
		await w.vm.$nextTick()
		expect(find(w, 'NcButton', 'Export AI Act audit trail').findComponent({ name: 'NcLoadingIcon' }).exists()).toBe(true)
		resolve({ recordCount: 1 })
		await flushPromises()
		expect(w.find('.compliance-operations__export-result').text()).toBe('Exported 1 record.')
		anchor.mockRestore()
	})

	it('saves the retention period typed into its field', async () => {
		ops.setRetention.mockResolvedValueOnce({ retentionMonths: 18 })
		const w = await mountIt(ComplianceOperations)
		w.findComponent({ name: 'NcTextField' }).vm.pick('18')
		find(w, 'NcButton', 'Save').vm.$emit('click')
		await flushPromises()
		expect(ops.setRetention).toHaveBeenCalledWith(18)
		expect(w.vm.retentionDraft).toBe(18)
		expect(toasts).toEqual([['success', 'Retention period saved.']])
	})
})

describe('AgentTemplateGithubStore, rendered', () => {
	const CARDS = [
		{ kind: 'skill', owner: 'o', repo: 'broken', stars: 1, unparseable: true },
		{ kind: 'agent-template', owner: 'o', repo: 'bot', name: 'Bot', stars: 3 },
	]

	beforeEach(() => {
		templates.searchGithubTemplates.mockResolvedValue({ outcome: 'ok', cards: [CARDS[1]] })
		skills.searchGithubSkills.mockResolvedValue({ outcome: 'ok', cards: [CARDS[0]] })
		vi.spyOn(axios, 'get').mockResolvedValue({ data: { results: [{ id: 'c1', name: 'Mine', provider: 'github' }] } })
	})

	afterEach(() => {
		vi.restoreAllMocks()
		vi.useRealTimers()
	})

	it('marks an unparseable card instead of offering Install', async () => {
		const w = await mountIt(Store)
		const cards = w.findAll('.agent-template-github-store__card')
		expect(cards).toHaveLength(2)
		expect(cards[1].text()).toContain('This repo does not carry a readable hermiq-skill.md — it cannot be installed.')
		expect(cards[1].findComponent({ name: 'NcButton' }).exists()).toBe(false)
		expect(cards[0].findComponent({ name: 'NcButton' }).text()).toBe('Install')
	})

	it('installs a card from its button, showing progress then the notice', async () => {
		let resolve
		templates.installGithubTemplate.mockReturnValueOnce(new Promise((r) => { resolve = r }))
		const w = await mountIt(Store)
		find(w, 'NcButton', 'Install').vm.$emit('click')
		await w.vm.$nextTick()
		expect(find(w, 'NcButton', 'Installing…').exists()).toBe(true)
		resolve({})
		await flushPromises()
		expect(templates.installGithubTemplate).toHaveBeenCalledWith({ owner: 'o', repo: 'bot', credentialId: null })
		expect(find(w, 'NcNoteCard', 'Installed "Bot"').attributes('data-type')).toBe('success')
	})

	it('shows a failed install as an error card', async () => {
		templates.installGithubTemplate.mockRejectedValueOnce(new Error('Quarantine full'))
		const w = await mountIt(Store)
		find(w, 'NcButton', 'Install').vm.$emit('click')
		await flushPromises()
		expect(find(w, 'NcNoteCard', 'Quarantine full').attributes('data-type')).toBe('error')
	})

	it('says GitHub is unreachable when every search fails', async () => {
		templates.searchGithubTemplates.mockRejectedValue(new Error('x'))
		skills.searchGithubSkills.mockRejectedValue(new Error('x'))
		const w = await mountIt(Store)
		expect(find(w, 'NcNoteCard', 'Could not reach GitHub right now.').attributes('data-type')).toBe('warning')
	})

	it('shows a spinner while searching and searches again from each control', async () => {
		vi.useFakeTimers()
		const w = mount(Store, { global: { mocks: { t } } })
		await w.vm.$nextTick()
		expect(w.findComponent({ name: 'NcLoadingIcon' }).exists()).toBe(true)
		await vi.runAllTimersAsync()
		templates.searchGithubTemplates.mockClear()

		const [kind, credential] = w.findAllComponents({ name: 'NcSelect' })
		kind.vm.pick({ label: 'Agent templates', value: 'agent-template' })
		await vi.runAllTimersAsync()
		expect(templates.searchGithubTemplates).toHaveBeenCalledTimes(1)
		credential.vm.pick({ label: 'Mine', value: 'c1' })
		await vi.runAllTimersAsync()
		expect(templates.searchGithubTemplates).toHaveBeenLastCalledWith('', 'c1')
		w.findComponent({ name: 'NcTextField' }).vm.pick('bot')
		await vi.runAllTimersAsync()
		expect(templates.searchGithubTemplates).toHaveBeenLastCalledWith('bot', 'c1')
		expect(skills.searchGithubSkills).toHaveBeenCalledTimes(1)
	})
})
