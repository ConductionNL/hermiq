/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Skill install idempotency e2e (skill-install-idempotency, test-plan.md "E2E"):
 * re-installing a skill bundle that is already installed updates the skills it
 * holds instead of adding a second copy of each.
 *
 * WHY THIS EXISTS: before the change, re-installing `buildiq-hydra` added 94 more
 * skills next to the 94 already there. The count is read from Hermiq's own skills
 * list before and after the install, never from the installer's report, because the
 * report is exactly what was wrong.
 *
 * The bundle is installed through the same route the skills page's install action
 * posts to, and the skills page is then opened to assert that no name is listed twice.
 * The quarantine and learnings surfaces of the same change are covered by
 * `skill-provenance.spec.ts`.
 *
 * The install reaches GitHub, so it needs network access from the Nextcloud host
 * and, for a private bundle, a broker credential. Without the bundle the test skips
 * loudly rather than passing vacuously.
 *
 * Run against a running Nextcloud with Hermiq + OpenRegister installed:
 *
 *     NEXTCLOUD_URL=http://localhost:8080 \
 *       NC_USER=admin NC_PASS=admin \
 *       E2E_SKILL_BUNDLE=ConductionNL/buildiq-hydra \
 *       npx playwright test tests/e2e/skill-install-idempotency.spec.ts --project chromium
 *
 * Scenario traceability (gate-19):
 * @e2e skills-marketplace::re-installing-a-bundle-does-not-duplicate-its-skills
 */

import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'

const NC_USER = process.env.NC_USER || 'admin'
const NC_PASS = process.env.NC_PASS || 'admin'
const BUNDLE = process.env.E2E_SKILL_BUNDLE || 'ConductionNL/buildiq-hydra'
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
 * Read every skill name through Hermiq's own API.
 *
 * @param page The Playwright page (carries the session).
 * @return The skill names, one per skill object.
 */
async function skillNames(page: Page): Promise<string[]> {
	return await page.evaluate(async () => {
		const res = await fetch('/index.php/apps/hermiq/api/skills?_limit=1000', {
			headers: { Accept: 'application/json' },
		})
		const body = await res.json()
		const results = (body.results || []) as Array<Record<string, unknown>>
		return results.map((skill) => String(skill.name ?? ''))
	})
}

/**
 * Install the bundle through the route the skills page's install action uses.
 *
 * @param page The authenticated page.
 * @return The status and parsed body.
 */
async function installBundle(page: Page) {
	const [owner, repo] = BUNDLE.split('/')
	return page.evaluate(
		async ({ route, body }) => {
			const res = await fetch(route, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'OCS-APIRequest': 'true',
				},
				body: JSON.stringify(body),
				credentials: 'same-origin',
			})
			return { status: res.status, json: await res.json().catch(() => null) }
		},
		{ route: INSTALL, body: { owner, repo } },
	)
}

/**
 * The names that occur more than once.
 *
 * @param names The skill names.
 * @return Each duplicated name once.
 */
function duplicates(names: string[]): string[] {
	const seen = new Set<string>()
	const twice = new Set<string>()
	for (const name of names) {
		const key = name.trim().toLowerCase()
		if (seen.has(key)) {
			twice.add(name)
		}
		seen.add(key)
	}
	return [...twice]
}

test.describe('skill install idempotency', () => {
	test.beforeEach(async ({ page }) => {
		await login(page)
		await page.goto('/apps/hermiq/skills', { waitUntil: 'domcontentloaded' })
	})

	test('re-installing a bundle does not duplicate its skills', async ({ page }) => {
		test.setTimeout(300_000)

		// First install makes sure the bundle is present; the second is the re-install
		// the scenario is about.
		const first = await installBundle(page)
		test.skip(
			first.status !== 200 && first.status !== 207,
			`bundle ${BUNDLE} could not be installed here (HTTP ${first.status}); this test needs it`,
		)

		const before = await skillNames(page)
		expect(before.length).toBeGreaterThan(0)

		const second = await installBundle(page)
		expect([200, 207]).toContain(second.status)

		const after = await skillNames(page)
		expect(after.length).toBe(before.length)
		expect(duplicates(after)).toEqual([])

		// The skills page itself shows no sampled name in two cells, and shows at
		// least one of them, so the check cannot pass on an empty page.
		await page.goto('/apps/hermiq/skills', { waitUntil: 'domcontentloaded' })
		await expect(page.locator('tr').nth(1)).toBeVisible({ timeout: 30_000 })
		let visible = 0
		for (const name of after.filter((n) => n.trim() !== '').slice(0, 10)) {
			const cells = page.getByRole('cell', { name, exact: true })
			const count = await cells.count()
			expect(count, `"${name}" is listed ${count} times`).toBeLessThanOrEqual(1)
			visible += count
		}
		expect(visible).toBeGreaterThan(0)
	})
})
