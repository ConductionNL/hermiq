/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The skill row actions, mounted with the skills API and the event bus doubled
 * and the component library stubbed: the buttons and notes render their labels,
 * the behind-badge compares the published and accepted moments, an export opens
 * the package or shows the failure, and each modal opens, closes on its own
 * close event and, for publish and install, refreshes the list.
 */

import { flushPromises, shallowMount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const emitted = vi.hoisted(() => [])
vi.mock('@conduction/nextcloud-vue', () => ({
	CnDataTable: { name: 'CnDataTable', render: () => null },
	CnMarkdownEditor: { name: 'CnMarkdownEditor', render: () => null },
	createObjectStore: () => () => ({}),
}))
vi.mock('@nextcloud/event-bus', () => ({
	emit: (name, payload) => emitted.push([name, payload]),
	subscribe: () => {},
	unsubscribe: () => {},
}))
vi.mock('../../src/api/skills.js', () => ({
	approveSkill: vi.fn(),
	exportSkill: vi.fn(),
	publishSkill: vi.fn(),
	qualifySkill: vi.fn(),
}))

const skillsApi = await import('../../src/api/skills.js')
const { default: SkillRowActions } = await import('../../src/widgets/SkillRowActions.vue')

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
 * Mount the actions for one row.
 *
 * @param {object} row The skill row.
 * @return {object} The wrapper.
 */
function mountRow(row) {
	return shallowMount(SkillRowActions, { props: { row }, global: { mocks: { t } } })
}

beforeEach(() => {
	emitted.length = 0
	vi.clearAllMocks()
})

describe('SkillRowActions behind-badge', () => {
	it('shows when the accepted version is newer than the published copy', () => {
		const w = mountRow({
			uuid: 'sk-1',
			githubRepo: 'org/skill',
			publishedAt: '2026-01-01T00:00:00Z',
			lastAcceptedVersionAt: '2026-02-01T00:00:00Z',
		})

		expect(w.vm.publishedCopyBehind).toBe(true)
		expect(w.find('.skill-row-actions__behind-badge').text()).toBe('Published copy is behind')
	})

	it.each([
		['no repository', { publishedAt: '2026-01-01', lastAcceptedVersionAt: '2026-02-01' }],
		['never published', { githubRepo: 'org/skill', lastAcceptedVersionAt: '2026-02-01' }],
		['never accepted', { githubRepo: 'org/skill', publishedAt: '2026-01-01' }],
		['published after acceptance', { githubRepo: 'org/skill', publishedAt: '2026-03-01', lastAcceptedVersionAt: '2026-02-01' }],
		['published at acceptance', { githubRepo: 'org/skill', publishedAt: '2026-02-01', lastAcceptedVersionAt: '2026-02-01' }],
		['unparseable moment', { githubRepo: 'org/skill', publishedAt: 'soon', lastAcceptedVersionAt: '2026-02-01' }],
	])('stays hidden with %s', (label, extra) => {
		const w = mountRow({ uuid: 'sk-1', ...extra })

		expect(w.vm.publishedCopyBehind).toBe(false)
		expect(w.find('.skill-row-actions__behind-badge').exists()).toBe(false)
	})
})

describe('SkillRowActions export', () => {
	it('exports by uuid, falling back to id, and opens the package', async () => {
		skillsApi.exportSkill.mockResolvedValue('---\nname: x\n---')
		const w = mountRow({ id: 'sk-id-only' })
		w.vm.error = 'stale'

		await w.vm.doExport()

		expect(skillsApi.exportSkill).toHaveBeenCalledWith('sk-id-only')
		expect(w.vm.exportedPackage).toBe('---\nname: x\n---')
		expect(w.vm.showExport).toBe(true)
		expect(w.vm.error).toBe('')
	})

	it('shows the server message, the thrown message or the fallback on failure', async () => {
		const w = mountRow({ uuid: 'sk-1', id: 'ignored' })

		skillsApi.exportSkill.mockRejectedValueOnce({ response: { data: { error: 'No such skill' } } })
		await w.vm.doExport()
		expect(skillsApi.exportSkill).toHaveBeenLastCalledWith('sk-1')
		expect(w.vm.error).toBe('No such skill')
		expect(w.vm.showExport).toBe(false)

		skillsApi.exportSkill.mockRejectedValueOnce(new Error('Network down'))
		await w.vm.doExport()
		expect(w.vm.error).toBe('Network down')

		skillsApi.exportSkill.mockRejectedValueOnce({})
		await w.vm.doExport()
		expect(w.vm.error).toBe('Unknown error')
	})
})

describe('SkillRowActions modals', () => {
	it('opens the GitHub-publish modal with a cleared notice, and a publish closes it, notes the URL and refreshes', async () => {
		const w = mountRow({ uuid: 'sk-1' })
		w.vm.githubPublishNotice = 'old'

		w.vm.openGithubPublishModal()
		expect(w.vm.githubPublishNotice).toBe('')
		expect(w.vm.showGithubPublish).toBe(true)

		w.vm.onGithubPublished('https://github.com/org/skill')
		await flushPromises()
		expect(w.vm.showGithubPublish).toBe(false)
		expect(w.vm.githubPublishNotice).toBe('Published to https://github.com/org/skill')
		expect(emitted).toEqual([['cn:page:refresh', {}]])
	})

	it('opens the install modal, and an install closes it and refreshes', () => {
		const w = mountRow({ uuid: 'sk-1' })

		w.vm.openInstallModal()
		expect(w.vm.showInstall).toBe(true)
		expect(emitted).toEqual([])

		w.vm.onInstalled()
		expect(w.vm.showInstall).toBe(false)
		expect(emitted).toEqual([['cn:page:refresh', {}]])
	})
})

describe('SkillRowActions template', () => {
	it('renders every action label, the error and the publish notice', async () => {
		const w = shallowMount(SkillRowActions, {
			props: { row: { uuid: 'sk-1', state: 'quarantined' } },
			global: { mocks: { t }, renderStubDefaultSlot: true },
		})
		w.vm.error = 'Something failed'
		w.vm.githubPublishNotice = 'Published to x'
		await w.vm.$nextTick()

		const text = w.text()
		for (const label of ['Approve', 'Qualify', 'Export', 'Publish', 'Publish to GitHub', 'Install', 'Something failed', 'Published to x']) {
			expect(text).toContain(label)
		}
	})

	it.each([
		['SkillScorecardModal', 'scorecardResult', { levels: [] }, null],
		['SkillExportModal', 'showExport', true, false],
		['SkillInstallModal', 'showInstall', true, false],
		['SkillGithubPublishModal', 'showGithubPublish', true, false],
	])('%s closes on its close event', async (name, field, open, closed) => {
		const w = mountRow({ uuid: 'sk-1', name: 'Skill' })
		w.vm[field] = open
		await w.vm.$nextTick()

		const modal = w.findComponent({ name })
		expect(modal.exists()).toBe(true)
		modal.vm.$emit('close')
		await w.vm.$nextTick()

		expect(w.vm[field]).toBe(closed)
		expect(w.findComponent({ name }).exists()).toBe(false)
		expect(emitted).toEqual([])
	})
})
