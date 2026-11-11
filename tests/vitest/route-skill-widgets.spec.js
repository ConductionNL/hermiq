/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Characterisation of the skill detail widgets that load the skill of the
 * route themselves (learnings and provenance): the store registration, the
 * fetch of the route's skill on creation, the loading state, a missing skill,
 * and a failed fetch with the server's message, the thrown one or the fallback.
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const store = vi.hoisted(() => ({ calls: [], fetchObject: null }))

vi.mock('@conduction/nextcloud-vue', () => ({
	SAFE_MARKDOWN_DOMPURIFY_CONFIG: {},
	createObjectStore: () => () => ({}),
}))
vi.mock('../../src/store/store.js', () => ({
	useSkillStore: () => ({
		registerObjectType: (...args) => store.calls.push(['registerObjectType', ...args]),
		fetchObject: (...args) => {
			store.calls.push(['fetchObject', ...args])
			return store.fetchObject()
		},
	}),
}))

const widgets = {
	SkillLearnings: (await import('../../src/widgets/SkillLearnings.vue')).default,
	SkillProvenance: (await import('../../src/widgets/SkillProvenance.vue')).default,
}

const skill = {
	id: 'sk-1',
	state: 'quarantined',
	quarantineReason: 'content-changed',
	sourceUrl: 'https://github.com/acme/skill',
	files: [{ name: 'learnings.md', content: '# Learned' }],
	levelEvidence: { l6: { lastCaptureAt: '2026-01-02T03:04:05Z' } },
}

/**
 * Mount a widget on the skill detail route.
 *
 * @param {object} component The widget.
 * @return {object} The wrapper.
 */
function open(component) {
	return mount(component, {
		global: { mocks: { t: (app, text) => text, $route: { params: { id: 'sk-1' } } } },
	})
}

beforeEach(() => {
	store.calls = []
	store.fetchObject = async () => skill
})

describe.each(Object.entries(widgets))('%s', (name, component) => {
	it('registers the skill type and loads the route skill', async () => {
		let resolve
		store.fetchObject = () => new Promise((done) => {
			resolve = done
		})
		const wrapper = open(component)
		expect(wrapper.vm.loading).toBe(true)
		expect(wrapper.vm.skillId).toBe('sk-1')
		expect(wrapper.html()).toMatchSnapshot()
		resolve(skill)
		await flushPromises()
		expect(store.calls).toEqual([
			['registerObjectType', 'agentskill', 'agentskill', 'hermiq'],
			['fetchObject', 'agentskill', 'sk-1'],
		])
		expect(wrapper.vm.skill).toEqual(skill)
		expect(wrapper.vm.loading).toBe(false)
		expect(wrapper.vm.error).toBe('')
		expect(wrapper.html()).toMatchSnapshot()
	})

	it('holds no skill when the store has none', async () => {
		store.fetchObject = async () => undefined
		const wrapper = open(component)
		await flushPromises()
		expect(wrapper.vm.skill).toBeNull()
		expect(wrapper.html()).toMatchSnapshot()
	})

	it.each([
		[{ response: { data: { error: 'Not allowed' } }, message: 'Request failed' }, 'Not allowed'],
		[new Error('Network down'), 'Network down'],
		[{}, 'Could not load the skill.'],
	])('reports a failed fetch (%j)', async (failure, message) => {
		store.fetchObject = async () => {
			throw failure
		}
		const wrapper = open(component)
		await flushPromises()
		expect(wrapper.vm.error).toBe(message)
		expect(wrapper.vm.skill).toBeNull()
		expect(wrapper.vm.loading).toBe(false)
		expect(wrapper.html()).toMatchSnapshot()
	})

	it('clears a previous error when it loads again', async () => {
		const wrapper = open(component)
		await flushPromises()
		wrapper.vm.error = 'old'
		const reload = wrapper.vm.load()
		expect(wrapper.vm.loading).toBe(true)
		expect(wrapper.vm.error).toBe('')
		await reload
		expect(wrapper.vm.loading).toBe(false)
	})
})
