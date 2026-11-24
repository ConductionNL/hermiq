/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The anthropic-cli credential Hermiq declares (cli-runner-credential-declaration,
 * agent-credentials), against the instance's OpenRegister broker.
 *
 * WHAT THE UNIT TESTS CANNOT SHOW. AnthropicCliCredentialDeclarationTest pins the
 * declaration in src/credentials.json and, with an OpenRegister checkout beside it,
 * that the identifier resolves to an inject-only catalogue entry. It cannot show that
 * the installed broker offers the provider, that a user can save a credential for it,
 * that the saved metadata never carries the secret back, or that the broker refuses
 * to proxy it. Those need both apps installed, so they are here.
 *
 * The secret is a dummy string (never a real token); the credential carries this
 * run's prefix and is deleted afterwards.
 *
 * Not run when written: it needs a live instance with OpenRegister. Runs in the nightly.
 *
 * @e2e agent-credentials::the-credentials-tab-offers-anthropic-cli-because-hermiq-declares-it
 * @e2e agent-credentials::a-user-can-create-an-anthropic-cli-credential
 * @e2e agent-credentials::the-broker-refuses-to-proxy-an-anthropic-cli-credential
 *
 * @spec openspec/changes/archive/2026-11-24-cli-runner-credential-declaration/specs/agent-credentials/spec.md
 */

import { expect, test } from '@playwright/test'
import { harvestToken, jsonHeaders, TEST_PREFIX } from './_fixtures.ts'

const CREDENTIALS = '/index.php/apps/openregister/api/credentials'
const PROVIDER = 'anthropic-cli'
const DUMMY_SECRET = `${TEST_PREFIX}-dummy-not-a-token`

test.describe.configure({ mode: 'serial' })

test.describe('anthropic-cli: offered, saved without its secret, never proxied', () => {
	let token = ''
	let credentialId = ''

	test.beforeEach(async ({ page }) => {
		token = await harvestToken(page)
	})

	test.afterAll(async ({ browser }) => {
		if (credentialId === '') {
			return
		}
		const page = await browser.newPage()
		const cleanupToken = await harvestToken(page)
		await page.request
			.delete(`${CREDENTIALS}/${credentialId}`, { headers: jsonHeaders(cleanupToken) })
			.catch(() => undefined)
		await page.close()
	})

	test('the broker offers anthropic-cli as a provider a user can add', async ({ page }) => {
		const answer = await page.request.get(`${CREDENTIALS}/providers`, { headers: jsonHeaders(token) })
		expect(answer.status(), await answer.text()).toBe(200)
		const identifiers = ((await answer.json()).results ?? []).map((row: { identifier?: string }) => row.identifier)
		expect(identifiers).toContain(PROVIDER)
	})

	test('a personal anthropic-cli credential allowing hermiq saves, and its secret never comes back', async ({ page }) => {
		const created = await page.request.post(CREDENTIALS, {
			headers: jsonHeaders(token),
			data: {
				name: `${TEST_PREFIX}-claude-cli`,
				provider: PROVIDER,
				secret: DUMMY_SECRET,
				allowedApps: ['hermiq'],
				scope: 'personal',
			},
		})
		const createdText = await created.text()
		expect(created.status(), createdText).toBe(201)
		expect(createdText, 'the create answer never echoes the secret').not.toContain(DUMMY_SECRET)
		const body = JSON.parse(createdText)
		credentialId = String(body.id ?? body.uuid ?? body['@self']?.id ?? '')
		expect(credentialId).not.toEqual('')

		const listed = await page.request.get(CREDENTIALS, { headers: jsonHeaders(token) })
		const listedText = await listed.text()
		expect(listed.status(), listedText).toBe(200)
		expect(listedText).toContain(credentialId)
		expect(listedText, 'no list shows the secret again').not.toContain(DUMMY_SECRET)
	})

	test('the broker refuses to proxy a request with it, for any method', async ({ page }) => {
		test.skip(credentialId === '', 'the credential was not created in the previous test')

		for (const method of ['GET', 'POST']) {
			const proxied = await page.request.post(`${CREDENTIALS}/${credentialId}/request`, {
				headers: jsonHeaders(token),
				data: { method, path: '/v1/messages' },
			})
			const text = await proxied.text()
			expect(proxied.status(), text).toBe(403)
			expect(text).not.toContain(DUMMY_SECRET)
		}
	})
})
