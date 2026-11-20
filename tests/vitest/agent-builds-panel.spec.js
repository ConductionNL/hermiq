/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * AgentBuildsPanel and PublishBuildModal (tools-agent-workspace-and-installed-tools
 * REQ-BUILD-001): the builds with files, tools, publisher and date, the pinned one
 * marked, "Unpublished changes since build N", and publishing from one of the
 * owner's own conversations with this agent. The API modules are doubled;
 * NcButton is a stub that emits click.
 */

import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

vi.mock('../../src/api/agentBuilds.js', () => ({
	listAgentBuilds: vi.fn(),
	publishAgentBuild: vi.fn(),
}))
vi.mock('../../src/api/chat.js', () => ({
	listSessions: vi.fn(),
}))

const { listAgentBuilds, publishAgentBuild } = await import('../../src/api/agentBuilds.js')
const { listSessions } = await import('../../src/api/chat.js')
const { default: AgentBuildsPanel } = await import('../../src/components/AgentBuildsPanel.vue')
const { default: PublishBuildModal } = await import('../../src/modals/PublishBuildModal.vue')

const NcButton = defineComponent({
	name: 'NcButton',
	inheritAttrs: false,
	emits: ['click'],
	render() {
		return h('button', { 'data-testid': this.$attrs['data-testid'], disabled: this.$attrs.disabled, onClick: () => this.$emit('click') }, this.$slots.default?.())
	},
})

const NcModal = defineComponent({
	name: 'NcModal',
	render() {
		return h('div', { class: 'nc-modal' }, this.$slots.default?.())
	},
})

const NcSelect = defineComponent({
	name: 'NcSelect',
	props: { modelValue: { type: Object, default: null }, options: { type: Array, default: () => [] } },
	emits: ['update:modelValue'],
	render() {
		return h('div', { class: 'nc-select' }, this.options.map((option) => h('button', { class: 'nc-select__option', onClick: () => this.$emit('update:modelValue', option) }, option.label)))
	},
})

const NcTextArea = defineComponent({
	name: 'NcTextArea',
	props: { modelValue: { type: String, default: '' } },
	emits: ['update:modelValue'],
	render() {
		return h('textarea', { class: 'nc-textarea', value: this.modelValue, onInput: (e) => this.$emit('update:modelValue', e.target.value) })
	},
})

const NcNoteCard = defineComponent({
	name: 'NcNoteCard',
	render() {
		return h('div', { class: 'nc-note-card' }, this.$slots.default?.())
	},
})

const translate = (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (m, k) => String(vars[k] ?? m))
const pluralize = (app, one, many, count) => (count === 1 ? one : many).replace('%n', String(count))

const BUILD_3 = {
	id: 'build-3',
	number: 3,
	files: [{ path: 'sjablonen/kwartaal.md', size: 11 }, { path: 'sjablonen/bijlage.csv', size: 4 }],
	tools: [{ manager: 'pip', package: 'csvkit', version: '2.1.0', sha256: 'abc' }],
	note: 'Sjablonen kwartaalrapportage',
	createdBy: 'alice',
	createdByName: 'Alice de Vries',
	createdAt: '2026-11-14T10:00:00+00:00',
}

const BUILD_2 = { ...BUILD_3, id: 'build-2', number: 2, files: [], tools: [], note: '' }

/**
 * Mount the panel on an agent's page.
 *
 * @return {Promise<object>} The wrapper.
 */
async function panel() {
	const wrapper = mount(AgentBuildsPanel, {
		global: {
			stubs: { NcButton, NcModal, NcNoteCard, NcSelect, NcTextArea, NcLoadingIcon: true },
			mocks: { t: translate, n: pluralize, $route: { params: { id: 'agent-1' } } },
		},
	})
	await flushPromises()
	return wrapper
}

/**
 * Mount the modal open for an agent.
 *
 * @return {Promise<object>} The wrapper.
 */
async function modal() {
	const wrapper = mount(PublishBuildModal, {
		props: { show: true, agentId: 'agent-1' },
		global: {
			stubs: { NcButton, NcModal, NcNoteCard, NcSelect, NcTextArea },
			mocks: { t: translate },
		},
	})
	await flushPromises()
	return wrapper
}

afterEach(() => {
	vi.clearAllMocks()
})

describe('AgentBuildsPanel', () => {
	it('lists each build with publisher, date, note, files and tools, and marks the pinned one', async () => {
		listAgentBuilds.mockResolvedValue({ builds: [BUILD_3, BUILD_2], pinnedBuildId: 'build-3', pinnedBuildNumber: 3, unpublishedChanges: false, canPublish: true })

		const wrapper = await panel()

		expect(listAgentBuilds).toHaveBeenCalledWith('agent-1')
		const rows = wrapper.findAll('[data-testid="agent-builds-row"]')
		expect(rows).toHaveLength(2)
		expect(rows[0].find('.agent-builds__name').text()).toBe('Build 3')
		expect(rows[0].find('.agent-builds__meta').text()).toMatch(/^published by Alice de Vries on /)
		expect(rows[0].find('.agent-builds__note').text()).toBe('Sjablonen kwartaalrapportage')
		expect(rows[0].find('.agent-builds__counts').text()).toBe('2 files, 1 tool')
		expect(rows[0].find('[data-testid="agent-builds-pinned"]').exists()).toBe(true)
		expect(rows[1].find('[data-testid="agent-builds-pinned"]').exists()).toBe(false)
		expect(rows[1].find('.agent-builds__counts').text()).toBe('0 files, 0 tools')
		expect(wrapper.find('[data-testid="agent-builds-unpublished"]').exists()).toBe(false)
	})

	it('says there are unpublished changes since the pinned build', async () => {
		listAgentBuilds.mockResolvedValue({ builds: [BUILD_3], pinnedBuildId: 'build-3', pinnedBuildNumber: 3, unpublishedChanges: true, canPublish: true })

		const wrapper = await panel()

		expect(wrapper.find('[data-testid="agent-builds-unpublished"]').text()).toBe('Unpublished changes since build 3')
	})

	it('offers Publish build only to someone who may modify the agent', async () => {
		listAgentBuilds.mockResolvedValue({ builds: [], pinnedBuildId: null, pinnedBuildNumber: null, unpublishedChanges: false, canPublish: false })
		const reader = await panel()
		expect(reader.find('[data-testid="agent-builds-publish"]').exists()).toBe(false)
		expect(reader.text()).toContain('No build published yet.')

		listAgentBuilds.mockResolvedValue({ builds: [], pinnedBuildId: null, pinnedBuildNumber: null, unpublishedChanges: false, canPublish: true })
		const owner = await panel()
		expect(owner.find('[data-testid="agent-builds-publish"]').exists()).toBe(true)
	})

	it('reloads the builds after a publish', async () => {
		listAgentBuilds.mockResolvedValue({ builds: [], pinnedBuildId: null, pinnedBuildNumber: null, unpublishedChanges: false, canPublish: true })
		listSessions.mockResolvedValue({ results: [], total: 0 })
		const wrapper = await panel()

		await wrapper.find('[data-testid="agent-builds-publish"]').trigger('click')
		listAgentBuilds.mockResolvedValue({ builds: [BUILD_3], pinnedBuildId: 'build-3', pinnedBuildNumber: 3, unpublishedChanges: false, canPublish: true })
		await wrapper.findComponent(PublishBuildModal).vm.$emit('published', BUILD_3)
		await flushPromises()

		expect(listAgentBuilds).toHaveBeenCalledTimes(2)
		expect(wrapper.findAll('[data-testid="agent-builds-row"]')).toHaveLength(1)
		expect(wrapper.findComponent(PublishBuildModal).props('show')).toBe(false)
	})
})

describe('PublishBuildModal', () => {
	it('offers only the caller\'s conversations with this agent and publishes the chosen one with the note', async () => {
		listSessions.mockResolvedValue({
			results: [
				{ uuid: 'conv-1', agentId: 'agent-1', title: 'Kwartaal Q3' },
				{ uuid: 'conv-2', agentId: 'agent-2', title: 'Other agent' },
				{ uuid: 'conv-3', agentId: 'agent-1', title: '' },
			],
			total: 3,
		})
		publishAgentBuild.mockResolvedValue(BUILD_3)

		const wrapper = await modal()

		const options = wrapper.findAll('.nc-select__option')
		expect(options.map((o) => o.text())).toEqual(['Kwartaal Q3', 'Untitled conversation'])
		expect(wrapper.find('[data-testid="publish-build-submit"]').attributes('disabled')).toBeDefined()

		await options[0].trigger('click')
		await wrapper.find('.nc-textarea').setValue('Sjablonen kwartaalrapportage')
		await wrapper.find('[data-testid="publish-build-submit"]').trigger('click')
		await flushPromises()

		expect(publishAgentBuild).toHaveBeenCalledWith('agent-1', 'conv-1', 'Sjablonen kwartaalrapportage')
		expect(wrapper.emitted('published')[0]).toEqual([BUILD_3])
	})

	it('names the reason when the conversation has no workspace', async () => {
		listSessions.mockResolvedValue({ results: [{ uuid: 'conv-1', agentId: 'agent-1', title: 'Leeg' }], total: 1 })
		publishAgentBuild.mockRejectedValue({ response: { status: 400 } })

		const wrapper = await modal()
		await wrapper.find('.nc-select__option').trigger('click')
		await wrapper.find('[data-testid="publish-build-submit"]').trigger('click')
		await flushPromises()

		expect(wrapper.text()).toContain('This conversation has no workspace to publish.')
		expect(wrapper.emitted('published')).toBeUndefined()
	})
})
