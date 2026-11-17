/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The chat's agent picker, the start fields form's input types and problem
 * messages, and the agent form's editor for those start questions.
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'

const { default: AgentSelector } = await import('../../src/components/AgentSelector.vue')
const { default: StartFieldsForm } = await import('../../src/components/StartFieldsForm.vue')
const { default: StartFieldsEditor } = await import('../../src/components/StartFieldsEditor.vue')

/**
 * Translate as the identity, filling {placeholders}.
 *
 * @param {string} app The app id.
 * @param {string} text The text.
 * @param {object} vars The placeholders.
 * @return {string} The filled text.
 */
const t = (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m))

/**
 * Pluralise as English, filling %n.
 *
 * @param {string} app The app id.
 * @param {string} one The singular.
 * @param {string} many The plural.
 * @param {number} count The count.
 * @return {string} The text.
 */
const n = (app, one, many, count) => (count === 1 ? one : many).replace('%n', String(count))

/**
 * Mount with the translation helpers.
 *
 * @param {object} component The component.
 * @param {object} props Its props.
 * @param {object} [mocks] Extra instance mocks.
 * @return {object} The wrapper.
 */
function mountIt(component, props, mocks = {}) {
	return mount(component, { props, global: { mocks: { t, n, ...mocks } } })
}

describe('AgentSelector', () => {
	it('shows only the loading state while loading', () => {
		const w = mountIt(AgentSelector, { loading: true, error: 'ignored', agents: [{ id: 'a1' }] })

		expect(w.find('.agent-selector__state p').text()).toBe('Loading agents…')
		expect(w.find('nc-notecard').exists()).toBe(false)
		expect(w.find('.agent-selector__grid').exists()).toBe(false)
	})

	it('shows the error before the agents', () => {
		const w = mountIt(AgentSelector, { error: 'Could not load', agents: [{ id: 'a1' }] })

		const note = w.find('nc-notecard')
		expect([note.attributes('data-type'), note.text()]).toEqual(['error', 'Could not load'])
		expect(w.find('.agent-selector__grid').exists()).toBe(false)
	})

	it('sends a person without agents to the agents page', async () => {
		const router = { push: vi.fn() }
		const w = mountIt(AgentSelector, { agents: [] }, { $router: router })

		const empty = w.find('nc-emptycontent')
		expect(empty.attributes('data-name')).toBe('No agents available')
		expect(empty.attributes('data-description')).toBe('You need an agent to start a session. Create one in the Agents page.')
		await w.findComponent({ name: 'NcButton' }).vm.$emit('click')
		expect(router.push).toHaveBeenCalledWith('/agents')
	})

	it('draws a card per agent with its model and tools, and starts the picked one', async () => {
		const agents = [
			{ id: 'a1', name: 'Intake', description: 'Answers intake', model: 'mistral-small', tools: ['mail'] },
			{ uuid: 'a2', name: '', tools: ['mail', 'files'] },
			{ id: 'a3', name: 'Plain', model: 'llama' },
			{ id: 'a4', name: 'Bare' },
		]
		const w = mountIt(AgentSelector, { agents })

		const cards = w.findAll('.agent-selector__card')
		expect(cards.map((card) => card.find('h3').text())).toEqual(['Intake', 'Untitled agent', 'Plain', 'Bare'])
		expect(cards[0].find('.agent-selector__title p').text()).toBe('Answers intake')
		expect(cards[1].find('.agent-selector__title p').exists()).toBe(false)
		expect(cards.map((card) => card.findAll('.agent-selector__chip').map((chip) => chip.text()))).toEqual([
			['mistral-small', '1 tool'],
			['2 tools'],
			['llama', 'All tools'],
			[],
		])

		await cards[1].findComponent({ name: 'NcButton' }).vm.$emit('click')
		expect(w.emitted('start')).toEqual([[agents[1]]])
	})

	it('disables every start button while one session starts, spinning only on that agent', () => {
		const w = mountIt(AgentSelector, { agents: [{ id: 'a1' }, { uuid: 'a2' }], startingId: 'a2' })

		const buttons = w.findAllComponents({ name: 'NcButton' })
		expect(buttons.map((b) => b.attributes('data-disabled'))).toEqual(['true', 'true'])
		expect(buttons.map((b) => b.findComponent({ name: 'NcLoadingIcon' }).exists())).toEqual([false, true])
	})
})

describe('StartFieldsForm', () => {
	it('uses a number or date input for those types and text for anything else', () => {
		const w = mountIt(StartFieldsForm, {
			fields: [
				{ key: 'age', label: 'Age', type: 'number' },
				{ key: 'day', label: 'Day', type: 'date' },
				{ key: 'mail', label: 'Mail', type: 'email' },
			],
			modelValue: { age: '42' },
		})

		const inputs = w.findAll('nc-textfield')
		expect(inputs.map((i) => i.attributes('data-type'))).toEqual(['number', 'date', 'text'])
		expect(inputs.map((i) => i.attributes('data-modelvalue'))).toEqual(['42', '', ''])
	})

	it.each([
		['required', 'Fill this in to start.'],
		['not one of the options', 'Pick one of the options.'],
		['not a number', 'Enter a number.'],
		['not a date (YYYY-MM-DD)', 'Enter a date.'],
		['too long', 'too long'],
	])('says what is wrong for %s', (reason, message) => {
		const w = mountIt(StartFieldsForm, {
			fields: [{ key: 'name', label: 'Name', type: 'text' }, { key: 'other', label: 'Other', type: 'text' }],
			modelValue: {},
			problems: { name: reason },
		})

		const alerts = w.findAll('[role="alert"]')
		expect(alerts.map((a) => a.text())).toEqual([message])
		expect(w.findAll('nc-textfield').map((i) => i.attributes('data-error'))).toEqual(['true', 'false'])
	})

	it('offers a clearable choice only for an optional field, and stores a cleared choice as empty', async () => {
		const w = mountIt(StartFieldsForm, {
			fields: [
				{ key: 'kind', label: 'Kind', type: 'select', options: ['a', 'b'] },
				{ key: 'size', label: 'Size', type: 'select', options: ['s'], required: true },
			],
			modelValue: { kind: 'a' },
		})

		const selects = w.findAllComponents({ name: 'NcSelect' })
		expect(selects.map((s) => s.attributes('data-clearable'))).toEqual(['true', 'false'])
		expect(selects.map((s) => s.attributes('data-inputlabel'))).toEqual(['Kind', 'Size (required)'])
		expect(selects[1].attributes('data-modelvalue')).toBe('null')

		selects[0].vm.pick(null)
		selects[1].vm.pick(3)
		expect(w.emitted('update:modelValue')).toEqual([[{ kind: '' }], [{ kind: 'a', size: '3' }]])
	})
})

describe('StartFieldsEditor', () => {
	const question = { key: 'topic', label: 'Topic?', type: 'select', options: ['a', 'b'], required: true, default: 'a' }

	it('explains the placeholder and shows each question with its token', () => {
		const w = mountIt(StartFieldsEditor, { modelValue: [question] })

		expect(w.find('.start-fields-editor__hint').text()).toBe('A person answers these before their first message. Use an answer in the instructions as {{field.key}}.')
		const fields = w.findAll('nc-textfield')
		expect(fields.map((f) => f.attributes('data-label'))).toEqual(['Question', 'Placeholder name', 'Options, separated by commas', 'Default answer'])
		expect(fields[1].attributes('data-helpertext')).toBe('{{field.topic}}')
		expect(fields[1].attributes('data-error')).toBe('false')
		expect(fields[2].attributes('data-modelvalue')).toBe('a, b')
		expect(JSON.parse(w.findComponent({ name: 'NcSelect' }).attributes('data-modelvalue'))).toEqual({ label: 'Choice', value: 'select' })
		expect(w.findComponent({ name: 'NcCheckboxRadioSwitch' }).attributes('data-modelvalue')).toBe('true')
	})

	it('flags a placeholder name that is not lower case, and hides options for other types', () => {
		const w = mountIt(StartFieldsEditor, { modelValue: [{ key: 'Topic', label: '', type: 'unknown' }] })

		const fields = w.findAll('nc-textfield')
		expect(fields).toHaveLength(3)
		expect(fields[1].attributes('data-error')).toBe('true')
		expect(fields[1].attributes('data-helpertext')).toBe('Lower case letters, digits and _, starting with a letter.')
		expect(JSON.parse(w.findComponent({ name: 'NcSelect' }).attributes('data-modelvalue')).value).toBe('text')
	})

	it('reports each change as a new list, leaving the given one alone', () => {
		const given = [question, { key: 'b', label: 'B', type: 'text' }]
		const w = mountIt(StartFieldsEditor, { modelValue: given })
		const fields = w.findAll('nc-textfield')

		w.findAllComponents({ name: 'NcTextField' })[0].vm.pick('New topic?')
		w.findAllComponents({ name: 'NcTextField' })[2].vm.pick(' x, , y ,')
		w.findAllComponents({ name: 'NcSelect' })[0].vm.pick(null)
		w.findAllComponents({ name: 'NcCheckboxRadioSwitch' })[1].vm.$emit('update:modelValue', true)

		const emitted = w.emitted('update:modelValue').map(([list]) => list)
		expect(emitted[0][0].label).toBe('New topic?')
		expect(emitted[1][0].options).toEqual(['x', 'y'])
		expect(emitted[2][0].type).toBe('text')
		expect(emitted[3][1]).toEqual({ key: 'b', label: 'B', type: 'text', required: true })
		expect(emitted[0][1]).toEqual(given[1])
		expect(given[0].label).toBe('Topic?')
		expect(fields).toHaveLength(7)
	})

	it('adds a numbered empty question and removes the chosen one', async () => {
		const w = mountIt(StartFieldsEditor, { modelValue: [question, { key: 'b', label: 'B', type: 'text' }] })
		const buttons = w.findAllComponents({ name: 'NcButton' })
		expect(buttons.map((b) => b.text())).toEqual(['Remove question', 'Remove question', 'Add a question'])

		await buttons[2].vm.$emit('click')
		await buttons[0].vm.$emit('click')

		const [[added], [removed]] = w.emitted('update:modelValue')
		expect(added[2]).toEqual({ key: 'field_3', label: '', type: 'text', options: [], required: false, default: '' })
		expect(removed.map((f) => f.key)).toEqual(['b'])
	})

	it('stops adding at ten questions', () => {
		const ten = Array.from({ length: 10 }, (v, i) => ({ key: `q${i}`, label: '', type: 'text' }))

		const full = mountIt(StartFieldsEditor, { modelValue: ten })
		const room = mountIt(StartFieldsEditor, { modelValue: ten.slice(0, 9) })

		expect(full.findAllComponents({ name: 'NcButton' }).at(-1).attributes('data-disabled')).toBe('true')
		expect(room.findAllComponents({ name: 'NcButton' }).at(-1).attributes('data-disabled')).toBe('false')
	})
})
