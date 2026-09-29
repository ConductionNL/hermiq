#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// agent-credentials.spec.js — the agent form's credential pins
// (operations-a-credential-per-agent).
//
// Usage:
//   node tests/agent-credentials.spec.js
//
// Exit codes:
//   0 — every assertion holds.
//   1 — one or more assertions failed.
//
// @spec openspec/specs/agent-credentials/spec.md#requirement-an-agent-can-carry-its-own-credential-per-provider-req-agcred-001

'use strict'

const assert = require('assert')
const fs = require('fs')
const path = require('path')
const { pathToFileURL } = require('url')

const ROOT = path.resolve(__dirname, '..')

const failures = []
function check(name, fn) {
	try {
		fn()
		console.log(`ok - ${name}`)
	} catch (e) {
		failures.push(name)
		console.log(`not ok - ${name}\n  ${e.message}`)
	}
}

async function main() {
	const lib = await import(
		pathToFileURL(path.join(ROOT, 'src', 'utils', 'agentCredentials.js'))
	)

	check("only the provider's credentials allowed for hermiq are offered", () => {
		const credentials = [
			{
				id: 'c1',
				name: 'Digest OpenAI key',
				provider: 'openai',
				allowedApps: ['hermiq'],
			},
			{
				id: 'c2',
				name: 'Filinq key',
				provider: 'openai',
				allowedApps: ['filinq'],
			},
			{ id: 'c3', name: 'Fireworks key', provider: 'fireworks' },
		]
		assert.deepStrictEqual(lib.credentialOptions(credentials, 'openai'), [
			{ label: 'Digest OpenAI key', value: 'c1' },
		])
		assert.deepStrictEqual(lib.credentialOptions(credentials, 'fireworks'), [
			{ label: 'Fireworks key', value: 'c3' },
		])
	})

	check(
		'an owner gives an agent its own key, and clearing it removes the pin',
		() => {
			const pinned = lib.setPin({}, 'openai', 'c1')
			assert.deepStrictEqual(pinned, { openai: 'c1' })
			assert.deepStrictEqual(lib.setPin(pinned, 'openai', null), {})
			assert.deepStrictEqual(lib.setPin(null, 'fireworks', 'c3'), {
				fireworks: 'c3',
			})
		},
	)

	check(
		'the agent form offers one picker per pinnable provider and saves the map',
		() => {
			const form = fs.readFileSync(
				path.join(ROOT, 'src', 'modals', 'AgentFormModal.vue'),
				'utf8',
			)
			assert.match(form, /v-for="provider in pinnableProviders"/)
			assert.match(form, /credentialOptions\(/)
			assert.match(form, /setPin\(/)
			assert.match(form, /credentialIds: this\.form\.credentialIds/)
			assert.match(form, /\/apps\/openregister\/api\/credentials/)
		},
	)

	if (failures.length) {
		console.log(`\n${failures.length} failed`)
		process.exit(1)
	}
}

main().catch((e) => {
	console.error(e)
	process.exit(1)
})
