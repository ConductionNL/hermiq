#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// llm-credentials.spec.js — the AI provider dialog offers organisation credentials
// (claude-provider-for-every-member).
//
// Usage:
//   node tests/llm-credentials.spec.js
//
// Exit codes:
//   0 — every assertion holds.
//   1 — one or more assertions failed.
//
// @spec openspec/changes/claude-provider-for-every-member/specs/claude-provider-for-every-member/spec.md#requirement-the-ai-provider-dialog-offers-organisation-credentials

'use strict'

const assert = require('assert')
const fs = require('fs')
const path = require('path')
const { pathToFileURL } = require('url')

const ROOT = path.resolve(__dirname, '..')

const failures = []
async function check(name, fn) {
	try {
		await fn()
		console.log(`ok - ${name}`)
	} catch (e) {
		failures.push(name)
		console.log(`not ok - ${name}\n  ${e.message}`)
	}
}

const PERSONAL = [{ id: 'p1', name: 'My own key', provider: 'anthropic' }]
const ORGANISATION = [
	{
		id: 'o1',
		name: 'Claude for the municipality',
		provider: 'anthropic',
		scope: 'organisation',
	},
]

function fakeGet(responses) {
	const asked = []
	const get = async (url) => {
		asked.push(url)
		const answer = responses[url]
		if (answer instanceof Error) {
			throw answer
		}
		return { data: { results: answer || [] } }
	}
	return { get, asked }
}

const urlFor = (p) => `/index.php${p}`

async function main() {
	const lib = await import(
		pathToFileURL(path.join(ROOT, 'src', 'utils', 'llmCredentials.js'))
	)

	await check(
		'the loader asks for personal AND organisation credentials',
		async () => {
			const { get, asked } = fakeGet({
				'/index.php/apps/openregister/api/credentials': PERSONAL,
				'/index.php/apps/openregister/api/credentials?scope=organisation':
					ORGANISATION,
			})
			const list = await lib.loadLlmCredentials(get, urlFor)
			assert.ok(
				asked.includes(
					'/index.php/apps/openregister/api/credentials?scope=organisation',
				),
				'the organisation list was never requested',
			)
			assert.deepStrictEqual(
				list.map((c) => [c.id, c.scope]),
				[
					['p1', 'personal'],
					['o1', 'organisation'],
				],
			)
		},
	)

	await check(
		'with organisations listed, each one is asked for and named',
		async () => {
			const { get, asked } = fakeGet({
				'/index.php/apps/openregister/api/credentials': PERSONAL,
				'/index.php/apps/openregister/api/credentials/organisations': [
					{ uuid: 'org-a', name: 'Municipality A', active: true },
					{ uuid: 'org-b', name: 'Municipality B', active: false },
				],
				'/index.php/apps/openregister/api/credentials?scope=organisation&organisation=org-a':
					ORGANISATION,
				'/index.php/apps/openregister/api/credentials?scope=organisation&organisation=org-b':
					[
						{
							id: 'o2',
							name: 'B key',
							provider: 'anthropic',
							scope: 'organisation',
						},
					],
			})
			const list = await lib.loadLlmCredentials(get, urlFor)
			assert.ok(
				asked.includes(
					'/index.php/apps/openregister/api/credentials?scope=organisation&organisation=org-b',
				),
				'the second organisation was never asked for',
			)
			assert.deepStrictEqual(
				list.map((c) => [c.id, c.scope, c.organisationName]),
				[
					['p1', 'personal', undefined],
					['o1', 'organisation', 'Municipality A'],
					['o2', 'organisation', 'Municipality B'],
				],
			)
		},
	)

	await check('the picker defaults to the active organisation', async () => {
		const orgLib = await import(
			pathToFileURL(
				path.join(ROOT, 'src', 'utils', 'organisationCredentials.js'),
			)
		)
		const options = orgLib.organisationOptions([
			{ uuid: 'org-a', name: 'Municipality A', active: false },
			{ uuid: 'org-b', name: 'Municipality B', active: true },
			{ name: 'no uuid' },
		])
		assert.deepStrictEqual(
			options.map((o) => o.value),
			['org-a', 'org-b'],
		)
		assert.strictEqual(orgLib.defaultOrganisation(options).value, 'org-b')
		assert.strictEqual(orgLib.defaultOrganisation([]), null)
	})

	await check(
		'the organisation form sends the chosen organisation and the settings page uses it',
		async () => {
			const scoped = fs.readFileSync(
				path.join(
					ROOT,
					'src',
					'components',
					'settings',
					'OrganisationScopedCredentials.js',
				),
				'utf8',
			)
			assert.ok(
				scoped.includes('extends: CnCredentials'),
				'does not extend CnCredentials',
			)
			assert.ok(
				/scope: 'organisation',\s*organisation: this\.organisation/.test(
					scoped,
				),
				'the list does not carry the chosen organisation',
			)
			assert.ok(
				/organisation: this\.organisation,/.test(
					scoped.slice(scoped.indexOf('async onCreate')),
				),
				'the create call does not carry the chosen organisation',
			)
			const admin = fs.readFileSync(
				path.join(ROOT, 'src', 'views', 'AdminRoot.vue'),
				'utf8',
			)
			assert.ok(
				admin.includes('<OrganisationCredentialSettings'),
				'AdminRoot does not mount the picker',
			)
			assert.ok(
				!/<CnCredentials\s+scope="organisation"/.test(admin),
				'AdminRoot still mounts the unscoped form',
			)
		},
	)

	await check(
		'a failing organisation list still shows the personal one',
		async () => {
			const { get } = fakeGet({
				'/index.php/apps/openregister/api/credentials': PERSONAL,
				'/index.php/apps/openregister/api/credentials?scope=organisation':
					new Error('500'),
			})
			const list = await lib.loadLlmCredentials(get, urlFor)
			assert.deepStrictEqual(
				list.map((c) => c.id),
				['p1'],
			)
		},
	)

	await check(
		'a failing personal list still shows the organisation one',
		async () => {
			const { get } = fakeGet({
				'/index.php/apps/openregister/api/credentials': new Error('500'),
				'/index.php/apps/openregister/api/credentials?scope=organisation':
					ORGANISATION,
			})
			const list = await lib.loadLlmCredentials(get, urlFor)
			assert.deepStrictEqual(
				list.map((c) => c.id),
				['o1'],
			)
		},
	)

	await check(
		'an id in both lists is kept once, as the organisation entry',
		async () => {
			const merged = lib.mergeCredentialLists(
				[{ id: 'o1', name: 'dup', provider: 'anthropic' }],
				ORGANISATION,
			)
			assert.strictEqual(merged.length, 1)
			assert.strictEqual(merged[0].scope, 'organisation')
		},
	)

	await check(
		'the dialog loads credentials through the shared loader and marks organisation ones',
		async () => {
			const modal = fs.readFileSync(
				path.join(ROOT, 'src', 'modals', 'LlmProviderModal.vue'),
				'utf8',
			)
			assert.ok(
				modal.includes('loadLlmCredentials('),
				'LlmProviderModal does not use loadLlmCredentials()',
			)
			assert.ok(
				modal.includes("'{name} (organisation)'")
					&& modal.includes("'{name} ({organisation})'"),
				'LlmProviderModal does not mark organisation credentials',
			)
			const nl = JSON.parse(
				fs.readFileSync(path.join(ROOT, 'l10n', 'nl.json'), 'utf8'),
			)
			assert.strictEqual(
				nl.translations['{name} (organisation)'],
				'{name} (organisatie)',
			)
		},
	)

	if (failures.length > 0) {
		console.log(`\n${failures.length} failing`)
		process.exit(1)
	}
	console.log('\nall passing')
}

main().catch((e) => {
	console.error(e)
	process.exit(1)
})
