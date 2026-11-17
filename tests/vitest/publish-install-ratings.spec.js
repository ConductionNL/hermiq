/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The two GitHub publish dialogs (skill and agent template), the skill install
 * dialog, the agent ratings widget and the start fields editor, mounted with
 * axios, the API and the stores doubled.
 */

import axios from '@nextcloud/axios'
import { flushPromises, shallowMount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../../src/api/skills.js', () => ({ publishSkillToGithub: vi.fn(), installSkill: vi.fn() }))
vi.mock('../../src/api/agentTemplates.js', () => ({ publishAgentTemplateToGithub: vi.fn() }))
vi.mock('../../src/api/analytics.js', () => ({ getAnalytics: vi.fn(), listLowRatings: vi.fn() }))
const agentStore = { registerObjectType: vi.fn(), fetchCollection: vi.fn() }
vi.mock('../../src/store/store.js', () => ({ useAgentStore: () => agentStore }))

const skills = await import('../../src/api/skills.js')
const templates = await import('../../src/api/agentTemplates.js')
const analytics = await import('../../src/api/analytics.js')
const { default: SkillGithubPublishModal } = await import('../../src/modals/SkillGithubPublishModal.vue')
const { default: AgentTemplatePublishModal } = await import('../../src/modals/AgentTemplatePublishModal.vue')
const { default: SkillInstallModal } = await import('../../src/modals/SkillInstallModal.vue')
const { default: AgentRatingsWidget } = await import('../../src/widgets/AgentRatingsWidget.vue')
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
 * Mount a component and let it load.
 *
 * @param {object} component The component.
 * @param {object} props Its props.
 * @param {object} [route] The route params.
 * @return {Promise<object>} The wrapper.
 */
async function mountIt(component, props, route = { id: 'a1' }) {
	const w = shallowMount(component, { props, global: { mocks: { t, $route: { params: route } } } })
	await flushPromises()
	return w
}

beforeEach(() => {
	vi.clearAllMocks()
	vi.spyOn(axios, 'get').mockResolvedValue({ data: { results: [{ id: 'c1', provider: 'github', name: 'GH' }, { id: 'c2', provider: 'openai' }, { id: 'c3', provider: 'github' }] } })
})

afterEach(() => {
	vi.restoreAllMocks()
})

describe.each([
	['SkillGithubPublishModal', SkillGithubPublishModal, { skillId: 'k1' }, {
		form: 'form', visibility: 'visibility', credential: 'credential', list: 'credentials',
		can: 'canPublish', publish: 'doPublish', error: 'publishError', busy: 'publishing',
		api: () => skills.publishSkillToGithub, id: 'k1',
	}],
	['AgentTemplatePublishModal', AgentTemplatePublishModal, { templateId: 't1' }, {
		form: 'form', visibility: 'visibility', credential: 'credential', list: 'credentials',
		can: 'canPublish', publish: 'doPublish', error: 'publishError', busy: 'publishing',
		api: () => templates.publishAgentTemplateToGithub, id: 't1',
	}],
])('%s', (name, component, props, keys) => {
	it('offers only GitHub credentials and publishes privately by default', async () => {
		const w = await mountIt(component, props)
		expect(w.vm.githubCredentials).toEqual([{ label: 'GH', value: 'c1' }, { label: 'c3', value: 'c3' }])
		expect(w.vm.visibilityOptions.map((o) => o.value)).toEqual(['private', 'public'])
		expect(w.vm[keys.can]).toBe(false)
		w.vm[keys.form].owner = ' o '
		w.vm[keys.form].repo = ' r '
		expect(w.vm[keys.can]).toBe(false)
		w.vm[keys.credential] = { value: 'c1' }
		expect(w.vm[keys.can]).toBe(true)
		keys.api().mockResolvedValueOnce({ repoUrl: 'https://github.com/o/r' })
		await w.vm[keys.publish]()
		expect(keys.api()).toHaveBeenCalledWith(keys.id, { owner: 'o', repo: 'r', visibility: 'private', credentialId: 'c1' })
		expect(w.emitted('published')).toEqual([['https://github.com/o/r']])
	})

	it('falls back to private without a visibility, and shows a refusal', async () => {
		const w = await mountIt(component, props)
		w.vm[keys.visibility] = null
		keys.api().mockRejectedValueOnce({ response: { data: { error: 'Repo exists' } } })
		await w.vm[keys.publish]()
		expect(keys.api().mock.calls[0][1].visibility).toBe('private')
		expect([w.vm[keys.error], w.vm[keys.busy]]).toEqual(['Repo exists', false])
		expect(w.emitted('published')).toBeUndefined()
	})

	it('has no credentials when they cannot be listed', async () => {
		axios.get.mockRejectedValueOnce(new Error('x'))
		const w = await mountIt(component, props)
		expect(w.vm[keys.list]).toEqual([])
		axios.get.mockResolvedValueOnce({ data: {} })
		await (w.vm.fetchGithubCredentials || w.vm.fetchCredentials)()
		expect(w.vm[keys.list]).toEqual([])
	})
})

describe('SkillInstallModal', () => {
	it('offers the agents and installs the skill on the chosen one', async () => {
		agentStore.fetchCollection.mockResolvedValueOnce([{ uuid: 'a1', name: 'Bot' }, { id: 'a2' }])
		const w = await mountIt(SkillInstallModal, { skillId: 'k1' })
		expect(w.vm.agentOptions).toEqual([{ label: 'Bot', value: 'a1' }, { label: 'a2', value: 'a2' }])
		await w.vm.doInstall()
		expect(skills.installSkill).not.toHaveBeenCalled()
		expect(w.vm.canInstall).toBe(false)
		w.vm.selectedAgent = { value: 'a2' }
		await w.vm.doInstall()
		expect(skills.installSkill).toHaveBeenCalledWith('k1', 'a2')
		expect(w.emitted('installed')).toHaveLength(1)
	})

	it('has no agents when they cannot be listed, and shows a refused install', async () => {
		agentStore.fetchCollection.mockRejectedValueOnce(new Error('x'))
		const w = await mountIt(SkillInstallModal, { skillId: 'k1' })
		expect([w.vm.agents, w.vm.loadingAgents]).toEqual([[], false])
		agentStore.fetchCollection.mockResolvedValueOnce('odd')
		await w.vm.fetchAgents()
		expect(w.vm.agents).toEqual([])
		w.vm.selectedAgent = { value: 'a1' }
		skills.installSkill.mockRejectedValueOnce({ response: { data: { error: 'Already installed' } } })
		await w.vm.doInstall()
		expect([w.vm.installError, w.vm.installing]).toEqual(['Already installed', false])
	})
})

describe('AgentRatingsWidget', () => {
	it('loads the rating counts and the low ratings of the routed agent', async () => {
		analytics.getAnalytics.mockResolvedValueOnce({ feedback: { positive: 3, negative: 1, helpfulRate: 0.746 } })
		analytics.listLowRatings.mockResolvedValueOnce([{ id: 'm1' }])
		const w = await mountIt(AgentRatingsWidget, {})
		expect(analytics.getAnalytics).toHaveBeenCalledWith('a1')
		expect([w.vm.rated, w.vm.helpfulPercent, w.vm.lowRatings]).toEqual([4, 75, [{ id: 'm1' }]])
	})

	it('keeps the empty counts without feedback, loads nothing without an agent, and shows a failure', async () => {
		analytics.getAnalytics.mockResolvedValueOnce({})
		analytics.listLowRatings.mockResolvedValueOnce([])
		const w = await mountIt(AgentRatingsWidget, {})
		expect([w.vm.rated, w.vm.helpfulPercent]).toEqual([0, 0])
		analytics.getAnalytics.mockRejectedValueOnce(new Error('Analytics down'))
		await w.vm.load()
		expect([w.vm.error, w.vm.loading]).toEqual(['Analytics down', false])
		const none = await mountIt(AgentRatingsWidget, {}, {})
		expect(analytics.getAnalytics).toHaveBeenCalledTimes(2)
		expect([none.vm.formatDate(''), none.vm.formatDate('nope')]).toEqual(['', ''])
		expect(none.vm.formatDate('2026-01-01T00:00:00Z')).toBe(new Date('2026-01-01T00:00:00Z').toLocaleString())
	})
})

describe('StartFieldsEditor', () => {
	const fields = [{ key: 'topic', label: 'Topic', type: 'select' }, { key: 'when', type: 'date' }]

	it('explains the placeholder and offers the field types', async () => {
		const w = await mountIt(StartFieldsEditor, { modelValue: fields })
		expect(w.vm.hint).toContain('{{field.key}}')
		expect(w.vm.maxFields).toBe(10)
		expect(w.vm.typeOption('date').value).toBe('date')
		expect(w.vm.typeOption('weird').value).toBe('text')
	})

	it('validates keys and splits choice options', async () => {
		const w = await mountIt(StartFieldsEditor, { modelValue: fields })
		expect(['topic', 'Topic', '1a', '', null].map(w.vm.keyIsValid)).toEqual([true, false, false, false, false])
		expect(w.vm.splitOptions(' a, ,b ,')).toEqual(['a', 'b'])
		expect(w.vm.splitOptions(null)).toEqual([])
	})

	it('emits the changed, added and removed fields without mutating the given list', async () => {
		const w = await mountIt(StartFieldsEditor, { modelValue: fields })
		w.vm.change(1, { required: true })
		w.vm.add()
		w.vm.remove(0)
		const [changed, added, removed] = w.emitted('update:modelValue').map((e) => e[0])
		expect(changed[1]).toEqual({ key: 'when', type: 'date', required: true })
		expect(changed[0]).toEqual(fields[0])
		expect(added[2]).toEqual({ key: 'field_3', label: '', type: 'text', options: [], required: false, default: '' })
		expect(removed).toEqual([fields[1]])
		expect(fields).toHaveLength(2)
	})
})
