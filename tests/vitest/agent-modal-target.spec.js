/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Characterisation of the agent dialogs opened from an agent page: which agent
 * they act on (the prop, else the route's `:id`), and that the three loaders
 * load when shown, also when mounted already open.
 */

import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../../src/api/agentAvailability.js', () => ({
	getAvailability: vi.fn(async () => ({ active: true, canSwitch: false })),
	setAvailability: vi.fn(),
}))
vi.mock('../../src/api/compliance.js', () => ({ getAgentFactsheet: vi.fn(async () => null) }))
vi.mock('../../src/api/agents.js', () => ({
	listAgentVersions: vi.fn(),
	rollbackAgentVersion: vi.fn(),
	pullAgentFromGit: vi.fn(),
	pushAgentToGit: vi.fn(),
	stampAgentRepo: vi.fn(),
}))
vi.mock('../../src/api/agentTemplates.js', () => ({ exportAgentToTemplate: vi.fn(), saveAgentAsTemplate: vi.fn() }))
vi.mock('../../src/store/store.js', () => ({ useAgentStore: () => ({ registerObjectType: () => {} }) }))
vi.mock('@nextcloud/auth', () => ({ getCurrentUser: () => null }))

const availability = await import('../../src/api/agentAvailability.js')
const compliance = await import('../../src/api/compliance.js')
const { default: AgentAvailabilityDialog } = await import('../../src/dialogs/AgentAvailabilityDialog.vue')
const { default: AgentFactsheetDialog } = await import('../../src/dialogs/AgentFactsheetDialog.vue')
const { default: AgentGitModal } = await import('../../src/modals/AgentGitModal.vue')
const { default: AgentExportModal } = await import('../../src/modals/AgentExportModal.vue')
const { default: AgentVersionHistoryDialog } = await import('../../src/dialogs/agents/AgentVersionHistoryDialog.vue')

/**
 * One option of a component, from the component itself or one of its mixins.
 *
 * @param {object} component The component.
 * @param {string} kind `computed` or `props`.
 * @param {string} name The option name.
 * @return {*} The option.
 */
function option(component, kind, name) {
	const owner = [component, ...(component.mixins || [])].find((entry) => entry[kind]?.[name] !== undefined)
	return owner[kind][name]
}

/**
 * Mount a dialog on a route whose `:id` is `route-1`.
 *
 * @param {object} component The component.
 * @param {object} props Its props.
 * @return {Promise<object>} The wrapper.
 */
async function open(component, props) {
	const wrapper = mount(component, {
		props,
		global: { mocks: { t: (app, text) => text, $route: { params: { id: 'route-1' } } } },
	})
	await flushPromises()
	return wrapper
}

beforeEach(() => {
	vi.mocked(availability.getAvailability).mockClear()
	vi.mocked(compliance.getAgentFactsheet).mockClear()
	axios.get = vi.fn(async () => ({ data: {} }))
})

describe('agent dialogs resolve their agent', () => {
	it.each([
		['AgentAvailabilityDialog', AgentAvailabilityDialog],
		['AgentFactsheetDialog', AgentFactsheetDialog],
		['AgentGitModal', AgentGitModal],
		['AgentExportModal', AgentExportModal],
		['AgentVersionHistoryDialog', AgentVersionHistoryDialog],
	])('%s takes the prop, else the route id', (name, component) => {
		const resolve = option(component, 'computed', 'resolvedAgentId')
		expect(resolve.call({ agentId: 'prop-1', $route: { params: { id: 'route-1' } } })).toBe('prop-1')
		expect(resolve.call({ agentId: '', $route: { params: { id: 'route-1' } } })).toBe('route-1')
		expect(resolve.call({ agentId: '', $route: undefined })).toBe('')
		expect(option(component, 'props', 'show').default).toBe(false)
		expect(option(component, 'props', 'agentId').default).toBe('')
	})
})

describe('agent dialogs load when shown', () => {
	it('AgentAvailabilityDialog loads on mount when open, and when opened later', async () => {
		await open(AgentAvailabilityDialog, { show: true })
		expect(availability.getAvailability).toHaveBeenCalledWith('route-1')

		vi.mocked(availability.getAvailability).mockClear()
		const wrapper = await open(AgentAvailabilityDialog, { show: false, agentId: 'prop-1' })
		expect(availability.getAvailability).not.toHaveBeenCalled()
		await wrapper.setProps({ show: true })
		await flushPromises()
		expect(availability.getAvailability).toHaveBeenCalledWith('prop-1')
	})

	it('AgentFactsheetDialog loads on mount when open', async () => {
		await open(AgentFactsheetDialog, { show: true, agentId: 'prop-1' })
		expect(compliance.getAgentFactsheet).toHaveBeenCalledWith('prop-1')
	})

	it('AgentGitModal reads the agent and the credentials on mount when open', async () => {
		await open(AgentGitModal, { show: true })
		expect(axios.get).toHaveBeenCalledWith('/index.php/apps/openregister/api/objects/hermiq/agent/route-1')
		expect(axios.get).toHaveBeenCalledWith('/index.php/apps/openregister/api/credentials')
	})
})
