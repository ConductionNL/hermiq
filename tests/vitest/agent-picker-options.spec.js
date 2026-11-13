/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Characterisation of the agent pickers of the skill install modal, the
 * memory page and the runs page: one option per agent, labelled by its name,
 * else its uuid, else its id, and valued by its uuid, else its id.
 */

import { describe, expect, it, vi } from 'vitest'

vi.mock('@conduction/nextcloud-vue', () => ({
	CnDataTable: { name: 'CnDataTable', render: () => null },
	createObjectStore: () => () => ({}),
}))
vi.mock('../../src/store/store.js', () => ({}))

const pickers = {
	SkillInstallModal: (await import('../../src/modals/SkillInstallModal.vue')).default,
	AgentMemory: (await import('../../src/views/AgentMemory.vue')).default,
	Runs: (await import('../../src/views/Runs.vue')).default,
}

const agents = [
	{ name: 'Briefing', uuid: 'u-1', id: 1 },
	{ uuid: 'u-2', id: 2 },
	{ id: 3 },
	{ name: 'Only name' },
]

describe.each(Object.entries(pickers))('%s agentOptions', (name, component) => {
	it('offers each agent by name and uuid', () => {
		expect(component.computed.agentOptions.call({ agents })).toEqual([
			{ label: 'Briefing', value: 'u-1' },
			{ label: 'u-2', value: 'u-2' },
			{ label: 3, value: 3 },
			{ label: 'Only name', value: undefined },
		])
	})

	it('offers nothing without agents', () => {
		expect(component.computed.agentOptions.call({ agents: [] })).toEqual([])
	})
})
