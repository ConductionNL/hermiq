/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The people and group pickers inside AgentFormModal must be reachable by
 * screen readers and the keyboard (WCAG 2.2 AA 2.1.1, 4.1.2). Each one names
 * itself through `inputLabel`, and keeps its option list inside the dialog
 * (`appendToBody` false): a list appended to the page body sits outside the
 * modal's focus trap and its aria-modal subtree. The Nextcloud components are
 * stubs that keep the props they were given; the APIs and the store are
 * doubled.
 */

import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

vi.mock('@conduction/nextcloud-vue', () => ({
	CnIconPicker: {
		name: 'CnIconPicker',
		props: ['modelValue'],
		emits: ['update:modelValue'],
		render: () => h('div', { class: 'stub-CnIconPicker' }),
	},
	fromOpenGemeenten: () => [],
	createObjectStore: () => () => ({}),
}))

vi.mock('../../src/store/store.js', () => ({
	useAgentStore: () => ({
		registerObjectType: () => {},
		saveObject: vi.fn(),
		fetchObject: vi.fn(async () => null),
		errors: {},
	}),
}))

vi.mock('../../src/api/toolOversight.js', () => ({ updateToolGrants: vi.fn() }))
vi.mock('../../src/api/agents.js', () => ({ listTools: async () => [], setAppAssistant: async () => ({}) }))
vi.mock('../../src/api/chat.js', () => ({ searchGroups: async () => [], searchUsers: async () => [] }))
vi.mock('../../src/api/actingUser.js', () => ({ getActingUserStatus: vi.fn(async () => null) }))
vi.mock('../../src/api/modelPolicy.js', () => ({ getEffectiveModelPolicy: async () => null }))

vi.stubGlobal('t', (app, text) => text)

const { default: AgentFormModal } = await import('../../src/modals/AgentFormModal.vue')
const { state } = await import('@nextcloud/initial-state')

/**
 * A stub for a Nextcloud component: it renders its slots and keeps the
 * attributes it was given, so a test can read the props a picker got.
 *
 * @param {string} name The component name.
 * @return {object} The stub.
 */
function stub(name) {
	return defineComponent({
		name,
		inheritAttrs: false,
		props: ['modelValue'],
		emits: ['update:modelValue', 'click', 'close', 'search'],
		render() {
			return h('div', { class: `stub-${name}` }, Object.values(this.$slots).map((slot) => slot()))
		},
	})
}

const stubs = Object.fromEntries(
	['NcModal', 'NcButton', 'NcCheckboxRadioSwitch', 'NcLoadingIcon', 'NcNoteCard', 'NcSelect', 'NcTextArea', 'NcTextField', 'PromptPlaceholderTools', 'StartFieldsEditor']
		.map((name) => [name, stub(name)]),
)

const PICKERS = {
	'agent-form-sharing-people': 'People',
	'agent-form-sharing-groups': 'Groups',
	'agent-form-acting-user': 'Service account',
	'agent-form-file-owner-users': 'Files owned by these people',
	'agent-form-file-owner-groups': 'Files owned by members of these groups',
}

/**
 * Mount the open modal with every people and group picker shown.
 *
 * @return {Promise<object>} The wrapper.
 */
async function openWithAllPickers() {
	state.can_set_acting_user = true
	const wrapper = mount(AgentFormModal, {
		props: { show: true, agent: { id: 'agent-1', name: 'Woo-triage', isPrivate: false, invitedUsers: [], groups: [] } },
		global: { stubs, mocks: { t: (app, text) => text } },
	})
	await flushPromises()
	wrapper.vm.form.sharing = 'people-and-groups'
	wrapper.vm.form.actingChoice = 'service-account'
	wrapper.vm.form.enableRag = true
	wrapper.vm.form.searchFiles = true
	await flushPromises()
	return wrapper
}

/**
 * The picker stubs by their test id.
 *
 * @param {object} wrapper The wrapper.
 * @return {Map<string, object>} Test id to the stub's wrapper.
 */
function pickers(wrapper) {
	const found = new Map()
	for (const select of wrapper.findAllComponents({ name: 'NcSelect' })) {
		const id = select.vm.$attrs['data-testid']
		if (id in PICKERS) {
			found.set(id, select)
		}
	}
	return found
}

afterEach(() => {
	delete state.can_set_acting_user
})

describe('AgentFormModal people and group pickers', () => {
	it('shows all five pickers once their options are chosen', async () => {
		const wrapper = await openWithAllPickers()

		expect([...pickers(wrapper).keys()].sort()).toEqual(Object.keys(PICKERS).sort())
	})

	it('names every picker for screen readers through inputLabel', async () => {
		const wrapper = await openWithAllPickers()

		for (const [id, select] of pickers(wrapper)) {
			expect(select.vm.$attrs.inputLabel, id).toBe(PICKERS[id])
			expect(select.vm.$attrs.ariaLabelCombobox, id).toBeUndefined()
		}
	})

	it('keeps every option list inside the dialog instead of the page body', async () => {
		const wrapper = await openWithAllPickers()

		for (const [id, select] of pickers(wrapper)) {
			expect(select.vm.$attrs.appendToBody, id).toBe(false)
		}
	})

	it('puts the pickers inside the dialog in reading order after the choice that shows them', async () => {
		const wrapper = await openWithAllPickers()
		const html = wrapper.html()
		const order = ['agent-form-sharing', 'agent-form-acting', 'agent-form-file-filters']
			.map((id) => wrapper.find(`[data-testid="${id}"]`))

		order.forEach((fieldset) => expect(fieldset.exists()).toBe(true))
		const positions = order.map((fieldset) => html.indexOf(fieldset.html()))
		expect(positions).toEqual([...positions].sort((a, b) => a - b))
		expect(wrapper.find('[data-testid="agent-form-sharing"]').findAllComponents({ name: 'NcSelect' })).toHaveLength(2)
		expect(wrapper.find('[data-testid="agent-form-acting"]').findAllComponents({ name: 'NcSelect' })).toHaveLength(1)
		expect(wrapper.find('[data-testid="agent-form-file-filters"]').findAllComponents({ name: 'NcSelect' })).toHaveLength(2)
	})
})
