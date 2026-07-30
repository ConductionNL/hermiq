/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Shared fixtures for the spec-coverage e2e specs.
 *
 * This file was referenced by hydra-console-agent-leaves.spec.ts but never
 * committed with it, which made the WHOLE e2e suite fail at collection
 * ("Cannot find module './_fixtures'") — reconstructed from the spec's usage
 * and the login pattern the sibling specs (dashboard-and-agents.spec.ts)
 * already use.
 *
 * @spec openspec/specs/agent-object-leaf/spec.md
 */

import { type Page } from '@playwright/test'

/** The OpenRegister HTTP API base every data-plane read goes through. */
export const OR_API = '/index.php/apps/openregister/api'

const NC_USER = process.env.NC_USER || 'admin'
const NC_PASS = process.env.NC_PASS || 'admin'

/**
 * The request headers an authenticated OpenRegister API call needs.
 *
 * @param token The harvested CSRF request-token.
 * @return The header map.
 */
export function jsonHeaders(token: string): Record<string, string> {
	return {
		requesttoken: token,
		'OCS-APIRequest': 'true',
		Accept: 'application/json',
	}
}

/**
 * Log in (idempotent — skips the form when already authenticated) and harvest
 * the session's CSRF request-token from a rendered Nextcloud page, so the
 * spec's `page.request` API calls pass the server's CSRF check.
 *
 * @param page The Playwright page (its session backs `page.request`).
 * @return The request-token.
 */
export async function harvestToken(page: Page): Promise<string> {
	await page.goto('/login', { waitUntil: 'domcontentloaded' })

	const userField = page.locator('#user')
	if (await userField.count() > 0) {
		await userField.fill(NC_USER)
		await page.locator('#password').fill(NC_PASS)
		await page.locator('button[type="submit"], input[type="submit"]').first().click()
		// Nextcloud holds persistent long-poll connections, so 'networkidle'
		// never fires; the login field detaching is the "logged in" signal.
		await page.locator('#user').waitFor({ state: 'hidden', timeout: 30_000 })
	}

	// Land on any app page so OC.requestToken / the requesttoken meta is present.
	await page.goto('/index.php/apps/dashboard/', { waitUntil: 'domcontentloaded' })

	const token = await page.evaluate(() => {
		const oc = (window as unknown as { OC?: { requestToken?: string } }).OC
		return oc?.requestToken
			|| document.head.querySelector<HTMLMetaElement>('meta[name=requesttoken]')?.content
			|| ''
	})

	if (token === '') {
		throw new Error('Could not harvest a CSRF request-token from the authenticated session.')
	}

	return token
}
