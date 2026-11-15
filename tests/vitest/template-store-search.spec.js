/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Characterisation of the marketplace search on AgentTemplateGithubStore: which
 * searches run per kind filter, how the cards merge, and which outcome and
 * rate-limit state the page shows.
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/event-bus', () => ({ emit: vi.fn() }))
vi.mock('../../src/api/agentTemplates.js', () => ({ searchGithubTemplates: vi.fn(), installGithubTemplate: vi.fn() }))
vi.mock('../../src/api/skills.js', () => ({ searchGithubSkills: vi.fn(), installGithubSkill: vi.fn() }))

const templates = await import('../../src/api/agentTemplates.js')
const skills = await import('../../src/api/skills.js')
const { default: Store } = await import('../../src/widgets/AgentTemplateGithubStore.vue')

/**
 * A store context with its methods bound.
 *
 * @param {object} state The data.
 * @return {object} The context.
 */
function store(state) {
	return { ...Store.methods, t: (app, text) => text, query: 'q', selectedCredential: null, kindFilter: null, ...state }
}

beforeEach(() => {
	vi.mocked(templates.searchGithubTemplates).mockReset()
	vi.mocked(skills.searchGithubSkills).mockReset()
})

describe('AgentTemplateGithubStore doSearch', () => {
	it('searches both kinds and merges their cards', async () => {
		vi.mocked(templates.searchGithubTemplates).mockResolvedValue({ outcome: 'ok', cards: [{ kind: 'agent-template', repo: 'a' }], brokerCredentialAvailable: false })
		vi.mocked(skills.searchGithubSkills).mockResolvedValue({ outcome: 'github_rate_limited', cards: [{ kind: 'skill', repo: 's' }], rateLimited: true, brokerCredentialAvailable: true })
		const ctx = store({ selectedCredential: { value: 'cred-1' } })
		await ctx.doSearch()

		expect(templates.searchGithubTemplates).toHaveBeenCalledWith('q', 'cred-1')
		expect(skills.searchGithubSkills).toHaveBeenCalledWith('q', 'cred-1')
		expect(ctx.cards.map((c) => c.repo)).toEqual(['a', 's'])
		expect(ctx.brokerCredentialAvailable).toBe(true)
		expect(ctx.rateLimited).toBe(true)
		expect(ctx.outcome).toBe('ok')
		expect(ctx.loading).toBe(false)
	})

	it('searches one kind under a kind filter, and a failed search answers unreachable', async () => {
		vi.mocked(skills.searchGithubSkills).mockRejectedValue(new Error('down'))
		const ctx = store({ kindFilter: { value: 'skill' } })
		await ctx.doSearch()

		expect(templates.searchGithubTemplates).not.toHaveBeenCalled()
		expect(skills.searchGithubSkills).toHaveBeenCalledWith('q', null)
		expect(ctx.cards).toEqual([])
		expect(ctx.outcome).toBe('github_unreachable')
		expect(ctx.rateLimited).toBe(false)
		expect(ctx.brokerCredentialAvailable).toBe(false)
	})

	it.each([
		[[{ outcome: 'github_rate_limited', rateLimited: true }, { outcome: 'x' }], 'github_rate_limited', true],
		[[{ outcome: 'first' }, { outcome: 'second' }], 'first', false],
		[[{}, {}], 'ok', false],
	])('picks the outcome %#', async (answers, outcome, rateLimited) => {
		vi.mocked(templates.searchGithubTemplates).mockResolvedValue(answers[0])
		vi.mocked(skills.searchGithubSkills).mockResolvedValue(answers[1])
		const ctx = store({ kindFilter: { value: 'all' } })
		await ctx.doSearch()

		expect(ctx.outcome).toBe(outcome)
		expect(ctx.rateLimited).toBe(rateLimited)
		expect(ctx.cards).toEqual([])
	})

	it('answers no cards when no kind is searched', async () => {
		const ctx = store({ kindFilter: { value: 'other' } })
		await ctx.doSearch()
		expect(ctx.cards).toEqual([])
		expect(ctx.outcome).toBe('ok')
		expect(ctx.rateLimited).toBe(false)
	})

	it('reports an unreachable search when merging fails', async () => {
		vi.mocked(templates.searchGithubTemplates).mockResolvedValue(null)
		const ctx = store({ kindFilter: { value: 'agent-template' }, cards: [{ old: true }] })
		await ctx.doSearch()
		expect(ctx.cards).toEqual([])
		expect(ctx.outcome).toBe('github_unreachable')
		expect(ctx.loading).toBe(false)
	})
})
