/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * agent-acting-identity-picker: the choice "On whose behalf the agent works"
 * on AgentFormModal. Each option writes the right actingUser into the save
 * payload (REQ-AGID-001), the service account option is disabled with the
 * administrator sentence for a viewer without agent.set-acting-user
 * (REQ-AGID-002), and a stored account the server says no longer resolves
 * shows the warning (REQ-AGID-003). The Nextcloud components are stubs; the
 * APIs, the store and the initial state are doubled.
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
vi.mock('../../src/api/chat.js', () => ({ searchGroups: async () => [], searchUsers: vi.fn(async () => [{ uid: 'woo-triage', displayName: 'Woo triage' }]) }))
vi.mock('../../src/api/actingUser.js', () => ({ getActingUserStatus: vi.fn() }))
vi.mock('../../src/api/modelPolicy.js', () => ({ getEffectiveModelPolicy: async () => null }))

vi.stubGlobal('t', (app, text) => text)

const { default: AgentFormModal } = await import('../../src/modals/AgentFormModal.vue')
const { getActingUserStatus } = await import('../../src/api/actingUser.js')
const { searchUsers } = await import('../../src/api/chat.js')
const { state } = await import('@nextcloud/initial-state')
const { ACTING_AS_PERSON, ACTING_AS_SERVICE_ACCOUNT, actingChoiceOf, actingUserField, actingUserOf } = await import('../../src/utils/agentActingUser.js')

/**
 * A stub for a Nextcloud component: it renders its slots and carries its
 * label (or radio value) as an attribute the tests find it by.
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
			const key = this.$attrs.inputLabel ?? this.$attrs.label ?? this.$attrs.value ?? this.$attrs['data-testid'] ?? ''
			return h(
				'div',
				{ class: `stub-${name}`, 'data-key': typeof key === 'string' ? key : '' },
				Object.values(this.$slots).map((slot) => slot()),
			)
		},
	})
}

const stubs = Object.fromEntries(
	['NcModal', 'NcButton', 'NcCheckboxRadioSwitch', 'NcLoadingIcon', 'NcNoteCard', 'NcSelect', 'NcTextArea', 'NcTextField', 'PromptPlaceholderTools', 'StartFieldsEditor']
		.map((name) => [name, stub(name)]),
)

/**
 * Mount the open modal with its template.
 *
 * @param {object} props The props.
 * @return {Promise<object>} The wrapper.
 */
async function open(props = {}) {
	const wrapper = mount(AgentFormModal, {
		props: { show: true, ...props },
		global: { stubs, mocks: { t: (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (m, k) => String(vars[k] ?? m)) } },
	})
	await flushPromises()
	return wrapper
}

/**
 * The stub of a component with this label, value or test id.
 *
 * @param {object} wrapper The wrapper.
 * @param {string} name The component name.
 * @param {string} key The label, radio value or test id.
 * @return {object} The stub's wrapper.
 */
function field(wrapper, name, key) {
	const found = wrapper.findAllComponents({ name }).filter((c) => c.attributes('data-key') === key)
	expect(found, `${name} "${key}"`).toHaveLength(1)
	return found[0]
}

/**
 * Emit a model update from a stub and wait for the form to take it.
 *
 * @param {object} component The stub's wrapper.
 * @param {*} value The new value.
 * @return {Promise<void>}
 */
async function input(component, value) {
	component.vm.$emit('update:modelValue', value)
	await flushPromises()
}


const AGENT = { id: 'agent-1', name: 'Woo-triage', isPrivate: true, invitedUsers: [], groups: [] }

afterEach(() => {
	delete state.can_set_acting_user
	vi.clearAllMocks()
})

describe('the acting user field helpers', () => {
	it('reads the stored choice from actingUser', () => {
		expect(actingUserOf({ actingUser: ' woo-triage ' })).toBe('woo-triage')
		expect(actingUserOf({ actingUser: ['x'] })).toBe('')
		expect(actingUserOf(null)).toBe('')
		expect(actingChoiceOf({ actingUser: 'woo-triage' })).toBe(ACTING_AS_SERVICE_ACCOUNT)
		expect(actingChoiceOf({ actingUser: '  ' })).toBe(ACTING_AS_PERSON)
		expect(actingChoiceOf({})).toBe(ACTING_AS_PERSON)
	})

	it('writes the chosen account only for the service account choice', () => {
		expect(actingUserField(ACTING_AS_SERVICE_ACCOUNT, { label: 'woo-triage', value: 'woo-triage' })).toEqual({ actingUser: 'woo-triage' })
		expect(actingUserField(ACTING_AS_SERVICE_ACCOUNT, 'woo-triage')).toEqual({ actingUser: 'woo-triage' })
		expect(actingUserField(ACTING_AS_SERVICE_ACCOUNT, null)).toEqual({ actingUser: '' })
		expect(actingUserField(ACTING_AS_PERSON, { label: 'woo-triage', value: 'woo-triage' })).toEqual({ actingUser: '' })
	})
})

describe('AgentFormModal on whose behalf the agent works', () => {
	it('gives an agent its own service account and shows it on reopening', async () => {
		state.can_set_acting_user = true
		const wrapper = await open({ agent: AGENT })

		expect(wrapper.vm.form.actingChoice).toBe('person')
		expect(wrapper.findAllComponents({ name: 'NcSelect' }).filter((c) => c.attributes('data-key') === 'Service account')).toHaveLength(0)

		await input(field(wrapper, 'NcCheckboxRadioSwitch', 'service-account'), 'service-account')
		const picker = field(wrapper, 'NcSelect', 'Service account')
		picker.vm.$emit('search', 'woo')
		await flushPromises()
		expect(searchUsers).toHaveBeenCalledWith('woo')
		expect(wrapper.vm.actingUserOptions).toEqual([{ label: 'woo-triage', value: 'woo-triage' }])
		await input(picker, { label: 'woo-triage', value: 'woo-triage' })

		const payload = wrapper.vm.buildPayload()
		expect(payload.actingUser).toBe('woo-triage')
		expect(payload.id).toBe('agent-1')

		getActingUserStatus.mockResolvedValue({ actingUser: 'woo-triage', resolves: true })
		const reopened = await open({ agent: { ...AGENT, actingUser: 'woo-triage' } })
		expect(reopened.vm.form.actingChoice).toBe('service-account')
		expect(reopened.vm.form.actingUser).toEqual({ label: 'woo-triage', value: 'woo-triage' })
		expect(getActingUserStatus).toHaveBeenCalledWith('agent-1')
		expect(reopened.find('[data-testid="agent-form-acting-unresolved"]').exists()).toBe(false)
	})

	it('stores an empty actingUser when the agent goes back to the person who uses it', async () => {
		state.can_set_acting_user = true
		getActingUserStatus.mockResolvedValue({ actingUser: 'woo-triage', resolves: true })
		const wrapper = await open({ agent: { ...AGENT, actingUser: 'woo-triage' } })

		await input(field(wrapper, 'NcCheckboxRadioSwitch', 'person'), 'person')

		expect(wrapper.vm.buildPayload().actingUser).toBe('')
	})

	it('disables the service account option for a viewer without the action', async () => {
		state.can_set_acting_user = false
		const wrapper = await open({ agent: AGENT })

		expect(field(wrapper, 'NcCheckboxRadioSwitch', 'service-account').vm.$attrs.disabled).toBe(true)
		expect(field(wrapper, 'NcCheckboxRadioSwitch', 'person').vm.$attrs.disabled).toBeUndefined()
		expect(wrapper.find('[data-testid="agent-form-acting-admin-only"]').text())
			.toBe('An administrator sets the account an agent works with.')
	})

	it('offers the service account option, without the sentence, to a viewer with the action', async () => {
		state.can_set_acting_user = true
		const wrapper = await open({ agent: AGENT })

		expect(field(wrapper, 'NcCheckboxRadioSwitch', 'service-account').vm.$attrs.disabled).toBe(false)
		expect(wrapper.find('[data-testid="agent-form-acting-admin-only"]').exists()).toBe(false)
	})

	it('keeps an account set before, unchanged, for a viewer without the action', async () => {
		getActingUserStatus.mockResolvedValue({ actingUser: 'woo-triage', resolves: true })
		const wrapper = await open({ agent: { ...AGENT, actingUser: 'woo-triage' } })

		expect(wrapper.vm.form.actingChoice).toBe('service-account')
		expect(field(wrapper, 'NcSelect', 'Service account').vm.$attrs.disabled).toBe(true)
		expect(wrapper.vm.buildPayload().actingUser).toBe('woo-triage')
	})

	it('warns when the stored service account no longer resolves', async () => {
		state.can_set_acting_user = true
		getActingUserStatus.mockResolvedValue({ actingUser: 'oud-account', resolves: false })
		const wrapper = await open({ agent: { ...AGENT, actingUser: 'oud-account' } })

		expect(wrapper.vm.form.actingChoice).toBe('service-account')
		const warning = wrapper.find('[data-testid="agent-form-acting-unresolved"]')
		expect(warning.attributes('role')).toBe('alert')
		expect(warning.text()).toBe('The account oud-account no longer exists or is disabled. Runs are refused until you choose another account or let the agent work on behalf of the person who uses it.')

		await input(field(wrapper, 'NcSelect', 'Service account'), { label: 'woo-triage', value: 'woo-triage' })
		expect(wrapper.find('[data-testid="agent-form-acting-unresolved"]').exists()).toBe(false)
	})

	it('asks nothing for a new agent or one without an account, and shows no warning when the server does not answer', async () => {
		await open()
		await open({ agent: AGENT })
		expect(getActingUserStatus).not.toHaveBeenCalled()

		getActingUserStatus.mockRejectedValue(new Error('offline'))
		const wrapper = await open({ agent: { ...AGENT, actingUser: 'oud-account' } })
		expect(getActingUserStatus).toHaveBeenCalledWith('agent-1')
		expect(wrapper.vm.actingUserStatus).toBeNull()
		expect(wrapper.find('[data-testid="agent-form-acting-unresolved"]').exists()).toBe(false)
	})
})
