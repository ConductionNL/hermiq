/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The chat health block on the Integrations page (spec-coverage-built-rows,
 * REQ-SPCOV-012): mounted over doubled HTTP answers, with one dependency that
 * does not answer, and the pure view builder it renders.
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const answers = {}
vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn(async (url) => {
			const answer = answers[url]
			if (answer instanceof Error) {
				throw answer
			}
			return { data: answer }
		}),
	},
}))
const llm = { value: null }
vi.mock('../../src/api/llm.js', () => ({
	getLlmSettings: vi.fn(async () => {
		if (llm.value instanceof Error) {
			throw llm.value
		}
		return llm.value
	}),
}))

const axios = (await import('@nextcloud/axios')).default
const { default: ChatHealthBlock } = await import('../../src/widgets/ChatHealthBlock.vue')
const { buildChatHealth, connectionState, defaultModel } = await import('../../src/services/chatHealth.js')

const t = (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m))

const HEALTH = '/index.php/apps/hermiq/api/chat/health'
const CONNECTIONS = '/index.php/apps/openregister/api/objects/integriq/app_connection'

/**
 * Mount the block and let its three reads settle.
 *
 * @return {Promise<object>} The wrapper.
 */
async function mountIt() {
	const w = mount(ChatHealthBlock, { global: { mocks: { t } } })
	await flushPromises()
	return w
}

describe('ChatHealthBlock', () => {
	beforeEach(() => {
		axios.get.mockClear()
		answers[HEALTH] = { status: 'ok', configured: true, capabilities: ['chat', 'stream'] }
		llm.value = {
			chatProvider: 'ollama',
			ollamaConfig: { url: 'http://ollama.internal:11434', chatModel: 'llama3.1' },
			openaiConfig: { chatModel: 'gpt-4o', credentialId: 'cred-openai-7', apiKeySet: true },
		}
		answers[CONNECTIONS] = {
			results: [
				{ key: 'speech', title: 'Speech services', order: 30, status: 'unavailable', checkedAt: '2026-11-14T09:05:00Z', settingsUrl: '/settings/admin/hermiq', secret: 'sk-live-should-not-show' },
				{ key: 'llm', title: 'AI provider', order: 10, status: 'configured', checkedAt: '2026-11-14T09:00:00Z' },
				{ key: 'web-search', title: 'Web search', order: 40, status: 'unconfigured' },
			],
		}
	})

	it('shows that chat works with the provider and default model above the connections', async () => {
		const w = await mountIt()

		expect(w.find('.chat-health__headline').text()).toBe('Chat works.')
		expect(w.find('.chat-health__provider').text()).toContain('AI provider: Ollama')
		expect(w.find('.chat-health__provider').text()).toContain('Default model: llama3.1')
		expect(w.findAll('.chat-health__title').map((n) => n.text())).toEqual(['AI provider', 'Speech services', 'Web search'])
		expect(axios.get).toHaveBeenCalledWith(HEALTH)
		expect(axios.get).toHaveBeenCalledWith(CONNECTIONS, { params: { app: 'hermiq', _limit: 100 } })
	})

	it('shows the one failing dependency as not answering, with its check time', async () => {
		const w = await mountIt()

		const rows = w.findAll('.chat-health__row')
		const speech = rows.find((r) => r.find('.chat-health__title').text() === 'Speech services')
		expect(speech.attributes('data-state')).toBe('no')
		expect(speech.find('.chat-health__state').text()).toBe('Does not answer')
		expect(speech.find('.chat-health__checked').text()).toBe('Checked ' + new Date('2026-11-14T09:05:00Z').toLocaleString())
		expect(rows[0].find('.chat-health__state').text()).toBe('Answers')
		expect(rows[2].find('.chat-health__state').text()).toBe('Not set up')
		expect(rows[2].find('.chat-health__checked').text()).toBe('Not checked yet')
	})

	it('shows no key, credential or endpoint from any source', async () => {
		const html = (await mountIt()).html()

		expect(html).not.toContain('sk-live-should-not-show')
		expect(html).not.toContain('cred-openai-7')
		expect(html).not.toContain('ollama.internal')
		expect(html).not.toContain('/settings/admin/hermiq')
	})

	it('says chat does not work when no provider is chosen', async () => {
		answers[HEALTH] = { status: 'unconfigured', configured: false, capabilities: [] }
		llm.value = { chatProvider: '' }

		const w = await mountIt()

		expect(w.find('.chat-health__headline').text()).toBe('Chat does not work: no AI provider is chosen.')
		expect(w.find('.chat-health__provider').exists()).toBe(false)
	})

	it('says chat does not work when the health check fails, and still lists the connections', async () => {
		answers[HEALTH] = new Error('503')
		llm.value = new Error('403')

		const w = await mountIt()

		expect(w.find('.chat-health__headline').text()).toBe('Chat does not work: its settings could not be read.')
		expect(w.findAll('.chat-health__row')).toHaveLength(3)
	})
})

describe('buildChatHealth', () => {
	it('keeps only the fields it shows, sorted by order', () => {
		const view = buildChatHealth({
			health: { status: 'ok' },
			llm: { chatProvider: 'anthropic', anthropicConfig: { chatModel: 'claude-x', apiKey: 'k' } },
			connections: [
				{ key: 'b', title: 'B', order: 20, status: 'error', checkedAt: '2026-01-02T00:00:00Z', statusMessage: 'token abc expired' },
				{ key: 'a', title: 'A', order: 10, status: 'limited' },
				null,
			],
		})

		expect(view).toEqual({
			chatWorks: true,
			chatStatus: 'ok',
			provider: 'Anthropic',
			model: 'claude-x',
			rows: [
				{ id: 'a', title: 'A', state: 'yes', checkedAt: '' },
				{ id: 'b', title: 'B', state: 'no', checkedAt: '2026-01-02T00:00:00Z' },
			],
		})
	})

	it('treats a missing or unknown health answer as an error', () => {
		expect(buildChatHealth({ health: null, llm: null, connections: null }).chatStatus).toBe('error')
		expect(buildChatHealth({ health: { status: 'config_error' }, llm: null, connections: [] }).chatWorks).toBe(false)
	})

	it('maps each stored connection status to answering, not answering or off', () => {
		expect(['configured', 'limited', 'simulated'].map(connectionState)).toEqual(['yes', 'yes', 'yes'])
		expect(['error', 'unavailable'].map(connectionState)).toEqual(['no', 'no'])
		expect(['unconfigured', 'disabled', undefined, 'later'].map(connectionState)).toEqual(['off', 'off', 'off', 'off'])
	})

	it('reads the default model of the chosen provider only', () => {
		expect(defaultModel({ openaiConfig: { chatModel: 'gpt' } }, 'openai')).toBe('gpt')
		expect(defaultModel({ openaiConfig: { chatModel: 'gpt' } }, 'nextcloud')).toBe('')
		expect(defaultModel({ openaiConfig: { chatModel: 42 } }, 'openai')).toBe('')
		expect(defaultModel(null, '')).toBe('')
	})
})
