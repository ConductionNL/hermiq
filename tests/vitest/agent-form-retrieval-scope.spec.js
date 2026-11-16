/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The agent form seeds the retrieval scope from a stored agent and writes it back
 * (memory-retrieval-scope): file filters, validated mode, floor and abstention.
 *
 * @spec openspec/changes/memory-retrieval-scope/specs/retrieval-scope/spec.md#requirement-an-owner-can-limit-file-retrieval-by-owner-file-name-and-modified-date-req-rscope-001
 * @spec openspec/changes/memory-retrieval-scope/specs/retrieval-scope/spec.md#requirement-an-agent-in-validated-mode-refuses-without-asking-the-model-when-nothing-validated-matches-req-rscope-003
 */

import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'

vi.mock('@conduction/nextcloud-vue', () => ({
	CnIconPicker: { name: 'CnIconPicker', render: () => null },
	fromOpenGemeenten: () => [],
	createObjectStore: () => () => ({}),
}))
vi.mock('../../src/store/store.js', () => ({
	useAgentStore: () => ({ registerObjectType: () => {}, errors: {} }),
}))
vi.mock('../../src/api/toolOversight.js', () => ({ updateToolGrants: vi.fn() }))
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

const { default: AgentFormModal } = await import('../../src/modals/AgentFormModal.vue')

/**
 * Mount the modal, without rendering, around one prop set.
 *
 * @param {object} props The props.
 * @return {Promise<object>} The component instance.
 */
async function modal(props) {
	const wrapper = mount(
		{ ...AgentFormModal, render: () => null },
		{
			props: { show: false, ...props },
			global: { mocks: { t: (app, text) => text, $route: { params: {} } } },
		},
	)
	await flushPromises()
	wrapper.vm.resetForm()
	return wrapper.vm
}

describe('AgentFormModal retrieval scope', () => {
	it('writes the validated mode and the file filters a create sets', async () => {
		const vm = await modal({})
		vm.form.enableRag = true
		vm.form.fileOwnerGroups = [{ label: 'Informatiebeheer', value: 'informatiebeheer' }]
		vm.form.fileModifiedAfter = '2025-01-01'
		vm.form.answerOnlyFromValidated = true
		vm.form.ragMinSimilarity = '0.55'
		vm.form.abstentionMessage = 'Neem contact op met het Klantcontactcentrum.'

		const payload = vm.buildPayload()

		expect(payload.ragFileFilters).toEqual({
			owners: [{ type: 'group', id: 'informatiebeheer' }],
			modifiedAfter: '2025-01-01',
		})
		expect(payload.answerOnlyFromValidated).toBe(true)
		expect(payload.ragMinSimilarity).toBe(0.55)
		expect(payload.abstentionMessage).toBe('Neem contact op met het Klantcontactcentrum.')
	})

	it('seeds an edit from the stored scope and keeps it on an unrelated edit', async () => {
		const stored = {
			id: 'agent-1',
			name: 'Informatiepunt',
			enableRag: true,
			ragFileFilters: { owners: [{ type: 'user', id: 'anna' }], namePattern: 'besluit*' },
			answerOnlyFromValidated: true,
			ragMinSimilarity: 0.6,
		}
		const vm = await modal({ agent: stored })

		expect(vm.form.fileOwnerUsers).toEqual([{ label: 'anna', value: 'anna' }])
		expect(vm.form.fileNamePattern).toBe('besluit*')
		expect(vm.form.answerOnlyFromValidated).toBe(true)

		vm.form.name = 'Informatiepunt Veiligheidsregio'
		const payload = vm.buildPayload()

		expect(payload.ragFileFilters).toEqual({ owners: [{ type: 'user', id: 'anna' }], namePattern: 'besluit*' })
		expect(payload.answerOnlyFromValidated).toBe(true)
		expect(payload.ragMinSimilarity).toBe(0.6)
		expect(payload).not.toHaveProperty('abstentionMessage')
	})

	it('switches validated mode off and clears the filters on a stored agent', async () => {
		const vm = await modal({
			agent: { id: 'agent-2', ragFileFilters: { namePattern: 'x*' }, answerOnlyFromValidated: true },
		})
		vm.form.fileNamePattern = ''
		vm.form.answerOnlyFromValidated = false

		const payload = vm.buildPayload()

		expect(payload.ragFileFilters).toEqual({})
		expect(payload.answerOnlyFromValidated).toBe(false)
	})
})
