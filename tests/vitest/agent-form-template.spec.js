/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * AgentFormModal's template: every field is two-way bound to the form (a
 * one-way binding once let typing never reach `form.name`), the people and
 * group pickers appear only for shared agents, the RAG source switches only
 * with RAG on, the draft check's findings show above the fields, and the
 * actions close or save. The Nextcloud components are stubs that emit what the
 * real ones emit; the API, the store and the icon picker are doubled.
 */

import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
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
vi.mock('../../src/api/modelPolicy.js', () => ({ getEffectiveModelPolicy: async () => null }))

vi.stubGlobal('t', (app, text) => text)

const { default: AgentFormModal } = await import('../../src/modals/AgentFormModal.vue')

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

describe('AgentFormModal template bindings', () => {
	it('writes the text fields back to the form', async () => {
		const wrapper = await open()
		const form = wrapper.vm.form

		await input(field(wrapper, 'NcTextField', 'Name'), 'Raadsgriffier')
		await input(field(wrapper, 'NcTextField', 'Description'), 'Beantwoordt vragen over de raad')
		await input(field(wrapper, 'NcTextArea', 'System prompt'), 'Je bent behulpzaam.')
		await input(field(wrapper, 'NcTextField', 'Temperature (0–2)'), '0.4')
		await input(field(wrapper, 'NcTextField', 'Max tokens per response'), '900')
		await input(field(wrapper, 'NcTextField', 'Maximum tool calls per answer'), '7')

		expect(form.name).toBe('Raadsgriffier')
		expect(form.description).toBe('Beantwoordt vragen over de raad')
		expect(form.prompt).toBe('Je bent behulpzaam.')
		expect(form.temperature).toBe('0.4')
		expect(form.maxTokens).toBe('900')
		expect(form.maxToolCalls).toBe('7')
	})

	it('shows the people and group pickers only for an agent shared with chosen people', async () => {
		const wrapper = await open()

		expect(wrapper.findAllComponents({ name: 'NcSelect' }).filter((c) => c.attributes('data-key') === 'People')).toHaveLength(0)

		await input(field(wrapper, 'NcCheckboxRadioSwitch', 'people-and-groups'), 'people-and-groups')
		expect(wrapper.vm.form.sharing).toBe('people-and-groups')

		const people = [{ label: 'Anna', value: 'anna' }]
		const groups = [{ label: 'Griffie', value: 'griffie' }]
		await input(field(wrapper, 'NcSelect', 'People'), people)
		await input(field(wrapper, 'NcSelect', 'Groups'), groups)
		expect(wrapper.vm.form.invitedUsers).toEqual(people)
		expect(wrapper.vm.form.groups).toEqual(groups)

		await input(field(wrapper, 'NcCheckboxRadioSwitch', 'organisation'), 'organisation')
		expect(wrapper.vm.form.sharing).toBe('organisation')
		await input(field(wrapper, 'NcCheckboxRadioSwitch', 'only-me'), 'only-me')
		expect(wrapper.vm.form.sharing).toBe('only-me')
	})

	it('writes the icon, tools, delegation and app choices back to the form', async () => {
		const wrapper = await open()

		wrapper.findComponent({ name: 'CnIconPicker' }).vm.$emit('update:modelValue', 'Robot')
		await flushPromises()
		await input(field(wrapper, 'NcSelect', 'Enabled tools'), [{ label: 'Search', value: 'search' }])
		await input(field(wrapper, 'NcSelect', 'Delegation allowlist'), [{ label: 'Helper', value: 'helper' }])
		await input(field(wrapper, 'NcSelect', 'App this agent serves'), { label: 'Decidiq', value: 'decidiq' })
		await input(field(wrapper, 'StartFieldsEditor', ''), [{ name: 'topic' }])

		expect(wrapper.vm.form.icon).toBe('Robot')
		expect(wrapper.vm.form.tools).toEqual([{ label: 'Search', value: 'search' }])
		expect(wrapper.vm.form.delegationAllowlist).toEqual([{ label: 'Helper', value: 'helper' }])
		expect(wrapper.vm.form.applicationSlug).toEqual({ label: 'Decidiq', value: 'decidiq' })
		expect(wrapper.vm.form.startFields).toEqual([{ name: 'topic' }])
	})

	it('shows the RAG source switches only with RAG on and binds them', async () => {
		const wrapper = await open()
		wrapper.vm.form.enableRag = false
		await flushPromises()
		expect(wrapper.findAllComponents({ name: 'NcTextField' }).filter((c) => c.attributes('data-key') === 'Number of RAG sources')).toHaveLength(0)

		const ragSwitch = wrapper.findAllComponents({ name: 'NcCheckboxRadioSwitch' }).find((c) => c.text().includes('Enable RAG') || c.text().includes('RAG'))
		await input(ragSwitch, true)
		expect(wrapper.vm.form.enableRag).toBe(true)

		await input(field(wrapper, 'NcTextField', 'Number of RAG sources'), '3')
		expect(wrapper.vm.form.ragNumSources).toBe('3')
	})

	it('binds the voice engines and the silence timeout', async () => {
		const wrapper = await open()

		const engines = field(wrapper, 'NcSelect', 'Dictation (speech to text)')
		const option = (engines.vm.$attrs.options || [])[0]
		await input(engines, option)
		expect(wrapper.vm.voiceInputEngineOption).toEqual(option)

		const voiceFields = wrapper.findAllComponents({ name: 'NcTextField' }).filter((c) => /silence/i.test(String(c.vm.$attrs.label ?? '')))
		expect(voiceFields).toHaveLength(1)
		await input(voiceFields[0], '4')
		expect(wrapper.vm.form.voiceSilenceTimeout).toBe('4')
	})

	it('shows an error and the draft findings for the schedule', async () => {
		const wrapper = await open({ draftFindings: { schedule: [{ message: 'No time given.', suggestion: 'every morning' }, { message: 'Too often.' }] } })
		wrapper.vm.error = 'The agent could not be saved.'
		await flushPromises()

		const findings = wrapper.findAll('[data-key="agent-form-finding-schedule"]').map((f) => f.text())
		expect(findings).toEqual(['No time given. Try every morning.', 'Too often.'])
		expect(wrapper.text()).toContain('The agent could not be saved.')
	})

	it('closes on Cancel and on the modal close, and saves on Save', async () => {
		const save = vi.spyOn(AgentFormModal.methods, 'save').mockResolvedValue(undefined)
		const wrapper = await open()
		const buttons = wrapper.findAllComponents({ name: 'NcButton' })

		buttons[0].vm.$emit('click')
		wrapper.findComponent({ name: 'NcModal' }).vm.$emit('close')
		await flushPromises()
		expect(wrapper.emitted('close')).toHaveLength(2)

		buttons[1].vm.$emit('click')
		await flushPromises()
		expect(save).toHaveBeenCalledTimes(1)
		save.mockRestore()
	})
})
