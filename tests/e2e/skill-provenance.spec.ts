/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Skill provenance e2e (skill-install-idempotency): the SkillDetail page's origin +
 * review-status card — a quarantined skill shows its state AND the recorded reason,
 * an installed skill shows the source it came from and when it was last refreshed,
 * and the "local learnings are ahead of the source" notice appears only when it
 * should.
 *
 * WHY THIS EXISTS: the install path re-quarantines a skill whose content changed and
 * preserves local learnings an update would otherwise overwrite. Both were reported
 * ONLY in the install API response — nowhere a person would look. This suite is what
 * keeps that surface honest.
 *
 * The third test drives the CONDITIONAL branch, not just the card's presence: it
 * pushes `lastAcceptedVersionAt` past `sourceUpdatedAt` through the real API and
 * asserts the notice appears, then restores the original value and asserts it is
 * gone. Asserting only the absent case would pass against a card that can never
 * render the warning at all.
 *
 * Run against a running Nextcloud with Hermiq + OpenRegister installed:
 *
 *     NEXTCLOUD_URL=http://localhost:8080 \
 *       NC_USER=admin NC_PASS=admin \
 *       npx playwright test tests/e2e/skill-provenance.spec.ts --project chromium
 *
 * Scenario traceability (gate-19):
 * @e2e skills-marketplace::a-quarantined-skill-shows-its-state-and-the-reason
 * @e2e skills-marketplace::an-installed-skill-shows-where-it-came-from-and-when-it-was-refreshed
 * @e2e skills-marketplace::the-learnings-notice-appears-only-when-learnings-are-ahead-of-the-source
 */

import { test, expect, type Page } from '@playwright/test'

const NC_USER = process.env.NC_USER || 'admin'
const NC_PASS = process.env.NC_PASS || 'admin'

/**
 * Log the configured user in through Nextcloud's real login form (idempotent).
 *
 * @param page The Playwright page.
 */
async function login(page: Page): Promise<void> {
	await page.goto('/login', { waitUntil: 'domcontentloaded' })

	const userField = page.locator('#user')
	if (await userField.count() === 0) {
		return
	}

	await userField.fill(NC_USER)
	await page.locator('#password').fill(NC_PASS)
	await page.locator('button[type="submit"], input[type="submit"]').first().click()
	await page.locator('#user').waitFor({ state: 'hidden', timeout: 30_000 })
}

/**
 * Read every skill through Hermiq's own API.
 *
 * Used to PICK the fixtures rather than hard-coding names: which skills are
 * quarantined or bundle-installed depends on what this instance has installed, and a
 * test that hard-codes one is a test that silently stops covering anything the day
 * the seed changes.
 *
 * @param page The Playwright page (carries the session).
 *
 * @return The skill objects.
 */
async function fetchSkills(page: Page): Promise<Array<Record<string, unknown>>> {
	return await page.evaluate(async () => {
		const res = await fetch('/index.php/apps/hermiq/api/skills', {
			headers: { Accept: 'application/json' },
		})
		const body = await res.json()
		return (body.results || []) as Array<Record<string, unknown>>
	})
}

/**
 * Open one skill's detail page directly by uuid.
 *
 * @param page The Playwright page.
 * @param uuid The skill uuid.
 */
async function openSkill(page: Page, uuid: string): Promise<void> {
	await page.goto(`/apps/hermiq/skills/${uuid}`, { waitUntil: 'domcontentloaded' })
	await expect(page.locator('.skill-provenance')).toBeVisible({ timeout: 30_000 })
}

test.describe('skill provenance (skill-install-idempotency)', () => {
	test.beforeEach(async ({ page }) => {
		await login(page)
	})

	// @e2e skills-marketplace::a-quarantined-skill-shows-its-state-and-the-reason
	test('a quarantined skill shows that it awaits review and why', async ({ page }) => {
		const skills = await fetchSkills(page)
		const quarantined = skills.find(
			(s) => s.state === 'quarantined' && typeof s.quarantineReason === 'string' && s.quarantineReason !== '',
		)

		test.skip(!quarantined, 'no quarantined skill with a recorded reason on this instance')

		await openSkill(page, quarantined!.id as string)

		const card = page.locator('.skill-provenance')
		await expect(card.getByText('Awaiting review').first()).toBeVisible()
		await expect(card.getByText('Quarantined — awaiting review').first()).toBeVisible()

		// The REASON itself, not merely the fact of quarantine — the reason is the
		// part that tells a reviewer what changed under an earlier approval.
		await expect(card.getByText(quarantined!.quarantineReason as string).first()).toBeVisible()

		// Read-only by contract: the card reports state, it never changes it.
		await expect(card.getByRole('button')).toHaveCount(0)
	})

	// @e2e skills-marketplace::an-installed-skill-shows-where-it-came-from-and-when-it-was-refreshed
	test('a bundle-installed skill shows its source url and last refresh', async ({ page }) => {
		const skills = await fetchSkills(page)
		const installed = skills.find(
			(s) => typeof s.sourceUrl === 'string' && (s.sourceUrl as string).startsWith('http'),
		)

		test.skip(!installed, 'no bundle-installed skill on this instance')

		await openSkill(page, installed!.id as string)

		const card = page.locator('.skill-provenance')
		await expect(card.getByText('Source').first()).toBeVisible()

		// The real origin, as a link — a person has to be able to go and look.
		const link = card.getByRole('link', { name: installed!.sourceUrl as string })
		await expect(link).toBeVisible()
		await expect(link).toHaveAttribute('href', installed!.sourceUrl as string)

		// A refreshed skill shows a real timestamp, never the "—" placeholder.
		await expect(card.getByText('Last updated from source').first()).toBeVisible()
		const facts = await card.innerText()
		expect(facts).not.toContain('Last updated from source\n—')
	})

	// @e2e skills-marketplace::the-learnings-notice-appears-only-when-learnings-are-ahead-of-the-source
	test('the learnings notice appears only when local learnings are ahead', async ({ page }) => {
		const skills = await fetchSkills(page)
		const target = skills.find(
			(s) => typeof s.sourceUpdatedAt === 'string' && (s.sourceUpdatedAt as string) !== '',
		)

		test.skip(!target, 'no skill carrying a sourceUpdatedAt on this instance')

		const uuid = target!.id as string
		const originalAccepted = (target!.lastAcceptedVersionAt as string) || ''

		// BASELINE: learnings are not ahead, so the notice must be absent.
		await openSkill(page, uuid)
		await expect(page.locator('.skill-provenance').getByText('Local learnings are ahead of the source')).toHaveCount(0)

		// Push lastAcceptedVersionAt PAST sourceUpdatedAt through OpenRegister's real
		// PATCH endpoint, so the condition the card tests is genuinely satisfied
		// rather than simulated. PATCH rather than PUT: OR's save is PUT-semantic and
		// would drop every property this body omits.
		const ahead = new Date(new Date(target!.sourceUpdatedAt as string).getTime() + 3_600_000).toISOString()

		const patch = async (when: string): Promise<number> => await page.evaluate(
			async ([id, value]) => {
				const res = await fetch(`/index.php/apps/openregister/api/objects/hermiq/agentskill/${id}`, {
					method: 'PATCH',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: (window as any).OC?.requestToken || '',
					},
					body: JSON.stringify({ lastAcceptedVersionAt: value }),
				})
				return res.status
			},
			[uuid, when] as const,
		)

		const wrote = await patch(ahead)

		// FAIL, never skip. A skip here would leave the baseline assertion above as the
		// whole test — and a card that can NEVER render the warning would pass it.
		expect(wrote, 'could not set lastAcceptedVersionAt via the OpenRegister PATCH endpoint').toBeLessThan(400)

		try {
			await openSkill(page, uuid)
			const card = page.locator('.skill-provenance')
			await expect(card.getByText('Local learnings are ahead of the source').first()).toBeVisible()
			await expect(card.getByText('the incoming learnings file is not applied', { exact: false }).first()).toBeVisible()
		} finally {
			// Restore, so the suite is re-runnable and leaves no state behind.
			await patch(originalAccepted)
		}
	})
})
