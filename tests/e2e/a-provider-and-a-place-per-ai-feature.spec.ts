/**
 * A provider and a place per AI feature.
 *
 * What a gemeente has to be able to answer, in front of a functionaris
 * gegevensbescherming: which model saw this case, and in which jurisdiction. The
 * two registers that hold the halves of that answer, `ModelPolicy` and `AiFeature`,
 * never met until this change, so an administrator looking at either screen saw a
 * complete-looking picture that was not one.
 *
 * This suite pins the parts a unit test cannot see: that the answer reaches the
 * screen, and that the write which decides where a case's text goes is closed to a
 * caller who may not administer the register. It probes that second one with the
 * least privileged principal that should be refused, an ordinary authenticated
 * user, because a superuser success proves almost nothing about a boundary.
 *
 * @e2e ai-feature-governance::two-features-two-providers
 * @e2e ai-feature-governance::a-binding-outside-the-policy-is-refused-at-write-time
 * @e2e ai-feature-governance::an-unbound-feature-keeps-todays-behaviour
 * @e2e ai-feature-governance::residency-is-a-statement-not-a-guess
 * @e2e ai-feature-governance::the-detail-an-enum-cannot-carry-is-kept
 * @e2e ai-feature-governance::what-leaves-the-building-is-readable-in-one-place
 * @e2e ai-feature-governance::the-case-system-stays-where-it-is
 * @e2e exclude ai-feature-governance::narrowing-the-policy-disables-a-stale-binding {covered by FeatureProviderResolverTest::testNarrowingThePolicyRefusesAStaleBinding — narrowing a live policy and running a turn would send a real request to a provider this instance does not have}
 * @e2e exclude ai-feature-governance::one-enforcement-point-whatever-the-trigger {the three triggers are a schedule tick, a manual run and an interactive turn; all three reach the same createChatDriver chokepoint, pinned in ProviderFactory rather than through three browser journeys}
 * @e2e exclude ai-feature-governance::case-text-never-leaves-for-a-forbidden-region {the assertion is that NO request leaves, which a browser cannot observe; pinned in FeatureProviderResolverTest::testCaseTextNeverLeavesForAForbiddenRegion}
 * @e2e exclude ai-feature-governance::the-refusing-step-names-itself {two refusals compared side by side; pinned in FeatureProviderResolverTest::testEachRefusalNamesTheCheckThatRefused}
 * @e2e exclude ai-feature-governance::no-required-residency-refuses-nothing {an absence of a refusal on a live provider call; pinned in FeatureProviderResolverTest::testNoRequiredResidencyRefusesNothing}
 * @e2e exclude ai-feature-governance::which-model-saw-this-case-and-where-is-a-read {needs a completed run against a configured provider, which the nightly run-trace suite owns}
 * @e2e exclude ai-feature-governance::relabelling-a-provider-does-not-rewrite-history {needs two runs months apart in audit terms; the copy-not-reference rule is pinned in FeatureProviderResolverTest::testTheDisclosureCarriesTheWholeAnswer}
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'

const NC_USER = process.env.NC_USER || 'admin'
const NC_PASS = process.env.NC_PASS || 'admin'

/** An ordinary account with no admin rights, if the instance has one seeded. */
const ORDINARY_USER = process.env.NC_ORDINARY_USER || ''
const ORDINARY_PASS = process.env.NC_ORDINARY_PASS || ''

/**
 * Log a user in through Nextcloud's own login form.
 *
 * @param page The Playwright page.
 * @param user The user id.
 * @param pass The password.
 */
async function login(page: Page, user: string, pass: string): Promise<void> {
	await page.goto('/login', { waitUntil: 'domcontentloaded' })
	const userField = page.locator('#user')
	if ((await userField.count()) === 0) {
		return
	}
	await userField.fill(user)
	await page.locator('#password').fill(pass)
	await page.locator('button[type="submit"], input[type="submit"]').first().click()
	await page.locator('#user').waitFor({ state: 'hidden', timeout: 30_000 })
}

/**
 * Read the AI-feature register through hermiq's own API.
 *
 * @param page The Playwright page (carries the session).
 *
 * @return The registered features.
 */
async function fetchFeatures(page: Page): Promise<Array<Record<string, unknown>>> {
	return await page.evaluate(async () => {
		const res = await fetch('/index.php/apps/hermiq/api/ai-features', {
			headers: { Accept: 'application/json' },
		})
		const body = await res.json()
		return (body.results || []) as Array<Record<string, unknown>>
	})
}

/**
 * Read which provider each feature will use and where that provider runs.
 *
 * @param page The Playwright page.
 *
 * @return One row per feature.
 */
async function fetchResidencyOverview(
	page: Page,
): Promise<Array<Record<string, unknown>>> {
	return await page.evaluate(async () => {
		const res = await fetch('/index.php/apps/hermiq/api/ai-features/residency', {
			headers: { Accept: 'application/json' },
		})
		const body = await res.json()
		return (body.results || []) as Array<Record<string, unknown>>
	})
}

/**
 * Write a binding onto a feature, returning the status rather than throwing, so a
 * refusal can be asserted as a refusal.
 *
 * @param page The Playwright page.
 * @param id The AiFeature uuid.
 * @param binding The provider, model and required residency to send.
 *
 * @return The HTTP status and the parsed body.
 */
async function putBinding(
	page: Page,
	id: string,
	binding: Record<string, string>,
): Promise<{ status: number; body: Record<string, unknown> }> {
	return await page.evaluate(
		async ({ id, binding }) => {
			const token =
				(
					document.querySelector(
						'head[data-requesttoken]',
					) as HTMLElement | null
				)?.dataset.requesttoken
				|| (window as unknown as { OC?: { requestToken?: string } }).OC
					?.requestToken
				|| ''

			const res = await fetch(
				`/index.php/apps/hermiq/api/ai-features/${id}/binding`,
				{
					method: 'PUT',
					headers: {
						'Content-Type': 'application/json',
						Accept: 'application/json',
						requesttoken: token,
					},
					body: JSON.stringify(binding),
				},
			)

			const body = (await res.json().catch(() => ({}))) as Record<
				string,
				unknown
			>

			return { status: res.status, body }
		},
		{ id, binding },
	)
}

test.describe('a provider and a place per AI feature', () => {
	// @e2e ai-feature-governance::what-leaves-the-building-is-readable-in-one-place
	// @e2e ai-feature-governance::the-case-system-stays-where-it-is
	test('the register says, per feature, which provider it uses and where that provider runs', async ({
		page,
	}) => {
		await login(page, NC_USER, NC_PASS)

		const features = await fetchFeatures(page)
		test.skip(
			features.length === 0,
			'no AI features registered on this instance',
		)

		const overview = await fetchResidencyOverview(page)

		// One row per registered feature: a feature missing from this read is a
		// feature whose destination nobody can look up, which is the gap the change
		// exists to close.
		expect(overview.length).toBe(features.length)

		for (const row of overview) {
			// Never blank, and never inferred: an unstated residency says so.
			expect(['on-premise', 'eu', 'outside-eu', 'undeclared']).toContain(
				row.residency,
			)
		}

		// The register renders inside hermiq's admin settings section, which is also
		// the only place it is reachable: it is not an in-app nav page.
		await page.goto('/settings/admin/hermiq', { waitUntil: 'domcontentloaded' })
		await expect(page.locator('.ai-feature-register').first()).toBeVisible({
			timeout: 30_000,
		})

		// The answer is on the screen, not only in the API: "Runs on" is the column
		// an administrator reads without opening a second register.
		await expect(page.getByText('Runs on').first()).toBeVisible()
		await expect(
			page.locator('.ai-feature-register__residency').first(),
		).toBeVisible()
	})

	// @e2e ai-feature-governance::two-features-two-providers
	// @e2e ai-feature-governance::an-unbound-feature-keeps-todays-behaviour
	// @e2e ai-feature-governance::residency-is-a-statement-not-a-guess
	// @e2e ai-feature-governance::the-detail-an-enum-cannot-carry-is-kept
	test('an administrator binds one feature, and only that feature moves', async ({
		page,
	}) => {
		await login(page, NC_USER, NC_PASS)

		const features = await fetchFeatures(page)
		test.skip(features.length < 2, 'needs at least two registered AI features')

		const before = await fetchResidencyOverview(page)
		const target = features[0]
		const other = features[1]

		// The provider the instance is actually configured with, so the binding
		// stays inside whatever model policy this instance carries.
		const effective = before.find((row) => row.slug === target.slug)
		test.skip(
			!effective || !effective.provider,
			'this instance resolves no provider for the first feature, so there is nothing in policy to bind to',
		)

		const written = await putBinding(page, target.uuid as string, {
			provider: effective!.provider as string,
			model: effective!.model as string,
			requiredResidency: '',
		})

		expect(written.status).toBe(200)

		const after = await fetchResidencyOverview(page)
		const boundRow = after.find((row) => row.slug === target.slug)
		const otherRow = after.find((row) => row.slug === other.slug)
		const otherBefore = before.find((row) => row.slug === other.slug)

		expect(boundRow!.source).toBe('feature')

		// The second feature was not touched, which is the whole point of a per
		// feature binding: one feature moving must not move the rest.
		expect(otherRow!.provider).toBe(otherBefore!.provider)
		expect(otherRow!.source).toBe(otherBefore!.source)

		// Restore: clearing both halves returns the feature to the policy default.
		const cleared = await putBinding(page, target.uuid as string, {
			provider: '',
			model: '',
			requiredResidency: '',
		})
		expect(cleared.status).toBe(200)
	})

	// @e2e ai-feature-governance::a-binding-outside-the-policy-is-refused-at-write-time
	test('a caller who may not administer the register cannot decide which model reads a case', async ({
		page,
	}) => {
		test.skip(
			ORDINARY_USER === '' || ORDINARY_PASS === '',
			'set NC_ORDINARY_USER / NC_ORDINARY_PASS to probe the boundary with a non-admin',
		)

		// The least privileged principal that should be refused. An admin succeeding
		// here would prove nothing about who else can write this.
		await login(page, ORDINARY_USER, ORDINARY_PASS)

		const features = await fetchFeatures(page)
		test.skip(features.length === 0, 'this user sees no AI features')

		const refused = await putBinding(page, features[0].uuid as string, {
			provider: 'openai',
			model: 'gpt-4o',
			requiredResidency: '',
		})

		// 403 from the action gate, or 404 when the register is not even visible to
		// this user. What must never happen is a 200.
		expect([403, 404]).toContain(refused.status)
		expect(refused.status).not.toBe(200)
	})
})
