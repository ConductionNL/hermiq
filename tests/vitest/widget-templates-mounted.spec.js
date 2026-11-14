/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The compliance operations, eval baseline, skill link and agent skills
 * widgets, fully mounted so their templates render: the error cards, the
 * busy icons, the empty states and the buttons each wired to its method.
 * Their API and stores are doubled; the @nextcloud/vue stand-ins render
 * their slots.
 */

import { state } from '@nextcloud/initial-state'
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const toasts = []
vi.mock('@nextcloud/dialogs', () => ({
	showError: (m) => toasts.push(['error', m]),
	showSuccess: (m) => toasts.push(['success', m]),
}))
vi.mock('../../src/api/skills.js', () => ({ listSkills: vi.fn(), installSkill: vi.fn(), uninstallSkill: vi.fn() }))
vi.mock('../../src/api/tenantOps.js', () => ({ getAuditExport: vi.fn(), getIncidents: vi.fn(), getRetention: vi.fn(), setRetention: vi.fn() }))
const datasetStore = { registerObjectType: vi.fn(), fetchObject: vi.fn(), saveObject: vi.fn() }
const agentStore = { registerObjectType: vi.fn(), fetchObject: vi.fn(), saveObject: vi.fn() }
vi.mock('../../src/store/store.js', () => ({ useEvalDatasetStore: () => datasetStore, useAgentStore: () => agentStore }))

const skillsApi = await import('../../src/api/skills.js')
const ops = await import('../../src/api/tenantOps.js')
const { default: ComplianceOperations } = await import('../../src/widgets/ComplianceOperations.vue')
const { default: AgentEvalBaselineWidget } = await import('../../src/widgets/AgentEvalBaselineWidget.vue')
const { default: SkillLinkPanel } = await import('../../src/widgets/SkillLinkPanel.vue')
const { default: AgentSkillsWidget } = await import('../../src/widgets/AgentSkillsWidget.vue')

/**
 * Translate as the identity.
 *
 * @param {string} app The app id.
 * @param {string} text The text.
 * @return {string} The text.
 */
const t = (app, text) => text

/**
 * Pluralise as the identity of the form, filling %n.
 *
 * @param {string} app The app id.
 * @param {string} one The singular.
 * @param {string} many The plural.
 * @param {number} count The count.
 * @return {string} The filled form.
 */
const n = (app, one, many, count) => (count === 1 ? one : many).replace('%n', String(count))

/** A stand-in that renders every slot, for components the shared stub module lacks. */
const SlotsOnly = (name) => ({ name, render() { return h('div', { class: name }, Object.values(this.$slots).map((slot) => slot())) } })

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
			stubs: { NcPopover: SlotsOnly('nc-popover'), CreateIncidentDialog: { name: 'CreateIncidentDialog', props: ['show'], emits: ['close', 'created'], render: () => h('div') } },
		},
	})
	await flushPromises()
	return w
}

/**
 * The stand-in button whose aria-label or text is the given one.
 *
 * @param {object} w The wrapper.
 * @param {string} label The aria-label or visible text.
 * @return {object} The button wrapper.
 */
function button(w, label) {
	const match = w.findAllComponents({ name: 'NcButton' }).find((b) => b.attributes('data-aria-label') === label || b.text().trim() === label)
	if (!match) {
		throw new Error('No button ' + label)
	}
	return match
}

beforeEach(() => {
	toasts.length = 0
	vi.clearAllMocks()
	for (const key of Object.keys(state)) {
		delete state[key]
	}
})

describe('ComplianceOperations, rendered', () => {
	beforeEach(() => {
		ops.getIncidents.mockResolvedValue({ incidents: [] })
		ops.getRetention.mockResolvedValue({ retentionMonths: 12 })
	})

	it('shows a non-manager only the admins-only notice with its shield icon', async () => {
		const w = await mountIt(ComplianceOperations)
		const empty = w.find('nc-emptycontent')
		expect(empty.attributes('data-name')).toBe('Organisation admins only')
		expect(empty.find('svg').exists()).toBe(true)
		expect(w.find('section').exists()).toBe(false)
	})

	it('renders each load error in its own card and the export count', async () => {
		state.can_manage_killswitch = true
		ops.getIncidents.mockRejectedValueOnce(new Error('Incidents down'))
		ops.getRetention.mockRejectedValueOnce({ response: { data: { error: 'Retention down' } } })
		const w = await mountIt(ComplianceOperations)
		const cards = w.findAll('nc-notecard').map((c) => [c.attributes('data-heading'), c.text()])
		expect(cards).toEqual([['Incident error', 'Incidents down'], ['Retention error', 'Retention down']])
		expect(w.text()).not.toContain('No incidents recorded yet.')

		ops.getAuditExport.mockRejectedValueOnce({ response: { data: { error: 'Too big' } } })
		await button(w, 'Export AI Act audit trail').vm.$emit('click')
		await flushPromises()
		expect(w.findAll('nc-notecard').map((c) => c.text())).toContain('Too big')
	})

	it('opens and closes the incident dialog from its button', async () => {
		state.can_manage_killswitch = true
		const w = await mountIt(ComplianceOperations)
		expect(w.text()).toContain('No incidents recorded yet.')
		const dialog = w.findComponent({ name: 'CreateIncidentDialog' })
		expect(dialog.props('show')).toBe(false)
		await button(w, 'Open incident').vm.$emit('click')
		expect(dialog.props('show')).toBe(true)
		await dialog.vm.$emit('close')
		expect(dialog.props('show')).toBe(false)
	})

	it('shows the busy icon while exporting', async () => {
		state.can_manage_killswitch = true
		let finish
		ops.getAuditExport.mockReturnValueOnce(new Promise((resolve, reject) => { finish = reject }))
		const w = await mountIt(ComplianceOperations)
		const exportButton = button(w, 'Export AI Act audit trail')
		await exportButton.vm.$emit('click')
		expect(exportButton.findComponent({ name: 'NcLoadingIcon' }).exists()).toBe(true)
		finish(new Error('Stopped'))
		await flushPromises()
		expect(exportButton.findComponent({ name: 'NcLoadingIcon' }).exists()).toBe(false)
	})

	it('saves the period typed in the retention field', async () => {
		state.can_manage_killswitch = true
		ops.setRetention.mockResolvedValueOnce({ retentionMonths: 36 })
		const w = await mountIt(ComplianceOperations)
		await w.findComponent({ name: 'NcTextField' }).vm.pick('36')
		expect(w.vm.retentionDraft).toBe('36')
		await button(w, 'Save').vm.$emit('click')
		await flushPromises()
		expect(ops.setRetention).toHaveBeenCalledWith(36)
	})
})

describe('AgentEvalBaselineWidget, rendered', () => {
	it('renders a load failure as an error card', async () => {
		agentStore.fetchObject.mockRejectedValueOnce(new Error('Agent gone'))
		const w = await mountIt(AgentEvalBaselineWidget)
		const card = w.find('nc-notecard')
		expect([card.attributes('data-heading'), card.text()]).toEqual(['Eval baseline error', 'Agent gone'])
	})

	it('saves the mode picked in the select, with a busy icon on the button meanwhile', async () => {
		agentStore.fetchObject.mockResolvedValue({ id: 'x1', evalBaselineMode: 'joint' })
		const w = await mountIt(AgentEvalBaselineWidget)
		expect(w.find('.nc-popover svg').exists()).toBe(true)
		const select = w.findComponent({ name: 'NcSelect' })
		expect(JSON.parse(select.attributes('data-modelvalue')).value).toBe('joint')

		await select.vm.pick(w.vm.modeOptions[1])
		expect(w.vm.isDirty).toBe(true)
		let finish
		agentStore.saveObject.mockReturnValueOnce(new Promise((resolve) => { finish = resolve }))
		const save = button(w, 'Save')
		await save.vm.$emit('click')
		expect(save.findComponent({ name: 'NcLoadingIcon' }).exists()).toBe(true)
		finish()
		await flushPromises()
		expect(agentStore.saveObject).toHaveBeenCalledWith('agent', { id: 'x1', evalBaselineMode: 'per-skill' })
		expect(toasts).toContainEqual(['success', 'Eval baseline mode saved.'])
		expect(save.findComponent({ name: 'NcLoadingIcon' }).exists()).toBe(false)
	})
})

describe('SkillLinkPanel, rendered', () => {
	it('renders a load failure as an error card', async () => {
		datasetStore.fetchObject.mockRejectedValueOnce({ response: { data: { error: 'Dataset gone' } } })
		skillsApi.listSkills.mockResolvedValueOnce([])
		const w = await mountIt(SkillLinkPanel)
		const card = w.find('nc-notecard')
		expect([card.attributes('data-heading'), card.text()]).toEqual(['Skill link error', 'Dataset gone'])
	})

	it('links the picked skill and unlinks one from its row button', async () => {
		datasetStore.fetchObject.mockResolvedValue({ id: 'x1', skillRefs: ['s1'] })
		skillsApi.listSkills.mockResolvedValue([{ uuid: 's1', name: 'Summarise' }, { uuid: 's2', name: 'Translate' }])
		datasetStore.saveObject.mockResolvedValue({})
		const w = await mountIt(SkillLinkPanel)
		const unlink = button(w, 'Unlink skill')
		expect(unlink.find('svg').exists()).toBe(true)

		await w.findComponent({ name: 'NcSelect' }).vm.pick({ label: 'Translate', value: 's2' })
		let finish
		datasetStore.saveObject.mockReturnValueOnce(new Promise((resolve) => { finish = resolve }))
		const link = button(w, 'Link')
		await link.vm.$emit('click')
		expect(link.findComponent({ name: 'NcLoadingIcon' }).exists()).toBe(true)
		finish()
		await flushPromises()
		expect(datasetStore.saveObject).toHaveBeenLastCalledWith('evaldataset', { id: 'x1', skillRefs: ['s1', 's2'] })

		await button(w, 'Unlink skill').vm.$emit('click')
		await flushPromises()
		expect(datasetStore.saveObject).toHaveBeenLastCalledWith('evaldataset', { id: 'x1', skillRefs: [] })
		expect(toasts).toEqual([['success', 'Skill linked.'], ['success', 'Skill unlinked.']])
	})
})

describe('AgentSkillsWidget, rendered', () => {
	it('lists no skills when the catalogue cannot be read', async () => {
		agentStore.fetchObject.mockResolvedValueOnce({ id: 'x1', skillInstalls: [] })
		skillsApi.listSkills.mockRejectedValueOnce(new Error('Catalogue down'))
		const w = await mountIt(AgentSkillsWidget)
		expect(w.vm.skills).toEqual([])
		expect(w.text()).toContain('No skills installed yet.')
	})

	it('attaches the picked skill and detaches one from its row button', async () => {
		agentStore.fetchObject.mockResolvedValue({ id: 'x1', skillInstalls: ['s1'] })
		skillsApi.listSkills.mockResolvedValue([{ uuid: 's1', name: 'Summarise' }, { uuid: 's2', name: 'Translate' }])
		skillsApi.uninstallSkill.mockResolvedValue({})
		const w = await mountIt(AgentSkillsWidget)
		const detach = button(w, 'Detach skill')
		expect(detach.find('svg').exists()).toBe(true)
		expect(w.find('.agent-skills-widget__name').text()).toBe('Summarise')

		await w.findComponent({ name: 'NcSelect' }).vm.pick({ label: 'Translate', value: 's2' })
		let finish
		skillsApi.installSkill.mockReturnValueOnce(new Promise((resolve) => { finish = resolve }))
		const attach = button(w, 'Attach')
		await attach.vm.$emit('click')
		expect(attach.findComponent({ name: 'NcLoadingIcon' }).exists()).toBe(true)
		finish()
		await flushPromises()
		expect(skillsApi.installSkill).toHaveBeenCalledWith('s2', 'x1')

		await button(w, 'Detach skill').vm.$emit('click')
		await flushPromises()
		expect(skillsApi.uninstallSkill).toHaveBeenCalledWith('s1', 'x1')
		expect(toasts).toEqual([['success', 'Skill attached.'], ['success', 'Skill detached.']])
	})
})
