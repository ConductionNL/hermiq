/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The chat settings, context and goal modals driven through their rendered
 * controls rather than their methods: every field, switch and button the
 * template wires is exercised, so a binding that stops writing back fails here.
 *
 * Mounted with the shared @nextcloud/vue stand-ins (tests/vitest/stubs), whose
 * `pick(value)` emits `update:modelValue`; the store and the chat API are doubled.
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@conduction/nextcloud-vue', () => ({
	CnMarkdownEditor: { name: 'CnMarkdownEditor', props: ['value'], emits: ['input'], render: () => null },
}))
const contextStore = { registerObjectType: vi.fn(), saveObject: vi.fn() }
vi.mock('../../src/store/store.js', () => ({ useContextStore: () => contextStore }))
vi.mock('../../src/api/chat.js', () => ({ setSessionGoal: vi.fn() }))

const chat = await import('../../src/api/chat.js')
const { default: ChatSettingsModal } = await import('../../src/modals/ChatSettingsModal.vue')
const { default: ContextFormModal } = await import('../../src/modals/ContextFormModal.vue')
const { default: GoalFormModal } = await import('../../src/modals/GoalFormModal.vue')

/**
 * Translate as the identity, filling {placeholders}.
 *
 * @param {string} app The app id.
 * @param {string} text The text.
 * @param {object} vars The placeholders.
 * @return {string} The filled text.
 */
const t = (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m))
const n = (app, one, many, count) => (count === 1 ? one : many).replace('%n', String(count))
globalThis.t = t

/**
 * Mount a component with the stand-ins rendered.
 *
 * @param {object} component The component.
 * @param {object} props Its props.
 * @return {object} The wrapper.
 */
function mountIt(component, props) {
	return mount(component, { props, global: { mocks: { t, n } } })
}

/**
 * The stand-ins of one kind.
 *
 * @param {object} w The wrapper.
 * @param {string} name The component name.
 * @return {Array<object>} The stand-ins.
 */
function all(w, name) {
	return w.findAllComponents({ name })
}

/**
 * The stand-in of a kind carrying an attribute value.
 *
 * @param {object} w The wrapper.
 * @param {string} name The component name.
 * @param {string} attr The attribute.
 * @param {string} value Its value.
 * @return {object} The stand-in.
 */
function by(w, name, attr, value) {
	const found = all(w, name).find((c) => c.vm.$attrs[attr] === value)
	if (!found) {
		throw new Error(`no ${name} with ${attr}=${value}`)
	}
	return found
}

/**
 * Click the button whose text is given.
 *
 * @param {object} w The wrapper.
 * @param {string} text The button text.
 * @return {Promise<void>}
 */
async function click(w, text) {
	const button = all(w, 'NcButton').find((b) => b.text().trim() === text)
	if (!button) {
		throw new Error(`no button ${text}`)
	}
	button.vm.$emit('click')
	await flushPromises()
}

beforeEach(() => {
	vi.clearAllMocks()
})

describe('ChatSettingsModal controls', () => {
	const value = { views: ['v1'], tools: [], includeObjects: true, includeFiles: false, numSourcesObjects: 5, numSourcesFiles: 5 }
	const props = {
		show: true,
		value,
		availableViews: [{ uuid: 'v1', name: 'Cases' }, { uuid: 'v2', name: 'Permits' }],
		availableTools: [{ uuid: 't1', name: 'Search' }],
	}

	it('toggles a view or tool off and on through its switch', async () => {
		const w = mountIt(ChatSettingsModal, props)
		const switches = all(w, 'NcCheckboxRadioSwitch')

		switches.find((s) => s.text() === 'Cases').vm.pick(false)
		switches.find((s) => s.text() === 'Permits').vm.pick(true)
		switches.find((s) => s.text() === 'Search').vm.pick(true)

		expect(w.emitted('input').map(([v]) => [v.views, v.tools])).toEqual([[[], []], [['v1', 'v2'], []], [['v1'], ['t1']]])
	})

	it('writes both search switches and clamps both source counts', async () => {
		const w = mountIt(ChatSettingsModal, props)
		const switches = all(w, 'NcCheckboxRadioSwitch')

		switches.find((s) => s.text() === 'Search in objects').vm.pick(false)
		switches.find((s) => s.text() === 'Search in files').vm.pick(true)
		by(w, 'NcTextField', 'label', 'Object sources').vm.pick('40')
		by(w, 'NcTextField', 'label', 'File sources').vm.pick('0')
		by(w, 'NcTextField', 'label', 'File sources').vm.pick('abc')

		const emitted = w.emitted('input').map(([v]) => v)
		expect(emitted).toHaveLength(4)
		expect([emitted[0].includeObjects, emitted[1].includeFiles, emitted[2].numSourcesObjects, emitted[3].numSourcesFiles]).toEqual([false, true, 20, 1])
	})

	it('closes from the button and the modal, and hides empty sections', async () => {
		const w = mountIt(ChatSettingsModal, { show: true, value })
		expect(w.text()).not.toContain('Views')

		await click(w, 'Close')
		w.findComponent({ name: 'NcModal' }).vm.$emit('close')

		expect(w.emitted('close')).toHaveLength(2)
	})
})

describe('ContextFormModal controls', () => {
	const item = { id: 'c1', name: 'Ctx', documents: [{ name: 'Doc', body: 'B' }], files: [{ path: '/a.pdf' }], objectQueries: [1] }

	it('writes every field back and adds and removes documents and files', async () => {
		const w = mountIt(ContextFormModal, { show: true, item })

		by(w, 'NcTextField', 'label', 'Name').vm.pick('Renamed')
		by(w, 'NcTextArea', 'label', 'Description').vm.pick('What it holds')
		by(w, 'NcTextField', 'label', 'Document name').vm.pick('Standard')
		w.findComponent({ name: 'CnMarkdownEditor' }).vm.$emit('input', '# Rules')
		by(w, 'NcTextField', 'label', 'Path in the user\'s files').vm.pick('/b.pdf')
		await click(w, 'Add document')
		await click(w, 'Add file')

		expect(w.vm.form.name).toBe('Renamed')
		expect(w.vm.form.description).toBe('What it holds')
		expect(w.vm.form.documents.map((d) => [d.name, d.body])).toEqual([['Standard', '# Rules'], ['', '']])
		expect(w.vm.form.files.map((f) => f.path)).toEqual(['/b.pdf', ''])
		expect(w.text()).toContain('This context also reads 1 live data query, which stays as it is.')

		await click(w, 'Remove document')
		await click(w, 'Remove file')
		expect(w.vm.form.documents.map((d) => d.name)).toEqual([''])
		expect(w.vm.form.files.map((f) => f.path)).toEqual([''])
	})

	it('saves from the Save button and closes from Cancel and the modal', async () => {
		contextStore.saveObject.mockResolvedValue({ id: 'c1' })
		const w = mountIt(ContextFormModal, { show: true, item })

		await click(w, 'Save')
		expect(contextStore.saveObject).toHaveBeenCalledWith('context', expect.objectContaining({ name: 'Ctx' }))
		expect(w.emitted('saved')).toEqual([[{ id: 'c1' }]])

		await click(w, 'Cancel')
		w.findComponent({ name: 'NcModal' }).vm.$emit('close')
		expect(w.emitted('close')).toHaveLength(3)
	})

	it('shows a refused save in the note card', async () => {
		contextStore.saveObject.mockRejectedValue(new Error('Name taken'))
		const w = mountIt(ContextFormModal, { show: true, item })

		await click(w, 'Save')

		expect(by(w, 'NcNoteCard', 'type', 'error').text()).toBe('Name taken')
	})
})

describe('GoalFormModal controls', () => {
	const session = { uuid: 's1' }

	it('writes a count goal from its fields and saves it', async () => {
		chat.setSessionGoal.mockResolvedValue({ id: 'g1' })
		const w = mountIt(GoalFormModal, { show: true, session })

		by(w, 'NcTextArea', 'data-testid', 'goal-statement').vm.pick(' Every reminder sent ')
		by(w, 'NcTextField', 'data-testid', 'goal-register').vm.pick('permits')
		by(w, 'NcTextField', 'data-testid', 'goal-schema').vm.pick('application')
		by(w, 'NcTextArea', 'data-testid', 'goal-filters').vm.pick('{"status":"overdue"}')
		by(w, 'NcTextField', 'data-testid', 'goal-target').vm.pick('0')
		by(w, 'NcTextField', 'data-testid', 'goal-interval').vm.pick('30')
		by(w, 'NcTextField', 'data-testid', 'goal-max-turns').vm.pick('5')
		await flushPromises()
		await click(w, by(w, 'NcButton', 'data-testid', 'goal-save').text().trim())

		expect(chat.setSessionGoal).toHaveBeenCalledWith('s1', {
			statement: 'Every reminder sent',
			check: { kind: 'objectCount', register: 'permits', schema: 'application', filters: { status: 'overdue' }, target: 0 },
			intervalMinutes: 30,
			maxTurns: 5,
		})
		expect(w.emitted('saved')).toEqual([[{ id: 'g1' }]])
	})

	it('switches to a judged goal, which asks its question instead of the count fields', async () => {
		chat.setSessionGoal.mockResolvedValue({ id: 'g2' })
		const w = mountIt(GoalFormModal, { show: true, session })

		by(w, 'NcTextArea', 'data-testid', 'goal-statement').vm.pick('Answer is complete')
		by(w, 'NcCheckboxRadioSwitch', 'data-testid', 'goal-kind-judge').vm.pick('judge')
		await flushPromises()
		expect(all(w, 'NcTextField').some((c) => c.vm.$attrs['data-testid'] === 'goal-register')).toBe(false)
		by(w, 'NcTextArea', 'data-testid', 'goal-question').vm.pick('Is every case answered?')
		await flushPromises()
		await click(w, by(w, 'NcButton', 'data-testid', 'goal-save').text().trim())

		expect(chat.setSessionGoal.mock.calls[0][1].check).toEqual({ kind: 'judge', question: 'Is every case answered?' })
	})

	it('refuses filters that are not JSON, and closes from Cancel and the modal', async () => {
		const w = mountIt(GoalFormModal, { show: true, session })
		by(w, 'NcTextArea', 'data-testid', 'goal-statement').vm.pick('Goal')
		by(w, 'NcTextField', 'data-testid', 'goal-register').vm.pick('r')
		by(w, 'NcTextField', 'data-testid', 'goal-schema').vm.pick('s')
		by(w, 'NcTextArea', 'data-testid', 'goal-filters').vm.pick('{status')
		await flushPromises()

		await click(w, by(w, 'NcButton', 'data-testid', 'goal-save').text().trim())
		expect(chat.setSessionGoal).not.toHaveBeenCalled()
		expect(by(w, 'NcNoteCard', 'type', 'error').text()).toBe('The filters are not valid JSON.')

		await click(w, 'Cancel')
		w.findComponent({ name: 'NcModal' }).vm.$emit('close')
		expect(w.emitted('close')).toHaveLength(2)
	})
})
