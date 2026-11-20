/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The chat page's session search and selection mode: the debounced search of
 * the current tab and its results, opening a result at its turn, and archiving,
 * restoring or deleting several sessions in one action.
 *
 * @spec openspec/changes/chat-session-search-and-bulk-delete/specs/session-surface/spec.md#requirement-a-person-can-search-their-own-sessions-by-title-and-content-req-ssrch-001
 * @spec openspec/changes/chat-session-search-and-bulk-delete/specs/session-surface/spec.md#requirement-a-person-can-archive-restore-or-delete-several-sessions-in-one-action-req-ssrch-002
 */

import { flushPromises, shallowMount } from '@vue/test-utils'
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
		bulkSessions: vi.fn(async (action, uuids) => uuids.map((uuid) => ({ uuid, status: 'ok' }))),
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
const { default: Chat } = await import('../../src/views/Chat.vue')

/**
 * Translate as the identity, filling {placeholders} and %n.
 *
 * @param {string} app The app id.
 * @param {string} text The text.
 * @param {object} vars The placeholders.
 * @return {string} The filled text.
 */
const t = (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m))

/**
 * Pick the singular or plural form, filling %n.
 *
 * @param {string} app The app id.
 * @param {string} singular The singular form.
 * @param {string} plural The plural form.
 * @param {number} count The count.
 * @return {string} The filled text.
 */
const n = (app, singular, plural, count) => (count === 1 ? singular : plural).replace('%n', String(count))

const ACTIVE = [{ uuid: 's1', title: 'WOZ' }, { uuid: 's2', title: 'Budget' }, { uuid: 's3', title: 'Shared', role: 'participant' }]
const ARCHIVED = [{ uuid: 'a1', title: 'Old test' }, { uuid: 'a2', title: 'Older test' }]

/**
 * Mount the page and let its opening loads settle.
 *
 * @param {boolean} [slots] Render the stubs' default slots, to read their text.
 * @return {Promise<object>} The wrapper.
 */
async function mountChat(slots = false) {
	const wrapper = shallowMount(Chat, { global: { mocks: { t, n }, renderStubDefaultSlot: slots } })
	await flushPromises()
	return wrapper
}

beforeEach(() => {
	toasts.length = 0
	vi.clearAllMocks()
	store.errors = {}
	api.listSessions.mockImplementation(async ({ archived, search }) => {
		if (search) {
			return {
				results: [{ uuid: 's1', title: 'WOZ', match: { turnUuid: 't2', excerpt: '…over een monument?' } }],
				total: 1,
				truncated: search === 'old',
			}
		}
		return { results: archived ? [...ARCHIVED] : [...ACTIVE], total: 3, truncated: false }
	})
})

afterEach(() => {
	vi.useRealTimers()
})

describe('searching the session list (REQ-SSRCH-001)', () => {
	it('searches the current tab 300 ms after typing and shows one list of results', async () => {
		vi.useFakeTimers()
		const w = await mountChat()
		api.listSessions.mockClear()
		w.vm.onSearchInput('monu')
		w.vm.onSearchInput('monument')
		await vi.advanceTimersByTimeAsync(299)
		expect(api.listSessions).not.toHaveBeenCalled()
		await vi.advanceTimersByTimeAsync(1)
		await flushPromises()
		expect(api.listSessions).toHaveBeenCalledTimes(1)
		expect(api.listSessions).toHaveBeenCalledWith({ archived: false, search: 'monument', limit: 100 })
		expect(w.vm.searchActive).toBe(true)
		expect(w.vm.sessionGroups).toEqual([
			{ key: 'results', heading: 'Results for "monument"', sessions: [{ uuid: 's1', title: 'WOZ', match: { turnUuid: 't2', excerpt: '…over een monument?' } }] },
		])
		await w.vm.$nextTick()
		expect(w.find('[data-testid="chat-session-row-excerpt"]').text()).toBe('…over een monument?')
	})

	it('brings the groups back when the field is cleared or shortened below two characters', async () => {
		vi.useFakeTimers()
		const w = await mountChat()
		w.vm.onSearchInput('monument')
		await vi.advanceTimersByTimeAsync(300)
		await flushPromises()
		expect(w.vm.searchActive).toBe(true)
		w.vm.onSearchInput('m')
		expect(w.vm.searchActive).toBe(false)
		expect(w.vm.visibleSessions).toEqual(ACTIVE)
		w.vm.onSearchInput('monument')
		await vi.advanceTimersByTimeAsync(300)
		await flushPromises()
		w.vm.clearSearch()
		expect(w.vm.searchTerm).toBe('')
		expect(w.vm.searchActive).toBe(false)
		expect(w.vm.sessionGroups.flatMap((group) => group.sessions)).toEqual(ACTIVE)
	})

	it('ignores a search answer that lands after the field was cleared', async () => {
		let release
		api.listSessions.mockImplementationOnce(async () => ({ results: [], total: 0, truncated: false }))
		const w = await mountChat()
		api.listSessions.mockImplementationOnce(() => new Promise((resolve) => { release = resolve }))
		w.vm.searchTerm = 'monument'
		const pending = w.vm.runSearch()
		w.vm.clearSearch()
		release({ results: [{ uuid: 'late' }], total: 1, truncated: false })
		await pending
		expect(w.vm.searchActive).toBe(false)
		expect(w.vm.searchResults).toEqual([])
		expect(w.vm.sessionsLoading).toBe(false)
	})

	it('says when only the most recent sessions were searched, and when nothing matched', async () => {
		const w = await mountChat(true)
		w.vm.searchTerm = 'old'
		await w.vm.runSearch()
		await w.vm.$nextTick()
		expect(w.vm.searchTruncated).toBe(true)
		expect(w.find('[data-testid="chat-session-search-truncated"]').exists()).toBe(true)

		api.listSessions.mockResolvedValueOnce({ results: [], total: 0, truncated: false })
		w.vm.searchTerm = 'nothing'
		await w.vm.runSearch()
		await w.vm.$nextTick()
		expect(w.text()).toContain('No sessions match nothing.')
	})

	it('re-runs the search on the other tab when the tab changes', async () => {
		const w = await mountChat()
		w.vm.searchTerm = 'monument'
		await w.vm.runSearch()
		api.listSessions.mockClear()
		await w.vm.setArchiveTab(true)
		expect(api.listSessions).toHaveBeenCalledWith({ archived: true, search: 'monument', limit: 100 })
		expect(api.listSessions).toHaveBeenCalledTimes(1)
	})

	it('says so when the search fails', async () => {
		const w = await mountChat()
		api.listSessions.mockRejectedValueOnce(new Error('down'))
		w.vm.searchTerm = 'monument'
		await w.vm.runSearch()
		expect(toasts).toContainEqual(['error', 'Could not search the sessions.'])
		expect(w.vm.sessionsLoading).toBe(false)
	})

	it('opens a result at its matching turn and highlights it, and falls back to the end without one', async () => {
		vi.useFakeTimers()
		const w = await mountChat()
		const turn = { scrollIntoView: vi.fn() }
		const container = { scrollTop: 0, scrollHeight: 500, querySelector: vi.fn((selector) => (selector === '[data-turn-uuid="t2"]' ? turn : null)) }
		w.vm.$refs.messagesContainer = container
		Object.defineProperty(w.vm.$refs, 'messagesContainer', { value: container, configurable: true })
		w.vm.scrollToTurn('t2')
		await w.vm.$nextTick()
		expect(turn.scrollIntoView).toHaveBeenCalledWith({ block: 'center' })
		expect(w.vm.highlightTurn).toBe('t2')
		await vi.advanceTimersByTimeAsync(2000)
		expect(w.vm.highlightTurn).toBe('')

		w.vm.scrollToTurn('gone')
		await w.vm.$nextTick()
		await w.vm.$nextTick()
		expect(container.scrollTop).toBe(500)
	})
})

describe('selecting several sessions (REQ-SSRCH-002)', () => {
	it('ticks own sessions only, toggles with click and space in selection mode, and selects all shown', async () => {
		const w = await mountChat()
		w.vm.startSelecting()
		await w.vm.$nextTick()
		expect(w.findAll('[data-testid="chat-session-row-check"]')).toHaveLength(2)
		expect(w.get('[data-testid="chat-session-bulk-count"]').text()).toBe('0 selected')

		w.vm.onRowActivate(ACTIVE[0])
		w.vm.onRowSpace(ACTIVE[1])
		w.vm.toggleSelected(ACTIVE[2])
		expect(w.vm.selected).toEqual(['s1', 's2'])
		w.vm.onRowSpace(ACTIVE[0])
		expect(w.vm.selected).toEqual(['s2'])

		w.vm.selectAllShown()
		expect(w.vm.selected).toEqual(['s1', 's2'])
		await w.vm.$nextTick()
		expect(w.get('[data-testid="chat-session-bulk-count"]').text()).toBe('2 selected')

		w.vm.stopSelecting()
		expect(w.vm.selecting).toBe(false)
		expect(w.vm.selected).toEqual([])
	})

	it('opens a row as usual outside selection mode, and space does nothing there', async () => {
		const w = await mountChat()
		w.vm.onRowSpace(ACTIVE[0])
		expect(w.vm.selected).toEqual([])
		await w.vm.onRowActivate(ACTIVE[0])
		expect(w.vm.activeSession).toEqual(ACTIVE[0])
	})

	it('archives the ticked sessions in one request, leaves the open one for the start surface and reloads', async () => {
		const w = await mountChat()
		await w.vm.selectSession(ACTIVE[0])
		w.vm.startSelecting()
		w.vm.selectAllShown()
		api.listSessions.mockClear()
		await w.vm.runBulk('archive')
		expect(api.bulkSessions).toHaveBeenCalledWith('archive', ['s1', 's2'])
		expect(w.vm.activeSession).toBe(null)
		expect(w.vm.selecting).toBe(false)
		expect(toasts).toContainEqual(['success', '2 sessions archived'])
		expect(api.listSessions).toHaveBeenCalled()
	})

	it('restores from the archive tab and says how many could not be changed', async () => {
		const w = await mountChat()
		await w.vm.setArchiveTab(true)
		w.vm.startSelecting()
		w.vm.selectAllShown()
		api.bulkSessions.mockResolvedValueOnce([{ uuid: 'a1', status: 'ok' }, { uuid: 'a2', status: 'refused' }])
		await w.vm.runBulk('restore')
		expect(api.bulkSessions).toHaveBeenCalledWith('restore', ['a1', 'a2'])
		expect(toasts).toContainEqual(['success', '1 session restored'])
		expect(toasts).toContainEqual(['error', '1 session could not be changed.'])
	})

	it('removes the deleted sessions the modal reports, and keeps the ones that were not found', async () => {
		const w = await mountChat()
		await w.vm.setArchiveTab(true)
		w.vm.startSelecting()
		w.vm.selectAllShown()
		api.listSessions.mockClear()
		await w.vm.onBulkDeleted([{ uuid: 'a1', status: 'ok' }, { uuid: 'a2', status: 'not_found' }])
		expect(w.vm.archivedSessions).toEqual([ARCHIVED[1]])
		expect(toasts).toContainEqual(['success', '1 session deleted'])
		expect(toasts).toContainEqual(['error', '1 session could not be changed.'])
		expect(api.listSessions).not.toHaveBeenCalled()
	})

	it('does nothing with an empty selection, and says so when the bulk request fails', async () => {
		const w = await mountChat()
		w.vm.startSelecting()
		await w.vm.runBulk('archive')
		expect(api.bulkSessions).not.toHaveBeenCalled()
		w.vm.selectAllShown()
		api.bulkSessions.mockRejectedValueOnce(new Error('down'))
		await w.vm.runBulk('archive')
		expect(toasts).toContainEqual(['error', 'Could not change the selected sessions.'])
		expect(w.vm.bulkBusy).toBe(false)
		expect(w.vm.selected).toEqual(['s1', 's2'])
	})

	it('leaves selection mode when the tab changes', async () => {
		const w = await mountChat()
		w.vm.startSelecting()
		w.vm.selectAllShown()
		await w.vm.setArchiveTab(true)
		expect(w.vm.selecting).toBe(false)
		expect(w.vm.selected).toEqual([])
	})
})
