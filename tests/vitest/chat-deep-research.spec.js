/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The chat's deep research controls (tools-deep-research-report task 6): when the
 * switch is offered, which run counts as going, the progress line, the API
 * helpers' routes, and how the page starts, stops and polls a run.
 */

import { afterEach, describe, expect, it, vi } from 'vitest'

const posted = []
const fetched = []
let postFails = false
vi.mock('@nextcloud/axios', () => ({
	default: {
		post: async (url, body) => {
			posted.push([url, body])
			if (postFails) {
				throw new Error('down')
			}
			return { data: { id: 'h-1', status: 'waiting' } }
		},
		get: async (url) => {
			fetched.push(url)
			return { data: { id: 'h-1', status: 'waiting' } }
		},
	},
}))
vi.mock('@conduction/nextcloud-vue', () => ({
	SAFE_MARKDOWN_DOMPURIFY_CONFIG: {},
	CnIconPicker: { name: 'CnIconPicker', render: () => null },
	CnMarkdownEditor: { name: 'CnMarkdownEditor', render: () => null },
	fromOpenGemeenten: () => [],
	createObjectStore: () => () => ({}),
}))
vi.mock('../../src/store/store.js', () => ({ useAgentStore: () => ({ registerObjectType: () => {}, errors: {} }) }))

const { getResearch, startResearch, stopResearch } = await import('../../src/api/research.js')
const { canDeepResearch, researchId, researchProgressLine, runningResearch } = await import('../../src/utils/deepResearch.js')
const { default: Chat } = await import('../../src/views/Chat.vue')

/**
 * A translate double that fills in the variables.
 *
 * @param {string} app The app.
 * @param {string} text The source text.
 * @param {object} vars The variables.
 * @return {string} The text.
 */
const t = (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (_, key) => String(vars[key]))

afterEach(() => {
	posted.length = 0
	fetched.length = 0
	postFails = false
	vi.useRealTimers()
})

describe('canDeepResearch', () => {
	it.each([
		['both tools as a list', { tools: ['hermiq.webSearch', 'hermiq.webFetch', 'hermiq.listFiles'] }, true],
		['both tools, one with a constraint', { tools: ['hermiq.webSearch', 'hermiq.webFetch?host=in:a.nl'] }, true],
		['both tools in the structured shape', { tools: { hermiq: { web: { search: 'hermiq.webSearch', fetch: [{ id: 'hermiq.webFetch' }] } } } }, true],
		['only search', { tools: ['hermiq.webSearch'] }, false],
		['only fetch, structured', { tools: { hermiq: { web: { fetch: 'hermiq.webFetch' } } } }, false],
		['no grants at all, which allows every tool', { tools: [] }, true],
		['no agent', null, false],
	])('%s', (_, agent, expected) => {
		expect(canDeepResearch(agent)).toBe(expected)
	})
})

describe('runningResearch and researchId', () => {
	it('takes the latest waiting research handoff and leaves other kinds and ended runs out', () => {
		const handoffs = [
			{ id: 'a', kind: 'research', status: 'done' },
			{ id: 'b', kind: 'research', status: 'waiting' },
			{ id: 'c', kind: 'flow', status: 'waiting' },
			{ uuid: 'd', kind: 'research', status: 'waiting' },
		]
		expect(runningResearch(handoffs)).toEqual(handoffs[3])
		expect(researchId(runningResearch(handoffs))).toBe('d')
		expect(researchId(handoffs[1])).toBe('b')
	})

	it('finds none without a waiting research run', () => {
		expect(runningResearch([{ kind: 'research', status: 'failed' }])).toBeNull()
		expect(runningResearch(undefined)).toBeNull()
		expect(researchId(null)).toBe('')
	})
})

describe('researchProgressLine', () => {
	it.each([
		[null, ''],
		[{ progress: { phase: 'plan' } }, 'Planning the research…'],
		[{}, 'Planning the research…'],
		[{ progress: { phase: 'gather', sources: 7, toolCalls: 12 } }, 'Researching: 7 sources read, 12 tool calls'],
		[{ progress: { phase: 'write', sources: 14 } }, 'Writing the report from 14 sources…'],
		[{ stopRequested: true, progress: { phase: 'gather' } }, 'Stopping the research…'],
	])('%j reads %s', (run, line) => {
		expect(researchProgressLine(run, t)).toBe(line)
	})
})

describe('the research API helpers', () => {
	it('starts, reads and stops a run on the research routes', async () => {
		await startResearch({ agentId: 'ag-1', conversationId: 'sess-1', question: 'Q?' })
		await getResearch('h-1')
		await stopResearch('h-1')

		expect(posted).toEqual([
			['/index.php/apps/hermiq/api/research', { agentId: 'ag-1', conversationId: 'sess-1', question: 'Q?', depth: 'thorough' }],
			['/index.php/apps/hermiq/api/research/h-1/stop', undefined],
		])
		expect(fetched).toEqual(['/index.php/apps/hermiq/api/research/h-1'])
	})
})

/**
 * A chat page context with its methods and research computed values bound.
 *
 * @param {object} state The data.
 * @return {object} The context.
 */
function page(state = {}) {
	const calls = []
	const ctx = {
		...Chat.methods,
		t,
		calls,
		currentMessage: '  Which councils publish an algorithm register?  ',
		sending: false,
		sendError: '',
		activeSession: { uuid: 'sess-1', agentId: 'ag-from-session' },
		currentAgent: { uuid: 'ag-1', tools: ['hermiq.webSearch', 'hermiq.webFetch'] },
		startFields: [],
		messages: [],
		handoffs: [],
		deepResearch: true,
		researchTimer: null,
		stoppingResearch: false,
		...state,
	}
	Object.defineProperty(ctx, 'canDeepResearch', { get: () => Chat.computed.canDeepResearch.call(ctx) })
	Object.defineProperty(ctx, 'activeResearch', { get: () => Chat.computed.activeResearch.call(ctx) })
	ctx.finishTurn = async (uuid) => calls.push(['finish', uuid])
	ctx.refreshThread = async (uuid) => calls.push(['refresh', uuid])
	ctx.sendViaStream = async () => calls.push(['stream'])
	ctx.sendViaPost = async () => calls.push(['post'])
	ctx.scrollToBottom = () => {}
	ctx.takeComposerAttachments = () => []
	return ctx
}

describe('Chat deep research', () => {
	it('starts a research run instead of a turn while the switch is on', async () => {
		const ctx = page()
		await ctx.handleSend()

		expect(posted).toEqual([[
			'/index.php/apps/hermiq/api/research',
			{ agentId: 'ag-1', conversationId: 'sess-1', question: 'Which councils publish an algorithm register?', depth: 'thorough' },
		]])
		expect(ctx.calls).toEqual([['finish', 'sess-1']])
		expect(ctx.currentMessage).toBe('')
		expect(ctx.deepResearch).toBe(false)
		expect(ctx.messages).toEqual([])
	})

	it('sends an ordinary turn when the switch is off or the agent lacks the web tools', async () => {
		const off = page({ deepResearch: false })
		await off.handleSend()
		const lacking = page({ currentAgent: { uuid: 'ag-1', tools: ['hermiq.webSearch'] } })
		await lacking.handleSend()

		expect(posted).toEqual([])
		expect(off.calls[0]).toEqual(['stream'])
		expect(lacking.calls[0]).toEqual(['stream'])
	})

	it('keeps the question and says so when the run cannot start', async () => {
		postFails = true
		const ctx = page()
		await ctx.handleSend()

		expect(ctx.sendError).toBe('The research could not be started.')
		expect(ctx.currentMessage).toContain('algorithm register')
		expect(ctx.calls).toEqual([['finish', 'sess-1']])
	})

	it('offers the switch only in a session whose agent holds both tools', () => {
		expect(page().canDeepResearch).toBe(true)
		expect(page({ activeSession: null }).canDeepResearch).toBe(false)
		expect(page({ currentAgent: { tools: ['hermiq.webFetch'] } }).canDeepResearch).toBe(false)
	})

	it('stops the running research and re-reads the thread', async () => {
		const ctx = page({ handoffs: [{ id: 'h-9', kind: 'research', status: 'waiting' }] })
		await ctx.onStopResearch()

		expect(posted).toEqual([['/index.php/apps/hermiq/api/research/h-9/stop', undefined]])
		expect(ctx.stoppingResearch).toBe(false)
		expect(ctx.calls).toEqual([['refresh', 'sess-1']])
	})

	it('sends no stop without a running research, and says so when the stop fails', async () => {
		const none = page()
		await none.onStopResearch()
		expect(posted).toEqual([])

		postFails = true
		const failing = page({ handoffs: [{ id: 'h-9', kind: 'research', status: 'waiting' }] })
		await failing.onStopResearch()
		expect(failing.sendError).toBe('The research could not be stopped.')
	})

	it('polls the thread while a run goes and stops polling once it ends', () => {
		vi.useFakeTimers()
		const ctx = page({ handoffs: [{ id: 'h-9', kind: 'research', status: 'waiting' }] })
		ctx.syncResearchPolling()
		ctx.syncResearchPolling()
		vi.advanceTimersByTime(10000)
		expect(ctx.calls).toEqual([['refresh', 'sess-1'], ['refresh', 'sess-1']])

		ctx.handoffs = [{ id: 'h-9', kind: 'research', status: 'done' }]
		ctx.syncResearchPolling()
		expect(ctx.researchTimer).toBeNull()
		vi.advanceTimersByTime(10000)
		expect(ctx.calls).toHaveLength(2)
	})
})
