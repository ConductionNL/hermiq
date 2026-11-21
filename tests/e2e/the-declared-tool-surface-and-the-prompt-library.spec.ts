/**
 * The declared tool surface, and the prompt library.
 *
 * Two halves of one sentence: the owning app declares the tools and the rights, and
 * hermiq owns the assistant. This suite pins what a browser can actually see of
 * both, and leaves the rest to the unit tests that can.
 *
 * The tool surface is probed with the least privileged principal that should be
 * refused: an ordinary authenticated user with no registration. What a model may
 * read is a privacy boundary, and the boundary that matters is the one an
 * unregistered caller meets, not the one an admin walks through.
 *
 * @e2e agent-tool-governance::the-owning-app-declares-hermiq-publishes
 * @e2e ai-feature-admin-surface::the-text-that-will-be-sent-is-the-text-on-screen
 * @e2e ai-feature-admin-surface::order-is-the-administrators
 * @e2e ai-feature-admin-surface::coming-back-is-deliberate
 * @e2e exclude agent-tool-governance::a-write-tool-is-denied-unless-granted {needs a registration granting nothing and a declared write tool on the instance; pinned in OutsideAgentGatewayTest::testAWriteToolIsDeniedUnlessGranted}
 * @e2e exclude agent-tool-governance::an-agent-cannot-exceed-its-principal {needs a case the principal may not read, which would mean seeding another tenant's data on the instance under test; pinned in OutsideAgentGatewayTest::testAGrantedAgentWithoutTheRightIsRefused}
 * @e2e exclude agent-tool-governance::revocation-reaches-the-agent-without-an-edit {revoking a real person's access mid-suite edits the instance; the no-impersonation half is pinned in OutsideAgentGatewayTest::testTheGatewayNeverImpersonates}
 * @e2e exclude agent-tool-governance::a-permitted-caller-without-the-grant-is-refused {pinned in OutsideAgentGatewayTest::testAPermittedCallerWithoutTheGrantIsRefused, which also proves the owning app is never asked}
 * @e2e exclude agent-tool-governance::a-granted-agent-without-the-right-is-refused {pinned in OutsideAgentGatewayTest::testAGrantedAgentWithoutTheRightIsRefused}
 * @e2e exclude agent-tool-governance::a-reading-agent-does-not-receive-every-field {needs a registration with an allowlist and a case carrying twelve fields; pinned in OutsideAgentGatewayTest::testAReadingAgentDoesNotReceiveEveryField}
 * @e2e exclude agent-tool-governance::nothing-is-renamed-on-the-way-out {a field-by-field comparison against the owning app's own names; pinned in OutsideAgentGatewayTest::testNothingIsRenamedOnTheWayOut}
 * @e2e exclude agent-tool-governance::one-place-to-read-who-called-what {needs an internal run and an outside call on one instance, which the nightly run-trace suite owns}
 * @e2e exclude ai-feature-admin-surface::scope-decides-where-a-prompt-appears {needs two record types seeded with scoped prompts; pinned in AssistantPromptLibraryTest::testScopeDecidesWhereAPromptAppears}
 * @e2e exclude ai-feature-admin-surface::everything-stops-in-one-act {a disable-all on a live instance switches off the assistant for everyone using it; pinned in AssistantPromptLibraryTest::testEverythingStopsInOneRecordedAct}
 * @e2e exclude ai-feature-admin-surface::the-switch-off-is-on-the-record {reads the audit entry that disable-all writes; pinned in the same test}
 * @e2e exclude ai-feature-admin-surface::an-edit-survives-the-shipping-app {needs the consuming app updated mid-suite; pinned in AssistantPromptLibraryTest::testAnEditSurvivesTheShippingApp}
 * @e2e exclude ai-feature-admin-surface::a-disabled-prompt-stays-disabled {the same app-update dependency; pinned in AssistantPromptLibraryTest::testADisabledPromptStaysDisabled}
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

/** hermiq's own tool namespace, which the outside surface must never offer. */
const OWN_NAMESPACE = 'hermiq.'

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
 * Read the outside tool surface.
 *
 * @param page The Playwright page.
 *
 * @return The HTTP status and the parsed body.
 */
async function readSurface(
	page: Page,
): Promise<{ status: number; body: Record<string, unknown> }> {
	return await page.evaluate(async () => {
		const res = await fetch('/index.php/apps/hermiq/api/outside-agent/tools', {
			headers: { Accept: 'application/json' },
		})
		const body = (await res.json().catch(() => ({}))) as Record<string, unknown>
		return { status: res.status, body }
	})
}

/**
 * Attempt one outside call, returning the status rather than throwing.
 *
 * @param page The Playwright page.
 * @param tool The tool id to call.
 *
 * @return The HTTP status and the parsed body.
 */
async function callTool(
	page: Page,
	tool: string,
): Promise<{ status: number; body: Record<string, unknown> }> {
	return await page.evaluate(async (tool) => {
		const token =
			(document.querySelector('head[data-requesttoken]') as HTMLElement | null)
				?.dataset.requesttoken
			|| (window as unknown as { OC?: { requestToken?: string } }).OC
				?.requestToken
			|| ''

		const res = await fetch('/index.php/apps/hermiq/api/outside-agent/call', {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				Accept: 'application/json',
				requesttoken: token,
			},
			body: JSON.stringify({ tool, arguments: {} }),
		})

		const body = (await res.json().catch(() => ({}))) as Record<string, unknown>
		return { status: res.status, body }
	}, tool)
}

test.describe('the declared tool surface and the prompt library', () => {
	// @e2e agent-tool-governance::the-owning-app-declares-hermiq-publishes
	test('the surface offers only what an owning app declared, and none of hermiq own tools', async ({
		page,
	}) => {
		await login(page, NC_USER, NC_PASS)

		const surface = await readSurface(page)
		expect(surface.status).toBe(200)

		const tools = (surface.body.tools || []) as Array<Record<string, unknown>>

		// A surface that is empty is a valid result on an instance where no app has
		// declared anything: what must never happen is hermiq publishing its own.
		for (const tool of tools) {
			const id = String(tool.name || tool.id || '')
			expect(id.startsWith(OWN_NAMESPACE)).toBe(false)
		}
	})

	test('an unregistered caller reaches no tool, whatever they may do for themselves', async ({
		page,
	}) => {
		test.skip(
			ORDINARY_USER === '' || ORDINARY_PASS === '',
			'set NC_ORDINARY_USER / NC_ORDINARY_PASS to probe the boundary with a non-admin',
		)

		// The least privileged principal that should be refused: authenticated,
		// with no registration behind them.
		await login(page, ORDINARY_USER, ORDINARY_PASS)

		const surface = await readSurface(page)
		expect(surface.body.registered).toBe(false)

		const refused = await callTool(page, 'dossiq.case.update')

		expect(refused.status).toBe(403)
		// The refusal names the gate, so an integrator knows whether to ask an
		// administrator here or an administrator there.
		expect(typeof refused.body.gate).toBe('string')
	})

	// @e2e ai-feature-admin-surface::the-text-that-will-be-sent-is-the-text-on-screen
	// @e2e ai-feature-admin-surface::order-is-the-administrators
	// @e2e ai-feature-admin-surface::coming-back-is-deliberate
	test('the prompt library shows the exact text, in the administrator order', async ({
		page,
	}) => {
		await login(page, NC_USER, NC_PASS)

		const prompts = await page.evaluate(async () => {
			const res = await fetch('/index.php/apps/hermiq/api/assistant-prompts', {
				headers: { Accept: 'application/json' },
			})
			const body = await res.json()
			return (body.results || []) as Array<Record<string, unknown>>
		})

		await page.goto('/settings/admin/hermiq', { waitUntil: 'domcontentloaded' })
		await expect(page.locator('.prompt-library').first()).toBeVisible({
			timeout: 30_000,
		})

		// There is one act that switches everything off, and no act that switches
		// everything back on: coming back is deliberate.
		await expect(
			page.getByRole('button', { name: 'Disable every prompt' }),
		).toBeVisible()
		await expect(
			page.getByRole('button', { name: /enable every|enable all/i }),
		).toHaveCount(0)

		test.skip(prompts.length === 0, 'no prompts shipped on this instance yet')

		// The text on screen is the text that is sent, in full rather than as a
		// preview: a truncated prompt is one nobody can defend an answer with.
		const shown = await page.locator('.prompt-library__text').allInnerTexts()
		expect(shown[0].trim()).toBe(String(prompts[0].prompt).trim())

		// And the order is the administrator's, not alphabetical.
		const labels = await page.locator('.prompt-library__label').allInnerTexts()
		expect(labels.map((l) => l.trim())).toEqual(
			prompts.map((p) => String(p.label).trim()),
		)
	})
})
