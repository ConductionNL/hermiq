/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The custom tool editor (tools-custom-tools-without-code): the input builder
 * turns rows into an input schema and back, "Try it" sends typed sample inputs,
 * the payload carries the chosen target, and every select has a label.
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { inputSchemaFrom, inputsFrom, sampleArguments } from '../../src/api/customTools.js'

const api = vi.hoisted(() => ({ createCustomTool: vi.fn(), updateCustomTool: vi.fn(), tryCustomTool: vi.fn() }))
vi.mock('../../src/api/customTools.js', async (importOriginal) => ({ ...(await importOriginal()), ...api }))
vi.mock('../../src/api/flowRuns.js', () => ({
	listFlows: vi.fn(async () => [{ uuid: 'flow-42', name: 'Vergunningcheck' }]),
}))

const { default: CustomToolFormModal } = await import('../../src/modals/CustomToolFormModal.vue')

const t = (app, text) => text

const STORED = {
	id: 'tool-1',
	slug: 'vergunningcheck',
	name: 'Vergunningcheck',
	description: 'Checks whether an activity at an address needs a permit, and names the rule.',
	inputSchema: {
		type: 'object',
		properties: {
			adres: { type: 'string', description: 'The address' },
			activiteit: { type: 'string', enum: ['terras', 'evenement', 'kap'] },
			nummer: { type: 'integer' },
		},
		required: ['adres'],
	},
	changesData: 'no',
	target: { type: 'flow', flowId: 'flow-42' },
	enabled: true,
}

/**
 * Mount the editor open.
 *
 * @param {object|null} item The edited tool, or null to add one.
 * @return {Promise<object>} The wrapper.
 */
async function open(item) {
	const w = mount(CustomToolFormModal, { props: { show: true, item }, global: { mocks: { t, n: (app, s, p, c) => (c === 1 ? s : p) } } })
	await flushPromises()
	return w
}

beforeEach(() => {
	api.createCustomTool.mockReset()
	api.updateCustomTool.mockReset()
	api.tryCustomTool.mockReset()
})

describe('custom tool input rows', () => {
	it('turn into an input schema with required names and string choices', () => {
		expect(inputSchemaFrom([
			{ name: ' adres ', type: 'string', required: true, description: 'The address', enumText: '' },
			{ name: 'activiteit', type: 'string', required: false, description: '', enumText: 'terras, evenement,,kap' },
			{ name: 'nummer', type: 'integer', required: false, description: '', enumText: 'ignored' },
			{ name: '', type: 'string', required: true, description: '', enumText: '' },
		])).toEqual({
			type: 'object',
			properties: {
				adres: { type: 'string', description: 'The address' },
				activiteit: { type: 'string', enum: ['terras', 'evenement', 'kap'] },
				nummer: { type: 'integer' },
			},
			required: ['adres'],
		})
	})

	it('come back from a stored schema unchanged', () => {
		expect(inputSchemaFrom(inputsFrom(STORED.inputSchema))).toEqual(STORED.inputSchema)
	})

	it('give typed sample arguments and leave empty samples out', () => {
		const inputs = inputsFrom(STORED.inputSchema).concat([{ name: 'open', type: 'boolean' }, { name: 'factor', type: 'number' }])
		expect(sampleArguments(inputs, { adres: ' Markt 3 ', activiteit: '', nummer: '12', open: 'true', factor: '1.5' }))
			.toEqual({ adres: 'Markt 3', nummer: 12, open: true, factor: 1.5 })
	})
})

describe('CustomToolFormModal', () => {
	it('labels every select', async () => {
		const w = await open(STORED)
		const selects = w.findAllComponents({ name: 'NcSelect' })
		expect(selects.length).toBeGreaterThan(0)
		for (const select of selects) {
			expect(select.attributes('data-inputlabel')).toBeTruthy()
		}
	})

	it('adds a new tool with an integriq target', async () => {
		api.createCustomTool.mockResolvedValue({ id: 'new' })
		const w = await open(null)
		w.vm.form.name = 'Adres opzoeken in BAG'
		w.vm.form.slug = 'bag_adres'
		w.vm.form.description = 'Finds an address in the BAG.'
		w.vm.addInput()
		w.vm.form.inputs[0].name = 'postcode'
		w.vm.form.inputs[0].required = true
		w.vm.form.targetType = 'integriq'
		w.vm.form.source = 'bag-api'
		w.vm.form.path = '/adressen?postcode={{input.postcode}}'

		await w.vm.save()

		expect(api.createCustomTool).toHaveBeenCalledWith({
			name: 'Adres opzoeken in BAG',
			slug: 'bag_adres',
			description: 'Finds an address in the BAG.',
			inputSchema: { type: 'object', properties: { postcode: { type: 'string' } }, required: ['postcode'] },
			changesData: 'no',
			target: { type: 'integriq', source: 'bag-api', method: 'GET', path: '/adressen?postcode={{input.postcode}}', bodyTemplate: '' },
			enabled: true,
		})
		expect(api.updateCustomTool).not.toHaveBeenCalled()
		expect(w.emitted('saved')).toEqual([[{ id: 'new' }]])
	})

	it('saves an edit to the stored tool', async () => {
		api.updateCustomTool.mockResolvedValue({ id: 'tool-1' })
		const w = await open(STORED)
		w.vm.form.changesData = 'yes'

		await w.vm.save()

		expect(api.updateCustomTool).toHaveBeenCalledWith('tool-1', expect.objectContaining({
			changesData: 'yes',
			target: { type: 'flow', flowId: 'flow-42' },
			inputSchema: STORED.inputSchema,
		}))
	})

	it('shows the server refusal and stays open', async () => {
		api.createCustomTool.mockRejectedValue({ response: { data: { error: 'slug: another custom tool already uses bag_adres' } } })
		const w = await open(null)
		w.vm.form.name = 'x'

		await w.vm.save()

		expect(w.vm.error).toBe('slug: another custom tool already uses bag_adres')
		expect(w.emitted('close')).toBeUndefined()
	})

	it('tries a saved tool with typed sample inputs and shows the answer', async () => {
		api.tryCustomTool.mockResolvedValue({ result: { runUuid: 'run-1' } })
		const w = await open(STORED)
		w.vm.samples = { adres: 'Markt 3', nummer: '3' }

		await w.vm.tryIt()

		expect(api.tryCustomTool).toHaveBeenCalledWith('tool-1', { adres: 'Markt 3', nummer: 3 })
		expect(JSON.parse(w.vm.answer)).toEqual({ result: { runUuid: 'run-1' } })
		expect(w.text()).toContain('This really runs the flow or calls the system.')
	})

	it('offers no Try it before the tool is saved', async () => {
		const w = await open(null)
		expect(w.text()).not.toContain('This really runs the flow or calls the system.')
	})
})
