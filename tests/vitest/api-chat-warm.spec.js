/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * warmChat(): the warm-up the chat sends when a session opens, which route and
 * body it posts, what it reports, and that it never throws
 * (warm-start-and-cli-step-visibility).
 */

import axios from '@nextcloud/axios'
import { afterEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/auth', () => ({ getRequestToken: () => 'tok-1' }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => `/index.php${path}`, generateOcsUrl: (path) => `/ocs/${path}` }))

const { warmChat } = await import('../../src/api/chat.js')

afterEach(() => {
	vi.restoreAllMocks()
})

describe('warmChat', () => {
	it('posts the agent and the session to the warm-up route and reports a warmed process', async () => {
		const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: { warmed: true } })

		await expect(warmChat('agent-1', 'session-1')).resolves.toBe(true)
		expect(post).toHaveBeenCalledTimes(1)
		expect(post).toHaveBeenCalledWith('/index.php/apps/hermiq/api/chat/warm', { agentUuid: 'agent-1', conversation: 'session-1' })
	})

	it('reports nothing warmed for a non-cli agent', async () => {
		vi.spyOn(axios, 'post').mockResolvedValue({ data: { warmed: false, reason: 'not a cli agent' } })

		await expect(warmChat('agent-1', 'session-1')).resolves.toBe(false)
	})

	it('never throws: a failed request reads as not warmed', async () => {
		vi.spyOn(axios, 'post').mockRejectedValue(new Error('offline'))

		await expect(warmChat('agent-1', 'session-1')).resolves.toBe(false)
	})

	it.each([
		['no agent', '', 'session-1'],
		['no session', 'agent-1', undefined],
	])('sends nothing with %s', async (_label, agent, session) => {
		const post = vi.spyOn(axios, 'post')

		await expect(warmChat(agent, session)).resolves.toBe(false)
		expect(post).not.toHaveBeenCalled()
	})
})
