/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Characterisation of the two "Publish to GitHub" modals (agent template and
 * skill): the credential read on mount, the picker of GitHub credentials, the
 * empty-credentials hint, when Publish is enabled, the publish payload, the
 * `published` event and a failed publish. Also the GitHub credential choices
 * of the template store and the agent git modal.
 */

import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const api = vi.hoisted(() => ({
	publishAgentTemplateToGithub: vi.fn(),
	publishSkillToGithub: vi.fn(),
}))

vi.mock('@conduction/nextcloud-vue', () => ({
	CnDataTable: { name: 'CnDataTable', render: () => null },
	createObjectStore: () => () => ({}),
}))
vi.mock('@nextcloud/event-bus', () => ({ emit: vi.fn(), subscribe: vi.fn(), unsubscribe: vi.fn() }))
vi.mock('../../src/api/agentTemplates.js', () => ({
	installGithubTemplate: vi.fn(),
	publishAgentTemplateToGithub: api.publishAgentTemplateToGithub,
	searchGithubTemplates: vi.fn(async () => ({ results: [] })),
}))
vi.mock('../../src/api/skills.js', () => ({
	installGithubSkill: vi.fn(),
	publishSkillToGithub: api.publishSkillToGithub,
	searchGithubSkills: vi.fn(async () => ({ results: [] })),
}))

const { default: AgentTemplatePublishModal } = await import('../../src/modals/AgentTemplatePublishModal.vue')
const { default: SkillGithubPublishModal } = await import('../../src/modals/SkillGithubPublishModal.vue')
const { default: AgentTemplateGithubStore } = await import('../../src/widgets/AgentTemplateGithubStore.vue')
const { default: AgentGitModal } = await import('../../src/modals/AgentGitModal.vue')

const credentials = [
	{ provider: 'github', id: 'c-1', name: 'Mine' },
	{ provider: 'openai', id: 'c-2', name: 'Not GitHub' },
	{ provider: 'github', id: 'c-3' },
]

/**
 * Mount one component the way the page does.
 *
 * @param {object} component The component.
 * @param {object} props Its props.
 * @return {Promise<object>} The wrapper, after the mount's requests settled.
 */
async function open(component, props) {
	const wrapper = mount(component, {
		props,
		global: { mocks: { t: (app, text, vars) => (vars ? `${text} ${JSON.stringify(vars)}` : text) } },
	})
	await flushPromises()
	return wrapper
}

/**
 * Fill the form: owner, repository, visibility and credential.
 *
 * @param {object} wrapper The mounted modal.
 * @param {Array} values The four values, in field order.
 * @return {Promise<void>}
 */
async function fill(wrapper, values) {
	const fields = wrapper.findAllComponents({ name: 'NcTextField' })
	const selects = wrapper.findAllComponents({ name: 'NcSelect' })
	fields[0].vm.pick(values[0])
	fields[1].vm.pick(values[1])
	if (values[2]) {
		selects[0].vm.pick(values[2])
	}
	selects[1].vm.pick(values[3])
	await flushPromises()
}

/**
 * The Publish button.
 *
 * @param {object} wrapper The mounted modal.
 * @return {object} The button wrapper.
 */
function publishButton(wrapper) {
	return wrapper.findAllComponents({ name: 'NcButton' }).at(-1)
}

beforeEach(() => {
	axios.get = vi.fn(async () => ({ data: { results: credentials } }))
	api.publishAgentTemplateToGithub.mockReset()
	api.publishSkillToGithub.mockReset()
})

describe.each([
	['AgentTemplatePublishModal', AgentTemplatePublishModal, { templateId: 'tpl-1' }, api.publishAgentTemplateToGithub],
	['SkillGithubPublishModal', SkillGithubPublishModal, { skillId: 'sk-1' }, api.publishSkillToGithub],
])('%s', (name, component, props, publish) => {
	it('reads the broker credentials once and offers the GitHub ones', async () => {
		const wrapper = await open(component, props)
		expect(axios.get.mock.calls).toEqual([['/index.php/apps/openregister/api/credentials']])
		expect(wrapper.html()).toMatchSnapshot()
	})

	it('shows the hint when the credentials cannot be read', async () => {
		axios.get = vi.fn(async () => {
			throw new Error('offline')
		})
		expect((await open(component, props)).html()).toMatchSnapshot()
	})

	it('shows the hint while no credential is in the response', async () => {
		axios.get = vi.fn(async () => ({ data: {} }))
		expect((await open(component, props)).html()).toMatchSnapshot()
	})

	it.each([
		['  ', 'repo', { value: 'c-1' }],
		['owner', ' ', { value: 'c-1' }],
		['owner', 'repo', null],
	])('keeps Publish disabled for owner %j, repo %j, credential %j', async (owner, repo, credential) => {
		const wrapper = await open(component, props)
		await fill(wrapper, [owner, repo, null, credential])
		expect(publishButton(wrapper).attributes('data-disabled')).toBe('true')
	})

	it('publishes the trimmed coordinates and reports the repository', async () => {
		publish.mockResolvedValue({ repoUrl: 'https://github.com/acme/repo' })
		const wrapper = await open(component, props)
		await fill(wrapper, [' acme ', ' repo ', { label: 'Public', value: 'public' }, { label: 'Mine', value: 'c-1' }])
		expect(publishButton(wrapper).attributes('data-disabled')).toBe('false')
		publishButton(wrapper).vm.$emit('click')
		await flushPromises()
		expect(publish.mock.calls).toEqual([[Object.values(props)[0], {
			owner: 'acme', repo: 'repo', visibility: 'public', credentialId: 'c-1',
		}]])
		expect(wrapper.emitted('published')).toEqual([['https://github.com/acme/repo']])
		expect(wrapper.html()).toMatchSnapshot()
	})

	it('publishes privately by default and shows a failure', async () => {
		let reject
		publish.mockImplementation(() => new Promise((resolve, no) => {
			reject = no
		}))
		const wrapper = await open(component, props)
		await fill(wrapper, ['acme', 'repo', null, { value: 'c-3' }])
		publishButton(wrapper).vm.$emit('click')
		await flushPromises()
		expect(wrapper.html()).toMatchSnapshot()
		reject({ response: { data: { error: 'Repository exists' } } })
		await flushPromises()
		expect(publish.mock.calls[0][1].visibility).toBe('private')
		expect(wrapper.emitted('published')).toBeUndefined()
		expect(wrapper.html()).toMatchSnapshot()
	})

	it('emits close from the modal', async () => {
		const wrapper = await open(component, props)
		wrapper.findComponent({ name: 'NcModal' }).vm.$emit('close')
		expect(wrapper.emitted('close')).toEqual([[]])
	})
})

describe('AgentTemplateGithubStore credentials', () => {
	it('reads the broker credentials on mount and offers the GitHub ones', async () => {
		const wrapper = await open(AgentTemplateGithubStore, {})
		expect(axios.get.mock.calls).toEqual([['/index.php/apps/openregister/api/credentials']])
		expect(wrapper.vm.githubCredentials).toEqual([
			{ label: 'Mine', value: 'c-1' },
			{ label: 'c-3', value: 'c-3' },
		])
		expect(wrapper.vm.loadingCredentials).toBe(false)
	})

	it('offers none when the read fails', async () => {
		axios.get = vi.fn(async () => {
			throw new Error('offline')
		})
		const wrapper = await open(AgentTemplateGithubStore, {})
		expect(wrapper.vm.credentials).toEqual([])
		expect(wrapper.vm.githubCredentials).toEqual([])
		expect(wrapper.vm.loadingCredentials).toBe(false)
	})
})

describe('AgentGitModal credentials', () => {
	it('offers the GitHub credentials it read', async () => {
		axios.get = vi.fn(async (url) => (url.endsWith('/credentials') ? { data: { results: credentials } } : { data: { id: 'a-1' } }))
		const wrapper = await open(AgentGitModal, { agentId: 'a-1', show: true })
		expect(wrapper.vm.githubCredentials).toEqual([
			{ label: 'Mine', value: 'c-1' },
			{ label: 'c-3', value: 'c-3' },
		])
	})
})
