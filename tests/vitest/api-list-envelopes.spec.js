/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Characterisation of how the API modules read a list response: a bare array,
 * a `results` envelope and (for some) a nested `data.results` envelope; and the
 * query the two GitHub searches send.
 */

import axios from '@nextcloud/axios'
import { afterEach, describe, expect, it, vi } from 'vitest'

const { listAgentTemplates, searchGithubTemplates } = await import('../../src/api/agentTemplates.js')
const { listRuns } = await import('../../src/api/agents.js')
const { listAiFeatures } = await import('../../src/api/aiFeatures.js')
const { listPendingApprovals } = await import('../../src/api/approvals.js')
const { listUserProfiles } = await import('../../src/api/memory.js')
const { listSkills, searchGithubSkills } = await import('../../src/api/skills.js')

const ROWS = [{ id: 1 }]

/**
 * Answer the next GET with a body.
 *
 * @param {*} data The response body.
 * @return {void}
 */
function answer(data) {
	axios.get = vi.fn(async () => ({ data }))
}

afterEach(() => {
	axios.get = async () => ({ data: {} })
})

describe.each([
	['listAgentTemplates', () => listAgentTemplates(), false],
	['listSkills', () => listSkills(), false],
	['listUserProfiles', () => listUserProfiles('a-1'), false],
	['listRuns', () => listRuns('s-1'), true],
	['listAiFeatures', () => listAiFeatures(), true],
	['listPendingApprovals', () => listPendingApprovals(), true],
])('%s', (name, call, readsNested) => {
	it('reads a bare array and a results envelope, and nothing else as a list', async () => {
		answer(ROWS)
		expect(await call()).toBe(ROWS)
		answer({ results: ROWS })
		expect(await call()).toBe(ROWS)
		answer({ results: 'no' })
		expect(await call()).toEqual([])
		answer(null)
		expect(await call()).toEqual([])
	})

	it(readsNested ? 'also reads a nested data.results envelope' : 'does not read a nested data.results envelope', async () => {
		answer({ data: { results: ROWS } })
		expect(await call()).toEqual(readsNested ? ROWS : [])
	})
})

describe.each([
	['searchGithubTemplates', searchGithubTemplates],
	['searchGithubSkills', searchGithubSkills],
])('%s', (name, search) => {
	it('sends the term and the credential only when given', async () => {
		answer({ outcome: 'ok' })
		await search()
		expect(axios.get.mock.calls[0][1]).toEqual({ params: {} })
		answer({ outcome: 'ok' })
		await search('weather', 'cred-1')
		expect(axios.get.mock.calls[0][1]).toEqual({ params: { q: 'weather', credentialId: 'cred-1' } })
	})
})
