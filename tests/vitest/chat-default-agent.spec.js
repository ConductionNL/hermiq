/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * personal-preferences: the chat page's "New session" button starts a session
 * with the person's default agent when one is set and still there, and shows
 * the agent picker otherwise.
 *
 * @spec openspec/specs/personal-preferences/spec.md#requirement-the-voorkeuren-section-offers-the-drawn-preferences-req-pref-002
 */

import { flushPromises, shallowMount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

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
vi.mock('../../src/api/preferences.js', () => ({
	PREFERENCE_KEYS: { defaultAgent: 'default-agent' },
	getPreference: vi.fn(async () => ''),
}))
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
		warmChat: vi.fn(async () => false),
	}
})

const api = await import('../../src/api/chat.js')
const preferences = await import('../../src/api/preferences.js')
const { default: Chat } = await import('../../src/views/Chat.vue')

const t = (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m))

/**
 * Mount the page with a stored default agent and let its loads settle.
 *
 * @param {string} defaultAgent The stored default agent id, or ''.
 * @return {Promise<object>} The wrapper.
 */
async function mountChat(defaultAgent) {
	preferences.getPreference.mockResolvedValue(defaultAgent)
	const wrapper = shallowMount(Chat, { global: { mocks: { t, n: t } } })
	await flushPromises()
	return wrapper
}

beforeEach(() => {
	toasts.length = 0
	vi.clearAllMocks()
	store.errors = {}
	store.fetchCollection.mockResolvedValue([{ uuid: 'a1', name: 'Helper' }, { uuid: 'woo', name: 'Hulp bij Woo-besluit' }])
	api.createSession.mockResolvedValue({ uuid: 'new-session', agentId: 'woo' })
})

describe('a new session with a default agent', () => {
	it('reads the default agent when the page opens', async () => {
		await mountChat('woo')
		expect(preferences.getPreference).toHaveBeenCalledWith('default-agent')
	})

	it('starts the session with the default agent at once', async () => {
		const w = await mountChat('woo')
		await w.vm.onNewSessionClick()
		await flushPromises()
		expect(api.createSession).toHaveBeenCalledTimes(1)
		expect(api.createSession).toHaveBeenCalledWith('woo')
		expect(w.vm.activeSession).toEqual({ uuid: 'new-session', agentId: 'woo' })
		expect(w.vm.currentAgent.name).toBe('Hulp bij Woo-besluit')
	})

	it('shows the picker when no default agent is set', async () => {
		const w = await mountChat('')
		w.vm.activeSession = { uuid: 's1' }
		await w.vm.onNewSessionClick()
		await flushPromises()
		expect(api.createSession).not.toHaveBeenCalled()
		expect(w.vm.activeSession).toBeNull()
	})

	it('shows the picker when the default agent is no longer there', async () => {
		const w = await mountChat('deleted-agent')
		w.vm.activeSession = { uuid: 's1' }
		await w.vm.onNewSessionClick()
		await flushPromises()
		expect(api.createSession).not.toHaveBeenCalled()
		expect(w.vm.activeSession).toBeNull()
	})

	it('treats an unreadable preference as none', async () => {
		preferences.getPreference.mockRejectedValue(new Error('offline'))
		const w = shallowMount(Chat, { global: { mocks: { t, n: t } } })
		await flushPromises()
		expect(w.vm.defaultAgentId).toBe('')
	})
})
