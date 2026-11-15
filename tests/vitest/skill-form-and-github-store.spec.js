/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The skill form (create, edit, paste a package, extra files) and the GitHub
 * store of agent templates and skills (search, kind filter, install), mounted
 * with axios and the API doubled.
 */

import axios from '@nextcloud/axios'
import { flushPromises, shallowMount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const bus = []
vi.mock('@nextcloud/event-bus', () => ({ emit: (name, payload) => bus.push([name, payload]) }))
vi.mock('@conduction/nextcloud-vue', () => ({ CnMarkdownEditor: { name: 'CnMarkdownEditor', render: () => null } }))
vi.mock('../../src/api/skills.js', () => ({
	importSkill: vi.fn(),
	installFromSource: vi.fn(),
	updateSkill: vi.fn(),
	installGithubSkill: vi.fn(),
	searchGithubSkills: vi.fn(),
}))
vi.mock('../../src/api/agentTemplates.js', () => ({ installGithubTemplate: vi.fn(), searchGithubTemplates: vi.fn() }))

const skills = await import('../../src/api/skills.js')
const templates = await import('../../src/api/agentTemplates.js')
const { default: SkillFormModal } = await import('../../src/modals/SkillFormModal.vue')
const { default: AgentTemplateGithubStore } = await import('../../src/widgets/AgentTemplateGithubStore.vue')

/**
 * Translate as the identity, filling {placeholders}.
 *
 * @param {string} app The app id.
 * @param {string} text The text.
 * @param {object} vars The placeholders.
 * @return {string} The filled text.
 */
const t = (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m))

beforeEach(() => {
	bus.length = 0
	vi.clearAllMocks()
})

afterEach(() => {
	vi.restoreAllMocks()
	vi.useRealTimers()
})

describe('SkillFormModal', () => {
	/**
	 * Mount the form closed, then open it.
	 *
	 * @param {object} props The props besides show.
	 * @return {Promise<object>} The wrapper.
	 */
	async function openForm(props = {}) {
		const w = shallowMount(SkillFormModal, { props: { show: false, ...props }, global: { mocks: { t } } })
		await w.setProps({ show: true })
		return w
	}

	it('opens blank with the given body, offering to paste a package', async () => {
		const w = await openForm({ initialBody: 'Steps' })
		expect([w.vm.heading, w.vm.showPastePackage, w.vm.form.body, w.vm.files]).toEqual(['Create skill', true, 'Steps', []])
	})

	it('opens an existing skill with its fields and files', async () => {
		const w = await openForm({ item: { id: 'k1', name: 'N', description: 'D', frontmatter: 'x: 1', body: 'B', files: [{ name: 'a.md' }, { content: 'c' }] } })
		expect([w.vm.heading, w.vm.showPastePackage]).toEqual(['Edit skill', false])
		expect(w.vm.form).toEqual({ name: 'N', description: 'D', frontmatter: 'x: 1', body: 'B' })
		expect(w.vm.files).toEqual([{ name: 'a.md', content: '' }, { name: '', content: 'c' }])
	})

	it('adds, renames and removes extra files, refusing a duplicate name', async () => {
		const w = await openForm()
		w.vm.newFileName = '  '
		w.vm.addFile()
		expect(w.vm.files).toEqual([])
		w.vm.newFileName = ' notes.md '
		w.vm.addFile()
		expect([w.vm.files, w.vm.newFileName]).toEqual([[{ name: 'notes.md', content: '' }], ''])
		w.vm.newFileName = 'notes.md'
		w.vm.addFile()
		expect(w.vm.error).toBe('A file named "notes.md" already exists.')
		w.vm.renameFile(0, ' ')
		w.vm.renameFile(0, ' guide.mdx ')
		expect(w.vm.files[0].name).toBe('guide.mdx')
		expect([w.vm.isMarkdownFile('guide.mdx'), w.vm.isMarkdownFile('a.json'), w.vm.isMarkdownFile(null)]).toEqual([true, false, false])
		w.vm.removeFile(0)
		expect(w.vm.files).toEqual([])
	})

	it('builds the package frontmatter, adding name and description only when missing', async () => {
		const w = await openForm()
		Object.assign(w.vm.form, { name: 'N', description: 'D', frontmatter: '---\nlicense: MIT\n---', body: 'Body' })
		expect(w.vm.buildPackage()).toBe('---\nname: N\ndescription: D\nlicense: MIT\n---\nBody')
		w.vm.form.frontmatter = 'name: Own\ndescription: Own'
		expect(w.vm.buildFrontmatterBlock()).toBe('name: Own\ndescription: Own')
		Object.assign(w.vm.form, { description: '', frontmatter: '' })
		expect(w.vm.buildFrontmatterBlock()).toBe('name: N')
	})

	it('imports a pasted fenced package into the form, refusing one without a fence', async () => {
		const w = await openForm()
		await w.vm.importPastedPackage()
		w.vm.pasteText = 'name: x'
		await w.vm.importPastedPackage()
		expect(w.vm.pasteError).toContain('must start with "---"')
		expect(skills.importSkill).not.toHaveBeenCalled()
		skills.importSkill.mockResolvedValueOnce({ id: 'k9', name: 'Imported', files: [{ name: 'f.md', content: 'c' }] })
		w.vm.pasteText = ' ---\nname: Imported\n--- '
		await w.vm.importPastedPackage()
		expect(skills.importSkill).toHaveBeenCalledWith('---\nname: Imported\n---')
		expect([w.vm.form.name, w.vm.files, w.vm.pasteText, w.vm.heading]).toEqual(['Imported', [{ name: 'f.md', content: 'c' }], '', 'Edit skill'])
		skills.importSkill.mockRejectedValueOnce({ response: { data: { error: 'Bad YAML' } } })
		w.vm.pasteText = '---\nx'
		await w.vm.importPastedPackage()
		expect([w.vm.pasteError, w.vm.pasteBusy]).toEqual(['Bad YAML', false])
	})

	it('saves nothing without a name', async () => {
		const w = await openForm()
		await w.vm.save()
		expect(skills.importSkill).not.toHaveBeenCalled()
		expect(w.emitted('saved')).toBeUndefined()
	})

	it('creates a skill as active, then stores its extra files', async () => {
		skills.importSkill.mockResolvedValueOnce({ uuid: 'k1', name: 'N' })
		skills.updateSkill.mockResolvedValueOnce({ uuid: 'k1', files: [1] })
		const close = vi.fn()
		const w = await openForm({ close })
		Object.assign(w.vm.form, { name: 'N', body: 'B' })
		w.vm.files = [{ name: 'f.md', content: 'c' }]
		await w.vm.save()
		expect(skills.importSkill).toHaveBeenCalledWith('---\nname: N\n---\nB')
		expect(skills.updateSkill).toHaveBeenCalledWith('k1', { uuid: 'k1', name: 'N', files: [{ name: 'f.md', content: 'c' }] })
		expect(w.emitted('saved')).toEqual([[{ uuid: 'k1', files: [1] }]])
		expect(close).toHaveBeenCalled()
	})

	it('creates a quarantined skill from local source, without a file update when there are none', async () => {
		skills.installFromSource.mockResolvedValueOnce({ id: 'k2' })
		const w = await openForm({ saveTarget: 'quarantine' })
		w.vm.form.name = 'N'
		await w.vm.save()
		expect(skills.installFromSource).toHaveBeenCalledWith('---\nname: N\n---\n', 'local')
		expect(skills.updateSkill).not.toHaveBeenCalled()
		expect(w.emitted('saved')).toEqual([[{ id: 'k2' }]])
	})

	it('keeps the created skill when the file update returns nothing', async () => {
		skills.importSkill.mockResolvedValueOnce({ id: 'k3' })
		skills.updateSkill.mockResolvedValueOnce(null)
		const w = await openForm()
		w.vm.form.name = 'N'
		w.vm.files = [{ name: 'f.md', content: '' }]
		await w.vm.save()
		expect(skills.updateSkill.mock.calls[0][0]).toBe('k3')
		expect(w.emitted('saved')).toEqual([[{ id: 'k3' }]])
	})

	it('updates an existing skill without its envelope, and reports a refusal', async () => {
		skills.updateSkill.mockResolvedValueOnce({ id: 'k1' })
		const w = await openForm({ item: { id: 'k1', '@self': { a: 1 }, name: 'N', owner: 'o' } })
		await w.vm.save()
		expect(skills.updateSkill).toHaveBeenCalledWith('k1', { id: 'k1', owner: 'o', name: 'N', description: '', frontmatter: '', body: '', files: [] })
		expect(w.emitted('saved')).toHaveLength(1)
		skills.updateSkill.mockResolvedValueOnce(null)
		await w.vm.save()
		expect(w.vm.error).toBe('Could not save skill')
		skills.updateSkill.mockRejectedValueOnce(new Error('Network'))
		await w.vm.save()
		expect([w.vm.error, w.vm.saving]).toEqual(['Network', false])
		expect(w.emitted('saved')).toHaveLength(1)
	})
})

describe('AgentTemplateGithubStore', () => {
	const template = { kind: 'agent-template', owner: 'o', repo: 't', name: 'Helper' }
	const skill = { kind: 'skill', owner: 'o', repo: 's' }

	beforeEach(() => {
		vi.spyOn(axios, 'get').mockResolvedValue({ data: { results: [{ id: 'c1', provider: 'github', name: 'GH' }, { id: 'c2', provider: 'x' }] } })
		templates.searchGithubTemplates.mockResolvedValue({ outcome: 'ok', cards: [template], brokerCredentialAvailable: true })
		skills.searchGithubSkills.mockResolvedValue({ outcome: 'ok', cards: [skill] })
	})

	/**
	 * Mount the store and let its first search settle.
	 *
	 * @return {Promise<object>} The wrapper.
	 */
	async function mountStore() {
		const w = shallowMount(AgentTemplateGithubStore, { global: { mocks: { t } } })
		await flushPromises()
		return w
	}

	it('searches both kinds on mount and offers the GitHub credentials', async () => {
		const w = await mountStore()
		expect(templates.searchGithubTemplates).toHaveBeenCalledWith('', null)
		expect(w.vm.cards).toEqual([template, skill])
		expect([w.vm.outcome, w.vm.brokerCredentialAvailable, w.vm.rateLimited]).toEqual(['ok', true, false])
		expect(w.vm.githubCredentials).toEqual([{ label: 'GH', value: 'c1' }])
		expect(w.vm.kindOptions.map((o) => o.value)).toEqual(['all', 'agent-template', 'skill'])
	})

	it('searches only the chosen kind, with the chosen credential', async () => {
		const w = await mountStore()
		vi.clearAllMocks()
		w.vm.kindFilter = { value: 'skill' }
		w.vm.selectedCredential = { value: 'c1' }
		w.vm.query = 'mail'
		await w.vm.doSearch()
		expect(templates.searchGithubTemplates).not.toHaveBeenCalled()
		expect(skills.searchGithubSkills).toHaveBeenCalledWith('mail', 'c1')
		w.vm.kindFilter = null
		expect([w.vm.kindActive('skill'), w.vm.kindActive('agent-template')]).toEqual([true, true])
	})

	it('reports a rate limit, an unreachable GitHub, and a failed search', async () => {
		templates.searchGithubTemplates.mockResolvedValueOnce({ outcome: 'github_rate_limited', rateLimited: true, cards: [] })
		skills.searchGithubSkills.mockRejectedValueOnce(new Error('down'))
		const w = await mountStore()
		expect([w.vm.outcome, w.vm.rateLimitHintVisible, w.vm.unreachableHintVisible]).toEqual(['github_rate_limited', true, false])
		templates.searchGithubTemplates.mockRejectedValueOnce(new Error('down'))
		skills.searchGithubSkills.mockRejectedValueOnce(new Error('down'))
		await w.vm.doSearch()
		expect([w.vm.outcome, w.vm.rateLimitHintVisible, w.vm.unreachableHintVisible]).toEqual(['github_unreachable', false, true])
		w.vm.kindFilter = { value: 'none' }
		await w.vm.doSearch()
		expect(w.vm.outcome).toBe('ok')
	})

	it('debounces a typed query into one search', async () => {
		const w = await mountStore()
		vi.useFakeTimers()
		vi.clearAllMocks()
		w.vm.onQueryChange()
		w.vm.onQueryChange()
		vi.advanceTimersByTime(399)
		expect(templates.searchGithubTemplates).not.toHaveBeenCalled()
		vi.advanceTimersByTime(1)
		expect(templates.searchGithubTemplates).toHaveBeenCalledTimes(1)
	})

	it('installs a skill or a template, quarantined, and refreshes the page after a template', async () => {
		const w = await mountStore()
		w.vm.selectedCredential = { value: 'c1' }
		await w.vm.doInstall(skill)
		expect(skills.installGithubSkill).toHaveBeenCalledWith({ owner: 'o', repo: 's', credentialId: 'c1' })
		expect(w.vm.installNotice).toBe('Installed skill "skill:o/s" — it is quarantined until reviewed. Find it on the Skills page.')
		expect(bus).toEqual([])
		await w.vm.doInstall(template)
		expect(templates.installGithubTemplate).toHaveBeenCalledWith({ owner: 'o', repo: 't', credentialId: 'c1' })
		expect(w.vm.installNotice).toBe('Installed "Helper" — it is quarantined until reviewed.')
		expect(bus).toEqual([['cn:page:refresh', {}]])
		expect(w.vm.installingId).toBeNull()
	})

	it('shows a refused install and labels the kinds', async () => {
		axios.get.mockRejectedValueOnce(new Error('x'))
		const w = await mountStore()
		expect(w.vm.credentials).toEqual([])
		templates.installGithubTemplate.mockRejectedValueOnce({ response: { data: { error: 'No manifest' } } })
		await w.vm.doInstall(template)
		expect([w.vm.installError, w.vm.installNotice]).toEqual(['No manifest', ''])
		expect([w.vm.kindLabel('skill'), w.vm.kindLabel('agent-template')]).toEqual(['Skill', 'Agent template'])
		expect(w.vm.unparseableHint('skill')).toContain('hermiq-skill.md')
		expect(w.vm.unparseableHint('agent-template')).toContain('hermiq-agent-template.json')
		expect(w.vm.cardKey(template)).toBe('agent-template:o/t')
	})
})
