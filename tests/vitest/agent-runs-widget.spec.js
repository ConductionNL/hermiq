/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Characterisation of CnAgentRunsWidget's requests: writing a summary, loading
 * the run history and running an agent on the object, and what each outcome
 * shows.
 */

import { afterEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/auth', () => ({ getRequestToken: () => 'token-1' }))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (url, params = {}) => '/index.php' + url.replace(/\{(\w+)\}/g, (match, key) => params[key] ?? match),
}))

const { default: Widget } = await import('../../src/components/CnAgentRunsWidget/CnAgentRunsWidget.vue')
const { summaryRefusal } = await import('../../src/utils/recordSummary.js')

/**
 * A widget context with its methods bound.
 *
 * @param {object} state The data.
 * @return {object} The context.
 */
function widget(state) {
	return {
		...Widget.methods,
		register: 'reg',
		schema: 'case',
		objectId: 'obj-1',
		summaryStatus: { available: true },
		summaryWriting: false,
		summaryError: 'old',
		runs: ['old'],
		selectedAgent: { id: 'agent-1' },
		dispatching: false,
		notice: 'old',
		noticeType: '',
		...state,
	}
}

/**
 * A fetch answering with the given status and body.
 *
 * @param {number} status The status.
 * @param {*} body The JSON body, or an Error the body read throws.
 * @return {Function} The fetch double.
 */
function answering(status, body) {
	return vi.fn(async () => ({
		status,
		ok: status >= 200 && status < 300,
		json: async () => {
			if (body instanceof Error) {
				throw body
			}
			return body
		},
	}))
}

const objectPost = {
	method: 'POST',
	headers: { 'Content-Type': 'application/json', requesttoken: 'token-1' },
	body: JSON.stringify({ register: 'reg', schema: 'case', objectId: 'obj-1' }),
}

afterEach(() => {
	vi.unstubAllGlobals()
})

describe('writeSummary', () => {
	it('posts the object and keeps the summary', async () => {
		vi.stubGlobal('fetch', answering(200, { text: 'Short.' }))
		const ctx = widget({})
		await ctx.writeSummary()
		expect(fetch).toHaveBeenCalledWith('/index.php/apps/hermiq/api/assistant/summarise', objectPost)
		expect(ctx.summaryStatus).toEqual({ available: true, summary: { text: 'Short.' } })
		expect(ctx.summaryError).toBe('')
		expect(ctx.summaryWriting).toBe(false)
	})

	it('shows the refusal for a status, and a failed request', async () => {
		vi.stubGlobal('fetch', answering(403, new Error('no json')))
		const refused = widget({})
		await refused.writeSummary()
		expect(refused.summaryError).toBe(Widget.methods.refusalText(summaryRefusal(403)))

		vi.stubGlobal('fetch', vi.fn(async () => { throw new Error('offline') }))
		const failed = widget({})
		await failed.writeSummary()
		expect(failed.summaryError).toBe(Widget.methods.refusalText('failed'))
		expect(failed.summaryWriting).toBe(false)
	})

	it('does nothing while a summary is being written', async () => {
		vi.stubGlobal('fetch', answering(200, {}))
		await widget({ summaryWriting: true }).writeSummary()
		expect(fetch).not.toHaveBeenCalled()
	})
})

describe('loadHistory', () => {
	it('keeps the agent-run entries of this object', async () => {
		vi.stubGlobal('fetch', answering(200, { results: [
			{ action: 'agent-run', context: { status: 'failed', summary: 'Oops', endedAt: '2026-01-02T03:04:05Z' } },
			{ action: 'update' },
			{ action: 'agent-run', created: '' },
		] }))
		const ctx = widget({})
		await ctx.loadHistory()
		expect(fetch).toHaveBeenCalledWith('/index.php/apps/openregister/api/objects/reg/case/obj-1/audit-trails', { headers: { requesttoken: 'token-1' } })
		expect(ctx.runs).toEqual([
			{ status: 'failed', summary: 'Oops', when: new Date('2026-01-02T03:04:05Z').toLocaleString() },
			{ status: 'ok', summary: '', when: '' },
		])
		expect(ctx.loadingHistory).toBe(false)
	})

	it.each([
		[answering(500, {})],
		[answering(200, [null])],
		[vi.fn(async () => { throw new Error('offline') })],
	])('shows no runs when the history cannot be read (%#)', async (fake) => {
		vi.stubGlobal('fetch', fake)
		const ctx = widget({})
		await ctx.loadHistory()
		expect(ctx.runs).toEqual([])
		expect(ctx.loadingHistory).toBe(false)
	})
})

describe('runAgent', () => {
	it('queues the run and reloads the history', async () => {
		vi.stubGlobal('fetch', answering(202, {}))
		const ctx = widget({})
		ctx.loadHistory = vi.fn(async () => {})
		await ctx.runAgent()
		expect(fetch).toHaveBeenCalledWith('/index.php/apps/hermiq/api/agents/agent-1/run-on-object', objectPost)
		expect([ctx.noticeType, ctx.notice]).toEqual(['success', 'Run queued. It will appear in the history shortly.'])
		expect(ctx.loadHistory).toHaveBeenCalled()
		expect(ctx.dispatching).toBe(false)
	})

	it.each([
		[404, {}, 'Object or agent not found.'],
		[409, { error: 'Busy' }, 'Busy'],
		[500, new Error('no json'), 'Could not start the run.'],
	])('shows a %i', async (status, body, notice) => {
		vi.stubGlobal('fetch', answering(status, body))
		const ctx = widget({})
		ctx.loadHistory = vi.fn(async () => {})
		await ctx.runAgent()
		expect([ctx.noticeType, ctx.notice]).toEqual(['error', notice])
		expect(ctx.loadHistory).not.toHaveBeenCalled()
	})

	it('says the service is unreachable, and does nothing without an agent or while dispatching', async () => {
		vi.stubGlobal('fetch', vi.fn(async () => { throw new Error('offline') }))
		const ctx = widget({})
		await ctx.runAgent()
		expect([ctx.noticeType, ctx.notice]).toEqual(['error', 'The agent service is unreachable.'])

		vi.mocked(fetch).mockClear()
		await widget({ selectedAgent: null }).runAgent()
		await widget({ dispatching: true }).runAgent()
		expect(fetch).not.toHaveBeenCalled()
	})
})
