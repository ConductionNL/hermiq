/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The maturity dots cell, the agent memory and tool activity widgets, and
 * what the agent template row actions render per template state.
 */

import { flushPromises, mount, shallowMount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const bus = []
vi.mock('@nextcloud/event-bus', () => ({ emit: (name, payload) => bus.push([name, payload]) }))
vi.mock('../../src/api/agentTemplates.js', () => ({
	approveAgentTemplate: vi.fn(),
	exportAgentTemplate: vi.fn(),
	instantiateAgentTemplate: vi.fn(),
}))

const templates = await import('../../src/api/agentTemplates.js')
const { default: SkillMaturityDots } = await import('../../src/widgets/SkillMaturityDots.vue')
const { default: AgentMemoryWidget } = await import('../../src/widgets/AgentMemoryWidget.vue')
const { default: AgentToolActivityWidget } = await import('../../src/widgets/AgentToolActivityWidget.vue')
const { default: AgentTemplateRowActions } = await import('../../src/widgets/AgentTemplateRowActions.vue')

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
 * Pluralise as English, filling %n.
 *
 * @param {string} app The app id.
 * @param {string} one The singular.
 * @param {string} many The plural.
 * @param {number} count The count.
 * @return {string} The text.
 */
const n = (app, one, many, count) => (count === 1 ? one : many).replace('%n', String(count))

beforeEach(() => {
	bus.length = 0
	vi.clearAllMocks()
})

describe('SkillMaturityDots', () => {
	it.each([
		[3, 3],
		['5', 5],
		[2.9, 2],
		[-1, 0],
		[12, 7],
		['high', 0],
		[null, 0],
	])('fills %p as level %i of seven', (value, level) => {
		const w = mount(SkillMaturityDots, { props: { value }, global: { mocks: { t } } })

		expect(w.findAll('.skill-maturity-dots__dot')).toHaveLength(7)
		expect(w.findAll('.skill-maturity-dots__dot--filled')).toHaveLength(level)
		expect(w.find('.skill-maturity-dots__label').text()).toBe(`L${level}`)
		expect(w.attributes('aria-label')).toBe(`Maturity level ${level} of 7`)
		expect(w.attributes('title')).toBe(`Maturity level ${level} of 7`)
		expect(w.attributes('role')).toBe('img')
	})

	it('fills the first dots, in order', () => {
		const w = mount(SkillMaturityDots, { props: { value: 2 }, global: { mocks: { t } } })

		expect(w.findAll('.skill-maturity-dots__dot').map((dot) => dot.classes().includes('skill-maturity-dots__dot--filled')))
			.toEqual([true, true, false, false, false, false, false])
	})
})

describe('the agent detail widgets', () => {
	it('hands the routed agent to the memory panel', () => {
		const w = shallowMount(AgentMemoryWidget, { global: { mocks: { t, $route: { params: { id: 'a7' } } } } })

		expect(w.findComponent({ name: 'AgentMemoryPanel' }).props('agentId')).toBe('a7')
	})

	it('hands the routed agent to the tool invocation table under its heading', () => {
		const w = shallowMount(AgentToolActivityWidget, { global: { mocks: { t, $route: { params: { id: 'a8' } } } } })

		expect(w.find('h3').text()).toBe('Tool activity')
		expect(w.findComponent({ name: 'ToolInvocationTable' }).props('agentId')).toBe('a8')
	})
})

describe('AgentTemplateRowActions rendering', () => {
	/**
	 * Mount the row actions with their children rendered.
	 *
	 * @param {object} row The template row.
	 * @return {Promise<{w: object, router: object}>} The wrapper and router double.
	 */
	async function mountRow(row) {
		const router = { push: vi.fn() }
		const w = mount(AgentTemplateRowActions, {
			props: { row },
			global: {
				mocks: { t, n, $router: router },
				stubs: { AgentTemplateExportModal: true, AgentTemplatePublishModal: true },
			},
		})
		await flushPromises()
		return { w, router }
	}

	/**
	 * The row's own buttons, by label.
	 *
	 * @param {object} w The wrapper.
	 * @return {Array<string>} The labels.
	 */
	function labels(w) {
		return rowButtons(w).map((b) => b.text())
	}

	/**
	 * The row's own button components.
	 *
	 * @param {object} w The wrapper.
	 * @return {Array<object>} The buttons.
	 */
	function rowButtons(w) {
		return w.findAllComponents({ name: 'NcButton' }).filter((b) => b.element.parentElement.classList.contains('agent-template-row-actions__buttons'))
	}

	it.each([
		['active', ['Use this template', 'Export', 'Publish to GitHub']],
		['quarantined', ['Approve', 'Export']],
		['retired', ['Export']],
	])('a %s template offers %p', async (state, expected) => {
		const { w } = await mountRow({ uuid: 't1', state })

		expect(labels(w)).toEqual(expected)
	})

	it('says which model replaced the suggested one and how many skills were skipped', async () => {
		templates.instantiateAgentTemplate.mockResolvedValue({
			agent: { uuid: 'a3' },
			modelCoerced: true,
			requestedProvider: 'openai',
			requestedModel: 'gpt',
			resolvedProvider: 'mistral',
			resolvedModel: 'small',
			unresolvedSkillRefs: ['k1', 'k2'],
		})
		const { w, router } = await mountRow({ uuid: 't1', state: 'active' })

		await rowButtons(w)[0].vm.$emit('click')
		await flushPromises()

		const note = w.findAll('nc-notecard').find((card) => card.attributes('data-type') === 'info')
		expect(note.attributes('data-heading')).toBe('Agent created')
		expect(note.findAll('p').map((p) => p.text())).toEqual([
			"The suggested model (openai/gpt) is outside your organisation's model policy. The agent was created with mistral/small instead.",
			'2 suggested skills could not be found in your organisation and were skipped.',
		])
		expect(router.push).not.toHaveBeenCalled()

		await note.findComponent({ name: 'NcButton' }).vm.$emit('click')
		expect(router.push).toHaveBeenCalledWith('/agents/a3')
		expect(w.findAll('nc-notecard')).toHaveLength(0)
	})

	it('names one skipped skill in the singular, without a model line', async () => {
		templates.instantiateAgentTemplate.mockResolvedValue({ agent: { uuid: 'a4' }, unresolvedSkillRefs: ['k1'] })
		const { w } = await mountRow({ uuid: 't1', state: 'active' })

		await w.vm.doInstantiate()
		await flushPromises()

		const paragraphs = w.findAll('nc-notecard p').map((p) => p.text())
		expect(paragraphs).toEqual(['1 suggested skill could not be found in your organisation and was skipped.'])
	})

	it('shows the export modal with the package, and closes it', async () => {
		templates.exportAgentTemplate.mockResolvedValue('{"name":"T"}')
		const { w } = await mountRow({ uuid: 't1', state: 'retired' })

		await rowButtons(w)[0].vm.$emit('click')
		await flushPromises()

		const modal = w.findComponent({ name: 'AgentTemplateExportModal' })
		expect(modal.props('exportedPackage')).toBe('{"name":"T"}')
		await modal.vm.$emit('close')
		expect(w.findComponent({ name: 'AgentTemplateExportModal' }).exists()).toBe(false)
	})

	it('opens the publish modal for this template and shows where it was published', async () => {
		const { w } = await mountRow({ id: 't-id', state: 'active' })

		await rowButtons(w)[2].vm.$emit('click')
		const modal = w.findComponent({ name: 'AgentTemplatePublishModal' })
		expect(modal.props('templateId')).toBe('t-id')

		await modal.vm.$emit('published', 'https://github.com/o/r')
		expect(w.findComponent({ name: 'AgentTemplatePublishModal' }).exists()).toBe(false)
		const notice = w.find('nc-notecard')
		expect([notice.attributes('data-type'), notice.text()]).toEqual(['success', 'Published to https://github.com/o/r'])
		expect(bus).toEqual([['cn:page:refresh', {}]])
	})

	it('shows the server error of a failed export', async () => {
		templates.exportAgentTemplate.mockRejectedValue({ response: { data: { error: 'Template is quarantined' } } })
		const { w } = await mountRow({ uuid: 't1', state: 'retired' })

		await w.vm.doExport()
		await flushPromises()

		const card = w.find('nc-notecard')
		expect([card.attributes('data-type'), card.text()]).toEqual(['error', 'Template is quarantined'])
		expect(w.findComponent({ name: 'AgentTemplateExportModal' }).exists()).toBe(false)
	})
})
