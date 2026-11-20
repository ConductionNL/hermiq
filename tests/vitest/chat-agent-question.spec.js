/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The chat page and the question an agent asks with hermiq.askPerson
 * (oversight-ask-and-hold): the page keeps the question from a turn's result
 * under the answer it came with, and sends the chosen answer as the next
 * message.
 *
 * @spec openspec/changes/oversight-ask-and-hold/specs/human-approval-gate/spec.md#requirement-an-agent-can-ask-a-person-a-question-with-suggested-answers-req-askhold-001
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

describe('Chat page and the agent question', () => {

	/**
	 * A chat page context with its methods bound.
	 *
	 * @param {object} state Extra state.
	 * @return {object} The context.
	 */
	function page(state = {}) {
		const ctx = { ...Chat.methods, pendingQuestion: 'old', pendingQuestionFor: 'old', sending: false, currentMessage: '', ...state }
		for (const key of Object.keys(Chat.methods)) {
			ctx[key] = Chat.methods[key].bind(ctx)
		}
		return ctx
	}

	it('keeps the question of a turn under the answer it came with', () => {
		const ctx = page()

		ctx.takeQuestion({ messageId: 'm-9', question: { text: 'Which?', options: ['A', 'B'], allowOther: 1 } })

		expect(ctx.pendingQuestion).toEqual({ text: 'Which?', options: ['A', 'B'], allowOther: false })
		expect(ctx.pendingQuestionFor).toBe('m-9')
	})

	it('clears the previous question when a turn asks none', () => {
		const ctx = page()

		ctx.takeQuestion({ messageId: 'm-10', question: null })
		expect(ctx.pendingQuestion).toBeNull()
		expect(ctx.pendingQuestionFor).toBe('')

		ctx.pendingQuestion = { text: 'x' }
		ctx.takeQuestion({ question: { text: '   ' } })
		expect(ctx.pendingQuestion).toBeNull()
	})

	it('sends the chosen answer as the next message', async () => {
		const sent = []
		const ctx = page()
		ctx.handleSend = async () => sent.push(ctx.currentMessage)

		await ctx.answerQuestion('  Houten ')

		expect(sent).toEqual(['Houten'])
	})

	it('sends nothing for an empty answer or while a turn is being sent', async () => {
		const sent = []
		const ctx = page({ sending: true })
		ctx.handleSend = async () => sent.push(ctx.currentMessage)

		await ctx.answerQuestion('Houten')
		ctx.sending = false
		await ctx.answerQuestion('  ')

		expect(sent).toEqual([])
	})
})
