/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The placeholder tools under an agent's instructions and the Talk delivery
 * settings, rendered through their slots: the insert menu and its event, the
 * preview button's state and hint, the filled-in preview with its warning, and
 * the delivery room picker, the new-room form and its notes.
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../../src/api/agents.js', () => ({ previewPrompt: vi.fn() }))
vi.mock('../../src/api/talk.js', () => ({ createRoom: vi.fn(), getDeliveryTarget: vi.fn(), listRooms: vi.fn(), setDeliveryTarget: vi.fn() }))
vi.mock('@nextcloud/vue', async () => {
	const { defineComponent: define, h } = await import('vue')
	const slotted = (name, tag) => define({
		name,
		props: ['type', 'menuName', 'disabled'],
		emits: ['click'],
		setup(props, { slots, emit, attrs }) {
			return () => h(tag, {
				'data-testid': attrs['data-testid'],
				'data-type': props.type,
				disabled: props.disabled,
				onClick: () => emit('click'),
			}, [slots.icon?.(), slots.default?.()])
		},
	})
	return {
		NcActions: slotted('NcActions', 'div'),
		NcActionButton: slotted('NcActionButton', 'button'),
		NcButton: slotted('NcButton', 'button'),
		NcNoteCard: slotted('NcNoteCard', 'div'),
		NcLoadingIcon: define({ name: 'NcLoadingIcon', setup: () => () => h('span', { class: 'loading-icon' }) }),
		NcSelect: define({ name: 'NcSelect', props: ['options', 'modelValue', 'loading'], emits: ['update:modelValue'], setup: () => () => h('div', { class: 'select' }) }),
		NcTextField: define({
			name: 'NcTextField',
			props: ['modelValue', 'label'],
			emits: ['update:modelValue'],
			setup(props, { emit }) {
				return () => h('input', { class: 'new-room', value: props.modelValue, onInput: (e) => emit('update:modelValue', e.target.value) })
			},
		}),
	}
})

const agentsApi = await import('../../src/api/agents.js')
const talk = await import('../../src/api/talk.js')
const { default: PromptPlaceholderTools } = await import('../../src/components/PromptPlaceholderTools.vue')
const { default: TalkDeliverySettings } = await import('../../src/components/TalkDeliverySettings.vue')

const t = (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m))
globalThis.t = t

/**
 * Mount a component in full and let it settle.
 *
 * @param {object} component The component.
 * @param {object} [props] Its props.
 * @return {Promise<object>} The wrapper.
 */
async function render(component, props = {}) {
	const w = mount(component, { props, global: { mocks: { t } } })
	await flushPromises()
	return w
}

beforeEach(() => {
	vi.clearAllMocks()
})

describe('PromptPlaceholderTools rendered', () => {
	it('offers every placeholder in the menu and inserts the one picked', async () => {
		const w = await render(PromptPlaceholderTools, { agentId: 'a1', startFields: [{ key: 'topic', label: 'Topic' }] })

		const items = w.findAllComponents({ name: 'NcActionButton' })
		expect(items).toHaveLength(9)
		expect(items[0].text()).toBe('Name of the person')
		expect(items.at(-1).text()).toBe('Answer to "Topic"')
		await items.at(-1).trigger('click')
		expect(w.emitted('insert')).toEqual([['{{field.topic}}']])
	})

	it('asks for a saved agent before it can preview', async () => {
		const w = await render(PromptPlaceholderTools)

		expect(w.find('[data-testid="agent-form-preview-prompt"]').element.disabled).toBe(true)
		expect(w.find('.prompt-placeholders__hint').text()).toBe('Save the agent to preview its filled-in instructions.')
	})

	it('shows the filled-in instructions and names what was left as written', async () => {
		let answer
		agentsApi.previewPrompt.mockReturnValueOnce(new Promise((resolve) => { answer = resolve }))
		const w = await render(PromptPlaceholderTools, { agentId: 'a1', prompt: 'Hallo {{user.displayName}} {{field.x}}' })

		await w.find('[data-testid="agent-form-preview-prompt"]').trigger('click')
		expect(w.find('.loading-icon').exists()).toBe(true)
		expect(w.find('[data-testid="agent-form-preview-prompt"]').element.disabled).toBe(true)
		answer({ text: 'Hallo Anna {{field.x}}', unknown: ['field.x'] })
		await flushPromises()

		expect(w.find('.loading-icon').exists()).toBe(false)
		expect(w.find('[data-testid="agent-form-prompt-preview"]').text()).toBe('Hallo Anna {{field.x}}')
		const warning = w.findAllComponents({ name: 'NcNoteCard' }).find((c) => c.props('type') === 'warning')
		expect(warning.text()).toBe('Left as written, because nothing has that name: field.x')
	})

	it('shows no warning when every placeholder was filled, and an error when the preview failed', async () => {
		agentsApi.previewPrompt.mockResolvedValueOnce({ text: 'Hallo Anna', unknown: [] })
		const w = await render(PromptPlaceholderTools, { agentId: 'a1', prompt: 'Hallo {{user.displayName}}' })
		await w.find('[data-testid="agent-form-preview-prompt"]').trigger('click')
		await flushPromises()
		expect(w.findAllComponents({ name: 'NcNoteCard' })).toHaveLength(0)

		agentsApi.previewPrompt.mockRejectedValueOnce(new Error('x'))
		await w.find('[data-testid="agent-form-preview-prompt"]').trigger('click')
		await flushPromises()
		const error = w.findAllComponents({ name: 'NcNoteCard' })
		expect(error).toHaveLength(1)
		expect(error[0].props('type')).toBe('error')
		expect(error[0].text()).toBe('Could not preview the instructions.')
		expect(w.find('[data-testid="agent-form-prompt-preview"]').exists()).toBe(false)
	})
})

describe('TalkDeliverySettings rendered', () => {
	beforeEach(() => {
		talk.listRooms.mockResolvedValue([{ token: 't1', name: 'Team' }])
		talk.getDeliveryTarget.mockResolvedValue('t1')
	})

	it('offers note to self and the rooms, and confirms a picked room', async () => {
		const w = await render(TalkDeliverySettings)
		const select = w.findComponent({ name: 'NcSelect' })
		expect(select.props('options')).toEqual([{ token: '', name: 'Note to self (private)' }, { token: 't1', name: 'Team' }])
		expect(select.props('modelValue')).toEqual({ token: 't1', name: 'Team' })
		expect(select.props('loading')).toBe(false)

		select.vm.$emit('update:modelValue', { token: 't1', name: 'Team' })
		await flushPromises()
		const note = w.findComponent({ name: 'NcNoteCard' })
		expect(note.props('type')).toBe('success')
		expect(note.text()).toBe('Saved — runs deliver to “Team”.')
	})

	it('creates a room only once a name is typed, showing progress meanwhile', async () => {
		let made
		talk.createRoom.mockReturnValueOnce(new Promise((resolve) => { made = resolve }))
		const w = await render(TalkDeliverySettings)
		const create = () => w.findAllComponents({ name: 'NcButton' }).at(-1)
		expect(create().text()).toBe('New room')
		expect(create().props('disabled')).toBe(true)

		await w.find('.new-room').setValue('Runs')
		expect(create().props('disabled')).toBe(false)
		await create().trigger('click')
		expect(w.find('.loading-icon').exists()).toBe(true)
		expect(create().props('disabled')).toBe(true)
		made({ token: 'new', name: 'Runs' })
		await flushPromises()

		expect(talk.createRoom).toHaveBeenCalledWith('Runs')
		expect(w.find('.loading-icon').exists()).toBe(false)
		expect(w.findComponent({ name: 'NcNoteCard' }).text()).toBe('Saved — runs deliver to “Runs”.')
	})

	it('shows an error rather than a confirmation when saving fails', async () => {
		talk.setDeliveryTarget.mockRejectedValueOnce(new Error('Not allowed'))
		const w = await render(TalkDeliverySettings)
		w.findComponent({ name: 'NcSelect' }).vm.$emit('update:modelValue', null)
		await flushPromises()

		const notes = w.findAllComponents({ name: 'NcNoteCard' })
		expect(notes).toHaveLength(1)
		expect(notes[0].props('type')).toBe('error')
		expect(notes[0].text()).toBe('Not allowed')
	})
})
