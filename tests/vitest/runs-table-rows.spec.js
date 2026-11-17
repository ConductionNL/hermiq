/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Runs page as a reader uses it: what each row says, ticking two runs to
 * compare, leaving a followed schedule link, filtering and paging.
 *
 * @spec openspec/specs/run-analytics/spec.md#requirement-a-cross-agent-run-list-on-the-same-tenant-boundary-as-the-metrics
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

vi.mock('../../src/api/analytics.js', () => ({ listRuns: vi.fn() }))
const store = { registerObjectType: vi.fn(), fetchCollection: vi.fn() }
vi.mock('../../src/store/store.js', () => ({ useAgentStore: () => store }))

const analytics = await import('../../src/api/analytics.js')
const { default: Runs } = await import('../../src/views/Runs.vue')

const t = (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m))
// The row formatters call the Nextcloud global, not the component's mixin.
globalThis.t = t

const NcButton = {
	emits: ['click'],
	setup(props, { slots, emit, attrs }) {
		return () => h('button', { class: attrs.class, disabled: attrs.disabled, onClick: () => emit('click') }, slots.default?.())
	},
}

const NcSelect = {
	name: 'NcSelect',
	props: ['modelValue', 'inputLabel', 'options'],
	emits: ['update:modelValue'],
	render: () => null,
}

const NcNoteCard = {
	props: ['type', 'heading'],
	setup(props, { slots }) {
		return () => h('aside', { class: `note-${props.type}` }, slots.default?.())
	},
}

const RouterLink = {
	props: ['to'],
	setup(props, { slots }) {
		return () => h('a', { href: props.to }, slots.default?.())
	},
}

const RUNS = [
	{
		id: 'r1',
		status: 'ok',
		objectId: 's1',
		agentId: 'a1',
		agentName: 'Samenvatter',
		trigger: 'schedule',
		durationMs: 1500,
		summary: 'Week samengevat',
		providerDisclosure: { provider: 'Mistral', dataUse: 'zero-retention', termsReference: 'https://example.org/terms' },
	},
	{ id: 'r2', status: 'dead_letter', objectId: 's2', agentName: 'Verwijderde agent', trigger: 'flow', durationMs: null, summary: '' },
	{ id: 'r3', status: 'running', objectId: 's1', agentName: 'Samenvatter', providerDisclosure: { provider: 'Ollama', dataUse: 'may-train' } },
]

/**
 * Mount the page on a route and let its loads settle.
 *
 * @param {object} query The route query.
 * @return {Promise<{w: object, router: object}>} The wrapper and router double.
 */
async function page(query = {}) {
	const router = { push: vi.fn(async () => {}), replace: vi.fn(async () => {}) }
	const w = mount(Runs, {
		global: {
			mocks: { t, $route: { query }, $router: router },
			stubs: {
				NcButton,
				NcSelect,
				NcNoteCard,
				NcEmptyContent: true,
				NcLoadingIcon: true,
				HistoryIcon: true,
				'router-link': RouterLink,
			},
		},
	})
	await flushPromises()
	return { w, router }
}

/**
 * The button with this text.
 *
 * @param {object} w The page.
 * @param {string} text The label.
 * @return {object} The button.
 */
const button = (w, text) => w.findAll('button').find((b) => b.text() === text)

describe('the Runs table', () => {
	beforeEach(() => {
		vi.clearAllMocks()
		analytics.listRuns.mockResolvedValue({ results: RUNS, total: 120 })
		store.fetchCollection.mockResolvedValue([{ uuid: 'a1', name: 'Samenvatter' }])
	})

	it('says per row who ran, how it went, and what the provider keeps', async () => {
		const { w } = await page()

		const rows = w.findAll('tbody tr')
		expect(rows).toHaveLength(3)

		const first = rows[0].findAll('td')
		expect(first[2].find('a').attributes('href')).toBe('/agents/a1')
		expect(first[2].text()).toBe('Samenvatter')
		expect(first[3].find('span').classes()).toContain('hermiq-runs__status--ok')
		expect(first[4].text()).toBe('Schedule')
		expect(first[5].text()).toBe('1.5s')
		expect(first[6].text()).toBe('Mistral Keeps nothing it is sent')
		expect(first[6].find('.hermiq-runs__data-use').attributes('title')).toBe('https://example.org/terms')
		expect(first[7].text()).toBe('Week samengevat')

		const second = rows[1].findAll('td')
		expect(second[2].find('a').exists()).toBe(false)
		expect(second[2].text()).toBe('Verwijderde agent')
		expect(second[3].find('span').classes()).toContain('hermiq-runs__status--error')
		expect(second[4].text()).toBe('Flow')
		expect(second[5].text()).toBe('—')
		expect(second[6].text()).toBe('—')
		expect(second[7].text()).toBe('—')

		expect(rows[2].findAll('td')[3].find('span').classes()).toContain('hermiq-runs__status--neutral')
		expect(rows[2].findAll('td')[6].text()).toBe('Ollama May train on your data')
	})

	it('offers Compare once two runs are ticked, and refuses a third', async () => {
		const { w, router } = await page()
		const boxes = w.findAll('tbody input[type="checkbox"]')

		expect(button(w, 'Compare')).toBeUndefined()
		await boxes[0].trigger('change')
		await boxes[1].trigger('change')
		expect(boxes[0].element.checked).toBe(true)
		await button(w, 'Compare').trigger('click')
		expect(router.push).toHaveBeenCalledWith({ path: '/runs/compare', query: { left: 'r1', right: 'r2' } })

		await boxes[2].trigger('change')
		expect(w.find('aside.note-warning').text()).toBe('Choose two runs to compare.')
	})

	it('opens the flow run comparison', async () => {
		const { w, router } = await page()

		await button(w, 'Compare flow runs').trigger('click')

		expect(router.push).toHaveBeenCalledWith({ path: '/flow-runs/compare' })
	})

	it('shows one schedule from a followed link until the reader clears it', async () => {
		const { w, router } = await page({ schedule: 's1' })
		expect(w.findAll('tbody tr')).toHaveLength(2)

		await button(w, 'Show every run').trigger('click')

		expect(router.replace).toHaveBeenCalledWith({ query: {} })
		expect(w.find('aside.note-info').exists()).toBe(false)
		expect(w.findAll('tbody tr')).toHaveLength(3)
	})

	it('reloads from the first page when a filter is chosen, and on Refresh', async () => {
		const { w } = await page()
		const [agentSelect, statusSelect] = w.findAllComponents({ name: 'NcSelect' })

		agentSelect.vm.$emit('update:modelValue', { value: 'a1', label: 'Samenvatter' })
		await flushPromises()
		expect(analytics.listRuns).toHaveBeenLastCalledWith({ agentId: 'a1', status: '', limit: 50, offset: 0 })

		statusSelect.vm.$emit('update:modelValue', { value: 'ok', label: 'ok' })
		await flushPromises()
		expect(analytics.listRuns).toHaveBeenLastCalledWith({ agentId: 'a1', status: 'ok', limit: 50, offset: 0 })

		await button(w, 'Refresh').trigger('click')
		await flushPromises()
		expect(analytics.listRuns).toHaveBeenCalledTimes(4)
	})

	it('pages with Next and Previous, and Previous is off on the first page', async () => {
		const { w } = await page()
		expect(button(w, 'Previous').attributes('disabled')).toBeDefined()

		await button(w, 'Next').trigger('click')
		await flushPromises()
		expect(analytics.listRuns).toHaveBeenLastCalledWith(expect.objectContaining({ offset: 50 }))
		expect(button(w, 'Previous').attributes('disabled')).toBeUndefined()

		await button(w, 'Previous').trigger('click')
		await flushPromises()
		expect(analytics.listRuns).toHaveBeenLastCalledWith(expect.objectContaining({ offset: 0 }))
	})

	it('names why the runs could not be loaded', async () => {
		analytics.listRuns.mockRejectedValueOnce({ response: { data: { error: 'Forbidden' } } })
		const { w } = await page()

		expect(w.find('aside.note-error').text()).toBe('Forbidden')
	})
})
