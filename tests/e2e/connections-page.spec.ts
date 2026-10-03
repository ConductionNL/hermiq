/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Integrations page: every outside connection hermiq has, on one page,
 * with a status integriq can back (adopt-connection-registry).
 *
 * WHERE THE ROWS COME FROM. The rows are integriq's `app_connection` objects,
 * synced from lib/Settings/connections.json with `app` equal to `hermiq`.
 * Hermiq writes no row: a save or an outcome sends integriq a report, and
 * integriq decides the status (hydra connection-registry D4). So this spec
 * needs integriq installed and synced. It has not been run in the change that
 * added it, and it sits outside `tests/e2e/spec-coverage`, which is the only
 * path CI runs, because CI does not install integriq.
 *
 * Locale: nothing forces the language of the E2E instance, so statuses are read
 * back over the API, and the page is addressed by route, by row title (declared,
 * not translated) and by href.
 *
 * The config supplies `use.storageState`, so specs start signed in as admin.
 *
 * @e2e app-connections::the-declaration-lists-the-six-connections
 * @e2e app-connections::a-settings-link-lands-on-a-section-that-exists
 * @e2e app-connections::the-page-opens-on-hermiqs-own-rows
 * @e2e app-connections::add-integration-goes-to-integriq
 * @e2e app-connections::saving-the-web-research-settings-reaches-the-row
 * @e2e app-connections::the-speech-row-names-the-fallback
 */

import type { APIRequestContext, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'

const APP_BASE = '/index.php/apps/hermiq'
const CONNECTIONS_API =
	'/index.php/apps/openregister/api/objects/integriq/app_connection'

/** The keys lib/Settings/connections.json declares, in declared order. */
const DECLARED_KEYS = [
	'llm',
	'llm-runner',
	'speech',
	'web-search',
	'webhook-delivery',
	'github-templates',
]

/** Rows that link into the admin settings, and the element id each link names. */
const LINKED: Record<string, string> = {
	llm: 'section-ai-provider',
	'web-search': 'section-web-research',
	'github-templates': 'section-organisation-credentials',
}

/**
 * Hermiq's rows in integriq's registry, keyed by connection key.
 *
 * `app` is a BARE filter key: the objects endpoint reads `filter[app]` as a
 * filter on nothing and answers the empty set without an error.
 *
 * @param api The authenticated request context.
 * @return The rows by key.
 */
async function connectionsByKey(
	api: APIRequestContext,
): Promise<Record<string, Record<string, unknown>>> {
	const res = await api.get(`${CONNECTIONS_API}?app=hermiq&_limit=200`)
	expect(res.ok(), `list integriq/app_connection -> ${res.status()}`).toBeTruthy()
	const body = await res.json()
	const byKey: Record<string, Record<string, unknown>> = {}
	for (const row of body.results ?? []) {
		// A row from another app here means the filter was dropped.
		expect(String(row.app), 'a connection row from another app').toBe('hermiq')
		byKey[String(row.key)] = row
	}
	return byKey
}

/**
 * The CSRF token of a running hermiq page, for a write through the API.
 *
 * @param page The Playwright page.
 * @return The request token.
 */
async function requestToken(page: Page): Promise<string> {
	await page.goto(`${APP_BASE}/`, { waitUntil: 'domcontentloaded' })
	return page.evaluate(
		() =>
			(window as unknown as { OC?: { requestToken?: string } }).OC
				?.requestToken ?? '',
	)
}

/**
 * Open the Integrations page the way its menu entry does, with the preset.
 *
 * @param page The Playwright page.
 */
async function openIntegrations(page: Page): Promise<void> {
	await page.goto(`${APP_BASE}/settings/integrations?app=hermiq`, {
		waitUntil: 'domcontentloaded',
	})
	await expect(page.locator('.cn-index-page')).toBeVisible({ timeout: 30_000 })
}

test.describe('Integrations', () => {
	// @e2e app-connections::the-declaration-lists-the-six-connections
	// @e2e app-connections::the-page-opens-on-hermiqs-own-rows
	test('lists the six declared connections, and only hermiq rows', async ({
		page,
	}) => {
		const byKey = await connectionsByKey(page.request)
		expect(Object.keys(byKey).sort()).toEqual([...DECLARED_KEYS].sort())

		const orders = DECLARED_KEYS.map((key) => Number(byKey[key].order))
		expect([...orders].sort((a, b) => a - b)).toEqual(orders)

		await openIntegrations(page)
		for (const key of DECLARED_KEYS) {
			await expect(
				page.getByRole('row', {
					name: new RegExp(String(byKey[key].title), 'i'),
				}),
			).toBeVisible()
		}
	})

	// @e2e app-connections::a-settings-link-lands-on-a-section-that-exists
	test('every settings link lands on an element that exists', async ({ page }) => {
		const byKey = await connectionsByKey(page.request)

		for (const [key, anchor] of Object.entries(LINKED)) {
			expect(String(byKey[key].settingsUrl)).toBe(
				`/settings/admin/hermiq#${anchor}`,
			)
		}

		await page.goto('/index.php/settings/admin/hermiq', {
			waitUntil: 'domcontentloaded',
		})
		for (const anchor of Object.values(LINKED)) {
			await expect(page.locator(`#${anchor}`)).toHaveCount(1, {
				timeout: 30_000,
			})
		}
	})

	// @e2e app-connections::add-integration-goes-to-integriq
	test('sends Add integration to integriq instead of offering a form', async ({
		page,
	}) => {
		await openIntegrations(page)

		await expect(page.locator('[data-testid="cn-cta-primary"]')).toHaveCount(0)

		await page.locator('[data-testid="cn-actions"] button').first().click()
		await Promise.all([
			page.waitForURL(/\/apps\/integriq\/connections\?app=hermiq&link=1$/, {
				timeout: 30_000,
			}),
			page
				.getByRole('menuitem', {
					name: /Add integration|Integratie toevoegen/i,
				})
				.click(),
		])
	})

	// @e2e app-connections::the-speech-row-names-the-fallback
	test('the speech row names the local fallback while speech_base_url is empty', async ({
		page,
	}) => {
		const speech = (await connectionsByKey(page.request)).speech
		test.skip(
			speech.status === 'configured',
			'speech_base_url is set on this instance, so the fallback message does not apply',
		)
		expect(speech.status).toBe('unconfigured')
		expect(String(speech.statusMessage)).toContain('http://127.0.0.1:8000')
	})

	// @e2e app-connections::saving-the-web-research-settings-reaches-the-row
	test('saving a search backend turns the web search row Configured', async ({
		page,
	}) => {
		const token = await requestToken(page)
		const headers = { requesttoken: token, 'Content-Type': 'application/json' }
		const settingsApi = `${APP_BASE}/api/settings/web-research`

		// Snapshot what this test changes, so the next run starts from the same page.
		const before = await page.request.get(settingsApi)
		expect(before.ok()).toBeTruthy()
		const previous = await before.json()
		test.skip(
			String(previous.searchEndpoint ?? '') !== '',
			'a search backend is already saved on this instance; the test would overwrite it',
		)

		try {
			const save = await page.request.patch(settingsApi, {
				headers,
				data: {
					webResearch: {
						searchProvider: 'searxng',
						searchEndpoint: 'https://search.e2e.invalid',
					},
				},
			})
			expect(save.ok(), `save web research -> ${save.status()}`).toBeTruthy()

			await expect
				.poll(
					async () =>
						(await connectionsByKey(page.request))['web-search'].status,
					{
						timeout: 15_000,
					},
				)
				.toBe('configured')
			expect(
				String(
					(await connectionsByKey(page.request))['web-search']
						.statusMessage,
				),
			).toContain('search.e2e.invalid')
		} finally {
			await page.request.patch(settingsApi, {
				headers,
				data: {
					webResearch: {
						searchProvider: String(previous.searchProvider ?? ''),
						searchEndpoint: String(previous.searchEndpoint ?? ''),
					},
				},
			})
		}
	})
})
