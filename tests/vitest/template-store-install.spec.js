/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Characterisation of the GitHub store's install: which install call each kind
 * makes with which credential, the notice it shows, the page refresh after a
 * template, and a failed install.
 */

import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/event-bus', () => ({ emit: vi.fn() }))
vi.mock('../../src/api/agentTemplates.js', () => ({ installGithubTemplate: vi.fn(), searchGithubTemplates: vi.fn() }))
vi.mock('../../src/api/skills.js', () => ({ installGithubSkill: vi.fn(), searchGithubSkills: vi.fn() }))

const bus = await import('@nextcloud/event-bus')
const templates = await import('../../src/api/agentTemplates.js')
const skills = await import('../../src/api/skills.js')
const { default: Store } = await import('../../src/widgets/AgentTemplateGithubStore.vue')

/**
 * A store context: its data, its methods bound, and a t() that fills placeholders.
 *
 * @param {object} state Extra state.
 * @return {object} The context.
 */
function store(state = {}) {
	const t = (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (m, key) => (key in vars ? vars[key] : m))
	return { ...Store.data.call({ t }), ...Store.methods, t, ...state }
}

describe('AgentTemplateGithubStore install', () => {
	it('installs a skill with the chosen credential and points to the Skills page', async () => {
		const ctx = store({ selectedCredential: { value: 'cred-1' }, installError: 'old', installNotice: 'old' })
		skills.installGithubSkill.mockResolvedValueOnce({})
		const card = { kind: 'skill', owner: 'o', repo: 'r', name: 'Summariser' }
		const done = ctx.doInstall(card)
		expect([ctx.installError, ctx.installNotice, ctx.installingId]).toEqual(['', '', ctx.cardKey(card)])
		await done
		expect(skills.installGithubSkill).toHaveBeenCalledWith({ owner: 'o', repo: 'r', credentialId: 'cred-1' })
		expect(ctx.installNotice).toBe('Installed skill "Summariser" — it is quarantined until reviewed. Find it on the Skills page.')
		expect(ctx.installingId).toBeNull()
		expect(bus.emit).not.toHaveBeenCalled()
	})

	it('installs a template without a credential, names it by key when unnamed, and refreshes the page', async () => {
		const ctx = store({ selectedCredential: null })
		templates.installGithubTemplate.mockResolvedValueOnce({})
		const card = { kind: 'agent-template', owner: 'o', repo: 'r' }
		await ctx.doInstall(card)
		expect(templates.installGithubTemplate).toHaveBeenCalledWith({ owner: 'o', repo: 'r', credentialId: null })
		expect(ctx.installNotice).toBe(`Installed "${ctx.cardKey(card)}" — it is quarantined until reviewed.`)
		expect(bus.emit).toHaveBeenCalledWith('cn:page:refresh', {})
	})

	it('shows the server wording of a failed install, else the error, else a fallback', async () => {
		for (const [failure, message] of [
			[{ response: { data: { error: 'Quarantine full' } } }, 'Quarantine full'],
			[new Error('Network down'), 'Network down'],
			[{}, 'Unknown error'],
		]) {
			const ctx = store({ selectedCredential: null })
			templates.installGithubTemplate.mockRejectedValueOnce(failure)
			await ctx.doInstall({ kind: 'agent-template', owner: 'o', repo: 'r', name: 'x' })
			expect([ctx.installError, ctx.installNotice, ctx.installingId]).toEqual([message, '', null])
		}
	})
})
