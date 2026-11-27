/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The remote agents block on the admin page and its form (flows-hand-off-and-wait
 * D5): what is read, what registering, changing, switching and removing send, and
 * the server's refusal shown in place.
 *
 * @spec openspec/changes/archive/2026-11-25-flows-hand-off-and-wait/specs/remote-agents/spec.md#requirement-an-admin-registers-a-remote-agent-through-an-integriq-source-req-a2a-001
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../../src/api/remoteAgents.js', () => ({
	listRemoteAgents: vi.fn(),
	registerRemoteAgent: vi.fn(),
	updateRemoteAgent: vi.fn(),
	deleteRemoteAgent: vi.fn(),
	listIntegriqSources: vi.fn(),
}))

const api = await import('../../src/api/remoteAgents.js')
const { default: RemoteAgents } = await import('../../src/components/settings/RemoteAgents.vue')
const { default: RemoteAgentFormModal } = await import('../../src/modals/RemoteAgentFormModal.vue')

const t = (app, text, vars = {}) => text.replace(/{(\w+)}/g, (m, k) => (k in vars ? String(vars[k]) : m))

const KVK = {
	id: 'ra-1',
	name: 'KvK-assistent',
	slug: 'kvk-assistent',
	source: 'src-1',
	enabled: false,
	agentCard: { name: 'KvK Agent', version: '1.2', skills: [{ id: 's1', name: 'Bedrijfsgegevens opzoeken op KvK-nummer', description: '' }, { id: 's2' }] },
}

/**
 * Mount a component with a t() that fills placeholders.
 *
 * @param {object} component The component.
 * @param {object} props Its props.
 * @return {Promise<object>} The wrapper, after it settled.
 */
async function mountWith(component, props = {}) {
	const wrapper = mount(component, { props, global: { mocks: { t } } })
	await flushPromises()
	return wrapper
}

describe('RemoteAgents', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('lists each remote agent with the card that was kept and its skills', async () => {
		api.listRemoteAgents.mockResolvedValue([KVK])

		const wrapper = await mountWith(RemoteAgents)

		expect(api.listRemoteAgents).toHaveBeenCalledTimes(1)
		expect(wrapper.vm.cardLine(KVK)).toBe('Card kept at registration: KvK Agent, version 1.2.')
		expect(wrapper.vm.cardLine({})).toBe('No agent card was kept.')
		expect(wrapper.vm.skillsOf(KVK)).toEqual([KVK.agentCard.skills[0]])
		expect(wrapper.findAll('[data-testid="remote-agent"]')).toHaveLength(1)
	})

	it('switches a remote agent on and shows what the server stored', async () => {
		api.listRemoteAgents.mockResolvedValue([KVK])
		api.updateRemoteAgent.mockResolvedValue({ ...KVK, enabled: true })
		const wrapper = await mountWith(RemoteAgents)

		await wrapper.vm.setEnabled(KVK, true)

		expect(api.updateRemoteAgent).toHaveBeenCalledWith('ra-1', { enabled: true })
		expect(wrapper.vm.agents[0].enabled).toBe(true)
	})

	it('removes a remote agent and reads the list again', async () => {
		api.listRemoteAgents.mockResolvedValueOnce([KVK]).mockResolvedValueOnce([])
		const wrapper = await mountWith(RemoteAgents)

		await wrapper.vm.remove(KVK)

		expect(api.deleteRemoteAgent).toHaveBeenCalledWith('ra-1')
		expect(wrapper.vm.agents).toEqual([])
	})

	it('shows the controller’s message when a call is refused', async () => {
		api.listRemoteAgents.mockRejectedValue({ response: { data: { message: 'Not allowed' } } })

		const wrapper = await mountWith(RemoteAgents)

		expect(wrapper.vm.error).toBe('Not allowed')
	})
})

describe('RemoteAgentFormModal', () => {
	beforeEach(() => {
		vi.clearAllMocks()
		api.listIntegriqSources.mockResolvedValue([{ id: 'src-1', label: 'kvk-a2a' }])
	})

	it('registers a new remote agent through the chosen source', async () => {
		api.registerRemoteAgent.mockResolvedValue(KVK)
		const wrapper = await mountWith(RemoteAgentFormModal, { show: true })
		expect(api.listIntegriqSources).toHaveBeenCalledTimes(1)
		expect(wrapper.vm.canSave).toBe(false)

		wrapper.vm.form.name = '  KvK-assistent '
		wrapper.vm.selectedSource = { id: 'src-1', label: 'kvk-a2a' }
		await wrapper.vm.submit()

		expect(api.registerRemoteAgent).toHaveBeenCalledWith({ name: 'KvK-assistent', slug: '', description: '', source: 'src-1' })
		expect(wrapper.emitted('saved')[0]).toEqual([KVK])
		expect(wrapper.emitted('close')).toHaveLength(1)
	})

	it('changes only the name and description of a registered one', async () => {
		api.updateRemoteAgent.mockResolvedValue(KVK)
		const wrapper = await mountWith(RemoteAgentFormModal, { show: true, agent: KVK })
		expect(api.listIntegriqSources).not.toHaveBeenCalled()

		wrapper.vm.form.description = 'Zoekt bedrijven op'
		await wrapper.vm.submit()

		expect(api.updateRemoteAgent).toHaveBeenCalledWith('ra-1', { name: 'KvK-assistent', description: 'Zoekt bedrijven op' })
		expect(api.registerRemoteAgent).not.toHaveBeenCalled()
	})

	it('keeps the form open with the server’s refusal', async () => {
		api.registerRemoteAgent.mockRejectedValue({ response: { data: { message: 'The agent card could not be fetched.' } } })
		const wrapper = await mountWith(RemoteAgentFormModal, { show: true })
		wrapper.vm.form.name = 'KvK'
		wrapper.vm.form.source = 'src-1'

		await wrapper.vm.submit()

		expect(wrapper.vm.error).toBe('The agent card could not be fetched.')
		expect(wrapper.emitted('close')).toBeUndefined()
		expect(wrapper.vm.saving).toBe(false)
	})
})
