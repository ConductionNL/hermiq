/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The keep-in-git dialog, the AI literacy course, and the two panels other
 * apps embed on a record (the agent chat tab and the agent runs widget),
 * mounted with axios, fetch and the API doubled.
 */

import axios from '@nextcloud/axios'
import { flushPromises, shallowMount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/auth', () => ({ getRequestToken: () => 'tok' }))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (url, params = {}) => '/index.php' + url.replace(/\{(\w+)\}/g, (m, k) => (k in params ? encodeURIComponent(params[k]) : m)),
}))
vi.mock('@nextcloud/l10n', async (orig) => ({ ...(await orig()), getCanonicalLocale: () => 'en-GB' }))
vi.mock('../../src/api/agents.js', () => ({ applyAgentPull: vi.fn(), previewAgentPull: vi.fn(), publishAgentToGit: vi.fn(), pushAgentToGit: vi.fn() }))
vi.mock('../../src/api/literacy.js', () => ({ answerLesson: vi.fn(), getLessons: vi.fn(), getOverview: vi.fn(), overviewCsvUrl: () => '/csv', setRequirement: vi.fn() }))

const agents = await import('../../src/api/agents.js')
const literacy = await import('../../src/api/literacy.js')
const { default: AgentGitModal } = await import('../../src/modals/AgentGitModal.vue')
const { default: AiLiteracy } = await import('../../src/views/AiLiteracy.vue')
const { default: CnAgentChatTab } = await import('../../src/components/CnAgentChatTab/CnAgentChatTab.vue')
const { default: CnAgentRunsWidget } = await import('../../src/components/CnAgentRunsWidget/CnAgentRunsWidget.vue')

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
 * Mount a component and let it load.
 *
 * @param {object} component The component.
 * @param {object} [props] Its props.
 * @return {Promise<object>} The wrapper.
 */
async function mountIt(component, props = {}) {
	const w = shallowMount(component, { props, global: { mocks: { t, $route: { params: { id: 'a1' } } } } })
	await flushPromises()
	return w
}

/**
 * A fetch Response double.
 *
 * @param {number} status The HTTP status.
 * @param {*} body The JSON body, or an Error to make json() reject.
 * @return {object} The response.
 */
function reply (status, body) {
  return {
	ok: status >= 200 && status < 300,
	status,
	json: async () => {
		if (body instanceof Error) {
			throw body
		}
		return body
	},
}
}

/**
 * Route fetch by URL substring to the given responses, recording every call.
 *
 * @param {Array<[string, Function]>} routes Pairs of URL substring and response maker.
 * @return {Array} The recorded calls.
 */
function routeFetch(routes) {
	const calls = []
	vi.stubGlobal('fetch', async (url, init = {}) => {
		calls.push([url, init])
		const route = routes.find(([part]) => url.includes(part))
		if (!route) {
			throw new Error('unrouted ' + url)
		}
		return route[1](init)
	})
	return calls
}

beforeEach(() => {
	vi.clearAllMocks()
})

afterEach(() => {
	vi.restoreAllMocks()
	vi.unstubAllGlobals()
})

describe('AgentGitModal', () => {
	beforeEach(() => {
		vi.spyOn(axios, 'get').mockImplementation(async (url) => {
			if (url.includes('/credentials')) {
				return { data: { results: [{ id: 'c1', provider: 'github', name: 'GH' }, { id: 'c2', provider: 'gitlab' }, { id: 'c3', provider: 'github' }] } }
			}
			return { data: { uuid: 'a1' } }
		})
	})

	it('loads the agent and offers only GitHub credentials, and the publish action for an unlinked agent', async () => {
		const w = await mountIt(AgentGitModal, { show: true })
		expect(axios.get.mock.calls[0][0]).toContain('/apps/openregister/api/objects/hermiq/agent/a1')
		expect(w.vm.githubCredentials).toEqual([{ label: 'GH', value: 'c1' }, { label: 'c3', value: 'c3' }])
		expect([w.vm.actions, w.vm.link]).toEqual([['publish'], ''])
	})

	it('publishes the agent and becomes linked to the repository', async () => {
		agents.publishAgentToGit.mockResolvedValueOnce({ repoUrl: 'https://github.com/o/r' })
		const w = await mountIt(AgentGitModal, { show: true })
		Object.assign(w.vm, { githubOwner: 'o', repo: 'r', credential: { value: 'c1' } })
		await w.vm.publish()
		expect(agents.publishAgentToGit).toHaveBeenCalledWith('a1', { githubOwner: 'o', repo: 'r', visibility: 'private', credentialId: 'c1' })
		expect([w.vm.done, w.vm.actions, w.vm.link]).toEqual(['Published to https://github.com/o/r', ['push', 'pull'], 'https://github.com/o/r'])
	})

	it('pushes, previews and applies a pull', async () => {
		agents.previewAgentPull.mockResolvedValueOnce({ changes: [{ field: 'prompt' }] })
		agents.applyAgentPull.mockResolvedValueOnce({ uuid: 'a1', gitOwner: 'o', gitRepo: 'r' })
		const w = await mountIt(AgentGitModal, { show: true, agentId: 'a9' })
		w.vm.credential = { value: 'c1' }
		await w.vm.push()
		expect(agents.pushAgentToGit).toHaveBeenCalledWith('a9', 'c1')
		expect(w.vm.done).toBe('Your changes are in the repository.')
		await w.vm.preview()
		expect(w.vm.changes).toEqual([{ field: 'prompt' }])
		await w.vm.apply()
		expect([w.vm.changes, w.vm.agent.gitRepo]).toEqual([null, 'r'])
		expect(w.vm.done).toContain('matches the repository')
		agents.previewAgentPull.mockResolvedValueOnce({})
		await w.vm.preview()
		expect(w.vm.changes).toEqual([])
	})

	it('shows load and action failures', async () => {
		axios.get.mockRejectedValueOnce(new Error('x'))
		const w = await mountIt(AgentGitModal, { show: true })
		expect(w.vm.error).toBe('Could not load this agent')
		agents.pushAgentToGit.mockRejectedValueOnce({ response: { data: { error: 'Repo gone' } } })
		await w.vm.push()
		expect(w.vm.error).toBe('Repo gone')
		agents.pushAgentToGit.mockRejectedValueOnce({})
		await w.vm.push()
		expect([w.vm.error, w.vm.done, w.vm.busy]).toEqual(['Keep in git failed', '', false])
	})

	it('labels fields and values in the change list', async () => {
		const w = await mountIt(AgentGitModal)
		expect(axios.get).not.toHaveBeenCalled()
		expect([w.vm.fieldLabel('prompt'), w.vm.fieldLabel('custom')]).toEqual(['Instructions', 'custom'])
		expect([w.vm.asText(['a', 'b']), w.vm.asText(null), w.vm.asText(3)]).toEqual(['a, b', '', '3'])
	})
})

describe('AiLiteracy', () => {
	beforeEach(() => {
		literacy.getLessons.mockResolvedValue({ lessons: [{ slug: 'l1', done: false }, { slug: 'l2', done: true }], done: 1, total: 2, required: true, mayAdminister: true })
		literacy.getOverview.mockResolvedValue({ people: [{ uid: 'bob' }] })
	})

	it('loads the course and, for an administrator, the overview', async () => {
		const w = await mountIt(AiLiteracy)
		expect([w.vm.lessons.length, w.vm.done, w.vm.total, w.vm.required, w.vm.people, w.vm.loading, w.vm.csvUrl]).toEqual([2, 1, 2, true, [{ uid: 'bob' }], false, '/csv'])
	})

	it('reads no overview for a learner, and says when the course cannot load', async () => {
		literacy.getLessons.mockResolvedValueOnce({})
		const w = await mountIt(AiLiteracy)
		expect([w.vm.lessons, w.vm.done, w.vm.required, w.vm.mayAdminister]).toEqual([[], 0, false, false])
		expect(literacy.getOverview).not.toHaveBeenCalled()
		literacy.getLessons.mockRejectedValueOnce(new Error('x'))
		await w.vm.load()
		expect(w.vm.error).toBe('The course could not be loaded.')
	})

	it('opens one lesson at a time and splits its body into paragraphs', async () => {
		const w = await mountIt(AiLiteracy)
		w.vm.choice = 1
		w.vm.toggle('l1')
		expect([w.vm.openSlug, w.vm.choice, w.vm.feedback]).toEqual(['l1', null, null])
		w.vm.toggle('l1')
		expect(w.vm.openSlug).toBeNull()
		expect(w.vm.paragraphs('One\n\n  \nTwo\n \nThree')).toEqual(['One', 'Two', 'Three'])
		expect(w.vm.paragraphs(null)).toEqual([])
	})

	it('passes a lesson once on a right answer, and explains a wrong one', async () => {
		const w = await mountIt(AiLiteracy)
		const lesson = w.vm.lessons[0]
		w.vm.choice = '2'
		literacy.answerLesson.mockResolvedValueOnce({ correct: true })
		await w.vm.check(lesson)
		expect(literacy.answerLesson).toHaveBeenCalledWith('l1', 2)
		expect([lesson.done, w.vm.done, w.vm.feedback.correct]).toEqual([true, 2, true])
		literacy.answerLesson.mockResolvedValueOnce({ correct: true })
		await w.vm.check(lesson)
		expect(w.vm.done).toBe(2)
		literacy.answerLesson.mockResolvedValueOnce({ correct: false, explanation: 'Models can be wrong.' })
		await w.vm.check(lesson)
		expect(w.vm.feedback).toEqual({ correct: false, text: 'Not quite. Models can be wrong. Try again.' })
		literacy.answerLesson.mockRejectedValueOnce(new Error('x'))
		await w.vm.check(lesson)
		expect([w.vm.feedback.text, w.vm.checking]).toEqual(['Your answer could not be checked.', false])
	})

	it('sets the course requirement, or says it could not', async () => {
		const w = await mountIt(AiLiteracy)
		literacy.setRequirement.mockResolvedValueOnce({ required: false })
		await w.vm.toggleRequired(false)
		expect(w.vm.required).toBe(false)
		literacy.setRequirement.mockRejectedValueOnce(new Error('x'))
		await w.vm.toggleRequired(true)
		expect([w.vm.required, w.vm.error]).toEqual([false, 'The course requirement could not be saved.'])
	})
})

describe('CnAgentChatTab', () => {
	const props = { register: 'r', schema: 'case', objectId: 'o1' }

	it('does nothing for a component without a record', async () => {
		const calls = routeFetch([])
		const w = await mountIt(CnAgentChatTab, {})
		expect([calls, w.vm.contextResolved, w.vm.hasBoundedContext]).toEqual([[], false, false])
	})

	it('loads the allowlisted context of the record and the ready-made prompts', async () => {
		const calls = routeFetch([
			['/api/objects/', () => reply(200, { title: 'Permit', secret: 'x' })],
			['/api/schemas/', () => reply(200, { 'x-openregister-agent-context': ['title'] })],
			['/assistant-prompts', () => reply(200, { results: [{ id: 1, label: 'Summarise', prompt: 'Summarise this' }] })],
		])
		const w = await mountIt(CnAgentChatTab, props)
		expect(w.vm.boundedContext).toEqual({ title: 'Permit' })
		expect([w.vm.hasBoundedContext, w.vm.contextResolved]).toEqual([true, true])
		expect(w.vm.readyMadePrompts).toEqual([{ id: '1', label: 'Summarise', prompt: 'Summarise this' }])
		expect(calls[0][1].headers.requesttoken).toBe('tok')
		w.vm.draft = 'Hi'
		w.vm.pickPrompt({ prompt: 'Summarise this' })
		expect(w.vm.draft).toBe('Hi\nSummarise this')
	})

	it('grounds on nothing when the record or schema cannot be read', async () => {
		routeFetch([
			['/api/objects/', () => reply(404, {})],
			['/api/schemas/', () => reply(200, null)],
			['/assistant-prompts', () => reply(500, {})],
		])
		const w = await mountIt(CnAgentChatTab, props)
		expect([w.vm.boundedContext, w.vm.contextResolved]).toEqual([{}, true])
		routeFetch([['/api/', () => { throw new Error('down') }]])
		await w.vm.loadBoundedContext()
		expect(w.vm.boundedContext).toEqual({})
	})

	it('sends a turn with the record context and keeps the session for the next one', async () => {
		const calls = routeFetch([
			['/api/objects/', () => reply(200, { title: 'Permit' })],
			['/api/schemas/', () => reply(200, { 'x-openregister-agent-context': ['title'] })],
			['/assistant-prompts', () => reply(200, {})],
			['/assistant/converse', () => reply(200, { sessionId: 's1', reply: 'Hello' })],
		])
		const w = await mountIt(CnAgentChatTab, { ...props, objectType: 'permit' })
		w.vm.draft = '  '
		await w.vm.send()
		w.vm.draft = ' Question '
		await w.vm.send()
		const converse = calls.filter(([url]) => url.includes('/converse'))
		expect(converse).toHaveLength(1)
		expect(JSON.parse(converse[0][1].body)).toEqual({ message: 'Question', context: { app: 'hermiq', objectType: 'permit', objectRef: 'o1', contextData: { title: 'Permit' } } })
		expect(w.vm.messages).toEqual([{ role: 'user', text: 'Question' }, { role: 'agent', text: 'Hello', grounded: true }])
		w.vm.draft = 'Again'
		await w.vm.send()
		expect(JSON.parse(calls.at(-1)[1].body).sessionId).toBe('s1')
		expect([w.vm.roleLabel('user'), w.vm.roleLabel('agent')]).toEqual(['You', 'Agent'])
	})

	it('marks the agent unavailable, shows its refusal, or says it is unreachable', async () => {
		let answer = () => reply(503, {})
		routeFetch([
			['/assistant/converse', () => answer()],
			['/assistant-prompts', () => reply(200, {})],
			['/api/', () => reply(200, {})],
		])
		const w = await mountIt(CnAgentChatTab, props)
		w.vm.draft = 'Q'
		await w.vm.send()
		expect(w.vm.unavailable).toBe(true)
		answer = () => reply(403, { message: 'Not for you' })
		w.vm.draft = 'Q'
		await w.vm.send()
		expect(w.vm.error).toBe('Not for you')
		answer = () => reply(500, {})
		w.vm.draft = 'Q'
		await w.vm.send()
		expect(w.vm.error).toBe('The agent could not answer.')
		answer = () => { throw new Error('offline') }
		w.vm.draft = 'Q'
		await w.vm.send()
		expect([w.vm.error, w.vm.sending]).toEqual(['The agent service is unreachable.', false])
	})
})

describe('CnAgentRunsWidget', () => {
	const props = { register: 'r', schema: 'case', objectId: 'o1' }

	/**
	 * The default routes: agents, audit trail and summary status.
	 *
	 * @param {object} [over] Route makers to override by key.
	 * @return {Array} The recorded calls.
	 */
	function defaults(over = {}) {
		const makers = {
			agents: () => reply(200, { results: [{ uuid: 'a1', name: 'Bot' }, { id: 'a2', title: 'T' }, {}] }),
			audit: () => reply(200, [
				{ action: 'agent-run', context: { status: 'error', summary: 'Failed', endedAt: '2026-01-01T00:00:00Z' } },
				{ action: 'update' },
				{ action: 'agent-run', changed: {} },
			]),
			summary: () => reply(200, { enabled: true, agent: 'a1' }),
			run: () => reply(202, {}),
			summarise: () => reply(200, { text: 'Summary', generatedAt: '2026-03-04T00:00:00Z' }),
			...over,
		}
		return routeFetch([
			['/run-on-object', (init) => makers.run(init)],
			['/assistant/summarise', (init) => makers.summarise(init)],
			['/assistant/summary', (init) => makers.summary(init)],
			['/audit-trails', (init) => makers.audit(init)],
			['/apps/hermiq/api/agents', (init) => makers.agents(init)],
		])
	}

	it('loads agents, run history and the summary status for the record', async () => {
		defaults()
		const w = await mountIt(CnAgentRunsWidget, props)
		expect(w.vm.agents).toEqual([{ id: 'a1', label: 'Bot' }, { id: 'a2', label: 'T' }])
		expect(w.vm.runs).toEqual([
			{ status: 'error', summary: 'Failed', when: new Date('2026-01-01T00:00:00Z').toLocaleString() },
			{ status: 'ok', summary: '', when: '' },
		])
		expect([w.vm.summaryShown, w.vm.summaryButton, w.vm.summaryWrittenOn]).toEqual([true, true, ''])
	})

	it('loads nothing without a record, and keeps loaded agents', async () => {
		const calls = defaults()
		await mountIt(CnAgentRunsWidget, {})
		expect(calls).toEqual([])
		const w = await mountIt(CnAgentRunsWidget, props)
		const before = calls.length
		await w.vm.loadAgents()
		expect(calls.length).toBe(before)
	})

	it('copes with refused or failing reads', async () => {
		defaults({ agents: () => reply(403, {}), audit: () => reply(500, {}), summary: () => reply(404, {}) })
		const w = await mountIt(CnAgentRunsWidget, props)
		expect([w.vm.agents, w.vm.runs, w.vm.summaryStatus]).toEqual([[], [], null])
		defaults({ agents: () => { throw new Error('x') }, audit: () => { throw new Error('x') }, summary: () => { throw new Error('x') } })
		await w.vm.refresh()
		expect([w.vm.agents, w.vm.runs, w.vm.summaryStatus, w.vm.loadingAgents]).toEqual([[], [], null, false])
		defaults({ agents: () => reply(200, [{ uuid: 'b' }]), audit: () => reply(200, { results: [] }) })
		await w.vm.refresh()
		expect(w.vm.agents).toEqual([{ id: 'b', label: 'b' }])
	})

	it('writes a summary and shows it, or explains the refusal', async () => {
		let summarise = () => reply(200, { text: 'S', generatedAt: '2026-03-04T00:00:00Z' })
		const calls = defaults({ summarise: () => summarise() })
		const w = await mountIt(CnAgentRunsWidget, props)
		await w.vm.writeSummary()
		const post = calls.find(([url]) => url.includes('/summarise'))
		expect(JSON.parse(post[1].body)).toEqual({ register: 'r', schema: 'case', objectId: 'o1' })
		expect([w.vm.summaryStatus.summary.text, w.vm.summaryWrittenOn, w.vm.summaryButton]).toEqual(['S', '4 March 2026', false])
		for (const [status, sentence] of [
			[404, 'This record could not be found.'],
			[403, 'Your organisation has switched off AI summaries of records.'],
			[409, 'No agent answers in this app.'],
			[422, "Your organisation's guardrail policy stopped this summary."],
			[500, 'The summary could not be written. Try again later.'],
		]) {
			summarise = () => reply(status, new Error('no body'))
			await w.vm.writeSummary()
			expect(w.vm.summaryError).toBe(sentence)
		}
		summarise = () => { throw new Error('offline') }
		await w.vm.writeSummary()
		expect([w.vm.summaryError, w.vm.summaryWriting]).toEqual(['The summary could not be written. Try again later.', false])
	})

	it('queues a run on the record for the selected agent and reports the outcome', async () => {
		let run = () => reply(202, {})
		const calls = defaults({ run: () => run() })
		const w = await mountIt(CnAgentRunsWidget, props)
		await w.vm.runAgent()
		expect(calls.some(([url]) => url.includes('/run-on-object'))).toBe(false)
		w.vm.selectedAgent = { id: 'a1' }
		await w.vm.runAgent()
		expect(calls.find(([url]) => url.includes('/run-on-object'))[0]).toContain('/agents/a1/run-on-object')
		expect([w.vm.noticeType, w.vm.notice]).toEqual(['success', 'Run queued. It will appear in the history shortly.'])
		run = () => reply(404, {})
		await w.vm.runAgent()
		expect(w.vm.notice).toBe('Object or agent not found.')
		run = () => reply(409, { error: 'Agent is off' })
		await w.vm.runAgent()
		expect(w.vm.notice).toBe('Agent is off')
		run = () => reply(500, new Error('no body'))
		await w.vm.runAgent()
		expect(w.vm.notice).toBe('Could not start the run.')
		run = () => { throw new Error('offline') }
		await w.vm.runAgent()
		expect([w.vm.noticeType, w.vm.notice, w.vm.dispatching]).toEqual(['error', 'The agent service is unreachable.', false])
	})

	it('labels run statuses', async () => {
		defaults()
		const w = await mountIt(CnAgentRunsWidget, props)
		expect(['ok', 'error', 'skipped_budget', 'awaiting_approval', 'custom'].map(w.vm.statusLabel)).toEqual(['Completed', 'Failed', 'Blocked (budget)', 'Awaiting approval', 'custom'])
	})
})
