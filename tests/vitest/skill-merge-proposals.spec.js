/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The curator's merge proposals on the Skills page (skills-curator-merges-duplicates):
 * each proposal names the skill that goes and the one that stays; "Merge" approves
 * and "Keep both" rejects, and the decided proposal leaves the list; a person
 * without the action sees nothing, and with no proposal the section is not shown.
 *
 * @spec openspec/changes/skills-curator-merges-duplicates/specs/skills-marketplace/spec.md#requirement-the-curator-shall-propose-merging-duplicate-skills
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

vi.mock('../../src/api/skillMerges.js', () => ({
	listMergeProposals: vi.fn(),
	approveMerge: vi.fn(),
	rejectMerge: vi.fn(),
}))
vi.mock('@nextcloud/vue', () => ({
	NcButton: {
		props: ['disabled', 'variant'],
		emits: ['click'],
		render() {
			return h('button', { disabled: this.disabled, class: `btn--${this.variant}`, onClick: () => this.$emit('click') }, this.$slots.default?.())
		},
	},
	NcNoteCard: { props: ['type'], render() { return h('div', { class: `note note--${this.type}` }, this.$slots.default?.()) } },
}))

const api = await import('../../src/api/skillMerges.js')
const { default: SkillMergeProposals } = await import('../../src/widgets/SkillMergeProposals.vue')

const t = (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m))
const n = (app, one, many, count) => (count === 1 ? one : many).replace('%n', String(count))

function proposal (id, survivor, reason = 'same-name') {
  return {
	duplicate: { id, name: `Copy ${id}`, description: '', agents: 1 },
	survivor: { id: survivor, name: `Skill ${survivor}`, description: '', agents: 2 },
	reason,
	proposedAt: '2026-11-01T10:00:00+00:00',
}
}

/**
 * Mount the widget and let it load.
 *
 * @return {Promise<object>} The wrapper.
 */
async function mountWidget() {
	const w = mount(SkillMergeProposals, { global: { mocks: { t, n } } })
	await flushPromises()
	return w
}

describe('SkillMergeProposals', () => {
	beforeEach(() => {
		api.listMergeProposals.mockReset()
		api.approveMerge.mockReset()
		api.rejectMerge.mockReset()
	})

	it('names the skill that goes and the skill that stays, with their agents and the reason', async () => {
		api.listMergeProposals.mockResolvedValueOnce([proposal('go-1', 'keep-1'), proposal('go-2', 'keep-2', 'same-instructions')])
		const w = await mountWidget()
		const items = w.findAll('.skill-merges__item')

		expect(items).toHaveLength(2)
		const parts = (line) => [line.find('.skill-merges__role').text(), line.find('strong').text(), line.find('.skill-merges__agents').text()]
		expect(items[0].findAll('.skill-merges__line').map(parts)).toEqual([
			['Goes', 'Copy go-1', 'used by 1 agent'],
			['Stays', 'Skill keep-1', 'used by 2 agents'],
		])
		expect(items[0].find('.skill-merges__reason').text()).toBe('Both skills have the same name.')
		expect(items[1].find('.skill-merges__reason').text()).toBe('Both skills have the same instructions.')
		expect(items[0].findAll('button').map((b) => b.text())).toEqual(['Merge', 'Keep both'])
	})

	it('merges the pair on Merge and takes it off the list', async () => {
		api.listMergeProposals.mockResolvedValueOnce([proposal('go-1', 'keep-1'), proposal('go-2', 'keep-2')])
		api.approveMerge.mockResolvedValueOnce({ merged: true, survivor: 'keep-1', agentsMoved: 1 })
		const w = await mountWidget()

		await w.findAll('.skill-merges__item')[0].find('.btn--primary').trigger('click')
		await flushPromises()

		expect(api.approveMerge).toHaveBeenCalledWith('go-1')
		expect(api.rejectMerge).not.toHaveBeenCalled()
		expect(w.findAll('.skill-merges__item')).toHaveLength(1)
		expect(w.find('.note--success').text()).toBe('Copy go-1 was merged into Skill keep-1.')
	})

	it('keeps both on Keep both and takes the proposal off the list', async () => {
		api.listMergeProposals.mockResolvedValueOnce([proposal('go-1', 'keep-1')])
		api.rejectMerge.mockResolvedValueOnce({ rejected: true })
		const w = await mountWidget()

		await w.find('.btn--secondary').trigger('click')
		await flushPromises()

		expect(api.rejectMerge).toHaveBeenCalledWith('go-1')
		expect(api.approveMerge).not.toHaveBeenCalled()
		expect(w.findAll('.skill-merges__item')).toHaveLength(0)
		expect(w.find('.note--success').text()).toBe('Both skills were kept.')
	})

	it('keeps a proposal whose decision failed, with the server error', async () => {
		api.listMergeProposals.mockResolvedValueOnce([proposal('go-1', 'keep-1')])
		api.approveMerge.mockRejectedValueOnce({ response: { status: 409, data: { error: 'survivor_gone' } } })
		const w = await mountWidget()

		await w.find('.btn--primary').trigger('click')
		await flushPromises()

		expect(w.findAll('.skill-merges__item')).toHaveLength(1)
		expect(w.find('.note--error').text()).toBe('survivor_gone')
	})

	it('shows nothing to a person without the action, or when nothing is proposed', async () => {
		api.listMergeProposals.mockRejectedValueOnce({ response: { status: 403, data: { error: 'Not allowed' } } })
		const refused = await mountWidget()
		expect(refused.find('.skill-merges').exists()).toBe(false)

		api.listMergeProposals.mockResolvedValueOnce([])
		const empty = await mountWidget()
		expect(empty.find('.skill-merges').exists()).toBe(false)
	})
})
