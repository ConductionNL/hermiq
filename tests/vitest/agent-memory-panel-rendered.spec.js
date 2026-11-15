/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The agent memory panel rendered: the budget bar and consolidation nudge,
 * adding a fact from the field and the button, the forgotten-facts switch,
 * correcting and forgetting an entry through its row buttons and the
 * forget dialog. The logic is pinned on the instance in
 * skill-memory-schedule.spec.js; this file drives the template.
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../../src/api/memory.js', () => ({
	addMemory: vi.fn(),
	consolidateMemory: vi.fn(),
	correctMemoryEntry: vi.fn(),
	forgetMemoryEntry: vi.fn(),
	getMemory: vi.fn(),
}))
vi.mock('@nextcloud/vue', async () => {
	const { defineComponent, h } = await import('vue')
	const stub = (name, props, tag = 'div') => defineComponent({
		name,
		props,
		emits: ['click', 'update:modelValue', 'keydown'],
		render() {
			return h(tag, {
				class: name,
				disabled: tag === 'button' ? (this.disabled || undefined) : undefined,
				'data-label': this.label ?? this.ariaLabel ?? this.name,
				onClick: () => this.$emit('click'),
			}, [this.$slots.icon?.(), this.$slots.default?.()])
		},
	})
	return {
		NcButton: stub('NcButton', ['variant', 'disabled', 'ariaLabel'], 'button'),
		NcCheckboxRadioSwitch: stub('NcCheckboxRadioSwitch', ['modelValue', 'type']),
		NcEmptyContent: stub('NcEmptyContent', ['name', 'description']),
		NcLoadingIcon: stub('NcLoadingIcon', ['size']),
		NcNoteCard: stub('NcNoteCard', ['type', 'heading']),
		NcTextField: stub('NcTextField', ['modelValue', 'label', 'disabled']),
	}
})

vi.mock('../../src/dialogs/ForgetMemoryDialog.vue', () => ({
	default: { name: 'ForgetMemoryDialog', props: ['text', 'busy'], emits: ['close', 'confirm'], template: '<div class="forget-dialog" />' },
}))

const memoryApi = await import('../../src/api/memory.js')
const { default: AgentMemoryPanel } = await import('../../src/components/AgentMemoryPanel.vue')

const t = (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m))
const n = (app, one, many, count) => (count === 1 ? one : many).replace('%n', String(count))

/**
 * Mount the panel for agent a1 over a stored memory.
 *
 * @param {object} memory The memory getMemory answers.
 * @return {Promise<object>} The wrapper.
 */
async function mountPanel(memory) {
	memoryApi.getMemory.mockResolvedValue(memory)
	const wrapper = mount(AgentMemoryPanel, {
		props: { agentId: 'a1' },
		global: {
			mocks: { t, n },
			stubs: {
				AlertIcon: true,
				BrainIcon: true,
				EyeOffIcon: true,
			},
		},
	})
	await flushPromises()
	return wrapper
}

/**
 * The button with an aria label, or text, matching.
 *
 * @param {object} wrapper The panel.
 * @param {string} label The aria label or text.
 * @return {object} The button wrapper.
 */
function button(wrapper, label) {
	return wrapper.findAll('button.NcButton').find((b) => b.attributes('data-label') === label || b.text() === label)
}

const entries = [
	{ id: 'e1', text: 'Prefers Dutch', createdAt: '2026-01-02T10:00:00Z' },
	{ id: 'e2', text: 'Old fact', deletedAt: '2026-01-03' },
]

beforeEach(() => {
	vi.clearAllMocks()
})

describe('AgentMemoryPanel rendered', () => {
	it('shows the budget used and no nudge while within it', async () => {
		const wrapper = await mountPanel({ entries, charBudget: 100, needsConsolidation: false })
		expect(wrapper.find('.agent-memory-panel__budget-count').text()).toBe('13 / 100 characters')
		expect(wrapper.find('.agent-memory-panel__budget-fill').attributes('style')).toContain('width: 13%')
		expect(wrapper.find('.agent-memory-panel__nudge').exists()).toBe(false)
	})

	it('nudges to consolidate when over budget and consolidates from the button', async () => {
		memoryApi.consolidateMemory.mockResolvedValue({ entries: [], needsConsolidation: false })
		const wrapper = await mountPanel({ entries, charBudget: 5, needsConsolidation: true })
		expect(wrapper.find('.agent-memory-panel__budget-bar--over').exists()).toBe(true)
		expect(wrapper.find('.agent-memory-panel__nudge').text()).toContain('Over budget — consolidation suggested.')
		await button(wrapper, 'Consolidate memory').trigger('click')
		await flushPromises()
		expect(memoryApi.consolidateMemory).toHaveBeenCalledWith('a1')
	})

	it('adds the typed fact from the button, which is disabled while the field is blank', async () => {
		memoryApi.addMemory.mockResolvedValue({ entries })
		const wrapper = await mountPanel({ entries: [] })
		expect(button(wrapper, 'Add fact').attributes('disabled')).toBeDefined()
		const field = wrapper.findAllComponents({ name: 'NcTextField' })[0]
		await field.vm.$emit('update:modelValue', ' Likes tea ')
		expect(button(wrapper, 'Add fact').attributes('disabled')).toBeUndefined()
		await button(wrapper, 'Add fact').trigger('click')
		await flushPromises()
		expect(memoryApi.addMemory).toHaveBeenCalledWith('a1', 'Likes tea')
	})

	it('shows the empty state without entries, and the entries otherwise', async () => {
		const empty = await mountPanel({ entries: [] })
		expect(empty.find('.NcEmptyContent').attributes('data-label')).toBe('No memory yet')
		expect(empty.find('.NcCheckboxRadioSwitch').exists()).toBe(false)

		const full = await mountPanel({ entries })
		expect(full.find('.agent-memory-panel__subhead').text()).toBe('Memory entries (1)')
		expect(full.findAll('.agent-memory-panel__entry-text').map((e) => e.text())).toEqual(['Prefers Dutch'])
	})

	it('reveals forgotten facts through the switch, marked and without actions', async () => {
		const wrapper = await mountPanel({ entries })
		const toggle = wrapper.findComponent({ name: 'NcCheckboxRadioSwitch' })
		expect(toggle.text()).toBe('Show 1 forgotten fact')
		await toggle.vm.$emit('update:modelValue', true)
		const rows = wrapper.findAll('.agent-memory-panel__entry')
		expect(rows).toHaveLength(2)
		expect(rows[1].classes()).toContain('agent-memory-panel__entry--forgotten')
		expect(rows[1].find('.agent-memory-panel__entry-forgotten').text()).toBe('Forgotten')
		expect(rows[1].find('.agent-memory-panel__entry-actions').exists()).toBe(false)
		expect(rows[0].find('.agent-memory-panel__entry-actions').exists()).toBe(true)
	})

	it('corrects an entry in place, and cancels back to the text', async () => {
		memoryApi.correctMemoryEntry.mockResolvedValue({ entries: [{ id: 'e1', text: 'Prefers English' }] })
		const wrapper = await mountPanel({ entries })
		await button(wrapper, 'Correct this fact').trigger('click')
		expect(wrapper.find('.agent-memory-panel__entry-text').exists()).toBe(false)
		await button(wrapper, 'Cancel').trigger('click')
		expect(wrapper.find('.agent-memory-panel__entry-text').text()).toBe('Prefers Dutch')

		await button(wrapper, 'Correct this fact').trigger('click')
		const edit = wrapper.findAllComponents({ name: 'NcTextField' }).find((f) => f.props('label') === 'Corrected fact')
		expect(edit.props('modelValue')).toBe('Prefers Dutch')
		await edit.vm.$emit('update:modelValue', 'Prefers English')
		await button(wrapper, 'Save').trigger('click')
		await flushPromises()
		expect(memoryApi.correctMemoryEntry).toHaveBeenCalledWith('a1', 'e1', 'Prefers English')
		expect(wrapper.find('.agent-memory-panel__entry-text').text()).toBe('Prefers English')
	})

	it('asks before forgetting, and forgets on confirm or leaves it on close', async () => {
		memoryApi.forgetMemoryEntry.mockResolvedValue({ entries: [] })
		const wrapper = await mountPanel({ entries })
		await button(wrapper, 'Forget this fact').trigger('click')
		const dialog = wrapper.findComponent({ name: 'ForgetMemoryDialog' })
		expect(dialog.props('text')).toBe('Prefers Dutch')
		await dialog.vm.$emit('close')
		expect(wrapper.findComponent({ name: 'ForgetMemoryDialog' }).exists()).toBe(false)
		expect(memoryApi.forgetMemoryEntry).not.toHaveBeenCalled()

		await button(wrapper, 'Forget this fact').trigger('click')
		await wrapper.findComponent({ name: 'ForgetMemoryDialog' }).vm.$emit('confirm')
		await flushPromises()
		expect(memoryApi.forgetMemoryEntry).toHaveBeenCalledWith('a1', 'e1')
	})

	it('shows a load failure as an error card', async () => {
		memoryApi.getMemory.mockRejectedValue(new Error('Memory down'))
		const wrapper = mount(AgentMemoryPanel, {
			props: { agentId: 'a1' },
			global: { mocks: { t, n }, stubs: { AlertIcon: true, BrainIcon: true, EyeOffIcon: true } },
		})
		await flushPromises()
		expect(wrapper.find('.NcNoteCard').text()).toBe('Memory down')
	})
})
