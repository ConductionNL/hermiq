/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A live chat with the seeded Agent builder (agents-plain-language-builder,
 * REQ-AGBUILD-001), against the instance's real chat model.
 *
 * WHAT THE OTHER TESTS CANNOT SHOW. SeedAgentBuilderTest pins the seed,
 * AgentDraftServiceTest the check, and agents-plain-language-builder.spec.ts the
 * page with a stubbed assistant message. None of them shows that the seeded agent,
 * given a described agent in plain words, answers with a `hermiq-agent-draft` block
 * that the draft check reads, and that the turn writes no agent. That needs the
 * model, so it is here.
 *
 * Skipped when the instance has no chat model configured (chat health not `ok`),
 * because then no turn can run at all; the nightly instance has one.
 *
 * Not run when written: it needs a live instance with a chat model. Runs in the nightly.
 *
 * @e2e agent-management-ui::a-team-lead-describes-an-agent
 *
 * @spec openspec/changes/archive/2026-11-24-agents-plain-language-builder/specs/agent-management-ui/spec.md
 */

import { expect, test } from '@playwright/test'
import { harvestToken, jsonHeaders } from './_fixtures.ts'

const API = '/index.php/apps/hermiq/api'
const BUILDER_NAME = 'Agent builder'
const DESCRIPTION = "An agent that summarises new objections every Monday at eight and posts them in the legal team's Talk room"
const FENCE = /(?:```|~~~)\s*hermiq-agent-draft[^\n]*\n([\s\S]*?)\n?(?:```|~~~)/

test.describe('Agent builder: a live chat', () => {
	test.setTimeout(240_000)

	test('a described agent comes back as a checked draft, and no agent is written', async ({ page }) => {
		const token = await harvestToken(page)
		const headers = jsonHeaders(token)

		const health = await (await page.request.get(`${API}/chat/health`, { headers })).json()
		test.skip(health.status !== 'ok', 'no chat model is configured on this instance, so no turn can run')

		const agents = await (await page.request.get(`${API}/agents?_limit=500`, { headers })).json()
		const rows: Array<Record<string, any>> = agents.results ?? []
		const builder = rows.find((row) => row.name === BUILDER_NAME)
		expect(builder, 'the repair step seeds the Agent builder').toBeTruthy()
		const builderId = String(builder?.id ?? builder?.uuid ?? builder?.['@self']?.id ?? '')
		expect(builderId).not.toEqual('')
		const agentsBefore = Number(agents.total ?? rows.length)

		const sent = await page.request.post(`${API}/chat/send`, {
			headers,
			data: { message: DESCRIPTION, agentUuid: builderId },
			timeout: 200_000,
		})
		expect(sent.status(), await sent.text()).toBe(200)
		const answer = String((await sent.json()).message ?? '')

		const block = FENCE.exec(answer)
		expect(block, `the answer holds a hermiq-agent-draft block:\n${answer}`).not.toBeNull()
		const draft = String(block?.[1] ?? '').trim()

		const checked = await page.request.post(`${API}/agents/draft-check`, {
			headers,
			data: { draft },
		})
		expect(checked.status(), await checked.text()).toBe(200)

		const after = await (await page.request.get(`${API}/agents?_limit=500`, { headers })).json()
		expect(Number(after.total ?? (after.results ?? []).length), 'a chat with the builder writes no agent').toBe(agentsBefore)
	})
})
