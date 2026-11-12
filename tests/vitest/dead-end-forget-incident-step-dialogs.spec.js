/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The flow dead-end warning, the forget-a-fact confirmation, the open
 * incident form and the step result dialog.
 */

import { shown } from '@nextcloud/dialogs'
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../../src/api/tenantOps.js', () => ({ createIncident: vi.fn() }))

const tenantOps = await import('../../src/api/tenantOps.js')
const { default: DeadEndWarningDialog } = await import('../../src/dialogs/DeadEndWarningDialog.vue')
const { default: ForgetMemoryDialog } = await import('../../src/dialogs/ForgetMemoryDialog.vue')
const { default: CreateIncidentDialog } = await import('../../src/dialogs/CreateIncidentDialog.vue')
const { default: StepResultDialog } = await import('../../src/dialogs/StepResultDialog.vue')

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
 * @return {object} The wrapper.
 */
function mountIt(component, props) {
	return mount(component, { props, global: { mocks: { t, n } } })
}

/**
 * The stand-in buttons.
 *
 * @param {object} w The wrapper.
 * @return {Array<object>} The buttons.
 */
function buttons(w) {
	return w.findAllComponents({ name: 'NcButton' })
}

beforeEach(() => {
	vi.clearAllMocks()
	shown.length = 0
})

afterEach(() => {
	vi.unstubAllGlobals()
})

describe('DeadEndWarningDialog', () => {
	it('lists the dead-end nodes and counts them', () => {
		const w = mountIt(DeadEndWarningDialog, { nodeIds: ['n-2', 'n-5'] })

		expect(w.find('nc-notecard').text()).toBe('2 nodes have nowhere to send their work.')
		expect(w.findAll('.dead-end__item').map((li) => li.text())).toEqual(['n-2', 'n-5'])
		expect(w.find('.dead-end__intro').text()).toBe('Your changes are saved. This is about what will happen when the flow RUNS.')
	})

	it('says one node in the singular, and closes from the button and the dialog', async () => {
		const w = mountIt(DeadEndWarningDialog, { nodeIds: ['n-1'] })

		expect(w.find('nc-notecard').text()).toBe('One node has nowhere to send its work.')
		await buttons(w)[0].vm.$emit('click')
		await w.findComponent({ name: 'NcDialog' }).vm.$emit('update:open', false)
		expect(w.emitted('close')).toEqual([[], []])
	})
})

describe('ForgetMemoryDialog', () => {
	it('shows the fact and confirms or cancels', async () => {
		const w = mountIt(ForgetMemoryDialog, { text: 'Prefers Dutch' })

		expect(w.find('.forget-memory__text').text()).toBe('Prefers Dutch')
		const [cancel, forget] = buttons(w)
		expect([cancel.text(), forget.text()]).toEqual(['Cancel', 'Forget'])
		await forget.vm.$emit('click')
		await cancel.vm.$emit('click')

		expect(w.emitted('confirm')).toEqual([[]])
		expect(w.emitted('close')).toEqual([[]])
	})

	it('disables both buttons while busy', () => {
		const w = mountIt(ForgetMemoryDialog, { text: 'x', busy: true })

		expect(buttons(w).map((b) => b.attributes('data-disabled'))).toEqual(['true', 'true'])
	})
})

describe('CreateIncidentDialog', () => {
	/**
	 * Open the dialog, filling the three required fields.
	 *
	 * @param {object} props Its props.
	 * @return {Promise<object>} The wrapper.
	 */
	async function openFilled(props = {}) {
		const w = mountIt(CreateIncidentDialog, { show: false, ...props })
		await w.setProps({ show: true })
		const areas = w.findAllComponents({ name: 'NcTextArea' })
		areas[0].vm.pick('Wrong answer sent')
		areas[1].vm.pick('One applicant')
		areas[2].vm.pick('Agent paused')
		await flushPromises()
		return w
	}

	it('cannot open an incident until description, impact and actions are filled in', async () => {
		const w = mountIt(CreateIncidentDialog, { show: true })
		const save = () => buttons(w)[1]
		expect(save().attributes('data-disabled')).toBe('true')

		const areas = w.findAllComponents({ name: 'NcTextArea' })
		areas[0].vm.pick('x')
		areas[1].vm.pick('  ')
		areas[2].vm.pick('z')
		await flushPromises()
		expect(save().attributes('data-disabled')).toBe('true')

		areas[1].vm.pick('y')
		await flushPromises()
		expect(save().attributes('data-disabled')).toBe('false')
	})

	it('prefills the agent and runs when it opens, and sends the cleaned run list', async () => {
		tenantOps.createIncident.mockResolvedValue({ id: 'inc-1' })
		const w = await openFilled({ defaultAgentId: 'a-1', defaultRunIds: ['r1', 'r2'] })

		const fields = w.findAllComponents({ name: 'NcTextField' })
		expect(fields.map((f) => f.attributes('data-modelvalue'))).toEqual(['a-1', 'r1, r2'])
		fields[1].vm.pick(' r1 , , r3,')
		await flushPromises()
		await buttons(w)[1].vm.$emit('click')
		await flushPromises()

		expect(tenantOps.createIncident).toHaveBeenCalledWith({
			description: 'Wrong answer sent',
			impact: 'One applicant',
			actionsTaken: 'Agent paused',
			linkedAgentId: 'a-1',
			linkedRunIds: ['r1', 'r3'],
		})
		expect(w.emitted('created')).toEqual([[{ id: 'inc-1' }]])
		expect(w.emitted('close')).toEqual([[]])
	})

	it('sends no linked agent when none is given', async () => {
		tenantOps.createIncident.mockResolvedValue({})
		const w = await openFilled()

		await buttons(w)[1].vm.$emit('click')
		await flushPromises()

		expect(tenantOps.createIncident.mock.calls[0][0]).toMatchObject({ linkedAgentId: null, linkedRunIds: [] })
	})

	it('shows why it could not be created and stays open', async () => {
		tenantOps.createIncident.mockRejectedValue({ response: { data: { error: 'Not allowed' } } })
		const w = await openFilled()

		await buttons(w)[1].vm.$emit('click')
		await flushPromises()

		const note = w.find('nc-notecard')
		expect([note.attributes('data-heading'), note.text()]).toEqual(['Could not create incident', 'Not allowed'])
		expect(w.emitted('close')).toBeUndefined()
		expect(buttons(w)[1].attributes('data-disabled')).toBe('false')
	})

	it('clears the form and the error when it opens again', async () => {
		tenantOps.createIncident.mockRejectedValue(new Error('down'))
		const w = await openFilled()
		await buttons(w)[1].vm.$emit('click')
		await flushPromises()
		expect(w.find('nc-notecard').exists()).toBe(true)

		await w.setProps({ show: false })
		await w.setProps({ show: true })

		expect(w.find('nc-notecard').exists()).toBe(false)
		expect(w.findAllComponents({ name: 'NcTextArea' }).map((a) => a.attributes('data-modelvalue'))).toEqual(['', '', ''])
	})
})

describe('StepResultDialog', () => {
	it('shows what the step produced and passed on, with its warnings', () => {
		const w = mountIt(StepResultDialog, {
			title: 'Classify',
			result: { produced: { label: 'urgent' }, passed: { id: 1, label: 'urgent' }, continue: false, error: 'Timed out' },
		})

		expect(w.findComponent({ name: 'NcDialog' }).attributes('data-name')).toBe('Step result — Classify')
		expect(w.findAll('nc-notecard').map((c) => [c.attributes('data-type'), c.text()])).toEqual([
			['warning', 'This step halted the flow — nothing after it ran.'],
			['error', 'Timed out'],
		])
		const json = w.findAll('.step-result__json').map((p) => p.text())
		expect(json.map((text) => JSON.parse(text))).toEqual([{ label: 'urgent' }, { id: 1, label: 'urgent' }])
		expect(w.findAll('.step-result__hint')[0].text()).toBe('Only what this step added to the run state. Later nodes read these values via {{key}} placeholders.')
	})

	it('says nothing was produced or passed, without warnings for a step that continued', () => {
		const w = mountIt(StepResultDialog, { result: { continue: true } })

		expect(w.findAll('nc-notecard')).toHaveLength(0)
		expect(w.findAll('.step-result__json').map((p) => p.text())).toEqual(['(nothing)', '(nothing)'])
	})

	it('shows a value that cannot be written as JSON as text, and no halt without an explicit stop', () => {
		const produced = { big: 10n }

		const w = mountIt(StepResultDialog, { result: { produced } })

		expect(w.findAll('.step-result__json')[0].text()).toBe('[object Object]')
		expect(w.findAll('nc-notecard')).toHaveLength(0)
	})

	it('copies the whole result and says so, and stays quiet when the clipboard is unavailable', async () => {
		const writeText = vi.fn(async () => {})
		vi.stubGlobal('navigator', { clipboard: { writeText } })
		const result = { produced: { a: 1 }, passed: null }
		const w = mountIt(StepResultDialog, { result })

		await buttons(w)[0].vm.$emit('click')
		await flushPromises()
		expect(writeText).toHaveBeenCalledWith(JSON.stringify(result, null, 2))
		expect(shown).toEqual([['success', 'Copied.']])

		writeText.mockRejectedValueOnce(new Error('insecure origin'))
		await buttons(w)[0].vm.$emit('click')
		await flushPromises()
		expect(shown).toHaveLength(1)

		await buttons(w)[1].vm.$emit('click')
		expect(w.emitted('close')).toEqual([[]])
	})
})
