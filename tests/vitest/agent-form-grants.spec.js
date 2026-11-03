/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * AgentFormModal keeps an agent's grants when the agent is edited, in both
 * stored shapes: a list of grant strings, and the app, subject, action
 * structure. An edit that leaves the tools alone writes no grants at all.
 * The save reads the stored grants again, so a change made meanwhile in
 * another tab is not undone.
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const api = vi.hoisted(() => ({
	updateToolGrants: vi.fn(),
	saveObject: vi.fn(),
	getAgentTools: vi.fn(),
}))

vi.mock('@conduction/nextcloud-vue', () => ({
	CnIconPicker: { name: 'CnIconPicker', render: () => null },
	fromOpenGemeenten: () => [],
	createObjectStore: () => () => ({}),
}))

vi.mock('../../src/store/store.js', () => ({
	useAgentStore: () => ({
		registerObjectType: () => {},
		saveObject: api.saveObject,
		errors: {},
	}),
}))

vi.mock('../../src/api/toolOversight.js', () => ({
	updateToolGrants: api.updateToolGrants,
}))

vi.mock('../../src/api/toolTaxonomy.js', async (importOriginal) => ({
	...(await importOriginal()),
	getAgentTools: api.getAgentTools,
}))

vi.mock('../../src/api/agents.js', () => ({
	listTools: async () => [],
	setAppAssistant: async () => ({}),
}))

vi.mock('../../src/api/chat.js', () => ({
	searchGroups: async () => [],
	searchUsers: async () => [],
}))

vi.mock('../../src/api/modelPolicy.js', () => ({
	getEffectiveModelPolicy: async () => null,
}))

const { default: AgentFormModal } =
	await import('../../src/modals/AgentFormModal.vue')

const structured = {
	hermiq: { file: { list: 'hermiq.listFiles' } },
	openregister: {
		flow: { run: [{ id: 'openregister.runFlow', args: { flowId: 'A' } }] },
	},
}

/**
 * Mount the modal editing one agent, and let it settle.
 *
 * @param {Array|object} tools The agent's stored `tools`.
 * @return {Promise<object>} The wrapper.
 */
async function editing(tools) {
	api.getAgentTools.mockResolvedValue(tools)
	// The template is not under test: rendering it needs the whole page.
	const wrapper = mount(
		{ ...AgentFormModal, render: () => null },
		{
			props: {
				show: true,
				agent: { id: 'agent-1', name: 'Digest', owner: 'alice', tools },
			},
			global: { mocks: { t: (app, text) => text, $route: { params: {} } } },
		},
	)
	await flushPromises()
	wrapper.vm.resetForm()
	return wrapper
}

describe('AgentFormModal grants on edit', () => {
	beforeEach(() => {
		vi.resetAllMocks()
		api.updateToolGrants.mockResolvedValue({})
		api.saveObject.mockImplementation(async (type, payload) => payload)
	})

	it('seeds the picker from structured grants', async () => {
		const wrapper = await editing(structured)

		expect(wrapper.vm.selectedGrants()).toEqual([
			'hermiq.listFiles',
			'openregister.runFlow?flowId=A',
		])
	})

	it('keeps structured grants when only the name changes', async () => {
		const wrapper = await editing(structured)
		wrapper.vm.form.name = 'Renamed'

		await wrapper.vm.save()

		expect(api.saveObject.mock.calls[0][1].tools).toEqual([
			'hermiq.listFiles',
			'openregister.runFlow?flowId=A',
		])
		expect(api.updateToolGrants).not.toHaveBeenCalled()
	})

	it('keeps listed grants when only the name changes', async () => {
		const list = ['hermiq.listFiles', 'pipelinq.lead.*']
		const wrapper = await editing(list)
		wrapper.vm.form.name = 'Renamed'

		await wrapper.vm.save()

		expect(api.saveObject.mock.calls[0][1].tools).toEqual(list)
		expect(api.updateToolGrants).not.toHaveBeenCalled()
	})

	it('writes the structured grants plus the added one when a tool is added', async () => {
		const wrapper = await editing(structured)
		wrapper.vm.form.tools = [
			...wrapper.vm.form.tools,
			{ label: 'mail.sendMail', value: 'mail.sendMail' },
		]

		await wrapper.vm.save()

		expect(api.updateToolGrants).toHaveBeenCalledWith('agent-1', [
			'hermiq.listFiles',
			'openregister.runFlow?flowId=A',
			'mail.sendMail',
		])
	})

	it('writes the shorter list when a listed grant is removed', async () => {
		const wrapper = await editing(['hermiq.listFiles', 'pipelinq.lead.*'])
		wrapper.vm.form.tools = wrapper.vm.form.tools.slice(1)

		await wrapper.vm.save()

		expect(api.updateToolGrants).toHaveBeenCalledWith('agent-1', [
			'pipelinq.lead.*',
		])
	})

	it('carries a list holding entries it cannot show verbatim', async () => {
		const list = ['hermiq.listFiles', { id: 'openregister.runFlow' }]
		const wrapper = await editing(list)

		await wrapper.vm.save()

		expect(api.saveObject.mock.calls[0][1].tools).toEqual(list)
		expect(api.updateToolGrants).not.toHaveBeenCalled()
	})

	it('carries an agent without tools as an empty list', async () => {
		const wrapper = await editing(undefined)

		await wrapper.vm.save()

		expect(api.saveObject.mock.calls[0][1].tools).toEqual([])
		expect(api.updateToolGrants).not.toHaveBeenCalled()
	})

	it('writes an empty list when every grant is removed on purpose', async () => {
		const wrapper = await editing(structured)
		wrapper.vm.form.tools = []

		await wrapper.vm.save()

		expect(api.updateToolGrants).toHaveBeenCalledWith('agent-1', [])
	})

	it('writes a waiver toggle that keeps the number of grants', async () => {
		const wrapper = await editing(['hermiq.sendMail', 'hermiq.listFiles'])
		wrapper.vm.form.tools = [
			{
				label: 'hermiq.sendMail#noapproval',
				value: 'hermiq.sendMail#noapproval',
			},
			{ label: 'hermiq.listFiles', value: 'hermiq.listFiles' },
		]

		await wrapper.vm.save()

		expect(api.updateToolGrants).toHaveBeenCalledWith('agent-1', [
			'hermiq.sendMail#noapproval',
			'hermiq.listFiles',
		])
	})

	it('carries grants changed in another tab when this form left them alone', async () => {
		const wrapper = await editing(['hermiq.listFiles'])
		const changed = ['hermiq.listFiles', 'mail.sendMail#noapproval']
		api.getAgentTools.mockResolvedValue(changed)
		wrapper.vm.form.name = 'Renamed'

		await wrapper.vm.save()

		expect(api.getAgentTools).toHaveBeenCalledWith('agent-1')
		expect(api.saveObject.mock.calls[0][1].tools).toEqual(changed)
		expect(api.saveObject.mock.calls[0][1].name).toBe('Renamed')
		expect(api.updateToolGrants).not.toHaveBeenCalled()
	})

	it('carries a structure changed in another tab as its grant strings', async () => {
		const wrapper = await editing(['hermiq.listFiles'])
		api.getAgentTools.mockResolvedValue(structured)

		await wrapper.vm.save()

		expect(api.saveObject.mock.calls[0][1].tools).toEqual([
			'hermiq.listFiles',
			'openregister.runFlow?flowId=A',
		])
	})

	it('saves nothing when this form and another tab both changed the grants', async () => {
		const wrapper = await editing(['hermiq.listFiles'])
		api.getAgentTools.mockResolvedValue(['hermiq.listFiles', 'mail.sendMail'])
		wrapper.vm.form.tools = []

		await wrapper.vm.save()

		expect(api.saveObject).not.toHaveBeenCalled()
		expect(api.updateToolGrants).not.toHaveBeenCalled()
		expect(wrapper.vm.error).toContain('changed elsewhere')
		expect(wrapper.vm.saving).toBe(false)
	})

	it('saves when the stored grants match, in another order', async () => {
		const wrapper = await editing(['hermiq.listFiles', 'mail.sendMail'])
		api.getAgentTools.mockResolvedValue(['mail.sendMail', 'hermiq.listFiles'])
		wrapper.vm.form.tools = wrapper.vm.form.tools.slice(1)

		await wrapper.vm.save()

		expect(api.updateToolGrants).toHaveBeenCalledWith('agent-1', [
			'mail.sendMail',
		])
	})

	it('saves nothing when the stored grants cannot be read', async () => {
		const wrapper = await editing(['hermiq.listFiles'])
		api.getAgentTools.mockRejectedValue(new Error('offline'))
		wrapper.vm.form.name = 'Renamed'

		await wrapper.vm.save()

		expect(api.saveObject).not.toHaveBeenCalled()
		expect(api.updateToolGrants).not.toHaveBeenCalled()
		expect(wrapper.vm.error).toContain('Could not read')
	})

	it('does not read the stored grants again on a create', async () => {
		const wrapper = mount(
			{ ...AgentFormModal, render: () => null },
			{
				props: { show: true, agent: null },
				global: {
					mocks: { t: (app, text) => text, $route: { params: {} } },
				},
			},
		)
		await flushPromises()
		wrapper.vm.resetForm()
		wrapper.vm.form.name = 'New'
		wrapper.vm.form.tools = [
			{ label: 'hermiq.listFiles', value: 'hermiq.listFiles' },
		]

		await wrapper.vm.save()

		expect(api.getAgentTools).not.toHaveBeenCalled()
		expect(api.saveObject.mock.calls[0][1].tools).toEqual(['hermiq.listFiles'])
	})
})
