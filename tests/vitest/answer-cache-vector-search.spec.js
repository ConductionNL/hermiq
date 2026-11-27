/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The agent form offers the similar-questions choice only when the page state
 * says OpenRegister's vector search can answer (models-prompt-and-answer-cache,
 * task 6).
 */

import { describe, expect, it, vi } from 'vitest'

const state = vi.hoisted(() => ({ values: {} }))

vi.mock('@nextcloud/initial-state', () => ({
	loadState: (app, key, fallback) => (app === 'hermiq' && key in state.values ? state.values[key] : fallback),
}))

vi.mock('@conduction/nextcloud-vue', () => ({
	CnIconPicker: { name: 'CnIconPicker', render: () => null },
	fromOpenGemeenten: () => [],
	createObjectStore: () => () => ({}),
}))

vi.mock('../../src/store/store.js', () => ({ useAgentStore: () => ({ registerObjectType: () => {} }) }))
vi.mock('../../src/api/toolOversight.js', () => ({ updateToolGrants: vi.fn() }))
vi.mock('../../src/api/agents.js', () => ({ listTools: async () => [], setAppAssistant: vi.fn() }))
vi.mock('../../src/api/chat.js', () => ({ searchGroups: vi.fn(), searchUsers: vi.fn() }))
vi.mock('../../src/api/modelPolicy.js', () => ({ getEffectiveModelPolicy: async () => null }))

const { default: AgentFormModal } = await import('../../src/modals/AgentFormModal.vue')
const available = () => AgentFormModal.computed.vectorSearchAvailable.call({})

describe('AgentFormModal similar questions', () => {
	it('is off when the page state does not say vector search is there', () => {
		state.values = {}
		expect(available()).toBe(false)
		state.values = { vector_search_available: false }
		expect(available()).toBe(false)
		state.values = { vector_search_available: 'yes' }
		expect(available()).toBe(false)
	})

	it('is on when OpenRegister vector search can answer', () => {
		state.values = { vector_search_available: true }
		expect(available()).toBe(true)
	})
})
