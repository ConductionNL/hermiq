/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The chat page mounted with its API doubled: what it loads on open, how a
 * session is started, selected, archived, restored and deleted, how a turn is
 * streamed or posted, how attachments, feedback, goals and agent drafts behave,
 * and the labels the page derives from its state.
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
		bulkSessions: vi.fn(async () => []),
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
const agentsApi = await import('../../src/api/agents.js')
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
	picker.nodes = []
	picker.fail = false
	vi.clearAllMocks()
	store.errors = {}
})

afterEach(() => {
	vi.useRealTimers()
})

describe('opening the page', () => {
	it('loads sessions, agents, speech and image availability, and marks the AI context as chat', async () => {
		const cnAiContext = { pageKind: '' }
		const w = await mountChat({ cnAiContext })
		expect(cnAiContext.pageKind).toBe('chat')
		expect(store.registerObjectType).toHaveBeenCalledWith('agent', 'agent', 'hermiq')
		expect(w.vm.sessions).toEqual([{ uuid: 's1' }])
		expect(w.vm.sessionsLoading).toBe(false)
		expect(w.vm.agents).toEqual([{ uuid: 'a1', name: 'Helper' }])
		expect(w.vm.agentsLoading).toBe(false)
		expect(w.vm.speechService).toEqual({ available: true })
		expect(w.vm.imageAvailable).toBe(true)
		expect(w.vm.currentUserName).toBe('Me')
		w.unmount()
		expect(cnAiContext.pageKind).toBe('custom')
	})

	it('shows an agent load error, and hides the image action when its check fails', async () => {
		store.errors = { agent: { message: '' } }
		store.fetchCollection.mockResolvedValueOnce(null)
		api.getImageAvailability.mockRejectedValueOnce(new Error('x'))
		const w = await mountChat()
		expect(w.vm.agents).toEqual([])
		expect(w.vm.agentsError).toBe('Could not load agents.')
		expect(w.vm.imageAvailable).toBe(false)
	})

	it('says so when the sessions cannot be loaded', async () => {
		api.listSessions.mockRejectedValueOnce(new Error('down'))
		const w = await mountChat()
		expect(toasts).toContainEqual(['error', 'Could not load sessions.'])
		expect(w.vm.sessionsLoading).toBe(false)
	})
})

describe('session list', () => {
	it('loads the archive when its tab opens, and reloads it with the active list', async () => {
		const w = await mountChat()
		await w.vm.setArchiveTab(true)
		expect(w.vm.archivedSessions).toEqual([{ uuid: 'old' }])
		expect(w.vm.visibleSessions).toEqual([{ uuid: 'old' }])
		expect(w.vm.sessionGroups).toEqual([{ key: 'archive', heading: '', sessions: [{ uuid: 'old' }] }])
		api.listSessions.mockClear()
		await w.vm.loadSessions(true)
		expect(api.listSessions.mock.calls.map((c) => c[0])).toEqual([{ archived: false }, { archived: true }])
		await w.vm.setArchiveTab(false)
		expect(w.vm.visibleSessions).toEqual([{ uuid: 's1' }])
	})

	it('says so when the archive cannot be loaded', async () => {
		const w = await mountChat()
		api.listSessions.mockRejectedValueOnce(new Error('down'))
		await w.vm.setArchiveTab(true)
		expect(toasts).toContainEqual(['error', 'Could not load archived sessions.'])
		expect(w.vm.sessionsLoading).toBe(false)
	})

	it('labels a row by origin and agent', async () => {
		const w = await mountChat()
		expect(w.vm.isAutomated({ triggerOrigin: 'cron' })).toBe(true)
		expect(w.vm.isAutomated({ triggerOrigin: 'human' })).toBe(false)
		expect(w.vm.originIcon({ triggerOrigin: 'flow' })).toBe('SitemapOutline')
		expect(w.vm.originIcon({})).toBe('Creation')
		expect(w.vm.agentNameFor({ agentId: 'a1' })).toBe('Helper')
		expect(w.vm.agentNameFor({ agentId: 'zz' })).toBe('')
		expect(w.vm.agentNameFor({})).toBe('')
		expect(w.vm.rowMeta({ agentId: 'a1' })).toBe('Helper · ')
		expect(w.vm.rowMeta({})).toBe('')
	})

	it('lets only the owner of a live, non-Talk session invite', async () => {
		const w = await mountChat()
		expect(w.vm.canInvite({})).toBe(true)
		expect(w.vm.canInvite({ role: 'participant' })).toBe(false)
		expect(w.vm.canInvite({ talkRoomToken: 'x' })).toBe(false)
		w.vm.showArchive = true
		expect(w.vm.canInvite({})).toBe(false)
	})

	it('records the new participant list on the session it was opened for', async () => {
		const w = await mountChat()
		w.vm.onParticipantsChanged([{ uid: 'x' }])
		const session = { uuid: 's1' }
		w.vm.openParticipants(session)
		w.vm.onParticipantsChanged([{ uid: 'bob' }, { uid: 'eve' }])
		expect(session.participants).toEqual(['bob', 'eve'])
	})
})

describe('starting and selecting', () => {
	it('starts a session with an agent and takes over its settings', async () => {
		api.createSession.mockResolvedValueOnce({ uuid: 'new' })
		const w = await mountChat()
		await w.vm.startWithAgent({ id: 'a2', name: '', views: [{ uuid: 'v' }], tools: ['x.t'], searchFiles: false, ragNumSources: 2 })
		expect(api.createSession).toHaveBeenCalledWith('a2')
		// The new session's CLI process starts before its first question.
		expect(api.warmChat).toHaveBeenCalledWith('a2', 'new')
		expect(w.vm.activeSession).toEqual({ uuid: 'new' })
		expect(w.vm.settings).toEqual({ views: ['v'], tools: ['x.t'], includeObjects: true, includeFiles: false, numSourcesObjects: 2, numSourcesFiles: 2 })
		expect(w.vm.startingId).toBe('')
		expect(toasts).toContainEqual(['success', 'Session started with a2'])
	})

	it('explains why a session could not start', async () => {
		api.createSession.mockRejectedValueOnce({ response: { status: 403 } })
		const w = await mountChat()
		await w.vm.startWithAgent({ uuid: 'a1' })
		expect(w.vm.startError).toBe('You do not have permission to start a session with this agent.')
		expect(toasts.at(-1)).toEqual(['error', w.vm.startError])
	})

	it('selects a session: its messages, its agent and its goal', async () => {
		api.listMessages.mockResolvedValueOnce({ results: [{ id: 1 }] })
		api.getSessionGoal.mockResolvedValueOnce({ id: 'g', status: 'active' })
		const w = await mountChat()
		w.vm.pendingAttachments = [{ fileId: 1 }]
		await w.vm.selectSession({ uuid: 's1', agentId: 'a1' })
		await flushPromises()
		expect(api.warmChat).toHaveBeenCalledWith('a1', 's1')
		expect(w.vm.messages).toEqual([{ id: 1 }])
		expect(w.vm.pendingAttachments).toEqual([])
		expect(w.vm.currentAgent.name).toBe('Helper')
		expect(w.vm.settings.tools).toEqual(['t1'])
		expect(w.vm.sessionGoal).toEqual({ id: 'g', status: 'active' })
		expect(w.vm.canSetGoal).toBe(false)
		expect(w.vm.headerTitle).toBe('Helper')
		expect(w.vm.agentName).toBe('Helper')
		expect(w.vm.availableViews).toEqual([{ uuid: 'v1', name: 'v1' }])
		expect(w.vm.availableTools).toEqual([{ uuid: 't1', name: 'T1' }])
		expect(w.vm.isActive({ uuid: 's1' })).toBe(true)
	})

	it('reads no goal for a shared session, and drops one that arrives after switching away', async () => {
		const w = await mountChat()
		await w.vm.loadGoalFor({ uuid: 's1', role: 'participant' })
		expect(api.getSessionGoal).not.toHaveBeenCalled()
		w.vm.activeSession = { uuid: 'other' }
		api.getSessionGoal.mockResolvedValueOnce({ id: 'g' })
		await w.vm.loadGoalFor({ uuid: 's1' })
		expect(w.vm.sessionGoal).toBeNull()
		api.getSessionGoal.mockRejectedValueOnce(new Error('x'))
		await w.vm.loadGoalFor({ uuid: 'other' })
		expect(w.vm.sessionGoal).toBeNull()
	})

	it('says so when a session cannot be loaded, and has no agent for an agentless session', async () => {
		api.listMessages.mockRejectedValueOnce(new Error('x'))
		const w = await mountChat()
		await w.vm.selectSession({ uuid: 's1' })
		expect(toasts).toContainEqual(['error', 'Could not load the session.'])
		expect(w.vm.messagesLoading).toBe(false)
		expect(w.vm.currentAgent).toBeNull()
		expect(w.vm.headerTitle).toBe('Chat')
		expect(w.vm.agentName).toBe('Assistant')
	})

	it('clears the page for a new session', async () => {
		const w = await mountChat()
		w.vm.activeSession = { uuid: 's1' }
		w.vm.messages = [{ id: 1 }]
		w.vm.sendError = 'x'
		w.vm.newSession()
		expect([w.vm.activeSession, w.vm.messages, w.vm.currentAgent, w.vm.sendError]).toEqual([null, [], null, ''])
		expect(w.vm.settingsCustomised).toBe(false)
	})
})

describe('goals', () => {
	it('lets the owner set a goal when none is active, and stops one', async () => {
		const w = await mountChat()
		expect(w.vm.canSetGoal).toBe(false)
		w.vm.activeSession = { uuid: 's1' }
		expect(w.vm.canSetGoal).toBe(true)
		w.vm.onGoalSaved({ id: 'g', status: 'active', statement: 'S' })
		expect(w.vm.showGoalForm).toBe(false)
		expect(w.vm.goalLine).toContain('Goal: S')
		api.stopGoal.mockResolvedValueOnce({ id: 'g', status: 'stopped' })
		await w.vm.onStopGoal()
		expect(api.stopGoal).toHaveBeenCalledWith('g')
		expect(w.vm.sessionGoal.status).toBe('stopped')
		expect(w.vm.goalStopping).toBe(false)
	})

	it('stops nothing without a goal, and says so when stopping fails', async () => {
		const w = await mountChat()
		await w.vm.onStopGoal()
		expect(api.stopGoal).not.toHaveBeenCalled()
		w.vm.sessionGoal = { id: 'g' }
		api.stopGoal.mockRejectedValueOnce(new Error('x'))
		await w.vm.onStopGoal()
		expect(toasts).toContainEqual(['error', 'Could not stop the goal.'])
	})
})

describe('start fields', () => {
	const agent = { startFields: [{ key: 'topic', label: 'Topic', required: true, default: 'x' }] }

	it('asks the start fields of a fresh session and stores the answers', async () => {
		const w = await mountChat()
		w.vm.currentAgent = agent
		w.vm.activeSession = { uuid: 's1' }
		await flushPromises()
		expect(w.vm.startFields.map((f) => f.key)).toEqual(['topic'])
		expect(w.vm.startAnswers).toEqual({ topic: 'x' })
		api.answerStartFields.mockResolvedValueOnce({ startValues: { topic: 'x' } })
		expect(await w.vm.saveStartAnswers()).toBe(true)
		expect(api.answerStartFields).toHaveBeenCalledWith('s1', { topic: 'x' })
		expect(w.vm.sessionStartValues).toEqual(['Topic: x'])
	})

	it('refuses to store missing required answers, and shows the server problems', async () => {
		const w = await mountChat()
		w.vm.currentAgent = agent
		w.vm.activeSession = { uuid: 's1' }
		await flushPromises()
		w.vm.startAnswers = { topic: ' ' }
		expect(w.vm.startFieldsMissing).toEqual(['topic'])
		expect(await w.vm.saveStartAnswers()).toBe(false)
		expect(api.answerStartFields).not.toHaveBeenCalled()
		w.vm.startAnswers = { topic: 'y' }
		api.answerStartFields.mockRejectedValueOnce({ response: { data: { problems: { topic: 'too long' } } } })
		expect(await w.vm.saveStartAnswers()).toBe(false)
		expect(w.vm.startProblems).toEqual({ topic: 'too long' })
		expect(w.vm.sendError).toBe('Check the fields above before you start.')
	})
})

describe('sending', () => {
	/**
	 * A mounted page with an active session and a typed message.
	 *
	 * @return {Promise<object>} The wrapper.
	 */
	async function ready() {
		const w = await mountChat()
		w.vm.activeSession = { uuid: 's1' }
		w.vm.currentMessage = 'Hello'
		return w
	}

	it('streams a turn, showing tokens and tool calls as they come', async () => {
		const w = await ready()
		const seen = []
		api.streamChatMessage.mockImplementationOnce(async (body, handlers) => {
			handlers.onToken('Hi ')
			handlers.onToken('there')
			handlers.onToolCall({ toolId: 'search' })
			handlers.onToolCall({})
			handlers.onToolResult({ toolId: 'search' })
			handlers.onToolResult({ toolId: 'missing' })
			seen.push([w.vm.streamingText, JSON.parse(JSON.stringify(w.vm.streamingTools))])
			return { attachmentNotices: ['skipped a.zip'], messageId: 'm9' }
		})
		await w.vm.handleSend()
		expect(api.streamChatMessage.mock.calls[0][0]).toEqual({ message: 'Hello', sessionUuid: 's1', attachments: [] })
		expect(seen).toEqual([['Hi there', [{ toolId: 'search', done: true }, { toolId: 'tool', done: false }]]])
		expect(w.vm.attachmentNotices).toEqual(['skipped a.zip'])
		expect(w.vm.attachmentNoticesFor).toBe('m9')
		expect(api.getSession).toHaveBeenCalledWith('s1')
		expect(w.vm.activeSession).toEqual({ uuid: 's1', title: 'Fresh' })
		expect(w.vm.sending).toBe(false)
	})

	it('falls back to a post when the stream cannot open, but not on a server-side failure', async () => {
		const w = await ready()
		api.streamChatMessage.mockRejectedValueOnce(new api.ChatStreamError('down', { transport: true }))
		await w.vm.handleSend()
		expect(api.sendChatMessage).toHaveBeenCalledTimes(1)
		expect(api.sendChatMessage.mock.calls[0][0]).toMatchObject({ message: 'Hello', sessionUuid: 's1', ragSettings: { includeObjects: true, numSourcesFiles: 5 } })

		w.vm.currentMessage = 'Again'
		api.streamChatMessage.mockRejectedValueOnce(new api.ChatStreamError('No access', { transport: false }))
		await w.vm.handleSend()
		expect(api.sendChatMessage).toHaveBeenCalledTimes(1)
		expect(w.vm.sendError).toBe('No access')
	})

	it('posts the turn directly when the settings are customised', async () => {
		const w = await ready()
		w.vm.settings = { ...w.vm.settings, includeFiles: false }
		expect(w.vm.settingsCustomised).toBe(true)
		await w.vm.handleSend()
		expect(api.streamChatMessage).not.toHaveBeenCalled()
		expect(api.sendChatMessage.mock.calls[0][0].ragSettings.includeFiles).toBe(false)
	})

	it('keeps the thread as it is when refreshing fails, and ignores a refresh for another session', async () => {
		const w = await ready()
		w.vm.messages = [{ id: 'keep' }]
		api.listMessages.mockRejectedValueOnce(new Error('x'))
		await w.vm.refreshThread('s1')
		expect(w.vm.messages).toEqual([{ id: 'keep' }])
		api.listMessages.mockResolvedValueOnce({ results: [{ id: 'other' }] })
		await w.vm.refreshThread('s2')
		expect(w.vm.messages).toEqual([{ id: 'keep' }])
	})

	it('adds a created image as two turns', async () => {
		const w = await mountChat()
		w.vm.showImageForm = true
		await w.vm.onImageCreated({ userTurn: { id: 'u' }, assistantTurn: { id: 'a' } })
		expect(w.vm.messages).toEqual([])
		w.vm.activeSession = { uuid: 's1' }
		api.listMessages.mockResolvedValueOnce({ results: [{ id: 'u' }, { id: 'a' }] })
		await w.vm.onImageCreated({ userTurn: { id: 'u' }, assistantTurn: { id: 'a' } })
		expect(w.vm.showImageForm).toBe(false)
		expect(w.vm.messages).toEqual([{ id: 'u' }, { id: 'a' }])
	})

	it('appends a dictated transcript to the composer', async () => {
		const w = await mountChat()
		w.vm.currentMessage = 'Hello'
		w.vm.sendError = 'old'
		w.vm.insertTranscript('world')
		expect(w.vm.currentMessage).toBe('Hello world')
		expect(w.vm.sendError).toBe('')
	})
})

describe('attachments', () => {
	it('uploads a device file and queues it once', async () => {
		api.uploadChatAttachment.mockResolvedValue({ fileId: 5, name: 'a.png', mimeType: 'image/png' })
		const w = await mountChat()
		const target = { files: [new Blob(['x'])], value: 'C:/a.png' }
		await w.vm.onDeviceFileChosen({ target })
		await w.vm.onDeviceFileChosen({ target: { files: [new Blob(['x'])] } })
		expect(target.value).toBe('')
		expect(w.vm.pendingAttachments).toEqual([{ fileId: 5, name: 'a.png', mimeType: 'image/png', origin: 'upload' }])
		expect(w.vm.attaching).toBe(false)
		w.vm.removeAttachment({ fileId: 5 })
		expect(w.vm.pendingAttachments).toEqual([])
	})

	it('shows the refusal of an upload, and does nothing without a file', async () => {
		const w = await mountChat()
		await w.vm.onDeviceFileChosen({ target: { files: [] } })
		await w.vm.onDeviceFileChosen(null)
		expect(api.uploadChatAttachment).not.toHaveBeenCalled()
		api.uploadChatAttachment.mockRejectedValueOnce({ response: { data: { error: 'Too large' } } })
		await w.vm.onDeviceFileChosen({ target: { files: [new Blob(['x'])] } })
		expect(w.vm.sendError).toBe('Too large')
		api.uploadChatAttachment.mockRejectedValueOnce(new Error('x'))
		await w.vm.onDeviceFileChosen({ target: { files: [new Blob(['x'])] } })
		expect(w.vm.sendError).toBe('The file could not be attached.')
	})

	it('queues the files picked from Files, skipping one without an id, and survives a closed picker', async () => {
		const w = await mountChat()
		picker.nodes = [{ fileid: 1, basename: 'a.pdf', mime: 'application/pdf' }, { basename: 'none' }]
		await w.vm.chooseFromFiles()
		expect(w.vm.pendingAttachments).toEqual([{ fileId: 1, name: 'a.pdf', mimeType: 'application/pdf', origin: 'files' }])
		picker.fail = true
		await expect(w.vm.chooseFromFiles()).resolves.toBeUndefined()
	})

	it('tells images from other files and links both', async () => {
		const w = await mountChat()
		expect(w.vm.isImage({ mimeType: 'image/jpeg' })).toBe(true)
		expect(w.vm.isImage({ mimeType: 'text/plain' })).toBe(false)
		expect(w.vm.isImage(null)).toBe(false)
		expect(w.vm.previewUrl({ fileId: 3 })).toContain('/core/preview')
		expect(w.vm.fileLink({ fileId: 3 })).toContain('/f/')
	})
})

describe('feedback', () => {
	it('records a rating, and clears it when the same rating is given again', async () => {
		const w = await mountChat()
		w.vm.activeSession = { uuid: 's1' }
		const message = { id: 'm1' }
		await w.vm.sendFeedback(message, 'positive')
		expect(api.sendMessageFeedback).toHaveBeenCalledWith('s1', 'm1', { type: 'positive' })
		expect([message.feedback, message.showFeedbackInput]).toEqual(['positive', true])
		await w.vm.sendFeedback(message, 'positive')
		expect([message.feedback, message.showFeedbackInput]).toEqual([null, false])
		expect(api.sendMessageFeedback).toHaveBeenCalledTimes(1)
	})

	it('undoes a rating that could not be stored', async () => {
		const w = await mountChat()
		w.vm.activeSession = { uuid: 's1' }
		api.sendMessageFeedback.mockRejectedValueOnce(new Error('x'))
		const message = { uuid: 'm1' }
		await w.vm.sendFeedback(message, 'negative')
		expect([message.feedback, message.showFeedbackInput]).toEqual([null, false])
		expect(toasts).toContainEqual(['error', 'Could not record feedback.'])
	})

	it('sends a trimmed comment with the rating, and nothing for a blank one', async () => {
		const w = await mountChat()
		w.vm.activeSession = { uuid: 's1' }
		await w.vm.saveFeedbackComment({ id: 'm1', feedbackComment: '  ' })
		expect(api.sendMessageFeedback).not.toHaveBeenCalled()
		const message = { id: 'm1', feedback: 'negative', feedbackComment: ' wrong date ', showFeedbackInput: true }
		await w.vm.saveFeedbackComment(message)
		expect(api.sendMessageFeedback).toHaveBeenCalledWith('s1', 'm1', { type: 'negative', comment: 'wrong date' })
		expect(message.showFeedbackInput).toBe(false)
		api.sendMessageFeedback.mockRejectedValueOnce(new Error('x'))
		await w.vm.saveFeedbackComment(message)
		expect(toasts).toContainEqual(['error', 'Could not save the feedback comment.'])
	})
})

describe('agent drafts and skills', () => {
	const content = 'Here:\n```hermiq-agent-draft\n{"name":"Bot"}\n```'

	it('offers a draft only on an assistant turn that holds one', async () => {
		const w = await mountChat()
		expect(w.vm.hasAgentDraft({ role: 'assistant', content })).toBe(true)
		expect(w.vm.hasAgentDraft({ role: 'user', content })).toBe(false)
		expect(w.vm.hasAgentDraft({ role: 'assistant', content: 'plain' })).toBe(false)
	})

	it('checks a draft and opens it as an agent with its schedule and findings', async () => {
		agentsApi.checkAgentDraft.mockResolvedValueOnce({ draft: { name: 'Bot', schedule: { kind: 'once' } }, findings: [{ field: 'name', message: 'short' }] })
		const w = await mountChat()
		await w.vm.openAgentDraft({ content })
		expect(agentsApi.checkAgentDraft).toHaveBeenCalledWith('{"name":"Bot"}')
		expect(w.vm.draftAgent.name).toBe('Bot')
		expect(w.vm.draftSchedule.kind).toBe('once')
		expect(w.vm.draftFindings).toEqual({ name: [{ message: 'short', suggestion: '' }] })
		w.vm.onDraftAgentSaved({ '@self': { id: 'new-agent' } })
		expect(w.vm.draftScheduleAgentId).toBe('new-agent')
		w.vm.closeAgentDraft()
		expect([w.vm.draftAgent, w.vm.draftFindings]).toEqual([null, {}])
	})

	it('names an unreadable draft apart from a failed check', async () => {
		const w = await mountChat()
		agentsApi.checkAgentDraft.mockRejectedValueOnce({ response: { status: 422 } })
		await w.vm.openAgentDraft({ content })
		agentsApi.checkAgentDraft.mockRejectedValueOnce(new Error('x'))
		await w.vm.openAgentDraft({ content })
		expect(toasts).toEqual([['error', 'This draft could not be read.'], ['error', 'Could not check the draft.']])
		expect(w.vm.checkingDraft).toBe(false)
	})

	it('keeps no schedule agent when the draft had no schedule', async () => {
		const w = await mountChat()
		w.vm.onDraftAgentSaved({ id: 'x' })
		expect(w.vm.draftScheduleAgentId).toBe('')
	})

	it('opens a turn as a skill body and confirms the save', async () => {
		const w = await mountChat()
		w.vm.openSaveAsSkill({ content: 'Steps' })
		expect([w.vm.saveAsSkillBody, w.vm.showSaveAsSkill]).toEqual(['Steps', true])
		w.vm.openSaveAsSkill({})
		expect(w.vm.saveAsSkillBody).toBe('')
		w.vm.onSkillSaved()
		expect(toasts.at(-1)[0]).toBe('success')
	})
})

describe('archive, restore, delete and rename', () => {
	it('archives the open session and starts a new one', async () => {
		const w = await mountChat()
		w.vm.activeSession = { uuid: 's1' }
		await w.vm.archive({ uuid: 's1' })
		expect(api.archiveSession).toHaveBeenCalledWith('s1')
		expect(w.vm.activeSession).toBeNull()
		expect(toasts).toContainEqual(['success', 'Session archived'])
		api.archiveSession.mockRejectedValueOnce(new Error('x'))
		await w.vm.archive({ uuid: 's1' })
		expect(toasts).toContainEqual(['error', 'Could not archive the session.'])
	})

	it('restores a session out of the archive list', async () => {
		const w = await mountChat()
		w.vm.archivedSessions = [{ uuid: 'old' }, { uuid: 'keep' }]
		await w.vm.restore({ uuid: 'old' })
		expect(w.vm.archivedSessions).toEqual([{ uuid: 'keep' }])
		api.restoreSession.mockRejectedValueOnce(new Error('x'))
		await w.vm.restore({ uuid: 'keep' })
		expect(w.vm.archivedSessions).toEqual([{ uuid: 'keep' }])
		expect(toasts).toContainEqual(['error', 'Could not restore the session.'])
	})

	it('deletes from the archive and leaves the open session when it was the deleted one', async () => {
		const w = await mountChat()
		w.vm.openDelete({ uuid: 'old' })
		expect([w.vm.deleteTarget, w.vm.showDelete]).toEqual([{ uuid: 'old' }, true])
		w.vm.archivedSessions = [{ uuid: 'old' }]
		w.vm.activeSession = { uuid: 'old' }
		w.vm.onDeleted({ uuid: 'old' })
		expect(w.vm.archivedSessions).toEqual([])
		expect(w.vm.activeSession).toBeNull()
	})

	it('shows the renamed session', async () => {
		const w = await mountChat()
		await w.vm.onRenamed({ uuid: 's1', title: 'New' })
		expect(w.vm.activeSession.title).toBe('New')
		expect(toasts).toContainEqual(['success', 'Session renamed'])
	})
})

describe('presentation', () => {
	it('names the author of a turn', async () => {
		const w = await mountChat()
		expect(w.vm.authorName({})).toBe('You')
		expect(w.vm.authorName({ authorId: 'me' })).toBe('You')
		expect(w.vm.authorName({ authorId: 'bob', authorDisplayName: 'Bob' })).toBe('Bob')
		expect(w.vm.authorName({ authorId: 'bob' })).toBe('bob')
	})

	it('renders markdown without scripts', async () => {
		const w = await mountChat()
		const html = w.vm.renderMarkdown('**bold**<script>alert(1)</script>')
		expect(html).toContain('<strong>bold</strong>')
		expect(html).not.toContain('<script')
		expect(w.vm.renderMarkdown(null)).toBe('')
	})

	it('shows a time as just now, as a clock time today, or as a date', async () => {
		vi.useFakeTimers({ now: new Date('2026-05-05T12:00:00Z'), toFake: ['Date'] })
		const w = await mountChat()
		expect(w.vm.formatTime('')).toBe('')
		expect(w.vm.formatTime('garbage')).toBe('')
		expect(w.vm.formatTime('2026-05-05T11:59:30Z')).toBe('Just now')
		expect(w.vm.formatTime('2026-05-05T10:00:00Z')).toMatch(/\d{2}.\d{2}/)
		expect(w.vm.formatTime('2026-01-01T10:00:00Z')).toBe(new Date('2026-01-01T10:00:00Z').toLocaleDateString())
	})

	it('names tools by their readable label, keeping object tools as given', async () => {
		const w = await mountChat()
		w.vm.currentAgent = { tools: ['openregister.search_objects', { uuid: 'x', name: 'X' }], views: [{ uuid: 'v', name: 'V' }] }
		expect(w.vm.availableTools).toEqual([{ uuid: 'openregister.search_objects', name: 'Search objects' }, { uuid: 'x', name: 'X' }])
		expect(w.vm.availableViews).toEqual([{ uuid: 'v', name: 'V' }])
	})

	it('offers dictation only where the browser can record', async () => {
		const w = await mountChat()
		w.vm.currentAgent = {}
		expect(w.vm.speechControls).toEqual({ dictate: false, readAloud: true })
	})
})
