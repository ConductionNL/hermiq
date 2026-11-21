/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * SkillRowActions as rendered: which button runs which action, which buttons a
 * row shows, and how the scorecard, export, install and GitHub-publish modals
 * open and close. The actions themselves are covered by busy-actions.spec.js;
 * this pins the template's wiring to them.
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const api = vi.hoisted(() => ({
	approveSkill: vi.fn(),
	exportSkill: vi.fn(),
	publishSkill: vi.fn(),
	qualifySkill: vi.fn(),
	emit: vi.fn(),
}))

vi.mock('@nextcloud/event-bus', () => ({ emit: api.emit, subscribe: vi.fn(), unsubscribe: vi.fn() }))
vi.mock('../../src/api/skills.js', () => ({
	approveSkill: api.approveSkill,
	exportSkill: api.exportSkill,
	publishSkill: api.publishSkill,
	qualifySkill: api.qualifySkill,
}))

/**
 * A stand-in for one modal: its name, the props the widget passes, and its events.
 *
 * @param {string} name The modal's name.
 * @return {object} The module.
 */
function modalModule(name) {
	return {
		default: {
			name,
			props: ['result', 'skillName', 'exportedPackage', 'skillId'],
			emits: ['close', 'installed', 'published'],
			render: () => null,
		},
	}
}

vi.mock('../../src/modals/SkillScorecardModal.vue', () => modalModule('SkillScorecardModal'))
vi.mock('../../src/modals/SkillExportModal.vue', () => modalModule('SkillExportModal'))
vi.mock('../../src/modals/SkillInstallModal.vue', () => modalModule('SkillInstallModal'))
vi.mock('../../src/modals/SkillGithubPublishModal.vue', () => modalModule('SkillGithubPublishModal'))

const { default: SkillRowActions } = await import('../../src/widgets/SkillRowActions.vue')

/**
 * Render the actions for one row.
 *
 * @param {object} row The skill row.
 * @return {object} The wrapper.
 */
function render(row) {
	return mount(SkillRowActions, {
		props: { row },
		global: {
			mocks: { t: (app, text, vars) => (vars ? text.replace('{repoUrl}', vars.repoUrl) : text) },
		},
	})
}

/**
 * The button with this aria-label.
 *
 * @param {object} wrapper The wrapper.
 * @param {string} label The aria-label.
 * @return {object} The button component.
 */
function button(wrapper, label) {
	return wrapper.findAllComponents({ name: 'NcButton' }).find((b) => b.attributes('data-aria-label') === label)
}

/**
 * Click the button with this aria-label and let the action settle.
 *
 * @param {object} wrapper The wrapper.
 * @param {string} label The aria-label.
 * @return {Promise<void>}
 */
async function click(wrapper, label) {
	button(wrapper, label).vm.$emit('click')
	await flushPromises()
}

beforeEach(() => {
	vi.resetAllMocks()
})

describe('SkillRowActions buttons', () => {
	it('offers Approve only on a quarantined skill', () => {
		expect(button(render({ uuid: 'sk-1', state: 'active' }), 'Approve quarantined skill')).toBeUndefined()
		expect(button(render({ uuid: 'sk-1', state: 'quarantined' }), 'Approve quarantined skill')).toBeDefined()
	})

	it('runs approve, qualify and publish against the row\'s skill', async () => {
		api.qualifySkill.mockResolvedValue({ level: 2 })
		api.publishSkill.mockResolvedValue({})
		const wrapper = render({ uuid: 'sk-1', id: 'ignored', state: 'quarantined', name: 'Summarise' })

		await click(wrapper, 'Approve quarantined skill')
		await click(wrapper, 'Qualify skill maturity')
		await click(wrapper, 'Publish skill to a hub')

		expect(api.approveSkill).toHaveBeenCalledWith('sk-1')
		expect(api.qualifySkill).toHaveBeenCalledWith('sk-1')
		expect(api.publishSkill).toHaveBeenCalledWith('sk-1')
		const scorecard = wrapper.findComponent({ name: 'SkillScorecardModal' })
		expect(scorecard.props()).toMatchObject({ result: { level: 2 }, skillName: 'Summarise' })

		scorecard.vm.$emit('close')
		await flushPromises()
		expect(wrapper.findComponent({ name: 'SkillScorecardModal' }).exists()).toBe(false)
	})

	it('opens the exported package and closes it again', async () => {
		api.exportSkill.mockResolvedValue('---\nname: summarise\n---')
		const wrapper = render({ id: 'sk-2' })

		await click(wrapper, 'Export skill')

		expect(api.exportSkill).toHaveBeenCalledWith('sk-2')
		const modal = wrapper.findComponent({ name: 'SkillExportModal' })
		expect(modal.props('exportedPackage')).toBe('---\nname: summarise\n---')
		modal.vm.$emit('close')
		await flushPromises()
		expect(wrapper.findComponent({ name: 'SkillExportModal' }).exists()).toBe(false)
	})

	it('opens the install modal for the skill and closes it', async () => {
		const wrapper = render({ uuid: 'sk-3' })

		await click(wrapper, 'Install on agent')
		const modal = wrapper.findComponent({ name: 'SkillInstallModal' })
		expect(modal.props('skillId')).toBe('sk-3')

		modal.vm.$emit('close')
		await flushPromises()
		expect(wrapper.findComponent({ name: 'SkillInstallModal' }).exists()).toBe(false)
	})

	it('publishes to GitHub through its modal and says where', async () => {
		const wrapper = render({ uuid: 'sk-4' })

		await click(wrapper, 'Publish skill to GitHub')
		const modal = wrapper.findComponent({ name: 'SkillGithubPublishModal' })
		expect(modal.props('skillId')).toBe('sk-4')

		modal.vm.$emit('published', 'https://github.com/acme/skills')
		await flushPromises()
		expect(wrapper.findComponent({ name: 'SkillGithubPublishModal' }).exists()).toBe(false)
		expect(wrapper.text()).toContain('Published to https://github.com/acme/skills')
		expect(api.emit).toHaveBeenCalledWith('cn:page:refresh', {})

		await click(wrapper, 'Publish skill to GitHub')
		expect(wrapper.text()).not.toContain('Published to')
		wrapper.findComponent({ name: 'SkillGithubPublishModal' }).vm.$emit('close')
		await flushPromises()
		expect(wrapper.findComponent({ name: 'SkillGithubPublishModal' }).exists()).toBe(false)
	})
})
