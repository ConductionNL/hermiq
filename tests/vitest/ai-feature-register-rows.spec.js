/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The AI feature register as rendered: each table cell's label, the DPO
 * acknowledgement text, and per-row actions — which are enabled, which
 * Algoritmeregister action shows for an admin with OpenCatalogi, and what a
 * click calls. The table renders its column and row-action slots per row; the
 * API is doubled.
 */

import { state } from '@nextcloud/initial-state'
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

vi.mock('@conduction/nextcloud-vue', async () => {
	const { h: render } = await import('vue')
	return {
		CnDataTable: {
			name: 'CnDataTable',
			props: { columns: Array, rows: Array },
			setup(props, { slots }) {
				return () => render('table', props.rows.map((row) => render('tr', { 'data-row': row.id }, [
					...props.columns.map((column) => render(
						'td',
						{ 'data-column': column.key },
						slots['column-' + column.key] ? slots['column-' + column.key]({ row }) : String(row[column.key]),
					)),
					render('td', { 'data-column': 'actions' }, slots['row-actions']({ row })),
				])))
			},
		},
	}
})
vi.mock('../../src/api/aiFeatures.js', () => ({
	acknowledgeAiFeature: vi.fn(),
	disableAiFeature: vi.fn(),
	enableAiFeature: vi.fn(),
	listAiFeatures: vi.fn(),
	listFeatureResidency: vi.fn(),
	publishAiFeature: vi.fn(),
	withdrawAiFeature: vi.fn(),
}))

const api = await import('../../src/api/aiFeatures.js')
const { default: AiFeatureRegister } = await import('../../src/views/AiFeatureRegister.vue')

/**
 * Translate as the identity, filling {placeholders}.
 *
 * @param {string} app The app id.
 * @param {string} text The text.
 * @param {object} vars The placeholders.
 * @return {string} The filled text.
 */
const t = (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m))

const READY = {
	uuid: 'f1', slug: 'summary', name: 'Summary', riskCategory: 'high', lifecycle: 'enabled', dpoAckAt: '2026-01-01', dpoAckBy: 'dpo-anna',
	doel: 'x', statutoryBasis: 'x', impactAssessments: ['dpia'], dataBronnen: ['x'], humanIntervention: 'x', responsible: 'x', publicatiecategorie: 'x',
}
const PUBLISHED = { ...READY, uuid: 'f2', slug: 'published', name: 'Published one', algoritmeregisterStatus: 'gepubliceerd', dpoAckBy: '' }
const BARE = { slug: 'bare' }

/**
 * Mount the register with the given features and capability flags.
 *
 * @param {object} options The options.
 * @param {Array} options.features The features the API lists.
 * @param {boolean} options.admin Whether the viewer is an admin.
 * @param {boolean} options.catalogi Whether OpenCatalogi is available.
 * @return {Promise<object>} The wrapper.
 */
async function mountRegister({ features = [READY, PUBLISHED, BARE], admin = true, catalogi = true } = {}) {
	state.is_admin = admin
	state.opencatalogi_available = catalogi
	api.listAiFeatures.mockResolvedValue(features)
	api.listFeatureResidency.mockResolvedValue([
		{ slug: 'summary', provider: 'mistral', model: 'large', residency: 'eu', location: 'Paris', source: 'binding' },
	])
	const w = mount(AiFeatureRegister, {
		global: {
			mocks: { t },
			stubs: {
				AiIcon: true,
				AiFeatureBindingModal: { name: 'AiFeatureBindingModal', props: ['featureId', 'featureName', 'current'], render: () => h('binding-modal') },
			},
		},
	})
	await flushPromises()
	return w
}

/**
 * The cell of one row and column.
 *
 * @param {object} w The wrapper.
 * @param {string} row The row id.
 * @param {string} column The column key.
 * @return {object} The cell wrapper.
 */
const cell = (w, row, column) => w.find(`tr[data-row="${row}"] td[data-column="${column}"]`)

/**
 * The action buttons of one row, keyed by their label.
 *
 * @param {object} w The wrapper.
 * @param {string} row The row id.
 * @return {Object<string, object>} The buttons.
 */
function buttons(w, row) {
	const byLabel = {}
	for (const button of cell(w, row, 'actions').findAllComponents({ name: 'NcButton' })) {
		byLabel[button.text()] = button
	}
	return byLabel
}

const disabled = (button) => button.attributes('data-disabled') === 'true'

beforeEach(() => {
	vi.clearAllMocks()
	for (const key of Object.keys(state)) {
		delete state[key]
	}
})

describe('AiFeatureRegister rows', () => {
	it('labels the risk, state, acknowledgement, provider and register cells', async () => {
		const w = await mountRegister()

		expect(cell(w, 'f1', 'riskCategory').text()).toBe('High')
		expect(cell(w, 'f1', 'riskCategory').find('span').classes()).toContain('ai-feature-register__risk--high')
		expect(cell(w, 'f1', 'lifecycle').text()).toBe('Enabled')
		expect(cell(w, 'f1', 'acknowledged').text()).toBe('Acknowledged by dpo-anna')
		expect(cell(w, 'f1', 'runsOn').findAll('span').map((s) => s.text())).toEqual(['mistral · large', 'In the EU', 'Paris'])
		expect(cell(w, 'f1', 'algoritmeregister').text()).toBe('Not published')

		expect(cell(w, 'f2', 'acknowledged').text()).toBe('Acknowledged by DPO')
		expect(cell(w, 'f2', 'algoritmeregister').text()).toBe('Published')

		expect(cell(w, 'bare', 'riskCategory').text()).toBe('Minimal')
		expect(cell(w, 'bare', 'lifecycle').text()).toBe('Disabled')
		expect(cell(w, 'bare', 'acknowledged').text()).toBe('Not acknowledged')
		expect(cell(w, 'bare', 'acknowledged').find('span').classes()).toContain('ai-feature-register__ack--missing')
		expect(cell(w, 'bare', 'runsOn').findAll('span').map((s) => s.text())).toEqual(['The instance default', 'Not stated'])
		expect(cell(w, 'bare', 'runsOn').find('.ai-feature-register__location').exists()).toBe(false)
	})

	it('enables only the lifecycle actions a row allows', async () => {
		const w = await mountRegister()

		const ready = buttons(w, 'f1')
		expect(disabled(ready['Acknowledge (DPO)'])).toBe(true)
		expect(disabled(ready.Enable)).toBe(true)
		expect(disabled(ready.Disable)).toBe(false)
		expect(disabled(ready['Change provider'])).toBe(false)

		const bare = buttons(w, 'bare')
		expect(disabled(bare['Acknowledge (DPO)'])).toBe(false)
		// Not acknowledged yet, so it cannot be enabled.
		expect(disabled(bare.Enable)).toBe(true)
		expect(disabled(bare.Disable)).toBe(true)
	})

	it('offers publish to an unpublished row and withdraw to a published one', async () => {
		const w = await mountRegister()

		const ready = buttons(w, 'f1')
		expect(Object.keys(ready)).toContain('Publish to Algoritmeregister')
		expect(Object.keys(ready)).not.toContain('Withdraw from Algoritmeregister')
		expect(disabled(ready['Publish to Algoritmeregister'])).toBe(false)
		expect(ready['Publish to Algoritmeregister'].attributes('data-title')).toBe('')

		const published = buttons(w, 'f2')
		expect(Object.keys(published)).toContain('Withdraw from Algoritmeregister')
		expect(Object.keys(published)).not.toContain('Publish to Algoritmeregister')

		const bare = buttons(w, 'bare')
		expect(disabled(bare['Publish to Algoritmeregister'])).toBe(true)
		expect(bare['Publish to Algoritmeregister'].attributes('data-title'))
			.toMatch(/^Cannot publish yet — missing: risk category must be high, feature must be enabled, DPO acknowledgement, doel/)
	})

	it('hides the register actions without admin rights or without OpenCatalogi', async () => {
		for (const flags of [{ admin: false, catalogi: true }, { admin: true, catalogi: false }]) {
			const w = await mountRegister(flags)
			const labels = [...Object.keys(buttons(w, 'f1')), ...Object.keys(buttons(w, 'f2'))]
			expect(labels).not.toContain('Publish to Algoritmeregister')
			expect(labels).not.toContain('Withdraw from Algoritmeregister')
			expect(labels).toContain('Enable')
			w.unmount()
		}
	})

	it('wires each row action to its feature', async () => {
		api.acknowledgeAiFeature.mockResolvedValue({})
		api.disableAiFeature.mockResolvedValue({})
		api.publishAiFeature.mockResolvedValue({})
		api.withdrawAiFeature.mockResolvedValue({})
		const w = await mountRegister()

		await buttons(w, 'bare')['Acknowledge (DPO)'].vm.$emit('click')
		await flushPromises()
		expect(api.acknowledgeAiFeature).toHaveBeenCalledTimes(1)

		await buttons(w, 'f1').Disable.vm.$emit('click')
		await flushPromises()
		expect(api.disableAiFeature).toHaveBeenCalledWith('f1')

		await buttons(w, 'f1')['Publish to Algoritmeregister'].vm.$emit('click')
		await flushPromises()
		expect(api.publishAiFeature).toHaveBeenCalledWith('f1')

		await buttons(w, 'f2')['Withdraw from Algoritmeregister'].vm.$emit('click')
		await flushPromises()
		expect(api.withdrawAiFeature).toHaveBeenCalledWith('f2')
	})

	it('opens the provider binding for a row with its current binding, and closes it', async () => {
		const w = await mountRegister()
		expect(w.findComponent({ name: 'AiFeatureBindingModal' }).exists()).toBe(false)

		await buttons(w, 'f1')['Change provider'].vm.$emit('click')
		const modal = w.findComponent({ name: 'AiFeatureBindingModal' })
		expect(modal.props()).toEqual({
			featureId: 'f1',
			featureName: 'Summary',
			current: { provider: '', model: '', requiredResidency: '' },
		})

		await modal.vm.$emit('close')
		expect(w.findComponent({ name: 'AiFeatureBindingModal' }).exists()).toBe(false)
	})

	it('shows the empty state and no table when there are no features', async () => {
		const w = await mountRegister({ features: [] })

		expect(w.findComponent({ name: 'NcEmptyContent' }).exists()).toBe(true)
		expect(w.find('table').exists()).toBe(false)
	})

	it('shows a load failure in an error card', async () => {
		api.listAiFeatures.mockRejectedValueOnce(new Error('Register down'))
		state.is_admin = true
		api.listFeatureResidency.mockResolvedValue([])
		const w = mount(AiFeatureRegister, { global: { mocks: { t }, stubs: { AiIcon: true, AiFeatureBindingModal: true } } })
		await flushPromises()

		const card = w.findComponent({ name: 'NcNoteCard' })
		expect(card.exists()).toBe(true)
		expect(card.text()).toBe('Register down')
	})
})
