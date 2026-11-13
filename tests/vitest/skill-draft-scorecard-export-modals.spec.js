/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The skill draft edit and reject modals, the maturity scorecard, the agent
 * export modal and the exported package modal, mounted with their API doubled.
 */

import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../../src/api/agentTemplates.js', () => ({
	exportAgentToTemplate: vi.fn(),
	saveAgentAsTemplate: vi.fn(),
}))

const templates = await import('../../src/api/agentTemplates.js')
const { default: SkillDraftEditModal } = await import('../../src/modals/SkillDraftEditModal.vue')
const { default: SkillDraftRejectModal } = await import('../../src/modals/SkillDraftRejectModal.vue')
const { default: SkillScorecardModal } = await import('../../src/modals/SkillScorecardModal.vue')
const { default: AgentExportModal } = await import('../../src/modals/AgentExportModal.vue')
const { default: AgentTemplateExportModal } = await import('../../src/modals/AgentTemplateExportModal.vue')

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
 * Mount a component with the translation helper and a route.
 *
 * @param {object} component The component.
 * @param {object} [props] Its props.
 * @return {object} The wrapper.
 */
function mountIt(component, props = {}) {
	return mount(component, { props, global: { mocks: { t, $route: { params: { id: 'a1' } } } } })
}

/**
 * The stand-in buttons, by their visible text.
 *
 * @param {object} wrapper The wrapper.
 * @return {Array<object>} The buttons.
 */
function buttons(wrapper) {
	return wrapper.findAllComponents({ name: 'NcButton' })
}

beforeEach(() => {
	vi.clearAllMocks()
})

afterEach(() => {
	vi.unstubAllGlobals()
})

describe('SkillDraftEditModal', () => {
	it('starts from the proposed body and saves the edited text', async () => {
		const w = mountIt(SkillDraftEditModal, { draft: { proposedBody: '# Old' } })
		const textarea = w.find('textarea')
		expect(textarea.element.value).toBe('# Old')

		await textarea.setValue('# New body')
		const [save, cancel] = buttons(w)
		expect(save.text()).toBe('Save and re-qualify')
		await save.vm.$emit('click')
		await cancel.vm.$emit('click')

		expect(w.emitted('save')).toEqual([[{ body: '# New body' }]])
		expect(w.emitted('close')).toEqual([[]])
	})

	it('starts empty for a draft without a string body, and cannot save blank text', async () => {
		const w = mountIt(SkillDraftEditModal, { draft: { proposedBody: 42 } })

		expect(w.find('textarea').element.value).toBe('')
		expect(buttons(w)[0].attributes('data-disabled')).toBe('true')

		await w.find('textarea').setValue('   ')
		expect(buttons(w)[0].attributes('data-disabled')).toBe('true')

		await w.find('textarea').setValue('text')
		expect(buttons(w)[0].attributes('data-disabled')).toBe('false')
	})

	it('says it is saving and disables both buttons while busy', () => {
		const w = mountIt(SkillDraftEditModal, { draft: { proposedBody: 'x' }, busy: true })

		expect(buttons(w)[0].text()).toBe('Saving…')
		expect(buttons(w).map((b) => b.attributes('data-disabled'))).toEqual(['true', 'true'])
	})

	it('closes when the modal closes', async () => {
		const w = mountIt(SkillDraftEditModal, { draft: {} })

		await w.findComponent({ name: 'NcModal' }).vm.$emit('close')

		expect(w.emitted('close')).toEqual([[]])
	})
})

describe('SkillDraftRejectModal', () => {
	it('rejects with the note and the learnings entries marked bad', async () => {
		const w = mountIt(SkillDraftRejectModal, { learningRefs: ['L-1', 'L-2', 'L-3'] })
		await w.find('textarea').setValue('Wrong tone')

		const boxes = w.findAllComponents({ name: 'NcCheckboxRadioSwitch' })
		expect(boxes).toHaveLength(3)
		await boxes[0].vm.$emit('update:modelValue', true)
		await boxes[2].vm.$emit('update:modelValue', true)
		await boxes[2].vm.$emit('update:modelValue', true)
		await boxes[0].vm.$emit('update:modelValue', false)

		expect(boxes.map((b) => b.attributes('data-modelvalue'))).toEqual(['false', 'false', 'true'])
		await buttons(w)[0].vm.$emit('click')

		expect(w.emitted('reject')).toEqual([[{ note: 'Wrong tone', rejectedLearningRefs: ['L-3'] }]])
	})

	it('shows no marking without learnings entries and rejects with an empty note', async () => {
		const w = mountIt(SkillDraftRejectModal)

		expect(w.findAllComponents({ name: 'NcCheckboxRadioSwitch' })).toHaveLength(0)
		expect(w.find('.skill-draft-reject__hint').exists()).toBe(false)
		await buttons(w)[0].vm.$emit('click')
		await buttons(w)[1].vm.$emit('click')

		expect(w.emitted('reject')).toEqual([[{ note: '', rejectedLearningRefs: [] }]])
		expect(w.emitted('close')).toEqual([[]])
	})

	it('says it is rejecting while busy', () => {
		const w = mountIt(SkillDraftRejectModal, { busy: true })

		expect(buttons(w)[0].text()).toBe('Rejecting…')
		expect(buttons(w).map((b) => b.attributes('data-disabled'))).toEqual(['true', 'true'])
	})
})

describe('SkillScorecardModal', () => {
	it('names each level, says whether it passed and lists its reasons', () => {
		const w = mountIt(SkillScorecardModal, {
			skillName: 'Summarise case',
			result: {
				maturityLevel: 2,
				targetLevel: 4,
				scorecard: [
					{ level: 1, passed: true },
					{ level: 2, passed: 1, reasons: [] },
					{ level: 3, passed: false, reasons: ['No examples', 'No pattern'] },
					{ level: 9, passed: false },
				],
			},
		})

		expect(w.find('.skill-scorecard-modal__subtitle').text()).toBe('Summarise case')
		expect(w.find('.skill-scorecard-modal__target').text()).toBe('Target: L4')
		const rows = w.findAll('tbody tr').map((row) => row.findAll('td').slice(0, 2).map((td) => td.text()))
		expect(rows).toEqual([
			['L1 Anatomy', 'Passed'],
			['L2 Triggering', 'Passed'],
			['L3 Patterns', 'Not passed'],
			['L9', 'Not passed'],
		])
		expect(w.findAll('tbody tr').map((row) => row.find('ul').exists())).toEqual([false, false, true, false])
		expect(w.findAll('tbody tr')[2].findAll('li').map((li) => li.text())).toEqual(['No examples', 'No pattern'])
	})

	it('shows no subtitle, target or rows when they are absent, and closes', async () => {
		const w = mountIt(SkillScorecardModal, { result: { maturityLevel: 0 } })

		expect(w.find('.skill-scorecard-modal__subtitle').exists()).toBe(false)
		expect(w.find('.skill-scorecard-modal__target').exists()).toBe(false)
		expect(w.findAll('tbody tr')).toHaveLength(0)

		await buttons(w)[0].vm.$emit('click')
		expect(w.emitted('close')).toEqual([[]])
	})
})

describe('AgentExportModal', () => {
	it('renders nothing while closed', () => {
		const w = mountIt(AgentExportModal)

		expect(w.findComponent({ name: 'NcModal' }).exists()).toBe(false)
	})

	it('saves the routed agent as a template and names it', async () => {
		templates.saveAgentAsTemplate.mockResolvedValue({ name: 'Intake helper' })
		const w = mountIt(AgentExportModal, { show: true, mode: 'save' })

		expect(w.find('h2').text()).toBe('Save as template')
		await buttons(w)[1].vm.$emit('click')
		await flushPromises()

		expect(templates.saveAgentAsTemplate).toHaveBeenCalledWith('a1')
		expect(templates.exportAgentToTemplate).not.toHaveBeenCalled()
		expect(w.findComponent({ name: 'NcNoteCard' }).text()).toBe('Saved as the template Intake helper. You find it in the Store.')
	})

	it('downloads the exported file under a name made from the agent name', async () => {
		templates.exportAgentToTemplate.mockResolvedValue(JSON.stringify({ name: 'Zaak Assistent' }))
		const created = []
		vi.stubGlobal('URL', { createObjectURL: vi.fn(() => 'blob:1'), revokeObjectURL: vi.fn() })
		const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function() {
			created.push([this.href, this.download])
		})
		const w = mountIt(AgentExportModal, { show: true, agentId: 'a9' })

		expect(w.find('h2').text()).toBe('Export agent')
		await buttons(w)[1].vm.$emit('click')
		await flushPromises()

		expect(templates.exportAgentToTemplate).toHaveBeenCalledWith('a9')
		expect(created).toEqual([['blob:1', 'zaak-assistent.hermiq-agent.json']])
		expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:1')
		expect(document.querySelectorAll('a[download]')).toHaveLength(0)
		expect(w.findComponent({ name: 'NcNoteCard' }).text()).toBe('The file is downloaded.')
		click.mockRestore()
	})

	it('names an unreadable package after the default file name', async () => {
		templates.exportAgentToTemplate.mockResolvedValue('{not json')
		vi.stubGlobal('URL', { createObjectURL: () => 'blob:2', revokeObjectURL: () => {} })
		const names = []
		const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function() {
			names.push(this.download)
		})
		const w = mountIt(AgentExportModal, { show: true })

		await buttons(w)[1].vm.$emit('click')
		await flushPromises()

		expect(names).toEqual(['agent.hermiq-agent.json'])
		click.mockRestore()
	})

	it.each([
		['save', { response: { status: 403 } }, 'Only the owner of this agent can save it as a template.'],
		['save', { response: { status: 500 } }, 'Could not save the agent as a template'],
		['export', new Error('offline'), 'Could not export the agent'],
	])('in %s mode reports a failure as the right message', async (mode, failure, message) => {
		templates.saveAgentAsTemplate.mockRejectedValue(failure)
		templates.exportAgentToTemplate.mockRejectedValue(failure)
		const w = mountIt(AgentExportModal, { show: true, mode })

		await buttons(w)[1].vm.$emit('click')
		await flushPromises()

		const note = w.findComponent({ name: 'NcNoteCard' })
		expect(note.attributes('data-type')).toBe('error')
		expect(note.text()).toBe(message)
		expect(buttons(w)[1].attributes('data-disabled')).toBe('false')
	})
})

describe('AgentTemplateExportModal', () => {
	it('shows the package read-only and closes', async () => {
		const w = mountIt(AgentTemplateExportModal, { exportedPackage: '{"name":"A"}' })

		const textarea = w.find('textarea')
		expect(textarea.element.value).toBe('{"name":"A"}')
		expect(textarea.attributes('readonly')).toBeDefined()

		await w.findComponent({ name: 'NcModal' }).vm.$emit('close')
		expect(w.emitted('close')).toEqual([[]])
	})
})
