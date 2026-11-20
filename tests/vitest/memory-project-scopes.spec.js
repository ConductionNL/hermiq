/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The project layers of an agent's memory (memory-project-scopes): the API
 * helpers, the picker and naming helpers, the project picker dialog, the
 * memory panel's moves and the Memory page's layer tabs.
 *
 * @spec openspec/changes/memory-project-scopes/specs/agent-memory/spec.md#requirement-an-owner-sees-and-moves-entries-between-layers-req-pmem-004
 */

import axios from '@nextcloud/axios'
import { flushPromises, shallowMount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import ProjectPickerDialog from '../../src/dialogs/ProjectPickerDialog.vue'
import * as chatApi from '../../src/api/chat.js'
import * as memoryApi from '../../src/api/memory.js'
import { projectChoice, projectNameOf } from '../../src/utils/memoryProjects.js'

const agentStore = { registerObjectType: vi.fn(), fetchCollection: vi.fn(async () => []), errors: {} }
vi.mock('../../src/store/store.js', () => ({ useAgentStore: () => agentStore }))

const { default: AgentMemoryPanel } = await import('../../src/components/AgentMemoryPanel.vue')
const { default: AgentMemory } = await import('../../src/views/AgentMemory.vue')

const t = (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m))
const mount = (component, props = {}) => shallowMount(component, { props, global: { mocks: { t, n: t } } })

const PROJECTS = [
	{ projectKey: 'aanbesteding-wagenpark', projectName: 'Aanbesteding wagenpark' },
	{ projectKey: 'omgevingsvisie-2040', projectName: 'Omgevingsvisie 2040' },
]

afterEach(() => {
	vi.restoreAllMocks()
})

describe('memory API helpers', () => {
	it('reads the shared memory without and a project memory with a project parameter', async () => {
		const get = vi.spyOn(axios, 'get').mockResolvedValue({ data: { entries: [] } })
		await memoryApi.getMemory('a1')
		await memoryApi.getMemory('a1', 'omgevingsvisie-2040')
		expect(get.mock.calls).toEqual([
			['/index.php/apps/hermiq/api/agents/a1/memory'],
			['/index.php/apps/hermiq/api/agents/a1/memory', { params: { project: 'omgevingsvisie-2040' } }],
		])
	})

	it('lists the projects, and none for an odd answer', async () => {
		vi.spyOn(axios, 'get').mockResolvedValueOnce({ data: { projects: PROJECTS } }).mockResolvedValueOnce({ data: {} })
		expect(await memoryApi.listMemoryProjects('a1')).toEqual(PROJECTS)
		expect(await memoryApi.listMemoryProjects('a1')).toEqual([])
	})

	it('moves an entry between layers', async () => {
		const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: { scope: 'shared' } })
		expect(await memoryApi.moveMemoryEntry('a1', 'e1', 'omgevingsvisie-2040', '')).toEqual({ scope: 'shared' })
		await memoryApi.moveMemoryEntry('a1', 'e2', null, 'omgevingsvisie-2040')
		expect(post.mock.calls).toEqual([
			['/index.php/apps/hermiq/api/agents/a1/memory/entries/e1/move', { from: 'omgevingsvisie-2040', to: '' }],
			['/index.php/apps/hermiq/api/agents/a1/memory/entries/e2/move', { from: '', to: 'omgevingsvisie-2040' }],
		])
	})

	it('corrects and forgets in a project only when one is named', async () => {
		const put = vi.spyOn(axios, 'put').mockResolvedValue({ data: {} })
		const del = vi.spyOn(axios, 'delete').mockResolvedValue({ data: {} })
		await memoryApi.correctMemoryEntry('a1', 'e1', 'x')
		await memoryApi.correctMemoryEntry('a1', 'e1', 'x', 'p')
		await memoryApi.forgetMemoryEntry('a1', 'e1')
		await memoryApi.forgetMemoryEntry('a1', 'e1', 'p')
		expect(put.mock.calls[0]).toEqual(['/index.php/apps/hermiq/api/agents/a1/memory/entries/e1', { text: 'x' }])
		expect(put.mock.calls[1]).toEqual(['/index.php/apps/hermiq/api/agents/a1/memory/entries/e1', { text: 'x' }, { params: { project: 'p' } }])
		expect(del.mock.calls).toEqual([
			['/index.php/apps/hermiq/api/agents/a1/memory/entries/e1'],
			['/index.php/apps/hermiq/api/agents/a1/memory/entries/e1', { params: { project: 'p' } }],
		])
	})

	it('creates a session in a project by key or by a new name', async () => {
		const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: { uuid: 's' } })
		await chatApi.createSession('a1')
		await chatApi.createSession('a1', 'Titel', { projectKey: 'p-1' })
		await chatApi.createSession('a1', undefined, { projectName: 'Nieuw' })
		expect(post.mock.calls.map((call) => call[1])).toEqual([
			{ agentUuid: 'a1' },
			{ agentUuid: 'a1', title: 'Titel', projectKey: 'p-1' },
			{ agentUuid: 'a1', projectName: 'Nieuw' },
		])
	})
})

describe('project helpers', () => {
	const markers = { NEW: '__new__', NONE: '__none__' }

	it('turns a picker choice into the project to start in', () => {
		expect(projectChoice('__none__', 'x', markers)).toBeNull()
		expect(projectChoice('', '', markers)).toBeNull()
		expect(projectChoice('__new__', '  Begroting 2027 ', markers)).toEqual({ projectName: 'Begroting 2027' })
		expect(projectChoice('__new__', '  ', markers)).toBeNull()
		expect(projectChoice('omgevingsvisie-2040', '', markers)).toEqual({ projectKey: 'omgevingsvisie-2040' })
	})

	it('names a project from the list, else by its key', () => {
		expect(projectNameOf('omgevingsvisie-2040', PROJECTS)).toBe('Omgevingsvisie 2040')
		expect(projectNameOf('onbekend', PROJECTS)).toBe('onbekend')
		expect(projectNameOf('', PROJECTS)).toBe('')
		expect(projectNameOf('p', null)).toBe('p')
	})
})

describe('ProjectPickerDialog', () => {
	it('starts with no project by default, and needs a name for a new one', () => {
		const w = mount(ProjectPickerDialog, { projects: PROJECTS })
		w.vm.start()
		expect(w.emitted('start')).toEqual([[null]])

		w.vm.choice = w.vm.NEW
		expect(w.vm.canStart).toBe(false)
		w.vm.start()
		expect(w.emitted('start')).toHaveLength(1)

		w.vm.newName = 'Begroting 2027'
		w.vm.start()
		expect(w.emitted('start')[1]).toEqual([{ projectName: 'Begroting 2027' }])

		w.vm.choice = 'omgevingsvisie-2040'
		w.vm.start()
		expect(w.emitted('start')[2]).toEqual([{ projectKey: 'omgevingsvisie-2040' }])
	})
})

describe('AgentMemoryPanel moves', () => {
	it('reads, corrects and forgets in the project it shows', async () => {
		const getMemory = vi.spyOn(memoryApi, 'getMemory')
		vi.spyOn(axios, 'get').mockResolvedValue({ data: { entries: [] } })
		const w = mount(AgentMemoryPanel, { agentId: 'a1', project: 'omgevingsvisie-2040' })
		await flushPromises()
		expect(w.vm.layerArgs()).toEqual(['omgevingsvisie-2040'])

		await w.setProps({ project: '' })
		await flushPromises()
		expect(w.vm.layerArgs()).toEqual([])
		getMemory.mockRestore()
	})

	it('moves an entry to shared, reloads its own layer and tells the page', async () => {
		const get = vi.spyOn(axios, 'get').mockResolvedValue({ data: { entries: [{ id: 'e2', text: 'blijft' }] } })
		const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: {} })
		const w = mount(AgentMemoryPanel, { agentId: 'a1', project: 'omgevingsvisie-2040', projects: PROJECTS })
		await flushPromises()
		get.mockClear()

		await w.vm.moveEntry({ id: 'e1', text: 'Beleidsstukken gebruiken B1-taalniveau.' }, '')

		expect(post).toHaveBeenCalledWith('/index.php/apps/hermiq/api/agents/a1/memory/entries/e1/move', { from: 'omgevingsvisie-2040', to: '' })
		expect(get).toHaveBeenCalledWith('/index.php/apps/hermiq/api/agents/a1/memory', { params: { project: 'omgevingsvisie-2040' } })
		expect(w.emitted('moved')).toEqual([[{ entryId: 'e1', to: '' }]])
		expect(w.vm.moveTarget).toBeNull()
	})

	it('moves a shared entry to a project, and never to its own layer', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({ data: { entries: [] } })
		const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: {} })
		const w = mount(AgentMemoryPanel, { agentId: 'a1', projects: PROJECTS })
		await flushPromises()

		await w.vm.moveEntry({ id: 'e1' }, '')
		expect(post).not.toHaveBeenCalled()

		w.vm.moveTarget = { id: 'e1' }
		await w.vm.moveEntry({ id: 'e1' }, 'aanbesteding-wagenpark')
		expect(post).toHaveBeenCalledWith('/index.php/apps/hermiq/api/agents/a1/memory/entries/e1/move', { from: '', to: 'aanbesteding-wagenpark' })
	})

	it('shows the error of a failed move', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({ data: { entries: [] } })
		vi.spyOn(axios, 'post').mockRejectedValue(new Error('Move refused'))
		const w = mount(AgentMemoryPanel, { agentId: 'a1', project: 'p' })
		await flushPromises()

		await w.vm.moveEntry({ id: 'e1' }, '')

		expect(w.vm.error).toBe('Move refused')
		expect(w.emitted('moved')).toBeUndefined()
	})
})

describe('AgentMemory layer tabs', () => {
	it('lists "Shared" and then one tab per project of the chosen agent', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({ data: { projects: PROJECTS } })
		const w = mount(AgentMemory)
		await flushPromises()
		w.vm.selectedAgent = { label: 'Beleidsondersteuner', value: 'a1' }
		await flushPromises()

		expect(w.vm.projects).toEqual(PROJECTS)
		expect(w.vm.layerTabs).toEqual([
			{ key: '', label: 'Shared' },
			{ key: 'aanbesteding-wagenpark', label: 'Aanbesteding wagenpark' },
			{ key: 'omgevingsvisie-2040', label: 'Omgevingsvisie 2040' },
		])
	})

	it('goes back to Shared when another agent is chosen, and keeps no projects on a failure', async () => {
		const get = vi.spyOn(axios, 'get').mockResolvedValue({ data: { projects: PROJECTS } })
		const w = mount(AgentMemory)
		await flushPromises()
		w.vm.selectedAgent = { value: 'a1' }
		await flushPromises()
		w.vm.layer = 'omgevingsvisie-2040'

		get.mockRejectedValueOnce(new Error('down'))
		w.vm.selectedAgent = { value: 'a2' }
		await flushPromises()

		expect(w.vm.layer).toBe('')
		expect(w.vm.projects).toEqual([])
		expect(w.vm.layerTabs).toEqual([{ key: '', label: 'Shared' }])
	})
})
