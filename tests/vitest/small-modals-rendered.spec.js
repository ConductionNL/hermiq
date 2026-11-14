/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The image, participants, skill install and export, webhook secret and
 * keep-in-git dialogs, mounted in full over the @nextcloud/vue stand-ins: what each shows,
 * which events its buttons and modal raise, and how a failed call reads.
 */

import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const toasts = []
vi.mock('@nextcloud/dialogs', () => ({
	showError: (m) => toasts.push(['error', m]),
	showSuccess: (m) => toasts.push(['success', m]),
}))
vi.mock('../../src/api/chat.js', () => ({
	createChatImage: vi.fn(),
	listParticipants: vi.fn(),
	addParticipant: vi.fn(),
	removeParticipant: vi.fn(),
	searchUsers: vi.fn(),
}))
vi.mock('../../src/api/skills.js', () => ({ installSkill: vi.fn() }))
vi.mock('../../src/api/agents.js', () => ({
	applyAgentPull: vi.fn(),
	previewAgentPull: vi.fn(),
	publishAgentToGit: vi.fn(),
	pushAgentToGit: vi.fn(),
}))
const agentStore = { registerObjectType: vi.fn(), fetchCollection: vi.fn() }
vi.mock('../../src/store/store.js', () => ({ useAgentStore: () => agentStore }))

const chat = await import('../../src/api/chat.js')
const skills = await import('../../src/api/skills.js')
const agentsApi = await import('../../src/api/agents.js')
const { default: GenerateImageModal } = await import('../../src/modals/GenerateImageModal.vue')
const { default: SessionParticipantsModal } = await import('../../src/modals/SessionParticipantsModal.vue')
const { default: SkillInstallModal } = await import('../../src/modals/SkillInstallModal.vue')
const { default: SkillExportModal } = await import('../../src/modals/SkillExportModal.vue')
const { default: WebhookSecretDialog } = await import('../../src/modals/WebhookSecretDialog.vue')
const { default: AgentGitModal } = await import('../../src/modals/AgentGitModal.vue')

/**
 * Translate as the identity, filling {placeholders}.
 *
 * @param {string} app The app id.
 * @param {string} text The text.
 * @param {object} vars The placeholders.
 * @return {string} The filled text.
 */
const t = (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m))
globalThis.t = t

/**
 * Mount a component in full and let it settle.
 *
 * @param {object} component The component.
 * @param {object} [props] Its props.
 * @return {Promise<object>} The wrapper.
 */
async function render(component, props = {}) {
	const w = mount(component, { props, global: { mocks: { t } } })
	await flushPromises()
	return w
}

/**
 * Mount a dialog closed, then open it so its open-watcher runs.
 *
 * @param {object} component The component.
 * @param {object} props Its props, without `show`.
 * @return {Promise<object>} The wrapper.
 */
async function open(component, props) {
	const w = await render(component, { ...props, show: false })
	await w.setProps({ show: true })
	await flushPromises()
	return w
}

/**
 * The stand-in buttons of a wrapper, by their visible text.
 *
 * @param {object} w The wrapper.
 * @param {string} text The button text.
 * @return {object} The button component.
 */
function button(w, text) {
	return w.findAllComponents({ name: 'NcButton' }).find((b) => b.text().includes(text))
}

beforeEach(() => {
	vi.clearAllMocks()
	toasts.length = 0
})

describe('GenerateImageModal rendered', () => {
	it('shows its title and help, and closes from the modal and from Cancel', async () => {
		const w = await open(GenerateImageModal, { session: { uuid: 's1' } })

		expect(w.find('[data-testid="image-form"] h2').text()).toBe('Create an image')
		expect(w.find('.image-form__help').text()).toContain('Hermiq/Generated images')
		w.findComponent({ name: 'NcModal' }).vm.$emit('close')
		button(w, 'Cancel').vm.$emit('click')
		expect(w.emitted('close')).toHaveLength(2)
	})

	it('only enables Create for a description, and shows the server error', async () => {
		const w = await open(GenerateImageModal, { session: { uuid: 's1' } })
		const create = () => button(w, 'Create')

		expect(create().attributes('data-disabled')).toBe('true')
		w.findComponent({ name: 'NcTextArea' }).vm.pick('  a canal at dusk ')
		await flushPromises()
		expect(create().attributes('data-disabled')).toBe('false')

		chat.createChatImage.mockRejectedValueOnce({ response: { data: { error: 'No image provider is configured.' } } })
		create().vm.$emit('click')
		await flushPromises()

		expect(chat.createChatImage).toHaveBeenCalledWith('s1', 'a canal at dusk')
		expect(w.findComponent({ name: 'NcNoteCard' }).text()).toBe('No image provider is configured.')
		expect(w.emitted('created')).toBeUndefined()
	})

	it('shows a spinner while creating and clears the form when reopened', async () => {
		let finish
		chat.createChatImage.mockReturnValueOnce(new Promise((resolve) => { finish = resolve }))
		const w = await open(GenerateImageModal, { session: { uuid: 's1' } })
		w.findComponent({ name: 'NcTextArea' }).vm.pick('a tram')
		await flushPromises()

		button(w, 'Create').vm.$emit('click')
		await flushPromises()
		expect(w.findComponent({ name: 'NcLoadingIcon' }).exists()).toBe(true)
		expect(w.findComponent({ name: 'NcTextArea' }).attributes('data-disabled')).toBe('true')

		finish({ userTurn: { id: 1 }, assistantTurn: { id: 2 } })
		await flushPromises()
		expect(w.emitted('created')).toEqual([[{ userTurn: { id: 1 }, assistantTurn: { id: 2 } }]])
		expect(w.findComponent({ name: 'NcLoadingIcon' }).exists()).toBe(false)

		w.vm.error = 'stale'
		await w.setProps({ show: false })
		await w.setProps({ show: true })
		expect([w.vm.prompt, w.vm.error]).toEqual(['', ''])
	})
})

describe('SessionParticipantsModal rendered', () => {
	const session = { uuid: 's1', userId: 'owner' }

	it('lists the participants when opened, or says only the owner is in', async () => {
		chat.listParticipants.mockResolvedValueOnce([])
		const w = await open(SessionParticipantsModal, { session })
		expect(chat.listParticipants).toHaveBeenCalledWith('s1')
		expect(w.find('.session-participants__list').text()).toBe('Only you are in this session.')

		chat.listParticipants.mockResolvedValueOnce([{ uid: 'ann', displayName: 'Ann' }, { uid: 'bo', displayName: 'Bo' }])
		await w.setProps({ show: false })
		await w.setProps({ show: true })
		await flushPromises()
		expect(w.findAll('.session-participants__row span').map((s) => s.text())).toEqual(['Ann', 'Bo'])
	})

	it('searches from two characters on, leaving out the owner and current participants', async () => {
		chat.listParticipants.mockResolvedValueOnce([{ uid: 'ann', displayName: 'Ann' }])
		const w = await open(SessionParticipantsModal, { session })
		const select = w.findComponent({ name: 'NcSelect' })

		select.vm.$emit('search', 'a')
		await flushPromises()
		expect(chat.searchUsers).not.toHaveBeenCalled()
		expect(w.vm.options).toEqual([])

		chat.searchUsers.mockResolvedValueOnce([
			{ uid: 'owner', displayName: 'Owner' },
			{ uid: 'ann', displayName: 'Ann' },
			{ uid: 'cas', displayName: 'Cas' },
		])
		select.vm.$emit('search', 'an')
		await flushPromises()
		expect(chat.searchUsers).toHaveBeenCalledWith('an')
		expect(w.vm.options).toEqual([{ uid: 'cas', displayName: 'Cas' }])
		expect(w.vm.searching).toBe(false)

		chat.searchUsers.mockRejectedValueOnce(new Error('down'))
		select.vm.$emit('search', 'ca')
		await flushPromises()
		expect(w.vm.options).toEqual([])
	})

	it('invites the selected colleague and removes one, reporting each change', async () => {
		chat.listParticipants.mockResolvedValueOnce([])
		const w = await open(SessionParticipantsModal, { session })
		expect(button(w, 'Invite').attributes('data-disabled')).toBe('true')

		w.findComponent({ name: 'NcSelect' }).vm.pick({ uid: 'cas', displayName: 'Cas' })
		await flushPromises()
		const roster = [{ uid: 'cas', displayName: 'Cas' }]
		chat.addParticipant.mockResolvedValueOnce(roster)
		button(w, 'Invite').vm.$emit('click')
		await flushPromises()
		expect(chat.addParticipant).toHaveBeenCalledWith('s1', 'cas')
		expect(w.vm.selected).toBe(null)
		expect(w.find('.session-participants__row span').text()).toBe('Cas')

		chat.removeParticipant.mockResolvedValueOnce([])
		button(w, 'Remove').vm.$emit('click')
		await flushPromises()
		expect(chat.removeParticipant).toHaveBeenCalledWith('s1', 'cas')
		expect(w.emitted('changed')).toEqual([[roster], [[]]])
	})

	it('explains a refused change by its status', async () => {
		chat.listParticipants.mockRejectedValueOnce({ response: { status: 500 } })
		const w = await open(SessionParticipantsModal, { session })
		expect(w.findComponent({ name: 'NcNoteCard' }).text()).toBe('The change could not be saved.')

		w.vm.selected = { uid: 'cas' }
		chat.addParticipant.mockRejectedValueOnce({ response: { status: 409 } })
		await w.vm.add()
		expect(w.vm.error).toBe('This session belongs to a Talk room. Invite the person to the room instead.')

		chat.removeParticipant.mockRejectedValueOnce({ response: { status: 400 } })
		await w.vm.remove({ uid: 'ghost' })
		expect(w.vm.error).toBe('There is no user with that name.')
		expect(w.vm.busy).toBe(false)
		expect(w.emitted('changed')).toBeUndefined()

		w.findComponent({ name: 'NcModal' }).vm.$emit('close')
		expect(w.emitted('close')).toHaveLength(1)
	})
})

describe('SkillInstallModal rendered', () => {
	it('offers the agents and installs the skill on the chosen one', async () => {
		agentStore.fetchCollection.mockResolvedValueOnce([{ uuid: 'a1', name: 'Clerk' }, { id: 'a2' }])
		skills.installSkill.mockResolvedValueOnce({})
		const w = await render(SkillInstallModal, { skillId: 'sk-1' })

		expect(agentStore.registerObjectType).toHaveBeenCalledWith('agent', 'agent', 'hermiq')
		expect(JSON.parse(w.findComponent({ name: 'NcSelect' }).attributes('data-options'))).toEqual([
			{ label: 'Clerk', value: 'a1' },
			{ label: 'a2', value: 'a2' },
		])
		expect(w.find('.skill-install-modal__hint').exists()).toBe(false)
		expect(button(w, 'Install').attributes('data-disabled')).toBe('true')

		w.findComponent({ name: 'NcSelect' }).vm.pick({ label: 'Clerk', value: 'a1' })
		await flushPromises()
		button(w, 'Install').vm.$emit('click')
		await flushPromises()

		expect(skills.installSkill).toHaveBeenCalledWith('sk-1', 'a1')
		expect(w.emitted('installed')).toHaveLength(1)
	})

	it('hints at creating an agent when there is none, and shows a failed install', async () => {
		agentStore.fetchCollection.mockRejectedValueOnce(new Error('store down'))
		const w = await render(SkillInstallModal, { skillId: 'sk-1' })
		expect(w.find('.skill-install-modal__hint').text()).toContain('No agents yet.')

		await w.vm.doInstall()
		expect(skills.installSkill).not.toHaveBeenCalled()

		w.vm.selectedAgent = { label: 'Clerk', value: 'a1' }
		let fail
		skills.installSkill.mockReturnValueOnce(new Promise((resolve, reject) => { fail = reject }))
		const pending = w.vm.doInstall()
		await flushPromises()
		expect(button(w, 'Installing').exists()).toBe(true)

		fail({ response: { data: { error: 'Skill is quarantined.' } } })
		await pending
		await flushPromises()
		expect(w.findComponent({ name: 'NcNoteCard' }).text()).toBe('Skill is quarantined.')
		expect(w.emitted('installed')).toBeUndefined()

		w.findComponent({ name: 'NcModal' }).vm.$emit('close')
		expect(w.emitted('close')).toHaveLength(1)
	})

	it('treats a non-list answer as no agents', async () => {
		agentStore.fetchCollection.mockResolvedValueOnce({ results: [] })
		const w = await render(SkillInstallModal, { skillId: 'sk-1' })

		expect(w.vm.agents).toEqual([])
		expect(w.vm.loadingAgents).toBe(false)
	})
})

describe('SkillExportModal rendered', () => {
	it('shows the package read-only and closes from the modal', async () => {
		const w = await render(SkillExportModal, { exportedPackage: '---\nname: x\n---\nBody' })
		const area = w.find('textarea')

		expect(area.element.value).toBe('---\nname: x\n---\nBody')
		expect(area.attributes('readonly')).toBeDefined()
		expect(w.find('.skill-export-modal__label span').text()).toBe('Package contents')
		w.findComponent({ name: 'NcModal' }).vm.$emit('close')
		expect(w.emitted('close')).toHaveLength(1)
	})
})

describe('WebhookSecretDialog rendered', () => {
	it('shows the secret once with its header hint, copies it, and closes', async () => {
		const writeText = vi.fn().mockResolvedValue(undefined)
		Object.defineProperty(globalThis.navigator, 'clipboard', { value: { writeText }, configurable: true })
		const w = await render(WebhookSecretDialog, { show: true, secret: 'whsec_123' })

		expect(w.findComponent({ name: 'NcTextField' }).attributes('data-modelvalue')).toBe('whsec_123')
		expect(w.find('.webhook-secret-dialog__hint').text()).toContain('X-Hermiq-Webhook-Secret')
		button(w, 'Copy to clipboard').vm.$emit('click')
		await flushPromises()
		expect(writeText).toHaveBeenCalledWith('whsec_123')
		expect(toasts).toEqual([['success', 'Secret copied to clipboard.']])

		button(w, 'Done').vm.$emit('click')
		w.findComponent({ name: 'NcModal' }).vm.$emit('close')
		expect(w.emitted('close')).toHaveLength(2)
	})
})

describe('AgentGitModal rendered', () => {
	/**
	 * Answer the agent and credential reads.
	 *
	 * @param {object|Error} agent The agent, or an error to throw.
	 * @return {void}
	 */
	function serve(agent) {
		vi.spyOn(axios, 'get').mockImplementation(async (url) => {
			if (agent instanceof Error) {
				throw agent
			}
			if (url.includes('/credentials')) {
				return { data: { results: [{ id: 'c1', provider: 'github', name: 'GH' }] } }
			}
			return { data: agent }
		})
	}

	it('renders nothing while closed', async () => {
		serve({ uuid: 'a1' })
		const w = await render(AgentGitModal, { show: false, agentId: 'a1' })

		expect(w.findComponent({ name: 'NcModal' }).exists()).toBe(false)
		expect(axios.get).not.toHaveBeenCalled()
	})

	it('offers publishing for an agent not kept in git, and only with every field filled', async () => {
		serve({ uuid: 'a1' })
		const w = await render(AgentGitModal, { show: true, agentId: 'a1' })
		const publish = () => button(w, 'Publish to GitHub')

		expect(w.find('.agent-git p').text()).toContain('Publish this agent to a new GitHub repository.')
		expect(w.findAllComponents({ name: 'NcTextField' })).toHaveLength(2)
		expect(button(w, 'Push changes')).toBeUndefined()
		expect(publish().attributes('data-disabled')).toBe('true')

		w.findComponent({ name: 'NcSelect' }).vm.pick({ label: 'GH', value: 'c1' })
		const [owner, repo] = w.findAllComponents({ name: 'NcTextField' })
		owner.vm.pick('acme')
		repo.vm.pick('clerk-agent')
		await flushPromises()
		expect(publish().attributes('data-disabled')).toBe('false')

		agentsApi.publishAgentToGit.mockResolvedValueOnce({ repoUrl: 'https://github.com/acme/clerk-agent' })
		publish().vm.$emit('click')
		await flushPromises()

		expect(w.findAllComponents({ name: 'NcNoteCard' }).map((n) => n.text())).toEqual(['Published to https://github.com/acme/clerk-agent'])
		expect(w.find('.agent-git a').attributes('href')).toBe('https://github.com/acme/clerk-agent')
		expect(button(w, 'Push changes').exists()).toBe(true)
		expect(button(w, 'Pull from git').exists()).toBe(true)
		expect(w.findAllComponents({ name: 'NcTextField' })).toHaveLength(0)
	})

	it('shows the pulled changes as a table and takes them on confirmation', async () => {
		serve({ uuid: 'a1', gitOwner: 'acme', gitRepo: 'clerk' })
		const w = await render(AgentGitModal, { show: true, agentId: 'a1' })
		w.findComponent({ name: 'NcSelect' }).vm.pick({ label: 'GH', value: 'c1' })
		await flushPromises()

		agentsApi.previewAgentPull.mockResolvedValueOnce({
			changes: [
				{ field: 'prompt', from: 'Old', to: 'New' },
				{ field: 'tools', from: ['search'], to: ['search', 'mail'] },
				{ field: 'colour', from: null, to: 'blue' },
			],
		})
		button(w, 'Pull from git').vm.$emit('click')
		await flushPromises()

		expect(agentsApi.previewAgentPull).toHaveBeenCalledWith('a1', 'c1')
		const rows = w.findAll('tbody tr').map((tr) => tr.findAll('td').map((td) => td.text()))
		expect(rows).toEqual([
			['Instructions', 'Old', 'New'],
			['Tools', 'search', 'search, mail'],
			['colour', '', 'blue'],
		])
		expect(button(w, 'Pull from git')).toBeUndefined()

		agentsApi.applyAgentPull.mockResolvedValueOnce({ uuid: 'a1', gitOwner: 'acme', gitRepo: 'clerk' })
		button(w, 'Take these changes').vm.$emit('click')
		await flushPromises()

		expect(agentsApi.applyAgentPull).toHaveBeenCalledWith('a1', 'c1')
		expect(w.find('.agent-git__changes').exists()).toBe(false)
		expect(w.findComponent({ name: 'NcNoteCard' }).text()).toContain('The agent now matches the repository.')
	})

	it('says when the agent already matches, and shows a failed push', async () => {
		serve({ uuid: 'a1', gitOwner: 'acme', gitRepo: 'clerk' })
		const w = await render(AgentGitModal, { show: true, agentId: 'a1' })
		w.findComponent({ name: 'NcSelect' }).vm.pick({ label: 'GH', value: 'c1' })
		await flushPromises()

		agentsApi.previewAgentPull.mockResolvedValueOnce({ changes: [] })
		button(w, 'Pull from git').vm.$emit('click')
		await flushPromises()
		expect(w.find('.agent-git__changes').text()).toBe('The agent already matches the repository.')
		expect(button(w, 'Take these changes')).toBeUndefined()

		agentsApi.pushAgentToGit.mockRejectedValueOnce({ response: { data: { error: 'Repository is archived.' } } })
		button(w, 'Push changes').vm.$emit('click')
		await flushPromises()
		expect(w.findComponent({ name: 'NcNoteCard' }).text()).toBe('Repository is archived.')

		agentsApi.pushAgentToGit.mockRejectedValueOnce(new Error('offline'))
		button(w, 'Push changes').vm.$emit('click')
		await flushPromises()
		expect(w.findComponent({ name: 'NcNoteCard' }).text()).toBe('Keep in git failed')

		button(w, 'Close').vm.$emit('click')
		w.findComponent({ name: 'NcModal' }).vm.$emit('close')
		expect(w.emitted('close')).toHaveLength(2)
	})

	it('says when the agent cannot be loaded', async () => {
		serve(new Error('404'))
		const w = await render(AgentGitModal, { show: true, agentId: 'gone' })

		expect(w.findComponent({ name: 'NcNoteCard' }).text()).toBe('Could not load this agent')
		expect(w.vm.loadingCredentials).toBe(false)
	})
})
