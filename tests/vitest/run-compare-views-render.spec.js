/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * observability-compare-two-runs: what RunCompare and FlowRunCompare put on
 * the page. The other specs shallow-mount these views, so nothing inside a
 * Nextcloud component's slot (the error, the notes, the Back and Compare
 * buttons) is ever rendered; here the components are stubbed with stubs that
 * render their slot, and the page is read as the user sees it.
 *
 * @spec openspec/specs/run-replay-and-dry-run/spec.md#requirement-a-person-can-compare-any-two-runs-they-may-see-req-rcmp-001
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../../src/api/analytics.js', () => ({ compareRuns: vi.fn() }))
vi.mock('../../src/api/flowRuns.js', () => ({ getFlowRun: vi.fn(), listFlowRuns: vi.fn(), listFlows: vi.fn() }))

const analytics = await import('../../src/api/analytics.js')
const flowRuns = await import('../../src/api/flowRuns.js')
const { default: RunCompare } = await import('../../src/views/RunCompare.vue')
const { default: FlowRunCompare } = await import('../../src/views/FlowRunCompare.vue')

const t = (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m))
globalThis.t = t

const stubs = {
	NcButton: { emits: ['click'], template: '<button class="nc-button" @click="$emit(\'click\')"><slot /></button>' },
	NcNoteCard: { props: ['type', 'heading'], template: '<div class="nc-note" :data-type="type">{{ heading }}<slot /></div>' },
	NcLoadingIcon: { template: '<span class="nc-loading" />' },
	NcSelect: { props: ['modelValue', 'options'], template: '<div class="nc-select" />' },
}

/**
 * Mount a view with slot-rendering stubs and let it load.
 *
 * @param {object} component The view.
 * @param {object} mocks Route and router.
 * @return {Promise<object>} The wrapper.
 */
async function render(component, mocks = {}) {
	const w = mount(component, { global: { stubs, mocks: { t, ...mocks } } })
	await flushPromises()
	return w
}

beforeEach(() => {
	vi.clearAllMocks()
})

describe('RunCompare on the page', () => {
	it('shows the error a refused comparison gives, and no table', async () => {
		analytics.compareRuns.mockRejectedValueOnce({ response: { status: 404 } })
		const w = await render(RunCompare, { $route: { query: { left: 'r1', right: 'r9' } } })

		const note = w.find('.nc-note')
		expect(note.attributes('data-type')).toBe('error')
		expect(note.text()).toBe('Could not compare these runsOne of these runs was not found, or you may not see it.')
		expect(w.find('table').exists()).toBe(false)
	})

	it('notes runs of different agents and marks the steps that differ', async () => {
		analytics.compareRuns.mockResolvedValueOnce({
			sameAgent: false,
			left: { agentName: 'Intake', status: 'ok', trigger: 'schedule', durationMs: 1200 },
			right: { agentName: 'Triage', status: 'failed', trigger: 'flow' },
			comparison: {
				steps: [
					{ left: { name: 'search', outcome: 'ok' }, right: { name: 'search', outcome: 'ok' }, mark: 'same' },
					{ left: null, right: { name: 'fetch', outcome: 'error' }, mark: 'only-right' },
				],
			},
		})
		const w = await render(RunCompare, { $route: { query: { left: 'r1', right: 'r2' } } })

		expect(w.find('.nc-note[data-type="info"]').text()).toBe('These runs are of different agents.')
		const rows = w.findAll('tbody tr')
		expect(rows.map((row) => row.findAll('td').map((td) => td.text()))).toEqual([
			['search (ok)', 'search (ok)', 'Same'],
			['-', 'fetch (error)', 'Only in run B'],
		])
		expect(rows.map((row) => row.classes('hermiq-run-compare__row--differs'))).toEqual([false, true])
		expect(w.findAll('section dd').map((dd) => dd.text())).toContain('1.2 s')
	})

	it('goes back to the run list from the Back button', async () => {
		analytics.compareRuns.mockResolvedValueOnce({ sameAgent: true, left: {}, right: {}, comparison: { steps: [] } })
		const router = { push: vi.fn(async () => {}) }
		const w = await render(RunCompare, { $route: { query: {} }, $router: router })

		expect(w.find('.nc-note[data-type="info"]').exists()).toBe(false)
		await w.find('.nc-button').trigger('click')
		expect(router.push).toHaveBeenCalledWith('/runs')
	})
})

describe('FlowRunCompare on the page', () => {
	it('shows why the flows could not be read', async () => {
		flowRuns.listFlows.mockRejectedValueOnce({ response: { data: { error: 'Flows are unavailable' } } })
		const w = await render(FlowRunCompare, { $route: { query: {} } })

		expect(w.find('.nc-note[data-type="error"]').text()).toBe('Could not load the flow runsFlows are unavailable')
	})

	it('lets two runs be ticked, refuses a third, and compares the two', async () => {
		flowRuns.listFlows.mockResolvedValueOnce([{ uuid: 'f1', name: 'Intake' }])
		flowRuns.listFlowRuns.mockResolvedValueOnce([
			{ uuid: 'a', status: 'ok', created: '2026-11-01', flowVersion: 1 },
			{ uuid: 'b', status: 'failed', created: '2026-11-02', flowVersion: 2 },
			{ uuid: 'c', status: 'ok' },
		])
		flowRuns.getFlowRun.mockImplementation(async (uuid) => ({
			uuid,
			flowVersion: uuid === 'a' ? 1 : 2,
			nodes: [{ node: 'start', status: 'ok', durationMs: 5 }],
		}))
		const w = await render(FlowRunCompare, { $route: { query: { flow: 'f1' } } })

		expect(flowRuns.listFlowRuns).toHaveBeenCalledWith('f1')
		const boxes = w.findAll('tbody input[type="checkbox"]')
		expect(boxes).toHaveLength(3)
		expect(w.find('.nc-button').exists()).toBe(false)

		await boxes[0].trigger('change')
		await boxes[1].trigger('change')
		await boxes[2].trigger('change')
		expect(w.find('.nc-note[data-type="warning"]').text()).toBe('Choose two runs to compare.')

		await w.find('.nc-button').trigger('click')
		await flushPromises()
		expect(flowRuns.getFlowRun.mock.calls.map((call) => call[0])).toEqual(['a', 'b'])
		expect(w.find('section.hermiq-flow-compare__result').exists()).toBe(true)
	})

	it('names the flow versions and an unreadable run', async () => {
		flowRuns.listFlows.mockResolvedValueOnce([])
		const w = await render(FlowRunCompare, { $route: { query: {} } })
		await w.setData({ result: { versions: [1, 2], unreadable: ['b'], rows: [] } })

		const notes = w.findAll('section .nc-note')
		expect(notes.map((note) => [note.attributes('data-type'), note.text()])).toEqual([
			['info', 'These runs used different versions of the flow (1 and 2).'],
			['warning', 'This flow run could not be read.'],
		])
		expect(w.find('section table').exists()).toBe(false)
	})
})
