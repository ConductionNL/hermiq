/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The skill form mounted in full over the @nextcloud/vue stand-ins: the paste
 * area, the name, description and frontmatter fields, the body editor, and the
 * extra files (renamed, edited as markdown or as text, added and removed) all
 * write the form, and the modal and Cancel close it.
 */

import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'

vi.mock('@conduction/nextcloud-vue', () => ({
	CnMarkdownEditor: { name: 'CnMarkdownEditor', emits: ['input'], render: () => null },
}))
vi.mock('../../src/api/skills.js', () => ({ importSkill: vi.fn(), installFromSource: vi.fn(), updateSkill: vi.fn() }))

const t = (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m))
globalThis.t = t

const { default: SkillFormModal } = await import('../../src/modals/SkillFormModal.vue')

/**
 * Mount the form open in create mode.
 *
 * @return {Promise<object>} The wrapper.
 */
async function render() {
	const w = mount(SkillFormModal, { props: { show: true }, global: { mocks: { t } } })
	await flushPromises()
	return w
}

/**
 * The stand-ins of one kind with a label.
 *
 * @param {object} w The wrapper.
 * @param {string} name The stand-in name.
 * @param {string} label The label.
 * @return {Array<object>} The component wrappers.
 */
function labelled(w, name, label) {
	return w.findAllComponents({ name }).filter((c) => c.attributes('data-label') === label)
}

describe('SkillFormModal as rendered', () => {
	it('writes the name, description, frontmatter and body', async () => {
		const w = await render()

		labelled(w, 'NcTextField', 'Name')[0].vm.pick('report-writer')
		labelled(w, 'NcTextField', 'Description')[0].vm.pick('Writes reports')
		labelled(w, 'NcTextArea', 'Frontmatter (YAML)')[0].vm.pick('license: EUPL-1.2')
		w.findAllComponents({ name: 'CnMarkdownEditor' })[0].vm.$emit('input', '# Body')

		expect(w.vm.form).toMatchObject({ name: 'report-writer', description: 'Writes reports', frontmatter: 'license: EUPL-1.2', body: '# Body' })
	})

	it('adds, renames, edits and removes extra files', async () => {
		const w = await render()
		const newName = () => labelled(w, 'NcTextField', 'New file name')[0]
		const addButton = () => w.findAllComponents({ name: 'NcButton' }).find((c) => c.text() === 'Add file')

		newName().vm.pick('notes.txt')
		await flushPromises()
		addButton().vm.$emit('click')
		newName().vm.pick('reference.md')
		await flushPromises()
		addButton().vm.$emit('click')
		await flushPromises()
		expect(w.vm.files.map((f) => f.name)).toEqual(['notes.txt', 'reference.md'])

		// A text file is edited in a text area, a markdown file in the editor.
		labelled(w, 'NcTextArea', 'File content')[0].vm.pick('plain text')
		w.findAllComponents({ name: 'CnMarkdownEditor' }).at(-1).vm.$emit('input', '## Reference')
		expect(w.vm.files.map((f) => f.content)).toEqual(['plain text', '## Reference'])

		labelled(w, 'NcTextField', 'File name')[0].vm.pick(' notes-v2.txt ')
		labelled(w, 'NcTextField', 'File name')[1].vm.pick('   ')
		expect(w.vm.files.map((f) => f.name)).toEqual(['notes-v2.txt', 'reference.md'])

		w.findAllComponents({ name: 'NcButton' }).find((c) => c.text() === 'Remove').vm.$emit('click')
		await flushPromises()
		expect(w.vm.files.map((f) => f.name)).toEqual(['reference.md'])
	})

	it('closes from the modal and from Cancel', async () => {
		const w = await render()

		w.findComponent({ name: 'NcModal' }).vm.$emit('close')
		w.findAllComponents({ name: 'NcButton' }).find((c) => c.text() === 'Cancel').vm.$emit('click')

		expect(w.emitted('close')).toHaveLength(2)
	})

	it('shows the paste area for a new skill and writes what is pasted', async () => {
		const w = await render()
		const paste = labelled(w, 'NcTextArea', 'Package (starts with a --- frontmatter fence)')
		expect(paste).toHaveLength(1)

		paste[0].vm.pick('---\nname: x\n---')
		w.vm.pasteBusy = true
		w.vm.pasteError = 'Not a package'
		await flushPromises()

		expect(w.vm.pasteText).toBe('---\nname: x\n---')
		expect(w.findComponent({ name: 'NcLoadingIcon' }).exists()).toBe(true)
		expect(w.findAllComponents({ name: 'NcNoteCard' }).some((c) => c.text() === 'Not a package')).toBe(true)
	})
})
