/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Characterisation of CnAgentChatTab's send: what it posts, how a reply,
 * an unavailable agent, a refusal and an unreachable service land on the tab.
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/auth', () => ({ getRequestToken: () => 'token-1' }))
vi.mock('../../src/utils/readyMadePrompts.js', () => ({ loadReadyMadePrompts: async () => [], withPrompt: (draft) => draft }))

const { default: CnAgentChatTab } = await import('../../src/components/CnAgentChatTab/CnAgentChatTab.vue')

/**
 * A tab context with its methods bound.
 *
 * @param {object} state The data and computed values.
 * @return {object} The context.
 */
function tab(state) {
	return {
		...CnAgentChatTab.methods,
		draft: '  Hello  ',
		sending: false,
		error: 'old',
		messages: [],
		sessionId: '',
		unavailable: false,
		objectType: 'case',
		schema: 'schema-x',
		objectId: 'obj-1',
		boundedContext: { title: 'T' },
		hasBoundedContext: true,
		...state,
	}
}

/**
 * A fetch answering with the given status and body.
 *
 * @param {number} status The status.
 * @param {object} body The JSON body.
 * @return {Function} The fetch double.
 */
function answering(status, body) {
	return vi.fn(async () => ({ status, ok: status >= 200 && status < 300, json: async () => body }))
}

afterEach(() => {
	vi.unstubAllGlobals()
})

describe('CnAgentChatTab send', () => {
	beforeEach(() => {
		vi.stubGlobal('fetch', answering(200, { sessionId: 'sess-1', reply: 'Hi there' }))
	})

	it('ignores an empty draft and a send in flight', async () => {
		const empty = tab({ draft: '   ' })
		await empty.send()
		const busy = tab({ sending: true })
		await busy.send()
		expect(fetch).not.toHaveBeenCalled()
		expect(empty.messages).toEqual([])
	})

	it('posts the message with its context and shows the reply', async () => {
		const ctx = tab({})
		await ctx.send()

		expect(fetch).toHaveBeenCalledWith('/index.php/apps/hermiq/api/assistant/converse', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json', requesttoken: 'token-1' },
			body: JSON.stringify({ message: 'Hello', context: { app: 'hermiq', objectType: 'case', objectRef: 'obj-1', contextData: { title: 'T' } } }),
		})
		expect(ctx.messages).toEqual([{ role: 'user', text: 'Hello' }, { role: 'agent', text: 'Hi there', grounded: true }])
		expect(ctx.sessionId).toBe('sess-1')
		expect(ctx.draft).toBe('')
		expect(ctx.error).toBe('')
		expect(ctx.sending).toBe(false)
	})

	it('continues a session and falls back to the schema as the object type', async () => {
		vi.stubGlobal('fetch', answering(200, {}))
		const ctx = tab({ sessionId: 'sess-0', objectType: '', hasBoundedContext: false })
		await ctx.send()

		const sent = JSON.parse(fetch.mock.calls[0][1].body)
		expect(sent.sessionId).toBe('sess-0')
		expect(sent.context.objectType).toBe('schema-x')
		expect(ctx.sessionId).toBe('sess-0')
		expect(ctx.messages[1]).toEqual({ role: 'agent', text: '', grounded: false })
	})

	it('marks the agent unavailable on a 503', async () => {
		vi.stubGlobal('fetch', answering(503, {}))
		const ctx = tab({})
		await ctx.send()
		expect(ctx.unavailable).toBe(true)
		expect(ctx.messages).toHaveLength(1)
		expect(ctx.sending).toBe(false)
	})

	it.each([
		[{ message: 'Not allowed' }, 'Not allowed'],
		[{}, 'The agent could not answer.'],
	])('shows a refusal', async (body, message) => {
		vi.stubGlobal('fetch', answering(403, body))
		const ctx = tab({})
		await ctx.send()
		expect(ctx.error).toBe(message)
		expect(ctx.messages).toHaveLength(1)
	})

	it('says the service is unreachable when the request or its body fails', async () => {
		vi.stubGlobal('fetch', vi.fn(async () => { throw new Error('offline') }))
		const ctx = tab({})
		await ctx.send()
		expect(ctx.error).toBe('The agent service is unreachable.')

		vi.stubGlobal('fetch', vi.fn(async () => ({ status: 200, ok: true, json: async () => { throw new Error('bad json') } })))
		const again = tab({})
		await again.send()
		expect(again.error).toBe('The agent service is unreachable.')
		expect(again.sending).toBe(false)
	})
})
