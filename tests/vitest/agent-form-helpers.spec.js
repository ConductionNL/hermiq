/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * AgentFormModal's helpers that the payload, grants and model picker specs
 * leave out: pinning a credential per provider, the people and group
 * searches, the instruction findings, the agent catalogue load, and the
 * save's refusals (model policy, a failed store write, a refused grant
 * update, a refused assistant flag).
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const api = vi.hoisted(() => ({
	updateToolGrants: vi.fn(),
	setAppAssistant: vi.fn(),
	saveObject: vi.fn(),
	fetchObject: vi.fn(),
	fetchCollection: vi.fn(),
	searchUsers: vi.fn(),
	searchGroups: vi.fn(),
	store: { errors: {} },
}))

vi.mock('@conduction/nextcloud-vue', () => ({
	CnIconPicker: { name: 'CnIconPicker', render: () => null },
	fromOpenGemeenten: () => [],
	createObjectStore: () => () => ({}),
}))

vi.mock('../../src/store/store.js', () => ({
	useAgentStore: () => Object.assign(api.store, {
		registerObjectType: () => {},
		saveObject: api.saveObject,
		fetchObject: api.fetchObject,
		fetchCollection: api.fetchCollection,
	}),
}))

vi.mock('../../src/api/toolOversight.js', () => ({ updateToolGrants: api.updateToolGrants }))
vi.mock('../../src/api/agents.js', () => ({ listTools: async () => [], setAppAssistant: api.setAppAssistant }))
vi.mock('../../src/api/chat.js', () => ({ searchGroups: api.searchGroups, searchUsers: api.searchUsers }))
vi.mock('../../src/api/modelPolicy.js', () => ({ getEffectiveModelPolicy: async () => null }))

const { default: AgentFormModal } = await import('../../src/modals/AgentFormModal.vue')

/**
 * Mount the modal (template not rendered) and let it settle.
 *
 * @param {object|null} agent The agent to edit, or null to create one.
 * @return {Promise<object>} The wrapper.
 */
async function modal(agent = null) {
	api.fetchObject.mockResolvedValue(agent)
	const wrapper = mount(
		{ ...AgentFormModal, render: () => null },
		{
			props: { show: true, agent },
			global: { mocks: { t: (app, text, vars) => (vars ? `${text}|${JSON.stringify(vars)}` : text), $route: { params: {} } } },
		},
	)
	await flushPromises()
	wrapper.vm.resetForm()
	return wrapper
}

beforeEach(() => {
	vi.resetAllMocks()
	api.store.errors = {}
	api.fetchCollection.mockResolvedValue([])
	api.saveObject.mockImplementation(async (type, payload) => payload)
	api.updateToolGrants.mockResolvedValue({})
	api.setAppAssistant.mockResolvedValue({})
})

describe('AgentFormModal credential pins', () => {
	it('offers the provider credentials usable by hermiq and shows the pinned one', async () => {
		const wrapper = await modal()
		wrapper.vm.credentials = [
			{ id: 'c-1', provider: 'openai', name: 'Team key' },
			{ id: 'c-2', provider: 'openai', allowedApps: ['other'] },
			{ id: 'c-3', provider: 'anthropic' },
		]

		expect(wrapper.vm.pinOptions('openai')).toEqual([{ label: 'Team key', value: 'c-1' }])
		expect(wrapper.vm.pinOption('openai')).toBeNull()

		wrapper.vm.pin('openai', { label: 'Team key', value: 'c-1' })
		expect(wrapper.vm.form.credentialIds).toEqual({ openai: 'c-1' })
		expect(wrapper.vm.pinOption('openai')).toEqual({ label: 'Team key', value: 'c-1' })
	})

	it('shows a pinned id it cannot list by the id itself, and clears a pin', async () => {
		const wrapper = await modal()
		wrapper.vm.pin('anthropic', { value: 'gone-key' })

		expect(wrapper.vm.pinOption('anthropic')).toEqual({ label: 'gone-key', value: 'gone-key' })

		wrapper.vm.pin('anthropic', null)
		expect(wrapper.vm.form.credentialIds).toEqual({})
	})
})

describe('AgentFormModal sharing searches', () => {
	it('maps found people and groups to options, ignores empty text and empties on failure', async () => {
		const wrapper = await modal()
		api.searchUsers.mockResolvedValue([{ uid: 'alice', displayName: 'Alice' }])
		api.searchGroups.mockResolvedValue([{ gid: 'staff', displayName: 'Staff' }])

		await wrapper.vm.onSearchPeople('')
		await wrapper.vm.onSearchGroups('')
		expect(api.searchUsers).not.toHaveBeenCalled()
		expect(api.searchGroups).not.toHaveBeenCalled()

		await wrapper.vm.onSearchPeople('ali')
		await wrapper.vm.onSearchGroups('sta')
		expect(api.searchUsers).toHaveBeenCalledWith('ali')
		expect(wrapper.vm.peopleOptions).toEqual([{ label: 'Alice', value: 'alice' }])
		expect(wrapper.vm.groupOptions).toEqual([{ label: 'Staff', value: 'staff' }])

		api.searchUsers.mockRejectedValue(new Error('down'))
		api.searchGroups.mockRejectedValue(new Error('down'))
		await wrapper.vm.onSearchPeople('ali')
		await wrapper.vm.onSearchGroups('sta')
		expect(wrapper.vm.peopleOptions).toEqual([])
		expect(wrapper.vm.groupOptions).toEqual([])
	})
})

describe('AgentFormModal instruction findings', () => {
	it('lists the findings per field and adds the suggestion when there is one', async () => {
		const wrapper = await modal()

		expect(wrapper.vm.draftFindingsFor('prompt')).toEqual([])
		expect(wrapper.vm.findingText({ message: 'Unknown variable.' })).toBe('Unknown variable.')
		expect(wrapper.vm.findingText({ message: 'Unknown variable.', suggestion: '{user}' }))
			.toBe('{message} Try {suggestion}.|{"message":"Unknown variable.","suggestion":"{user}"}')
	})
})

describe('AgentFormModal agent catalogue', () => {
	it('names delegation targets from the catalogue, and keeps the id when the catalogue fails', async () => {
		const wrapper = await modal()
		api.fetchCollection.mockResolvedValue([{ uuid: 'a-1', name: 'Router' }])
		wrapper.vm.form.delegationAllowlist = [{ value: 'a-1' }, { value: 'a-2' }]

		await wrapper.vm.loadAgentCatalog()
		expect(wrapper.vm.form.delegationAllowlist).toEqual([
			{ label: 'Router', value: 'a-1' },
			{ label: 'a-2', value: 'a-2' },
		])

		api.fetchCollection.mockRejectedValue(new Error('down'))
		await wrapper.vm.loadAgentCatalog()
		expect(wrapper.vm.agentCatalog).toEqual([])
		expect(wrapper.vm.agentCatalogLoading).toBe(false)
		expect(wrapper.vm.form.delegationAllowlist).toEqual([
			{ label: 'a-1', value: 'a-1' },
			{ label: 'a-2', value: 'a-2' },
		])
	})
})

describe('AgentFormModal save refusals', () => {
	it('refuses a provider or model outside the organisation policy without writing', async () => {
		const wrapper = await modal()
		wrapper.vm.policy = { allowed: [{ provider: 'openai', models: ['gpt-4o'] }] }
		const refusal = "The chosen provider/model is not allowed by your organisation's model policy."

		Object.assign(wrapper.vm.form, { name: 'Digest', provider: 'anthropic', model: 'claude' })
		await wrapper.vm.save()
		expect(wrapper.vm.error).toBe(refusal)

		Object.assign(wrapper.vm.form, { provider: 'openai', model: 'gpt-3' })
		await wrapper.vm.save()
		expect(wrapper.vm.error).toBe(refusal)
		expect(api.saveObject).not.toHaveBeenCalled()
	})

	it('reports the store error, or a generic one, when the write returns nothing', async () => {
		const wrapper = await modal()
		wrapper.vm.form.name = 'Digest'
		api.saveObject.mockResolvedValue(null)

		api.store.errors = { agent: { message: 'Name taken' } }
		await wrapper.vm.save()
		expect(wrapper.vm.error).toBe('Name taken')

		api.store.errors = {}
		await wrapper.vm.save()
		expect(wrapper.vm.error).toBe('Could not save agent')
		expect(wrapper.emitted('saved')).toBeUndefined()
		expect(wrapper.vm.saving).toBe(false)
	})

	it('reports a thrown write with its message', async () => {
		const wrapper = await modal()
		wrapper.vm.form.name = 'Digest'
		api.saveObject.mockRejectedValue(new Error('Network down'))

		await wrapper.vm.save()

		expect(wrapper.vm.error).toBe('Network down')
	})

	it('says the agent saved when its grants or assistant flag were refused', async () => {
		const agent = { id: 'agent-1', name: 'Digest', owner: 'alice', tools: [] }
		const wrapper = await modal(agent)
		wrapper.vm.form.tools = [{ value: 'hermiq.listFiles' }]
		api.updateToolGrants.mockRejectedValue(new Error('403'))

		await wrapper.vm.save()
		expect(wrapper.vm.error).toBe("The agent was saved, but its tool grants were not updated. Only the agent's owner may change them.")

		const again = await modal(agent)
		again.vm.form.appAssistant = true
		api.setAppAssistant.mockRejectedValue({ response: { data: { error: 'Only an admin may decide this.' } } })
		await again.vm.save()
		expect(api.setAppAssistant).toHaveBeenCalledWith('agent-1', true)
		expect(again.vm.error).toBe('Only an admin may decide this.')

		api.setAppAssistant.mockRejectedValue(new Error('down'))
		await again.vm.save()
		expect(again.vm.error).toBe("The agent was saved, but it was not made this app's assistant.")
		expect(again.emitted('saved')).toBeUndefined()
	})
})
