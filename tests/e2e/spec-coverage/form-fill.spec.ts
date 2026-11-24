/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Form fill: the route a form's "Paste to fill" calls (forms-smart-paste-fill,
 * REQ-FFILL-001 to REQ-FFILL-003).
 *
 * WHAT THE UNIT TESTS CANNOT SHOW. FormFillEngineTest, FormFillControllerTest and
 * SeedFormFillFeatureTest pin the engine with a fake provider, the controller's
 * limits and the seed. They cannot show that the routes answer a signed-in browser
 * session through Nextcloud's router and middleware, or that a real model's answer
 * is fitted to the fields. Those are here.
 *
 * The availability answer decides which half applies: with the feature off (a fresh
 * install) the off scenario is asserted and the fill scenario is skipped; with it on,
 * the other way round. The oversized paste is refused either way, before any model call.
 *
 * Not run when written: it needs a live instance. Runs in the nightly.
 *
 * @e2e form-fill::a-built-app-hides-paste-to-fill-while-the-feature-is-off
 * @e2e form-fill::an-oversized-paste-is-refused-before-any-model-call
 * @e2e form-fill::an-email-signature-fills-three-fields
 *
 * @spec openspec/changes/archive/2026-11-24-forms-smart-paste-fill/specs/form-fill/spec.md
 */

import { expect, test } from '@playwright/test'
import { harvestToken, jsonHeaders } from './_fixtures.ts'

const FILL = '/index.php/apps/hermiq/api/assistant/form-fill'

const FIELDS = [
	{ key: 'naam', label: 'Naam', type: 'text', options: [] },
	{ key: 'adres', label: 'Adres', type: 'text', options: [] },
	{ key: 'telefoon', label: 'Telefoon', type: 'text', options: [] },
	{ key: 'soort', label: 'Soort', type: 'choice', options: ['particulier', 'zakelijk'] },
]

test.describe('Form fill route', () => {
	let token = ''
	let available = false

	test.beforeEach(async ({ page }) => {
		token = await harvestToken(page)
		const answer = await page.request.get(`${FILL}/available`, { headers: jsonHeaders(token) })
		expect(answer.status(), await answer.text()).toBe(200)
		const body = await answer.json()
		expect(typeof body.available).toBe('boolean')
		available = body.available === true
	})

	test('a built app hides Paste to fill while the feature is off', async ({ page }) => {
		test.skip(available, 'form-fill is switched on in this instance; the off state is pinned by FormFillEngineTest')

		const answer = await page.request.post(FILL, {
			headers: jsonHeaders(token),
			data: { text: 'Jansen BV, Kerkstraat 1, 3511 AB Utrecht, 030 123 4567', fields: FIELDS, language: 'nl' },
		})
		expect(answer.status(), await answer.text()).toBe(200)
		const body = await answer.json()
		expect(body.available).toBe(false)
		expect(typeof body.reason).toBe('string')
		expect(body.values ?? {}).toEqual({})
	})

	test('an oversized paste is refused before any model call', async ({ page }) => {
		const answer = await page.request.post(FILL, {
			headers: jsonHeaders(token),
			data: { text: 'a'.repeat(10_001), fields: [FIELDS[0]] },
		})
		expect(answer.status(), await answer.text()).toBe(400)
	})

	test('an email signature fills three fields', async ({ page }) => {
		test.skip(!available, 'form-fill is off in this instance (the fresh-install default); the fill needs an administrator to switch it on')

		const answer = await page.request.post(FILL, {
			headers: jsonHeaders(token),
			data: { text: 'Jansen BV, Kerkstraat 1, 3511 AB Utrecht, 030 123 4567', fields: FIELDS, language: 'nl' },
		})
		expect(answer.status(), await answer.text()).toBe(200)
		const body = await answer.json()
		expect(body.available).toBe(true)
		expect(body.draft).toBe(true)
		const values = body.values ?? {}
		for (const key of Object.keys(values)) {
			expect(FIELDS.map((field) => field.key)).toContain(key)
		}
		expect(Object.keys(values)).toEqual(expect.arrayContaining(['naam', 'adres', 'telefoon']))
		if (values.soort !== undefined) {
			expect(['particulier', 'zakelijk']).toContain(values.soort)
		}
	})
})
