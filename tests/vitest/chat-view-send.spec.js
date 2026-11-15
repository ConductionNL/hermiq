/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Characterisation of the chat page's send and session grouping: when a turn is
 * sent, what the composer and the thread show around it, which transport takes
 * it, how a failure lands, and how the session list is grouped.
 */

import { describe, expect, it, vi } from 'vitest'

vi.mock('@conduction/nextcloud-vue', () => ({
	SAFE_MARKDOWN_DOMPURIFY_CONFIG: {},
	CnIconPicker: { name: 'CnIconPicker', render: () => null },
	CnMarkdownEditor: { name: 'CnMarkdownEditor', render: () => null },
	fromOpenGemeenten: () => [],
	createObjectStore: () => () => ({}),
}))
vi.mock('../../src/store/store.js', () => ({ useAgentStore: () => ({ registerObjectType: () => {}, errors: {} }) }))

const { default: Chat } = await import('../../src/views/Chat.vue')

/**
 * A chat page context with its methods bound and recording doubles.
 *
 * @param {object} state The data and computed values.
 * @return {object} The context.
 */
function page(state) {
	const calls = []
	const ctx = {
		...Chat.methods,
		t: (app, text) => text,
		calls,
		currentMessage: '  Hello  ',
		sending: false,
		activeSession: { uuid: 'sess-1' },
		startFields: [],
		pendingAttachments: [{ fileId: 7, origin: 'files', name: 'a.pdf' }],
		sendError: 'old',
		attachmentNotices: ['old'],
		attachmentNoticesFor: 'old',
		messages: [],
		settingsCustomised: false,
		isStreaming: true,
		streamingText: 'x',
		streamingTools: ['x'],
		sendNeedsCourse: false,
		...state,
	}
	ctx.scrollToBottom = () => calls.push(['scroll'])
	ctx.focusComposer = () => calls.push(['focus'])
	ctx.refreshThread = async (uuid) => calls.push(['refresh', uuid])
	ctx.sendViaStream = state.sendViaStream || (async (...args) => calls.push(['stream', ...args]))
	ctx.sendViaPost = state.sendViaPost || (async (...args) => calls.push(['post', ...args]))
	ctx.saveStartAnswers = state.saveStartAnswers || (async () => true)
	return ctx
}

describe('Chat handleSend', () => {
	it.each([
		[{ currentMessage: '   ' }],
		[{ sending: true }],
		[{ activeSession: null }],
		[{ startFields: [{ key: 'a' }], saveStartAnswers: async () => false }],
	])('sends nothing when it cannot (%#)', async (state) => {
		const ctx = page(state)
		await ctx.handleSend()
		expect(ctx.calls).toEqual([])
		expect(ctx.messages).toEqual([])
	})

	it('streams the turn with the attachment references, and tidies up after', async () => {
		const ctx = page({})
		await ctx.handleSend()

		expect(ctx.messages).toHaveLength(1)
		expect(ctx.messages[0]).toMatchObject({ role: 'user', content: 'Hello', attachments: [{ fileId: 7, origin: 'files', name: 'a.pdf' }] })
		expect(ctx.messages[0].id).toMatch(/^optimistic-\d+$/)
		expect(ctx.calls).toEqual([
			['scroll'],
			['stream', 'Hello', 'sess-1', [{ fileId: 7, origin: 'files' }]],
			['refresh', 'sess-1'],
			['scroll'],
			['focus'],
		])
		expect(ctx.currentMessage).toBe('')
		expect(ctx.pendingAttachments).toEqual([])
		expect(ctx.sendError).toBe('')
		expect(ctx.attachmentNotices).toEqual([])
		expect(ctx.attachmentNoticesFor).toBe('')
		expect([ctx.sending, ctx.isStreaming, ctx.streamingText, ctx.streamingTools]).toEqual([false, false, '', []])
	})

	it('posts the turn when the chat settings are customised, after saving the start answers', async () => {
		const ctx = page({ settingsCustomised: true, startFields: [{ key: 'a' }] })
		await ctx.handleSend()
		expect(ctx.calls[1][0]).toBe('post')
	})

	it.each([
		[{ response: { data: { message: 'Server says no', errorCode: 'ai_literacy_required' } } }, 'Server says no', true],
		[Object.assign(new Error('Plain'), { code: 'ai_literacy_required' }), 'Plain', true],
		[{}, 'Failed to get a response.', false],
	])('shows a failed turn', async (thrown, message, needsCourse) => {
		const ctx = page({ sendViaStream: async () => { throw thrown } })
		await ctx.handleSend()
		expect(ctx.sendError).toBe(message)
		expect(ctx.sendNeedsCourse).toBe(needsCourse)
		expect(ctx.sending).toBe(false)
		expect(ctx.calls.at(-1)).toEqual(['focus'])
	})
})

describe('Chat sessionGroups', () => {
	const groups = Chat.computed.sessionGroups
	const automated = (session) => session.auto === true

	it('shows the archive as one headless group', () => {
		expect(groups.call({ showArchive: true, visibleSessions: [{ id: 1 }], t: (a, s) => s })).toEqual([{ key: 'archive', heading: '', sessions: [{ id: 1 }] }])
	})

	it('groups by who started a session, headed when there is more than one group', () => {
		const sessions = [{ id: 1 }, { id: 2, role: 'participant' }, { id: 3, auto: true }, { id: 4 }]
		expect(groups.call({ showArchive: false, visibleSessions: sessions, isAutomated: automated, t: (a, s) => s })).toEqual([
			{ key: 'human', heading: 'Started by you', sessions: [{ id: 1 }, { id: 4 }] },
			{ key: 'shared', heading: 'Shared with me', sessions: [{ id: 2, role: 'participant' }] },
			{ key: 'automated', heading: 'Started automatically', sessions: [{ id: 3, auto: true }] },
		])
	})

	it('leaves a single group headless, and no sessions as no groups', () => {
		expect(groups.call({ showArchive: false, visibleSessions: [{ id: 3, auto: true }], isAutomated: automated, t: (a, s) => s }))
			.toEqual([{ key: 'automated', heading: '', sessions: [{ id: 3, auto: true }] }])
		expect(groups.call({ showArchive: false, visibleSessions: [], isAutomated: automated, t: (a, s) => s })).toEqual([])
	})
})

describe('Chat goalLine', () => {
	/**
	 * Translate as the identity, filling {placeholders}.
	 *
	 * @param {string} app The app id.
	 * @param {string} text The text.
	 * @param {object} vars The placeholders.
	 * @return {string} The filled text.
	 */
	const t = (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (match, key) => (key in vars ? String(vars[key]) : match))
	const line = (sessionGoal) => Chat.computed.goalLine.call({ sessionGoal, t })

	it('says nothing without a goal', () => {
		expect(line(null)).toBe('')
	})

	it('names how an ended goal ended', () => {
		expect(line({ status: 'stopped', statement: 'Book a room' })).toBe('Goal stopped: Book a room')
		expect(line({ status: 'reached', statement: 'Book a room' })).toBe('Goal reached: Book a room')
		expect(line({ status: 'exhausted' })).toBe('Turn limit used: ')
	})

	it('describes an active goal with its turns, block and last check', () => {
		expect(line({ status: 'active', statement: 'S', turnsUsed: 3, maxTurns: 8, blocked: true, lastCheckResult: { summary: 'close' } }))
			.toBe('Goal: S, turn 3 of 8, blocked, last check: close')
		expect(line({ statement: 'S' })).toBe('Goal: S, turn 0 of 10')
	})
})
