/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Clearing an agent's cached answers (models-prompt-and-answer-cache): the API
 * helper and the dialog the agent page's "Clear cached answers" action opens.
 *
 * @spec openspec/changes/models-prompt-and-answer-cache/specs/answer-cache/spec.md#requirement-cached-answers-can-be-cleared-and-expire-req-acache-004
 */

import axios from '@nextcloud/axios'
import { flushPromises, shallowMount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const api = await import('../../src/api/agentAnswerCache.js')
const { default: AgentAnswerCacheClearDialog } = await import('../../src/dialogs/AgentAnswerCacheClearDialog.vue')

const t = (app, text) => text
const n = (app, one, many, count) => (count === 1 ? one : many).replace('%n', String(count))

/**
 * Mount the dialog open, on the agent routed as `a/1`.
 *
 * @return {object} The wrapper.
 */
function mountDialog() {
	return shallowMount(AgentAnswerCacheClearDialog, {
		props: { show: true },
		global: {
			mocks: { t, n, $route: { params: { id: 'a/1' } } },
			stubs: { NcDialog: { template: '<div><slot /><slot name="actions" /></div>' } },
		},
	})
}

beforeEach(() => {
	vi.restoreAllMocks()
})

describe('clearAnswerCache', () => {
	it('deletes the agent\'s answer cache and returns how many entries went', async () => {
		const spy = vi.spyOn(axios, 'delete').mockResolvedValue({ data: { removed: 3 } })
		expect(await api.clearAnswerCache('a/1')).toBe(3)
		expect(spy).toHaveBeenCalledWith('/index.php/apps/hermiq/api/agents/a%2F1/answer-cache')
	})

	it('reads a response without a count as none removed', async () => {
		vi.spyOn(axios, 'delete').mockResolvedValue({ data: {} })
		expect(await api.clearAnswerCache('a1')).toBe(0)
	})
})

describe('AgentAnswerCacheClearDialog', () => {
	it('clears the routed agent\'s cache and says how many answers were removed', async () => {
		const spy = vi.spyOn(axios, 'delete').mockResolvedValue({ data: { removed: 2 } })
		const w = mountDialog()

		await w.vm.clear()
		await flushPromises()

		expect(spy).toHaveBeenCalledWith('/index.php/apps/hermiq/api/agents/a%2F1/answer-cache')
		expect([w.vm.done, w.vm.removed, w.vm.busy, w.vm.error]).toEqual([true, 2, false, ''])
		expect(w.find('[data-testid="answer-cache-cleared"]').text()).toBe('2 cached answers removed.')
	})

	it('shows the server\'s refusal and stays open to try again', async () => {
		vi.spyOn(axios, 'delete').mockRejectedValue({ response: { data: { error: 'You do not have permission to clear this agent\'s cached answers' } } })
		const w = mountDialog()

		await w.vm.clear()

		expect([w.vm.done, w.vm.busy]).toEqual([false, false])
		expect(w.vm.error).toBe('You do not have permission to clear this agent\'s cached answers')
	})

	it('falls back to its own sentence when the failure says nothing', async () => {
		vi.spyOn(axios, 'delete').mockRejectedValue(new Error('network'))
		const w = mountDialog()

		await w.vm.clear()

		expect(w.vm.error).toBe('The cached answers could not be cleared.')
	})
})
