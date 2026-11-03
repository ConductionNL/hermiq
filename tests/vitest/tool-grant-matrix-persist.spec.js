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
