/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The GitHub store and the run history widgets, fully mounted so their
 * templates render: the hint and notice cards, the credential picker, each
 * card's install button, and each run row's details, re-run, replay and
 * compare buttons. Their API, stores and router are doubled; the
 * @nextcloud/vue stand-ins render their slots.
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const toasts = []
const bus = []
vi.mock('@nextcloud/dialogs', () => ({
	showError: (m) => toasts.push(['error', m]),
	showSuccess: (m) => toasts.push(['success', m]),
}))
vi.mock('@nextcloud/event-bus', () => ({
	emit: (name, payload) => bus.push([name, payload]),
	subscribe: vi.fn(),
	unsubscribe: vi.fn(),
}))
const credentialsResponse = { data: { results: [] } }
vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(async () => credentialsResponse) } }))
vi.mock('../../src/api/agentTemplates.js', () => ({ installGithubTemplate: vi.fn(), searchGithubTemplates: vi.fn() }))
vi.mock('../../src/api/skills.js', () => ({ installGithubSkill: vi.fn(), searchGithubSkills: vi.fn() }))
vi.mock('../../src/api/agents.js', () => ({ getRunTrace: vi.fn(), listRuns: vi.fn(), replayRun: vi.fn(), runScheduleNow: vi.fn() }))
const scheduleStore = { registerObjectType: vi.fn(), fetchCollection: vi.fn() }
vi.mock('../../src/store/store.js', () => ({ useScheduleStore: () => scheduleStore }))
const downloads = []
vi.mock('../../src/utils/downloadJson.js', () => ({ downloadJson: (data, name) => downloads.push([data, name]) }))

const templates = await import('../../src/api/agentTemplates.js')
const skills = await import('../../src/api/skills.js')
const agents = await import('../../src/api/agents.js')
const { default: GithubStore } = await import('../../src/widgets/AgentTemplateGithubStore.vue')
const { default: RunHistory } = await import('../../src/widgets/AgentRunHistoryWidget.vue')

/**
 * Translate as the identity, filling {placeholders}.
 *
 * @param {string} app The app id.
 * @param {string} text The text.
 * @param {object} vars The placeholders.
 * @return {string} The filled text.
 */
const t = (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m))

const router = { push: vi.fn(() => Promise.resolve()) }

/**
 * Fully mount a component on the route of agent a1 and let it load.
 *
 * @param {object} component The component.
 * @return {Promise<object>} The wrapper.
 */
async function mountIt(component) {
	const w = mount(component, { global: { mocks: { t, $route: { params: { id: 'a1' } }, $router: router } } })
	await flushPromises()
	return w
}

/**
 * The stand-in buttons whose aria-label or visible text is the given one.
 *
 * @param {object} w The wrapper.
 * @param {string} label The aria-label or text.
 * @return {Array<object>} The button wrappers.
 */
function buttons(w, label) {
	return w.findAllComponents({ name: 'NcButton' }).filter((b) => b.attributes('data-aria-label') === label || b.text().trim() === label)
}

beforeEach(() => {
	toasts.length = 0
	bus.length = 0
	downloads.length = 0
	vi.clearAllMocks()
	credentialsResponse.data.results = []
})

describe('AgentTemplateGithubStore, rendered', () => {
	it('shows the rate-limit hint and offers only the GitHub credentials, searching again with the picked one', async () => {
		credentialsResponse.data.results = [{ id: 'c1', name: 'Mine', provider: 'github' }, { id: 'c2', provider: 'gitlab' }]
		templates.searchGithubTemplates.mockResolvedValue({ outcome: 'github_rate_limited', cards: [], rateLimited: true })
		skills.searchGithubSkills.mockResolvedValue({ outcome: 'github_rate_limited', cards: [], rateLimited: true })
		const w = await mountIt(GithubStore)
		expect(w.find('nc-notecard').text()).toContain('anonymous search rate limit was reached')
		expect(w.find('nc-emptycontent').attributes('data-name')).toBe('No GitHub results found')

		const selects = w.findAllComponents({ name: 'NcSelect' })
		expect(selects).toHaveLength(2)
		expect(JSON.parse(selects[1].attributes('data-options'))).toEqual([{ label: 'Mine', value: 'c1' }])
		await selects[1].vm.pick({ label: 'Mine', value: 'c1' })
		await flushPromises()
		expect(templates.searchGithubTemplates).toHaveBeenLastCalledWith('', 'c1')
		expect(skills.searchGithubSkills).toHaveBeenLastCalledWith('', 'c1')
	})

	it('narrows the search to the kind picked, and searches a typed term after the debounce', async () => {
		vi.useFakeTimers()
		try {
			templates.searchGithubTemplates.mockRejectedValue(new Error('down'))
			skills.searchGithubSkills.mockRejectedValue(new Error('down'))
			const w = await mountIt(GithubStore)
			expect(w.findAllComponents({ name: 'NcSelect' })).toHaveLength(1)
			expect(w.find('nc-notecard').text()).toBe('Could not reach GitHub right now. Please try again later.')

			await w.findComponent({ name: 'NcSelect' }).vm.pick({ label: 'Skills', value: 'skill' })
			await flushPromises()
			expect(templates.searchGithubTemplates).toHaveBeenCalledTimes(1)
			expect(skills.searchGithubSkills).toHaveBeenCalledTimes(2)

			await w.findComponent({ name: 'NcTextField' }).vm.pick('summar')
			expect(skills.searchGithubSkills).toHaveBeenCalledTimes(2)
			await vi.advanceTimersByTimeAsync(400)
			expect(skills.searchGithubSkills).toHaveBeenLastCalledWith('summar', null)
		} finally {
			vi.useRealTimers()
		}
	})

	it('renders an install button per readable card and the warning for an unreadable one', async () => {
		templates.searchGithubTemplates.mockResolvedValue({ outcome: 'ok', cards: [{ kind: 'agent-template', owner: 'o', repo: 'bad', unparseable: true }] })
		skills.searchGithubSkills.mockResolvedValue({ outcome: 'ok', cards: [{ kind: 'skill', owner: 'o', repo: 'good', name: 'Summariser', stars: 3 }] })
		const w = await mountIt(GithubStore)
		expect(w.findAll('nc-notecard').map((c) => c.text())).toEqual(['This repo does not carry a readable hermiq-agent-template.json — it cannot be installed.'])
		const [install] = buttons(w, 'Install')

		let finish
		skills.installGithubSkill.mockReturnValueOnce(new Promise((resolve) => { finish = resolve }))
		await install.vm.$emit('click')
		expect(install.text()).toBe('Installing…')
		finish({})
		await flushPromises()
		expect(skills.installGithubSkill).toHaveBeenCalledWith({ owner: 'o', repo: 'good', credentialId: null })
		const notice = w.findAll('nc-notecard').find((c) => c.attributes('data-type') === 'success')
		expect(notice.text()).toContain('Installed skill "Summariser"')

		skills.installGithubSkill.mockRejectedValueOnce(new Error('Quarantine full'))
		await install.vm.$emit('click')
		await flushPromises()
		const error = w.findAll('nc-notecard').find((c) => c.attributes('data-type') === 'error')
		expect(error.text()).toBe('Quarantine full')
	})
})

describe('AgentRunHistoryWidget, rendered', () => {
	const schedules = [{ id: 's1', agentId: 'a1' }]

	it('shows a warning when the runs cannot be read', async () => {
		scheduleStore.fetchCollection.mockResolvedValueOnce(schedules)
		agents.listRuns.mockRejectedValueOnce(new Error('down'))
		const w = await mountIt(RunHistory)
		expect(w.find('nc-notecard').text()).toBe('Could not load run history.')
		expect(w.find('table').exists()).toBe(false)
	})

	it('expands a run to its steps, downloads the trace, and collapses it again', async () => {
		scheduleStore.fetchCollection.mockResolvedValueOnce(schedules)
		agents.listRuns.mockResolvedValueOnce([{ id: 'r1', status: 'ok' }])
		const trace = { toolStepsAvailable: false, steps: [{ seq: 1, type: 'tool', name: 'search', durationMs: 1500, outcome: 'error' }] }
		agents.getRunTrace.mockResolvedValueOnce(trace)
		const w = await mountIt(RunHistory)
		const [details] = buttons(w, 'View this run\'s step timeline')
		await details.vm.$emit('click')
		await flushPromises()
		expect(agents.getRunTrace).toHaveBeenCalledWith('s1', 'r1')
		expect(details.text()).toBe('Hide details')
		expect(w.find('.agent-run-history-widget__trace-hint').exists()).toBe(true)
		expect(w.find('.agent-run-history-widget__trace-step-name').text()).toBe('search')
		expect(w.find('.agent-run-history-widget__trace-step-duration').text()).toBe('1.5s')

		await buttons(w, 'Download trace (JSON)')[0].vm.$emit('click')
		expect(downloads).toEqual([[trace, 'run-trace-r1.json']])
		await details.vm.$emit('click')
		expect(w.find('.agent-run-history-widget__trace').exists()).toBe(false)
	})

	it('shows a warning in the expanded row when its trace cannot be read', async () => {
		scheduleStore.fetchCollection.mockResolvedValueOnce(schedules)
		agents.listRuns.mockResolvedValueOnce([{ id: 'r1', status: 'ok' }])
		agents.getRunTrace.mockRejectedValueOnce(new Error('down'))
		const w = await mountIt(RunHistory)
		await buttons(w, 'View this run\'s step timeline')[0].vm.$emit('click')
		await flushPromises()
		expect(w.find('nc-notecard').text()).toBe('Could not load this run\'s trace.')
	})

	it('re-runs a dead-lettered schedule and opens the comparison for a run', async () => {
		scheduleStore.fetchCollection.mockResolvedValueOnce(schedules)
		agents.listRuns.mockResolvedValue([{ id: 'r1', status: 'dead_letter' }, { id: 'r2', status: 'ok' }])
		agents.runScheduleNow.mockResolvedValueOnce({ status: 'ok' })
		const w = await mountIt(RunHistory)
		const rerun = buttons(w, 'Re-run this dead-lettered schedule')
		expect(rerun).toHaveLength(1)
		await rerun[0].vm.$emit('click')
		await flushPromises()
		expect(agents.runScheduleNow).toHaveBeenCalledWith('s1')
		expect(toasts).toContainEqual(['success', 'Agent run started.'])
		expect(bus).toContainEqual(['cn:page:refresh', {}])

		await buttons(w, 'Compare with…')[1].vm.$emit('click')
		expect(router.push).toHaveBeenCalledWith({ path: '/runs', query: { compareWith: 'r2' } })
	})

	it('replays a run with a busy icon, previews its steps and dismisses the preview', async () => {
		scheduleStore.fetchCollection.mockResolvedValueOnce(schedules)
		agents.listRuns.mockResolvedValueOnce([{ id: 'r1', status: 'ok' }])
		let finish
		agents.replayRun.mockReturnValueOnce(new Promise((resolve) => { finish = resolve }))
		const w = await mountIt(RunHistory)
		const [replay] = buttons(w, 'Replay this run as a dry run and compare')
		expect(replay.findComponent({ name: 'NcLoadingIcon' }).exists()).toBe(false)
		await replay.vm.$emit('click')
		expect(replay.findComponent({ name: 'NcLoadingIcon' }).exists()).toBe(true)
		finish({ diff: { changed: true }, replay: { steps: [{ seq: 1, type: 'tool', name: 'search' }] } })
		await flushPromises()
		expect(agents.replayRun).toHaveBeenCalledWith('s1', 'r1')
		expect(w.text()).toContain('Nothing was changed — side-effecting tools were reported, not executed.')
		expect(w.text()).toContain('The replay produced a DIFFERENT outcome than the original run.')

		await buttons(w, 'Dismiss')[0].vm.$emit('click')
		expect(w.text()).not.toContain('Replay preview')
		expect([w.vm.replayResult, w.vm.replayResultRunId]).toEqual([null, null])
	})
})
