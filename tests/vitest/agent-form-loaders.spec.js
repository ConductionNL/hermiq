/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * AgentFormModal's loaders and helpers: the credentials read, the agent named
 * by the route, the tool picker's labels, the model policy, the app choices
 * and a placeholder put at the cursor. Every loader is non-fatal: a failing
 * read leaves an empty picker, never a broken form.
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const axiosGet = vi.fn()
const listTools = vi.fn(async () => [])
const getEffectiveModelPolicy = vi.fn(async () => null)
const fetchObject = vi.fn(async () => null)
const registerObjectType = vi.fn()

vi.mock('@nextcloud/axios', () => ({ default: { get: (...args) => axiosGet(...args) } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => '/index.php' + path }))

vi.mock('@conduction/nextcloud-vue', () => ({
	CnIconPicker: { name: 'CnIconPicker', render: () => null },
	fromOpenGemeenten: (icons) => ({ count: Object.keys(icons || {}).length }),
	createObjectStore: () => () => ({}),
}))

vi.mock('../../src/store/store.js', () => ({
	useAgentStore: () => ({
		registerObjectType,
		saveObject: vi.fn(),
		fetchObject: (...args) => fetchObject(...args),
		errors: {},
	}),
}))

vi.mock('../../src/api/toolOversight.js', () => ({ updateToolGrants: vi.fn() }))
vi.mock('../../src/api/agents.js', () => ({ listTools: (...args) => listTools(...args), setAppAssistant: async () => ({}) }))
vi.mock('../../src/api/chat.js', () => ({ searchGroups: async () => [], searchUsers: async () => [] }))
vi.mock('../../src/api/modelPolicy.js', () => ({ getEffectiveModelPolicy: (...args) => getEffectiveModelPolicy(...args) }))

vi.stubGlobal('t', (app, text) => text)

const { default: AgentFormModal } = await import('../../src/modals/AgentFormModal.vue')

/**
 * Mount the modal without its template.
 *
 * @param {object} props The props.
 * @param {object} route The route the modal reads.
 * @return {Promise<object>} The component instance.
 */
async function modal(props = {}, route = { params: {} }) {
	const wrapper = mount({ ...AgentFormModal, render: () => null }, {
		props: { show: false, ...props },
		global: { mocks: { t: (app, text) => text, $route: route } },
	})
	await flushPromises()
	return wrapper.vm
}

beforeEach(() => {
	axiosGet.mockReset()
	listTools.mockReset()
	getEffectiveModelPolicy.mockReset()
	fetchObject.mockReset()
	registerObjectType.mockReset()
})

describe('AgentFormModal credentials', () => {
	it('reads the caller\'s credentials from OpenRegister, and an unreadable list is empty', async () => {
		const vm = await modal()
		axiosGet.mockResolvedValueOnce({ data: { results: [{ id: 'cred-1', provider: 'openai' }] } })

		await vm.loadCredentials()

		expect(axiosGet).toHaveBeenCalledWith('/index.php/apps/openregister/api/credentials')
		expect(vm.credentials).toEqual([{ id: 'cred-1', provider: 'openai' }])

		axiosGet.mockResolvedValueOnce({ data: {} })
		await vm.loadCredentials()
		expect(vm.credentials).toEqual([])

		vm.credentials = [{ id: 'stale' }]
		axiosGet.mockRejectedValueOnce(new Error('403'))
		await vm.loadCredentials()
		expect(vm.credentials).toEqual([])
	})
})

describe('AgentFormModal agent from the route', () => {
	it('reads nothing for an agent handed in or a route without an id', async () => {
		const handed = await modal({ agent: { id: 'a1', name: 'Digest' } }, { params: { id: 'a9' } })
		await handed.loadRouteAgent()
		expect(handed.routeAgent).toBeNull()

		const bare = await modal()
		await bare.loadRouteAgent()
		expect(bare.routeAgent).toBeNull()
		expect(fetchObject).not.toHaveBeenCalled()
	})

	it('fetches the agent the route names, and a failed fetch leaves none', async () => {
		const vm = await modal({}, { params: { id: 'a9' } })
		fetchObject.mockResolvedValueOnce({ id: 'a9', name: 'Routed' })

		await vm.loadRouteAgent()

		expect(registerObjectType).toHaveBeenCalledWith('agent', 'agent', 'hermiq')
		expect(fetchObject).toHaveBeenCalledWith('agent', 'a9')
		expect(vm.routeAgent).toEqual({ id: 'a9', name: 'Routed' })

		fetchObject.mockRejectedValueOnce(new Error('gone'))
		await vm.loadRouteAgent()
		expect(vm.routeAgent).toBeNull()
	})
})

describe('AgentFormModal tool picker', () => {
	it('labels a tool by app, tool and right, else by its name, and keeps its id as the value', async () => {
		const vm = await modal()
		listTools.mockResolvedValueOnce([
			{ id: 'cms_create_page', app: 'OpenCatalogi', tool: 'Page', right: 'create', description: 'Create a page' },
			{ name: 'search', app: 'Hermiq' },
			{ key: 'k1' },
			'plain_tool',
		])

		const loading = vm.loadTools()
		expect(vm.toolsLoading).toBe(true)
		await loading

		expect(vm.toolOptions).toEqual([
			{ label: 'OpenCatalogi | Page | create', value: 'cms_create_page', description: 'Create a page' },
			{ label: 'search', value: 'search', description: '' },
			{ label: 'k1', value: 'k1', description: '' },
			{ label: 'plain_tool', value: 'plain_tool', description: '' },
		])
		expect(vm.toolsLoading).toBe(false)
	})

	it('leaves the picker empty when the catalogue cannot be read', async () => {
		const vm = await modal()
		vm.toolOptions = [{ value: 'stale' }]
		listTools.mockRejectedValueOnce(new Error('500'))

		await vm.loadTools()

		expect(vm.toolOptions).toEqual([])
		expect(vm.toolsLoading).toBe(false)
	})
})

describe('AgentFormModal model policy and app choices', () => {
	it('keeps the effective policy, and none when it cannot be read', async () => {
		const vm = await modal()
		getEffectiveModelPolicy.mockResolvedValueOnce({ defaultModel: 'gpt-4o', allowed: [] })
		await vm.loadPolicy()
		expect(vm.policy).toEqual({ defaultModel: 'gpt-4o', allowed: [] })

		getEffectiveModelPolicy.mockRejectedValueOnce(new Error('offline'))
		await vm.loadPolicy()
		expect(vm.policy).toBeNull()
	})

	it('offers the installed apps, never core, plus the app the agent already serves', async () => {
		vi.stubGlobal('OC', { appswebroots: { core: '/core', pipelinq: '/apps/pipelinq', decidiq: '/apps/decidiq' } })
		const vm = await modal()
		vm.form.applicationSlug = ' Larpinq '

		expect(vm.appChoices).toEqual([
			{ label: 'decidiq', value: 'decidiq' },
			{ label: 'larpinq', value: 'larpinq' },
			{ label: 'pipelinq', value: 'pipelinq' },
		])
		expect(vm.iconCatalogues.opengemeenten.count).toBeGreaterThan(0)
		vi.unstubAllGlobals()
		vi.stubGlobal('t', (app, text) => text)
	})
})

describe('AgentFormModal placeholders', () => {
	it('puts a placeholder at the cursor and puts the cursor after it', () => {
		const area = { selectionStart: 6, selectionEnd: 6, focus: vi.fn(), setSelectionRange: vi.fn() }
		// The method reads the prompt field's text area through its ref, which a
		// template-less mount does not have: hand it the component's own shape.
		const self = {
			$refs: { promptInput: { $el: { querySelector: (selector) => (selector === 'textarea' ? area : null) } } },
			form: { prompt: 'Hello world' },
			$nextTick: (callback) => callback(),
		}

		AgentFormModal.methods.insertPlaceholderAtCursor.call(self, '{user}')

		expect(self.form.prompt).toBe('Hello {user}world')
		expect(area.focus).toHaveBeenCalled()
		expect(area.setSelectionRange).toHaveBeenCalledWith(12, 12)
	})

	it('appends the placeholder when there is no text area to read a cursor from', async () => {
		const vm = await modal()
		vm.form.prompt = 'Hi '

		vm.insertPlaceholderAtCursor('{date}')
		await vm.$nextTick()

		expect(vm.form.prompt).toBe('Hi {date}')
	})
})
