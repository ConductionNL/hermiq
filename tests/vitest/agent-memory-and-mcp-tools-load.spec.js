/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * How the memory page and the MCP tools page load: the memory page picks the
 * first agent and shows the store's or the request's error, the tools page
 * names every tool and the app that provides it, and both stop loading
 * whatever happened.
 */

import { flushPromises, shallowMount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const listTools = vi.fn()
const store = { registerObjectType: vi.fn(), fetchCollection: vi.fn(), errors: {} }

vi.mock('@conduction/nextcloud-vue', () => ({
	CnDataTable: { name: 'CnDataTable', render: () => null },
	createObjectStore: () => () => ({}),
}))
vi.mock('../../src/store/store.js', () => ({ useAgentStore: () => store }))
vi.mock('../../src/api/agents.js', () => ({ listTools: (...args) => listTools(...args) }))

const AgentMemory = (await import('../../src/views/AgentMemory.vue')).default
const McpTools = (await import('../../src/views/McpTools.vue')).default

const t = (_app, text) => text

/**
 * The memory page's state, with its computed picker, over a store double.
 *
 * @param {object} store The agent store double.
 * @return {object} The page state.
 */
function memoryPage(store) {
	return {
		...AgentMemory.data(),
		agentStore: store,
		t,
		get agentOptions() {
			return AgentMemory.computed.agentOptions.call(this)
		},
	}
}

describe('AgentMemory loadAgents', () => {
	it('loads the agents and picks the first one', async () => {
		const page = memoryPage({ fetchCollection: vi.fn().mockResolvedValue([{ name: 'Briefing', uuid: 'u-1' }, { name: 'Triage', uuid: 'u-2' }]), errors: {} })

		await AgentMemory.methods.loadAgents.call(page)

		expect(page.agentStore.fetchCollection).toHaveBeenCalledWith('agent')
		expect(page.agents).toHaveLength(2)
		expect(page.selectedAgent).toEqual({ label: 'Briefing', value: 'u-1' })
		expect(page.error).toBe('')
		expect(page.loading).toBe(false)
	})

	it('shows the store error, and Unknown error when it carries no message', async () => {
		const page = memoryPage({ fetchCollection: vi.fn().mockResolvedValue([]), errors: { agent: { message: 'Forbidden' } } })
		await AgentMemory.methods.loadAgents.call(page)
		expect(page.error).toBe('Forbidden')
		expect(page.selectedAgent).toBeNull()

		const silent = memoryPage({ fetchCollection: vi.fn().mockResolvedValue([]), errors: { agent: {} } })
		await AgentMemory.methods.loadAgents.call(silent)
		expect(silent.error).toBe('Unknown error')
	})

	it('treats a non-list answer as no agents', async () => {
		const page = memoryPage({ fetchCollection: vi.fn().mockResolvedValue({ results: [] }), errors: {} })

		await AgentMemory.methods.loadAgents.call(page)

		expect(page.agents).toEqual([])
		expect(page.selectedAgent).toBeNull()
		expect(page.loading).toBe(false)
	})

	it('shows the error of a failed request and stops loading', async () => {
		const page = memoryPage({ fetchCollection: vi.fn().mockRejectedValue({ response: { data: { error: 'Server gone' } } }) })

		await AgentMemory.methods.loadAgents.call(page)

		expect(page.error).toBe('Server gone')
		expect(page.loading).toBe(false)
	})
})

describe('McpTools', () => {
	beforeEach(() => {
		listTools.mockReset()
	})

	it('loads the tools and stops loading', async () => {
		listTools.mockResolvedValue([{ name: 'hermiq.searchMemory' }])
		const page = { ...McpTools.data(), t }

		await McpTools.methods.load.call(page)

		expect(page.tools).toEqual([{ name: 'hermiq.searchMemory' }])
		expect([page.error, page.loading]).toEqual(['', false])
	})

	it('shows why the tools could not be read', async () => {
		listTools.mockRejectedValue(new Error('Network down'))
		const page = { ...McpTools.data(), t }

		await McpTools.methods.load.call(page)

		expect(page.error).toBe('Network down')
		expect(page.loading).toBe(false)
	})

	it('names each tool and the app that provides it, with stand-ins for what is missing', () => {
		const tools = [
			{ id: 'openregister.find', name: 'Find objects', description: 'Search a register', appId: 'openregister' },
			{ name: 'docs.read', app: 'docs' },
			{ id: 'x.only-id', provider: 'x' },
			{ source: 'filinq' },
			{},
		]

		expect(McpTools.computed.rows.call({ tools, t })).toEqual([
			{ id: 'openregister.find', name: 'Find objects', description: 'Search a register', source: 'openregister' },
			{ id: 'docs.read', name: 'docs.read', description: '—', source: 'docs' },
			{ id: 'x.only-id', name: 'x.only-id', description: '—', source: 'x' },
			{ id: 'tool-3', name: 'Unnamed tool', description: '—', source: 'filinq' },
			{ id: 'tool-4', name: 'Unnamed tool', description: '—', source: '—' },
		])
		expect(McpTools.computed.columns.call({ t }).map((column) => column.key)).toEqual(['name', 'description', 'source'])
	})
})

describe('the two pages as mounted', () => {
	beforeEach(() => {
		listTools.mockReset()
		store.registerObjectType.mockReset()
		store.fetchCollection.mockReset()
		store.errors = {}
	})

	it('the memory page registers the agent type, loads on creation and shows the first agent', async () => {
		store.fetchCollection.mockResolvedValue([{ name: 'Briefing', uuid: 'u-1' }])
		const page = shallowMount(AgentMemory, { global: { mocks: { t } } })
		expect(page.vm.loading).toBe(true)

		await flushPromises()

		expect(store.registerObjectType).toHaveBeenCalledWith('agent', 'agent', 'hermiq')
		expect(page.vm.selectedAgent).toEqual({ label: 'Briefing', value: 'u-1' })
		expect(page.findComponent({ name: 'AgentMemoryPanel' }).exists()).toBe(true)
		expect(page.findComponent({ name: 'NcNoteCard' }).exists()).toBe(false)
	})

	it('the memory page shows its error and no panel when the agents cannot be read', async () => {
		store.fetchCollection.mockRejectedValue(new Error('Forbidden'))
		const page = shallowMount(AgentMemory, { global: { mocks: { t } } })

		await flushPromises()

		expect(page.vm.error).toBe('Forbidden')
		expect(page.findComponent({ name: 'NcNoteCard' }).exists()).toBe(true)
		expect(page.findComponent({ name: 'AgentMemoryPanel' }).exists()).toBe(false)
	})

	it('the tools page loads on creation and shows the table, or its error', async () => {
		listTools.mockResolvedValue([{ name: 'hermiq.searchMemory', appId: 'hermiq' }])
		const page = shallowMount(McpTools, { global: { mocks: { t } } })
		await flushPromises()
		expect(listTools).toHaveBeenCalledTimes(1)
		expect(page.findComponent({ name: 'CnDataTable' }).exists()).toBe(true)

		listTools.mockRejectedValue(new Error('Network down'))
		const failed = shallowMount(McpTools, { global: { mocks: { t } } })
		await flushPromises()
		expect(failed.vm.error).toBe('Network down')
		expect(failed.findComponent({ name: 'NcNoteCard' }).exists()).toBe(true)
	})
})
