/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Hydra Triage flow must actually be seeded on a clean install (hermiq#140).
 *
 * 🔴 This asserts the OUTCOME of an install-time repair step, which nothing else
 * can reach. `SeedHydraTriageFlow` runs once, during `occ app:enable`, long
 * before any browser exists — so the only evidence it leaves is the app-config
 * breadcrumb it now writes, and this is the only thing that reads it.
 *
 * The step has been failing silently on clean installs. It logged every time and
 * it made no difference: CI keeps a 50-line log tail and the install output is
 * thousands of lines earlier, so two investigations got only as far as
 * "something in here threw". The breadcrumb records the exception CLASS and
 * message; this test surfaces them in the failure so the diagnosis arrives with
 * the red rather than requiring another round.
 *
 * Covers openspec/specs/agent-object-leaf/spec.md — "the triage loop is a seeded
 * agentflow, not bespoke code". A flow that is never written is not a seeded
 * agentflow.
 */

import { test, expect } from '@playwright/test'
import { harvestToken, jsonHeaders } from './_fixtures'

/** Where the repair step records how it ended. */
const OUTCOME_KEY = 'hydra_triage_flow_seed'
const DETAIL_KEY = 'hydra_triage_flow_seed_detail'

/**
 * Read one hermiq app-config value through the provisioning API.
 *
 * @param request The Playwright request context (admin).
 * @param token   The CSRF request-token.
 * @param key     The app-config key.
 * @return The stored string, or '' when unset.
 */
async function appConfig(
	// eslint-disable-next-line @typescript-eslint/no-explicit-any
	request: any,
	token: string,
	key: string,
): Promise<string> {
	const res = await request.get(
		`/ocs/v2.php/apps/provisioning_api/api/v1/config/apps/hermiq/${key}?format=json`,
		{ headers: jsonHeaders(token) },
	)
	if (res.status() !== 200) {
		return ''
	}
	const body = await res.json().catch(() => null)
	return String(body?.ocs?.data?.data ?? '')
}

test.describe('hermiq#140: the Hydra Triage flow seeds on a clean install', () => {
	// 🔴 FIXME(hermiq#140) — ROOT CAUSE FOUND, and it is not what the issue says.
	//
	// The write does NOT fail. Measured on run 30883436861 (fresh stable33):
	// the breadcrumb this test reads says `seeded`, and the flow store returns
	// `[]`. The step succeeds and the row is unreachable.
	//
	// `FlowService::findAll()` scopes every read by organisation —
	// `findAllFlows(organisation: $organisation)` adds
	// `WHERE organisation = :org`. `SeedHydraTriageFlow` sets `owner: null` and
	// never sets `organisation` at all, so the row is written with a NULL
	// organisation and no `WHERE organisation = '<anything>'` can ever match it.
	//
	// It is therefore seeded exactly once, invisible to every tenant, forever —
	// and worse, the step's own idempotency check reads the mapper DIRECTLY
	// (no org filter), sees the orphan, and never re-seeds. The bug is
	// self-sealing.
	//
	// 🔑 The two assertions below are split for exactly this reason: checking
	// only the breadcrumb would have reported this as FIXED.
	//
	// NOT fixed here, deliberately. The repair step runs with no user session,
	// so it has no organisation any more than it has an owner — and choosing
	// one means deciding whose tenant a seeded flow belongs to. That is a
	// tenancy decision spanning hermiq and OpenRegister, not a null to fill in;
	// writing a flow into someone's organisation because it was convenient is
	// how a seed becomes a data-ownership incident. Escalated for a decision.
	test.fixme('the install-time flow seed reports success, and the flow exists', async ({ page }) => {
		const token = await harvestToken(page)

		const outcome = await appConfig(page.request, token, OUTCOME_KEY)
		const detail = await appConfig(page.request, token, DETAIL_KEY)

		// 🔴 An EMPTY breadcrumb is its own finding, distinct from a failure: it
		// means the step did not reach any of its exit paths — it never ran, or
		// it died before recording. Saying so is the difference between "the
		// seed failed" and "the seed was never attempted", which are different
		// bugs with different fixes.
		expect(
			outcome,
			'The flow-seed step recorded no outcome at all. Either it never ran during '
			+ '`occ app:enable`, or it died before its own breadcrumb. Both are different '
			+ 'from "the write failed".',
		).not.toEqual('')

		expect(
			outcome,
			`The install-time Hydra Triage flow seed did not succeed (outcome="${outcome}"). `
			+ `Recorded cause: ${detail || '<none recorded>'}`,
		).toBe('seeded')

		// And the flow is actually readable — the breadcrumb says what the step
		// BELIEVED; this says what the store contains. A step that reported
		// success while writing nothing is exactly the failure mode that made
		// this bug survive two investigations.
		const flows = await page.request.get(
			'/index.php/apps/openregister/api/flows?app=hermiq',
			{ headers: jsonHeaders(token) },
		)
		expect(flows.status(), 'the flow store must be readable').toBe(200)

		const body = await flows.json().catch(() => null)
		const results = (body?.results ?? body?.data ?? []) as Array<Record<string, unknown>>
		const names = results.map((f) => String(f.name ?? ''))

		expect(
			names,
			`the seeded flow must exist in the store, not merely be reported as written. Got: ${JSON.stringify(names)}`,
		).toContain('Hydra Triage')
	})
})
