/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Characterisation of SkillFormModal's save and paste-import: which endpoint
 * each path calls with what, the follow-up files write, what lands in the
 * form, and every error message.
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@conduction/nextcloud-vue', () => ({
	CnMarkdownEditor: { name: 'CnMarkdownEditor', render: () => null },
}))

vi.mock('../../src/api/skills.js', () => ({
	importSkill: vi.fn(),
	installFromSource: vi.fn(),
	updateSkill: vi.fn(),
}))

const api = await import('../../src/api/skills.js')
const { default: SkillFormModal } = await import('../../src/modals/SkillFormModal.vue')

/**
 * A modal context: its methods bound, a recording $emit, and the given state.
 *
 * @param {object} state The data and computed values.
 * @return {object} The context.
 */
function modal(state) {
	const ctx = {
		...SkillFormModal.methods,
		t: (app, text) => text,
		emitted: [],
		files: [],
		error: 'old',
		pasteError: 'old',
		saveTarget: 'local',
		effectiveSkill: null,
		...state,
	}
	ctx.$emit = (name, value) => ctx.emitted.push([name, value])
	ctx.buildFrontmatterBlock = () => 'name: x'
	return ctx
}

beforeEach(() => {
	vi.mocked(api.importSkill).mockReset()
	vi.mocked(api.installFromSource).mockReset()
	vi.mocked(api.updateSkill).mockReset()
})

describe('SkillFormModal save', () => {
	it('does nothing without a name', async () => {
		const ctx = modal({ form: { name: '  ' } })
		await ctx.save()
		expect(ctx.saving).toBeUndefined()
		expect(api.importSkill).not.toHaveBeenCalled()
	})

	it('edits through updateSkill with the edit payload', async () => {
		vi.mocked(api.updateSkill).mockResolvedValue({ id: 's1', name: 'N' })
		const ctx = modal({
			form: { name: 'N', description: 'D', frontmatter: 'F', body: 'B' },
			effectiveSkill: { id: 's1', '@self': {}, kept: 1 },
			files: [{ name: 'a.md', content: 'A', extra: true }],
		})
		await ctx.save()

		expect(api.updateSkill).toHaveBeenCalledWith('s1', {
			kept: 1, id: 's1', name: 'N', description: 'D', frontmatter: 'F', body: 'B', files: [{ name: 'a.md', content: 'A' }],
		})
		expect(ctx.emitted).toEqual([['saved', { id: 's1', name: 'N' }], ['close', undefined]])
		expect(ctx.error).toBe('')
		expect(ctx.saving).toBe(false)
	})

	it('reports an edit the server refused', async () => {
		vi.mocked(api.updateSkill).mockResolvedValue(null)
		const ctx = modal({ form: { name: 'N' }, effectiveSkill: { id: 's1' } })
		await ctx.save()

		expect(ctx.error).toBe('Could not save skill')
		expect(ctx.emitted).toEqual([])
		expect(ctx.saving).toBe(false)
	})

	it('creates locally, then writes the files and keeps the result', async () => {
		vi.mocked(api.importSkill).mockResolvedValue({ uuid: 'u1', name: 'N' })
		vi.mocked(api.updateSkill).mockResolvedValue({ uuid: 'u1', name: 'N', files: ['x'] })
		const ctx = modal({ form: { name: 'N', body: 'Body' }, files: [{ name: 'a.md', content: 'A' }] })
		await ctx.save()

		expect(api.importSkill).toHaveBeenCalledWith('---\nname: x\n---\nBody')
		expect(api.updateSkill).toHaveBeenCalledWith('u1', { uuid: 'u1', name: 'N', files: [{ name: 'a.md', content: 'A' }] })
		expect(ctx.emitted[0]).toEqual(['saved', { uuid: 'u1', name: 'N', files: ['x'] }])
	})

	it('creates into quarantine and keeps the created skill when the files write answers nothing', async () => {
		vi.mocked(api.installFromSource).mockResolvedValue({ id: 'i1' })
		vi.mocked(api.updateSkill).mockResolvedValue(null)
		const ctx = modal({ form: { name: 'N' }, saveTarget: 'quarantine', files: [{ name: 'a.md', content: 'A' }] })
		await ctx.save()

		expect(api.installFromSource).toHaveBeenCalledWith('---\nname: x\n---\n', 'local')
		expect(api.updateSkill).toHaveBeenCalledWith('i1', { id: 'i1', files: [{ name: 'a.md', content: 'A' }] })
		expect(ctx.emitted[0]).toEqual(['saved', { id: 'i1' }])
	})

	it('emits a create the server answered with nothing', async () => {
		vi.mocked(api.importSkill).mockResolvedValue(null)
		const ctx = modal({ form: { name: 'N' } })
		await ctx.save()

		expect(ctx.error).toBe('')
		expect(ctx.emitted[0]).toEqual(['saved', null])
	})

	it('creates without files and writes nothing more', async () => {
		vi.mocked(api.importSkill).mockResolvedValue({ id: 'c1' })
		const ctx = modal({ form: { name: 'N' } })
		await ctx.save()

		expect(api.updateSkill).not.toHaveBeenCalled()
		expect(ctx.emitted[0]).toEqual(['saved', { id: 'c1' }])
	})

	it.each([
		[Object.assign(new Error('x'), { response: { data: { error: 'Server' } } }), 'Server'],
		[new Error('Plain'), 'Plain'],
		[{}, 'Unknown error'],
	])('reports a failed save', async (thrown, message) => {
		vi.mocked(api.importSkill).mockRejectedValue(thrown)
		const ctx = modal({ form: { name: 'N' } })
		await ctx.save()

		expect(ctx.error).toBe(message)
		expect(ctx.saving).toBe(false)
	})
})

describe('SkillFormModal importPastedPackage', () => {
	it('ignores an empty paste and refuses an unfenced one', async () => {
		const ctx = modal({ pasteText: '   ' })
		await ctx.importPastedPackage()
		expect(api.importSkill).not.toHaveBeenCalled()
		expect(ctx.pasteError).toBe('old')

		ctx.pasteText = 'name: x'
		await ctx.importPastedPackage()
		expect(ctx.pasteError).toBe('This does not look like a fenced agentskills.io package (it must start with "---").')
		expect(api.importSkill).not.toHaveBeenCalled()
	})

	it('imports the package into the form and the files', async () => {
		const result = { name: 'N', body: 'B', files: [{ name: 'a.md' }, { content: 'C' }] }
		vi.mocked(api.importSkill).mockResolvedValue(result)
		const ctx = modal({ pasteText: '  ---\nname: N\n---\nB  ' })
		await ctx.importPastedPackage()

		expect(api.importSkill).toHaveBeenCalledWith('---\nname: N\n---\nB')
		expect(ctx.importedSkill).toBe(result)
		expect(ctx.form).toEqual({ name: 'N', description: '', frontmatter: '', body: 'B' })
		expect(ctx.files).toEqual([{ name: 'a.md', content: '' }, { name: '', content: 'C' }])
		expect(ctx.pasteText).toBe('')
		expect(ctx.pasteError).toBe('')
		expect(ctx.pasteBusy).toBe(false)
	})

	it('leaves no files when the package has none', async () => {
		vi.mocked(api.importSkill).mockResolvedValue({ files: 'odd' })
		const ctx = modal({ pasteText: '---' })
		await ctx.importPastedPackage()
		expect(ctx.files).toEqual([])
	})

	it.each([
		[new Error('Bad'), 'Bad'],
		[{}, 'Could not import the pasted package.'],
	])('reports a failed import', async (thrown, message) => {
		vi.mocked(api.importSkill).mockRejectedValue(thrown)
		const ctx = modal({ pasteText: '---' })
		await ctx.importPastedPackage()
		expect(ctx.pasteError).toBe(message)
		expect(ctx.pasteBusy).toBe(false)
	})
})
