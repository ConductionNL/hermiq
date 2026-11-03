/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * AgentFormModal keeps an agent's grants when the agent is edited, in both
 * stored shapes: a list of grant strings, and the app, subject, action
 * structure. An edit that leaves the tools alone writes no grants at all.
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const api = vi.hoisted(() => ({
	updateToolGrants: vi.fn(),
	saveObject: vi.fn(),
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
})
