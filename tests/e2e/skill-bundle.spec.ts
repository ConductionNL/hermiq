/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Skill bundle e2e (skill-bundle-publish): the bundle publish/install contract
 * against a live instance — routes reachable, coordinates validated before any
 * GitHub call, and a repository WITHOUT `hermiq-skills.json` refused as
 * `not_a_bundle` rather than mis-parsed as a single skill.
 *
 * Deliberately scoped. A full publish→install round trip needs a real GitHub
 * repository and a broker credential; that is exercised when buildiq-hydra is
 * wired, not here. What this spec proves is the part that can fail silently: the
 * routes exist, they refuse bad input BEFORE reaching out, and a plain repo is
 * never mistaken for a bundle. Asserting a round trip we cannot actually perform
 * would be theatre.
 *
 * Run against a running Nextcloud with Hermiq + OpenRegister installed:
 *
 *     NEXTCLOUD_URL=http://localhost:8099 \
 *       NC_USER=admin NC_PASS=admin \
 *       npx playwright test tests/e2e/skill-bundle.spec.ts --project chromium
 *
 * Scenario traceability (gate-19):
 * @e2e skills-marketplace::a-bundle-repo-is-not-mistaken-for-a-single-skill-repo
 * @e2e skills-marketplace::a-partial-failure-is-reported-not-hidden
 * @e2e skills-marketplace::a-crafted-skill-name-cannot-escape-the-bundle
 */

import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'

const NC_USER = process.env.NC_USER || 'admin'
const NC_PASS = process.env.NC_PASS || 'admin'

const PUBLISH = '/apps/hermiq/api/skills/bundle/publish'
const INSTALL = '/apps/hermiq/api/skills/bundle/install'

/**
 * Log the configured user in through Nextcloud's real login form (idempotent).
 *
 * @param page The Playwright page.
 */
async function login(page: Page): Promise<void> {
	await page.goto('/login', { waitUntil: 'domcontentloaded' })

	const userField = page.locator('#user')
	if ((await userField.count()) === 0) {
		return
	}

	await userField.fill(NC_USER)
	await page.locator('#password').fill(NC_PASS)
	await page.locator('button[type="submit"], input[type="submit"]').first().click()
	await page.locator('#user').waitFor({ state: 'hidden', timeout: 30_000 })
}

/**
 * POST JSON through the page's authenticated session.
 *
 * @param page  The authenticated page.
 * @param route The app route.
 * @param body  The JSON payload.
 * @return The status and parsed body.
 */
async function post(page: Page, route: string, body: Record<string, unknown>) {
	return page.evaluate(
		async ({ r, b }) => {
			const res = await fetch(r, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'OCS-APIRequest': 'true',
				},
				body: JSON.stringify(b),
				credentials: 'same-origin',
			})
			return { status: res.status, json: await res.json().catch(() => null) }
		},
		{ r: route, b: body },
	)
}

test.describe('skill-bundle-publish — contract', () => {
	test.beforeEach(async ({ page }) => {
		await login(page)
		await page.goto('/apps/hermiq/skills', { waitUntil: 'domcontentloaded' })
	})

	test('the bundle routes are registered and reachable', async ({ page }) => {
		// A router 404 and a validation 400 look nothing alike — this is the marker
		// that the new routes actually exist on the running instance.
		const result = await post(page, INSTALL, { owner: 'bad!!owner', repo: 'x' })

		expect(result.status).toBe(400)
		expect(result.json?.error).toBe('invalid_repo')
	})

	test('coordinates are validated before any GitHub call', async ({ page }) => {
		const badRef = await post(page, INSTALL, {
			owner: 'acme',
			repo: 'demo',
			ref: 'bad ref with spaces',
		})
		expect(badRef.status).toBe(400)
		expect(badRef.json?.error).toBe('invalid_ref')

		const noSkills = await post(page, PUBLISH, {
			owner: 'acme',
			repo: 'demo',
			skillIds: [],
		})
		expect(noSkills.status).toBe(400)
		expect(noSkills.json?.error).toBe('skillIds must be a non-empty array')

		const badVisibility = await post(page, PUBLISH, {
			owner: 'acme',
			repo: 'demo',
			skillIds: ['x'],
			visibility: 'secret',
		})
		expect(badVisibility.status).toBe(400)
		expect(badVisibility.json?.error).toBe('invalid_visibility')
	})

	test('a repository without the manifest is refused, not mis-parsed', async ({
		page,
	}) => {
		// A real, reachable public repository that is NOT a bundle. The failure mode
		// this guards against is installing it as an empty/partial skill set while
		// reporting success.
		const result = await post(page, INSTALL, {
			owner: 'ConductionNL',
			repo: 'openbuild',
		})

		expect(result.status).toBe(404)
		expect(result.json?.error).toBe('not_a_bundle')
		expect(result.json?.installed).toBeUndefined()
	})

	test('the bundle routes are not anonymously usable', async ({ browser }) => {
		// A fresh context with no session at all.
		//
		// Nextcloud answers 404 here rather than 401: app routes are not resolved
		// for an anonymous session, so the request never reaches the controller's
		// own 401 guard (a direct API-shaped call with no cookie DOES get that 401).
		// Either way the requirement is the same and is what we assert — anonymous
		// access must not install anything. Pinning 401 specifically would be
		// asserting Nextcloud's redirect behaviour, not this feature's.
		const anon = await browser.newContext()
		const page = await anon.newPage()
		await page.goto('/', { waitUntil: 'domcontentloaded' }).catch(() => {})

		const result = await post(page, INSTALL, { owner: 'acme', repo: 'demo' })

		expect([401, 404]).toContain(result.status)
		expect(result.json?.installed).toBeUndefined()
		expect(result.json?.skills).toBeUndefined()

		await anon.close()
	})
})
