/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * ToolGrantMatrix saves only grants it has read. A save after a failed load
 * would write the empty draft back and revoke every grant the agent holds.
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const api = vi.hoisted(() => ({
	getToolCatalog: vi.fn(),
	updateToolGrants: vi.fn(),
	getAgentGrants: vi.fn(),
	getToolTaxonomy: vi.fn(),
}))

vi.mock('../../src/api/toolOversight.js', () => ({
	getToolCatalog: api.getToolCatalog,
	updateToolGrants: api.updateToolGrants,
}))

vi.mock('../../src/api/toolTaxonomy.js', async (importOriginal) => ({
	...(await importOriginal()),
	getAgentGrants: api.getAgentGrants,
	getToolTaxonomy: api.getToolTaxonomy,
}))

const { default: ToolGrantMatrix } =
	await import('../../src/components/ToolGrantMatrix.vue')

/**
 * Mount the matrix and let its first load settle.
 *
 * @return {Promise<object>} The wrapper.
 */
async function matrix() {
	const wrapper = mount(ToolGrantMatrix, {
		props: { agentId: 'agent-1', canEdit: true },
	})
	await flushPromises()
	return wrapper
}

describe('ToolGrantMatrix persist()', () => {
	beforeEach(() => {
		vi.resetAllMocks()
		api.getToolCatalog.mockResolvedValue([])
		api.getToolTaxonomy.mockResolvedValue([])
		api.getAgentGrants.mockResolvedValue(['hermiq.listFiles', 'pipelinq.lead.*'])
		api.updateToolGrants.mockResolvedValue({})
	})

	it('saves the grants it read, unchanged', async () => {
		const wrapper = await matrix()

		await wrapper.vm.persist()

		expect(api.updateToolGrants).toHaveBeenCalledWith('agent-1', [
			'hermiq.listFiles',
			'pipelinq.lead.*',
		])
	})

	it('refuses to save after the grants could not be read', async () => {
		api.getAgentGrants.mockRejectedValue(new Error('Gateway Timeout'))
		const wrapper = await matrix()

		await expect(wrapper.vm.persist()).rejects.toThrow('could not be read')
		expect(api.updateToolGrants).not.toHaveBeenCalled()
	})

	it('refuses to save when a reload fails after a good load', async () => {
		const wrapper = await matrix()
		api.getAgentGrants.mockRejectedValueOnce(new Error('Gateway Timeout'))
		await wrapper.vm.load()

		await expect(wrapper.vm.persist()).rejects.toThrow('could not be read')
		expect(api.updateToolGrants).not.toHaveBeenCalled()
	})

	it('saves again once a later load succeeds', async () => {
		api.getAgentGrants.mockRejectedValueOnce(new Error('Gateway Timeout'))
		const wrapper = await matrix()
		await wrapper.vm.load()

		await wrapper.vm.persist()

		expect(api.updateToolGrants).toHaveBeenCalledTimes(1)
	})
})

/**
 * A promise with its resolve and reject exposed.
 *
 * @return {{promise: Promise, resolve: Function, reject: Function}} The deferred.
 */
function deferred() {
	let resolve
	let reject
	const promise = new Promise((res, rej) => {
		resolve = res
		reject = rej
	})
	return { promise, resolve, reject }
}

describe('ToolGrantMatrix load() after an agent switch', () => {
	beforeEach(() => {
		vi.resetAllMocks()
		api.getToolCatalog.mockResolvedValue([])
		api.getToolTaxonomy.mockResolvedValue([])
		api.updateToolGrants.mockResolvedValue({})
	})

	/**
	 * Mount on agent-1 with its grants still in flight, then switch to agent-2.
	 *
	 * @param {object} first The deferred answer for agent-1.
	 * @param {Array<string>} second The grants agent-2 answers with.
	 * @return {Promise<object>} The wrapper, with agent-2 loaded.
	 */
	async function switched(first, second) {
		api.getAgentGrants.mockImplementation((agentId) =>
			agentId === 'agent-1' ? first.promise : Promise.resolve(second),
		)
		const wrapper = mount(ToolGrantMatrix, {
			props: { agentId: 'agent-1', canEdit: true },
		})
		await wrapper.setProps({ agentId: 'agent-2' })
		await flushPromises()
		return wrapper
	}

	it("drops the previous agent's late grants", async () => {
		const first = deferred()
		const wrapper = await switched(first, ['agent2.tool'])

		first.resolve(['agent1.tool'])
		await flushPromises()

		expect(wrapper.vm.draftGrants).toEqual(['agent2.tool'])
		await wrapper.vm.persist()
		expect(api.updateToolGrants).toHaveBeenCalledWith('agent-2', ['agent2.tool'])
	})

	it("drops the previous agent's late failure", async () => {
		const first = deferred()
		const wrapper = await switched(first, ['agent2.tool'])

		first.reject(new Error('Gateway Timeout'))
		await flushPromises()

		expect(wrapper.vm.error).toBe('')
		expect(wrapper.vm.loaded).toBe(true)
		expect(wrapper.vm.loading).toBe(false)
	})

	it('stays loading while the current agent is still in flight', async () => {
		const first = deferred()
		const second = deferred()
		api.getAgentGrants.mockImplementation((agentId) =>
			agentId === 'agent-1' ? first.promise : second.promise,
		)
		const wrapper = mount(ToolGrantMatrix, {
			props: { agentId: 'agent-1', canEdit: true },
		})
		await wrapper.setProps({ agentId: 'agent-2' })

		first.resolve(['agent1.tool'])
		await flushPromises()
		expect(wrapper.vm.loading).toBe(true)

		second.resolve(['agent2.tool'])
		await flushPromises()
		expect(wrapper.vm.loading).toBe(false)
		expect(wrapper.vm.draftGrants).toEqual(['agent2.tool'])
	})

	it('keeps the newer answer when two loads for one agent cross', async () => {
		const older = deferred()
		api.getAgentGrants.mockResolvedValueOnce(['first.tool'])
		const wrapper = mount(ToolGrantMatrix, {
			props: { agentId: 'agent-1', canEdit: true },
		})
		await flushPromises()
		api.getAgentGrants
			.mockImplementationOnce(() => older.promise)
			.mockResolvedValueOnce(['newer.tool'])
		const slow = wrapper.vm.load()
		await wrapper.vm.load()

		older.resolve(['older.tool'])
		await slow

		expect(wrapper.vm.draftGrants).toEqual(['newer.tool'])
	})
})
