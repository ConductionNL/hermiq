/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The chat page's template wiring: which button, menu entry, radio and
 * modal event calls which handler, and what each changes on the page.
 */

import { config, flushPromises, shallowMount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@conduction/nextcloud-vue', () => ({
	SAFE_MARKDOWN_DOMPURIFY_CONFIG: {},
	CnIconPicker: { name: 'CnIconPicker', render: () => null },
	CnMarkdownEditor: { name: 'CnMarkdownEditor', render: () => null },
	fromOpenGemeenten: () => [],
	createObjectStore: () => () => ({}),
}))
vi.mock('@nextcloud/auth', () => ({ getCurrentUser: () => ({ uid: 'me', displayName: 'Me' }), getRequestToken: () => 't' }))

const toasts = []
const picker = { nodes: [], fail: false }
vi.mock('@nextcloud/dialogs', () => ({
	showError: (m) => toasts.push(['error', m]),
	showSuccess: (m) => toasts.push(['success', m]),
	getFilePickerBuilder: () => {
		let button
		const builder = {
			setMultiSelect: () => builder,
			allowDirectories: () => builder,
			addButton: (b) => { button = b; return builder },
			build: () => ({
				pick: async () => {
					if (picker.fail) {
						throw new Error('closed')
					}
					button.callback(picker.nodes)
				},
			}),
		}
		return builder
	},
}))

const store = {
	registerObjectType: vi.fn(),
	fetchCollection: vi.fn(async () => [{ uuid: 'a1', name: 'Helper' }]),
	fetchObject: vi.fn(async () => ({ uuid: 'a1', name: 'Helper', views: ['v1'], tools: ['t1'] })),
	errors: {},
}
vi.mock('../../src/store/store.js', () => ({ useAgentStore: () => store }))
vi.mock('../../src/api/agents.js', () => ({ checkAgentDraft: vi.fn() }))
vi.mock('../../src/api/speech.js', () => ({ speechCapabilities: vi.fn(async () => ({ available: true })) }))
vi.mock('../../src/api/chat.js', async (orig) => {
	const real = await orig()
	return {
		ChatStreamError: real.ChatStreamError,
		answerStartFields: vi.fn(),
		archiveSession: vi.fn(async () => ({})),
		createSession: vi.fn(),
		getImageAvailability: vi.fn(async () => true),
		getSession: vi.fn(async (uuid) => ({ uuid, title: 'Fresh' })),
		getSessionGoal: vi.fn(async () => null),
		listMessages: vi.fn(async () => ({ results: [], total: 0 })),
		listSessions: vi.fn(async ({ archived }) => ({ results: archived ? [{ uuid: 'old' }] : [{ uuid: 's1' }], total: 1 })),
		restoreSession: vi.fn(async () => ({})),
		sendChatMessage: vi.fn(async () => ({})),
		sendMessageFeedback: vi.fn(async () => ({})),
		stopGoal: vi.fn(),
		streamChatMessage: vi.fn(),
		uploadChatAttachment: vi.fn(),
	}
})

const api = await import('../../src/api/chat.js')
const { default: Chat } = await import('../../src/views/Chat.vue')

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
 * Mount the page and let its opening loads settle.
 *
 * @param {object} [provide] Values to provide.
 * @return {Promise<object>} The wrapper.
 */
async function mountChat(provide = {}) {
	const wrapper = shallowMount(Chat, { global: { mocks: { t, n: t }, provide } })
	await flushPromises()
	return wrapper
}

beforeEach(() => {
	toasts.length = 0
	vi.clearAllMocks()
	store.errors = {}
	config.global.renderStubDefaultSlot = true
})

afterEach(() => {
	config.global.renderStubDefaultSlot = false
})

/**
 * The stubbed child of a component name whose rendered text contains a label.
 *
 * @param {object} wrapper The page wrapper.
 * @param {string} name The child component name.
 * @param {string} label Text (or aria-label) the child carries.
 * @return {object} The child wrapper.
 */
function child(wrapper, name, label) {
	const found = wrapper.findAllComponents({ name }).find(
		(c) => c.text().includes(label) || c.attributes('aria-label') === label,
	)
	if (!found) {
		throw new Error(`No ${name} with "${label}"`)
	}
	return found
}

/**
 * A menu entry by its label. NcActions and NcActionButton render as plain
 * elements in this environment, so their click listeners are native ones.
 *
 * @param {object} wrapper The page wrapper.
 * @param {string} label The entry's text.
 * @return {object} The DOM wrapper.
 */
function entry(wrapper, label) {
	const found = wrapper.findAll('ncactionbutton').find((e) => e.text() === label)
	if (!found) {
		throw new Error(`No menu entry "${label}"`)
	}
	return found
}

/**
 * The labels of all menu entries on the page.
 *
 * @param {object} wrapper The page wrapper.
 * @return {string[]} The labels.
 */
function entries(wrapper) {
	return wrapper.findAll('ncactionbutton').map((e) => e.text())
}

/**
 * Mount the page with one active, owned session open.
 *
 * @param {Array} [messages] The thread.
 * @return {Promise<object>} The wrapper.
 */
async function mountWithSession(messages = []) {
	const w = await mountChat()
	w.vm.activeSession = { uuid: 's1', title: 'Budget' }
	w.vm.messages = messages
	await flushPromises()
	return w
}

describe('session list wiring', () => {
	it('the archive tab loads archived sessions and the active tab returns to the live list', async () => {
		const w = await mountChat()
		child(w, 'NcCheckboxRadioSwitch', 'Archive').vm.$emit('update:modelValue', 'archive')
		await flushPromises()
		expect(api.listSessions).toHaveBeenCalledWith({ archived: true })
		expect(w.vm.showArchive).toBe(true)
		expect(w.vm.archivedSessions).toEqual([{ uuid: 'old' }])

		child(w, 'NcCheckboxRadioSwitch', 'Active').vm.$emit('update:modelValue', 'active')
		await flushPromises()
		expect(w.vm.showArchive).toBe(false)
	})

	it('a row click opens the session and its menu archives, invites and deletes it', async () => {
		const w = await mountChat()
		w.vm.sessions = [{ uuid: 's1', title: 'Budget' }]
		await flushPromises()

		await w.find('[data-testid="chat-session-row"]').trigger('click')
		await flushPromises()
		expect(api.listMessages).toHaveBeenCalledWith('s1')
		expect(w.vm.activeSession.uuid).toBe('s1')

		await entry(w, 'Continue').trigger('click')
		await flushPromises()
		expect(w.vm.activeSession.uuid).toBe('s1')

		await entry(w, 'Invite colleagues').trigger('click')
		expect(w.vm.participantsTarget).toEqual({ uuid: 's1', title: 'Budget' })
		w.findComponent({ name: 'SessionParticipantsModal' }).vm.$emit('close')
		expect(w.vm.participantsTarget).toBe(null)

		await entry(w, 'Delete session').trigger('click')
		expect(w.vm.showDelete).toBe(true)
		expect(w.vm.deleteTarget).toEqual({ uuid: 's1', title: 'Budget' })
		w.findComponent({ name: 'SessionDeleteModal' }).vm.$emit('close')
		expect(w.vm.showDelete).toBe(false)

		await entry(w, 'Archive session').trigger('click')
		await flushPromises()
		expect(api.archiveSession).toHaveBeenCalledWith('s1')
		expect(w.vm.activeSession).toBe(null)
		expect(toasts).toContainEqual(['success', 'Session archived'])
	})

	it('an archived row offers restore instead of archive', async () => {
		const w = await mountChat()
		await w.vm.setArchiveTab(true)
		await flushPromises()
		expect(entries(w)).not.toContain('Archive session')

		await entry(w, 'Restore session').trigger('click')
		await flushPromises()
		expect(api.restoreSession).toHaveBeenCalledWith('old')
		expect(toasts).toContainEqual(['success', 'Session restored'])
	})

	it('a participant row has no archive, restore, delete or invite action', async () => {
		const w = await mountChat()
		w.vm.sessions = [{ uuid: 's2', title: 'Shared', role: 'participant' }]
		await flushPromises()
		expect(entries(w)).toEqual(['Continue'])
	})
})

describe('header and modal wiring', () => {
	it('the goal, rename and settings buttons open their modals and each modal closes itself', async () => {
		const w = await mountWithSession()

		await w.find('[data-testid="chat-set-goal"]').trigger('click')
		child(w, 'NcButton', 'Set a goal').vm.$emit('click')
		expect(w.vm.showGoalForm).toBe(true)
		w.findComponent({ name: 'GoalFormModal' }).vm.$emit('close')
		expect(w.vm.showGoalForm).toBe(false)

		child(w, 'NcButton', 'Rename session').vm.$emit('click')
		expect(w.vm.showRename).toBe(true)
		w.findComponent({ name: 'SessionRenameModal' }).vm.$emit('close')
		expect(w.vm.showRename).toBe(false)

		child(w, 'NcButton', 'Chat settings').vm.$emit('click')
		expect(w.vm.showSettings).toBe(true)
		const settings = { views: ['v9'], tools: [] }
		w.findComponent({ name: 'ChatSettingsModal' }).vm.$emit('input', settings)
		expect(w.vm.settings).toEqual(settings)
		w.findComponent({ name: 'ChatSettingsModal' }).vm.$emit('close')
		expect(w.vm.showSettings).toBe(false)
	})

	it('the image action opens the image modal, which closes itself', async () => {
		const w = await mountWithSession()
		await entry(w, 'Create an image').trigger('click')
		expect(w.vm.showImageForm).toBe(true)
		w.findComponent({ name: 'GenerateImageModal' }).vm.$emit('close')
		expect(w.vm.showImageForm).toBe(false)
	})

	it('the save-as-skill and schedule modals close themselves', async () => {
		const w = await mountWithSession()
		w.vm.showSaveAsSkill = true
		w.vm.draftScheduleAgentId = 'a1'
		await flushPromises()
		w.findComponent({ name: 'SkillFormModal' }).vm.$emit('close')
		expect(w.vm.showSaveAsSkill).toBe(false)
		w.findComponent({ name: 'ScheduleFormModal' }).vm.$emit('close')
		expect(w.vm.draftScheduleAgentId).toBe('')
	})
})

describe('message feedback wiring', () => {
	it('thumbs up records positive feedback and opens the comment box, which sends a trimmed comment', async () => {
		const w = await mountWithSession([{ uuid: 'm1', role: 'assistant', content: 'Answer' }])

		child(w, 'NcButton', 'Helpful').vm.$emit('click')
		await flushPromises()
		expect(api.sendMessageFeedback).toHaveBeenCalledWith('s1', 'm1', { type: 'positive' })
		expect(toasts).toContainEqual(['success', 'Feedback recorded'])

		const box = w.find('.chat-page__feedback-input')
		await box.setValue('  too long  ')
		expect(w.vm.messages[0].feedbackComment).toBe('  too long  ')

		child(w, 'NcButton', 'Send feedback').vm.$emit('click')
		await flushPromises()
		expect(api.sendMessageFeedback).toHaveBeenLastCalledWith('s1', 'm1', { type: 'positive', comment: 'too long' })
		expect(w.vm.messages[0].showFeedbackInput).toBe(false)
	})

	it('thumbs down records negative feedback', async () => {
		const w = await mountWithSession([{ id: 7, role: 'assistant', content: 'Answer' }])
		child(w, 'NcButton', 'Not helpful').vm.$emit('click')
		await flushPromises()
		expect(api.sendMessageFeedback).toHaveBeenCalledWith('s1', 7, { type: 'negative' })
	})

	it('save as skill opens the skill form with the message body', async () => {
		const w = await mountWithSession([{ uuid: 'm1', role: 'assistant', content: '# Skill' }])
		child(w, 'NcButton', 'Save as skill').vm.$emit('click')
		expect(w.vm.showSaveAsSkill).toBe(true)
		expect(w.vm.saveAsSkillBody).toBe('# Skill')
	})
})

describe('composer wiring', () => {
	it('a pending attachment is removed by its button', async () => {
		const w = await mountWithSession()
		w.vm.pendingAttachments = [{ fileId: 1, name: 'a.pdf' }, { fileId: 2, name: 'b.pdf' }]
		await flushPromises()
		child(w, 'NcButton', 'Remove a.pdf').vm.$emit('click')
		expect(w.vm.pendingAttachments).toEqual([{ fileId: 2, name: 'b.pdf' }])
	})

	it('upload from device opens the hidden file input', async () => {
		const w = await mountWithSession()
		const click = vi.spyOn(w.vm.$refs.attachmentInput, 'click').mockImplementation(() => {})
		await entry(w, 'Upload from device').trigger('click')
		expect(click).toHaveBeenCalledTimes(1)
	})

	it('typing grows the message box up to 150px', async () => {
		const w = await mountWithSession()
		const box = w.find('.chat-page__input')
		Object.defineProperty(box.element, 'scrollHeight', { configurable: true, value: 90 })
		await box.setValue('Hello')
		expect(w.vm.currentMessage).toBe('Hello')
		expect(box.element.style.height).toBe('90px')

		Object.defineProperty(box.element, 'scrollHeight', { configurable: true, value: 400 })
		await box.trigger('input')
		expect(box.element.style.height).toBe('150px')
	})

	it('a send error is shown in the composer', async () => {
		const w = await mountWithSession()
		w.vm.sendError = 'Quota reached'
		await flushPromises()
		expect(w.find('.chat-page__composer').text()).toContain('Quota reached')
	})
})

describe('starting a new session', () => {
	it('moves focus to the first agent card on the start surface', async () => {
		const w = await mountWithSession()
		w.vm.activeSession = null
		await flushPromises()
		const surface = w.vm.$refs.startSurface
		const button = document.createElement('button')
		const card = document.createElement('div')
		card.className = 'agent-selector__card'
		card.appendChild(button)
		surface.appendChild(card)
		const focus = vi.spyOn(button, 'focus')

		w.vm.newSession()
		await flushPromises()
		expect(focus).toHaveBeenCalledTimes(1)
		expect(surface.scrollTop).toBe(0)
	})

	it('skips the focus move when the page is gone before the surface renders', async () => {
		const w = await mountWithSession()
		const vm = w.vm
		vm.newSession()
		w.unmount()
		await flushPromises()
		expect(vm.$refs.startSurface ?? null).toBe(null)
		expect(vm.activeSession).toBe(null)
		expect(vm.messages).toEqual([])
	})
})
