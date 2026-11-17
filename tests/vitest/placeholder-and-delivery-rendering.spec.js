/**
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * What PromptPlaceholderTools and TalkDeliverySettings render: the menu items
 * that insert a placeholder, the preview's loading state, error and unknown
 * names, the hint for an unsaved agent, and the delivery settings' loading
 * state, error and saved notes. The Nextcloud components are stubbed so their
 * slots render; the API modules are mocked.
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../../src/api/agents.js', () => ({ previewPrompt: vi.fn() }))
vi.mock('../../src/api/talk.js', () => ({ createRoom: vi.fn(), getDeliveryTarget: vi.fn(), listRooms: vi.fn(), setDeliveryTarget: vi.fn() }))

const agentsApi = await import('../../src/api/agents.js')
const talk = await import('../../src/api/talk.js')
const { default: PromptPlaceholderTools } = await import('../../src/components/PromptPlaceholderTools.vue')
const { default: TalkDeliverySettings } = await import('../../src/components/TalkDeliverySettings.vue')

/**
 * Translate as the identity, filling {placeholders}.
 *
 * @param {string} app The app id.
 * @param {string} text The text.
 * @param {object} vars The placeholders.
 * @return {string} The filled text.
 */
const t = (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m))

/** Stubs that render their slots, so the component's own template content shows. */
const stubs = {
	NcActions: { template: '<div class="actions"><slot /></div>' },
	NcActionButton: { emits: ['click'], template: '<button class="action" @click="$emit(\'click\')"><slot /></button>' },
	NcButton: { props: ['disabled'], emits: ['click'], template: '<button class="button" :disabled="disabled" @click="$emit(\'click\')"><slot name="icon" /><slot /></button>' },
	NcLoadingIcon: { template: '<span class="loading-icon" />' },
	NcNoteCard: { props: ['type'], template: '<div :class="\'note note--\' + type"><slot /></div>' },
	NcSelect: { props: ['loading'], template: '<div class="select" :data-loading="String(loading)" />' },
	NcTextField: { props: ['modelValue'], template: '<input class="text-field" />' },
}

/**
 * Mount a component with the slot-rendering stubs and let it load.
 *
 * @param {object} component The component.
 * @param {object} [props] Its props.
 * @return {Promise<object>} The wrapper.
 */
async function mountIt(component, props = {}) {
	const w = mount(component, { props, global: { stubs, mocks: { t } } })
	await flushPromises()
	return w
}

beforeEach(() => {
	vi.clearAllMocks()
})

describe('PromptPlaceholderTools renders', () => {
	it('a menu item per placeholder that inserts its token', async () => {
		const w = await mountIt(PromptPlaceholderTools, { agentId: 'a1', startFields: [{ key: 'topic', label: 'Topic' }] })
		const items = w.findAll('button.action')
		expect(items).toHaveLength(9)
		expect(items[0].text()).toBe('Name of the person')

		await items.at(-1).trigger('click')

		expect(w.emitted('insert')).toEqual([['{{field.topic}}']])
	})

	it('a hint and a disabled preview for an agent that is not saved', async () => {
		const w = await mountIt(PromptPlaceholderTools)
		expect(w.find('.prompt-placeholders__hint').text()).toBe('Save the agent to preview its filled-in instructions.')
		expect(w.find('button.button').attributes('disabled')).toBeDefined()
	})

	it('the loading icon while previewing, then the preview and the names it did not know', async () => {
		let answer
		agentsApi.previewPrompt.mockReturnValueOnce(new Promise((resolve) => { answer = resolve }))
		const w = await mountIt(PromptPlaceholderTools, { agentId: 'a1', prompt: 'Hi {{user.nickname}}' })

		await w.find('button.button').trigger('click')
		expect(w.find('.loading-icon').exists()).toBe(true)
		expect(w.find('button.button').text()).toBe('Preview')
		expect(w.find('button.button').attributes('disabled')).toBeDefined()

		answer({ text: 'Hi {{user.nickname}}', unknown: ['user.nickname', 'field.x'] })
		await flushPromises()

		expect(w.find('.loading-icon').exists()).toBe(false)
		expect(w.find('[data-testid="agent-form-prompt-preview"]').text()).toBe('Hi {{user.nickname}}')
		expect(w.find('.note--warning').text()).toBe('Left as written, because nothing has that name: user.nickname, field.x')
	})

	it('no warning when every name was known, and an error when the preview failed', async () => {
		agentsApi.previewPrompt.mockResolvedValueOnce({ text: 'Hi Anna', unknown: [] })
		const w = await mountIt(PromptPlaceholderTools, { agentId: 'a1' })
		await w.find('button.button').trigger('click')
		await flushPromises()
		expect(w.find('.note--warning').exists()).toBe(false)

		agentsApi.previewPrompt.mockRejectedValueOnce(new Error('down'))
		await w.find('button.button').trigger('click')
		await flushPromises()
		expect(w.find('.note--error').text()).toBe('Could not preview the instructions.')
		expect(w.find('[data-testid="agent-form-prompt-preview"]').exists()).toBe(false)
	})
})

describe('TalkDeliverySettings renders', () => {
	it('a loading picker until the rooms are read', async () => {
		let rooms
		talk.listRooms.mockReturnValueOnce(new Promise((resolve) => { rooms = resolve }))
		talk.getDeliveryTarget.mockResolvedValueOnce('')
		const w = mount(TalkDeliverySettings, { global: { stubs, mocks: { t } } })
		await w.vm.$nextTick()
		expect(w.find('.select').attributes('data-loading')).toBe('true')

		rooms([])
		await flushPromises()
		expect(w.find('.select').attributes('data-loading')).toBe('false')
	})

	it('an error when the rooms cannot be read, in the server\'s words when it gives them', async () => {
		talk.listRooms.mockRejectedValueOnce({ response: { data: { message: 'Talk is not installed' } } })
		talk.getDeliveryTarget.mockResolvedValueOnce('')
		const w = await mountIt(TalkDeliverySettings)
		expect(w.find('.note--error').text()).toBe('Talk is not installed')
		expect(w.find('.note--success').exists()).toBe(false)
	})

	it('the saved note once a room is chosen', async () => {
		talk.listRooms.mockResolvedValueOnce([{ token: 't1', name: 'Team' }])
		talk.getDeliveryTarget.mockResolvedValueOnce('')
		talk.setDeliveryTarget.mockResolvedValueOnce({})
		const w = await mountIt(TalkDeliverySettings)
		expect(w.find('.note--success').exists()).toBe(false)

		await w.vm.onSelect({ token: 't1', name: 'Team' })
		await flushPromises()

		expect(w.find('.note--success').text()).toBe('Saved — runs deliver to “Team”.')
	})

	it('the loading icon while a room is created, then the new room chosen', async () => {
		talk.listRooms.mockResolvedValueOnce([])
		talk.getDeliveryTarget.mockResolvedValueOnce('')
		talk.setDeliveryTarget.mockResolvedValueOnce({})
		let created
		talk.createRoom.mockReturnValueOnce(new Promise((resolve) => { created = resolve }))
		const w = await mountIt(TalkDeliverySettings)
		await w.setData({ newName: ' Hermiq ' })

		await w.find('button.button').trigger('click')
		expect(talk.createRoom).toHaveBeenCalledWith('Hermiq')
		expect(w.find('.loading-icon').exists()).toBe(true)
		expect(w.find('button.button').text()).toBe('New room')

		created({ token: 'r9', name: 'Hermiq' })
		await flushPromises()

		expect(w.find('.loading-icon').exists()).toBe(false)
		expect(talk.setDeliveryTarget).toHaveBeenCalledWith('r9')
		expect(w.find('.note--success').text()).toBe('Saved — runs deliver to “Hermiq”.')
	})
})
