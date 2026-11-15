/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The tool invocation oversight table, fully rendered: one row per recorded
 * invocation with its tool, acting identity, shortened argument digest,
 * result badge and touched objects, the reduced-detail warning, the export
 * buttons and the load error.
 */

import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

vi.mock('../../src/api/toolOversight.js', () => ({
	getToolInvocations: vi.fn(),
	toolInvocationsExportUrl: (id, format) => `/export/${id}.${format}`,
}))
vi.mock('@nextcloud/vue', () => ({
	NcButton: {
		props: ['disabled'],
		emits: ['click'],
		render() {
			return h('button', { disabled: this.disabled, onClick: () => this.$emit('click') }, this.$slots.default?.())
		},
	},
	NcEmptyContent: { props: ['name'], render() { return h('div', { class: 'empty' }, this.name) } },
	NcLoadingIcon: { render: () => h('span', { class: 'spinner' }) },
	NcModal: { props: ['show'], render() { return this.show ? h('div', { class: 'modal' }, this.$slots.default?.()) : null } },
	NcTextArea: { props: ['modelValue'], render: () => h('textarea') },
	NcNoteCard: { props: ['type', 'heading'], render() { return h('div', { class: `note note--${this.type}` }, this.$slots.default?.()) } },
}))

const oversight = await import('../../src/api/toolOversight.js')
const { default: ToolInvocationTable } = await import('../../src/components/ToolInvocationTable.vue')

const t = (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m))
globalThis.t = t

/**
 * Mount the table for agent a1 and let it load.
 *
 * @return {Promise<object>} The wrapper.
 */
async function mountTable() {
	const w = mount(ToolInvocationTable, { props: { agentId: 'a1' }, global: { mocks: { t } } })
	await flushPromises()
	return w
}

const cells = (row) => row.findAll('td').map((td) => td.text())

describe('ToolInvocationTable rows', () => {
	let href

	beforeEach(() => {
		oversight.getToolInvocations.mockReset()
		href = ''
		vi.stubGlobal('location', { set href(v) { href = v }, get href() { return href } })
		vi.spyOn(Date.prototype, 'toLocaleString').mockReturnValue('13-11-2026 10:00')
	})

	afterEach(() => {
		vi.unstubAllGlobals()
		vi.restoreAllMocks()
	})

	it('renders one row per invocation with what is and is not recorded', async () => {
		oversight.getToolInvocations.mockResolvedValueOnce({
			available: true,
			retention: '90 days',
			rows: [
				{
					at: '2026-11-13T10:00:00Z',
					toolId: 'openregister.search',
					actingUser: 'alice',
					paramsDigest: 'abcdef0123456789',
					resultSummary: { count: 3 },
					dataTouched: ['obj-1', 'obj-2'],
				},
				{ at: '', toolId: '', actingUser: '', paramsDigest: 'short', resultSummary: { isError: true } },
				{ at: '2026-11-13T09:00:00Z', resultSummary: null, dataTouched: [] },
			],
		})
		const w = await mountTable()
		const rows = w.findAll('tbody tr')

		expect(rows).toHaveLength(3)
		expect(cells(rows[0])).toEqual(['13-11-2026 10:00', 'openregister.search', 'alice', 'abcdef012345…', '3 result(s)', 'obj-1, obj-2', '—'])
		expect(cells(rows[1])).toEqual(['—', 'not recorded', '—', 'short', 'Error', '—', '—'])
		expect(cells(rows[2])).toEqual(['13-11-2026 10:00', 'not recorded', '—', 'not recorded', '—', '—', '—'])
		expect(rows[1].find('td:nth-child(5) span').classes()).toContain('tool-oversight__badge--error')
		expect(rows[0].find('td:nth-child(5) span').classes()).toContain('tool-oversight__badge--ok')
		expect(w.find('.tool-oversight__retention').text()).toBe('Retention: 90 days')
		expect(w.find('.note--warning').exists()).toBe(false)
		expect(w.find('.empty').exists()).toBe(false)
	})

	it('warns about reduced detail and exports in both formats', async () => {
		oversight.getToolInvocations.mockResolvedValueOnce({
			available: false,
			retention: '30 days',
			rows: [{ at: '2026-11-13T10:00:00Z', resultSummary: { status: 'completed' } }],
		})
		const w = await mountTable()

		expect(w.find('.note--warning').text()).toContain('Reduced detail')
		expect(cells(w.find('tbody tr'))[4]).toBe('completed')

		const [csv, json] = w.findAll('.tool-oversight__exports button')
		await csv.trigger('click')
		expect(href).toBe('/export/a1.csv')
		await json.trigger('click')
		expect(href).toBe('/export/a1.json')
	})

	it('shows the empty state with disabled exports when nothing was recorded', async () => {
		oversight.getToolInvocations.mockResolvedValueOnce({ available: true, retention: '30 days', rows: [] })
		const w = await mountTable()

		expect(w.find('.empty').text()).toBe('No invocations recorded yet')
		expect(w.find('table').exists()).toBe(false)
		expect(w.findAll('.tool-oversight__exports button').map((b) => b.attributes('disabled'))).toEqual(['', ''])
	})

	it('shows the load error and no table', async () => {
		oversight.getToolInvocations.mockRejectedValueOnce({ response: { data: { error: 'Forbidden' } } })
		const w = await mountTable()

		expect(w.find('.note--error').text()).toBe('Forbidden')
		expect(w.find('table').exists()).toBe(false)
		expect(w.find('.spinner').exists()).toBe(false)
	})
})
