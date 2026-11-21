#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// agent-app.spec.js: the app choices of the agent form and the value it stores
// in applicationSlug (agents-bound-to-their-app).
//
// Usage:
//   node tests/agent-app.spec.js
//
// @spec openspec/specs/agent-management-ui/spec.md#requirement-an-agent-owner-ties-an-agent-to-the-app-it-serves-req-appag-001

'use strict'

const assert = require('assert')
const path = require('path')
const { pathToFileURL } = require('url')

const ROOT = path.resolve(__dirname, '..')

;(async () => {
	const { answersInItsApp, appOptions, appSlugOf } = await import(
		pathToFileURL(path.join(ROOT, 'src/utils/agentApp.js')).href
	)
	const failures = []
	const check = (name, fn) => {
		try {
			fn()
			console.log(`ok - ${name}`)
		} catch (e) {
			failures.push(name)
			console.log(`not ok - ${name}\n  ${e.message}`)
		}
	}

	check('the installed apps are offered, sorted, without core', () => {
		assert.deepStrictEqual(
			appOptions({ pipelinq: '/apps', core: '/core', decidiq: '/apps' }, ''),
			[
				{ label: 'decidiq', value: 'decidiq' },
				{ label: 'pipelinq', value: 'pipelinq' },
			],
		)
	})

	check('a built app the instance does not list stays choosable', () => {
		assert.deepStrictEqual(appOptions({ pipelinq: '/apps' }, 'subsidies'), [
			{ label: 'pipelinq', value: 'pipelinq' },
			{ label: 'subsidies', value: 'subsidies' },
		])
	})

	check('no web roots is an empty list, not an error', () => {
		assert.deepStrictEqual(appOptions(undefined, ''), [])
	})

	check('the stored value is the slug, trimmed and lower case', () => {
		assert.strictEqual(
			appSlugOf({ label: 'Subsidies ', value: 'Subsidies ' }),
			'subsidies',
		)
		assert.strictEqual(appSlugOf('pipelinq'), 'pipelinq')
		assert.strictEqual(appSlugOf(null), '')
	})

	check(
		'the agent answers in its app only while it serves the app it was chosen for',
		() => {
			assert.strictEqual(
				answersInItsApp({
					applicationSlug: 'subsidies',
					appAssistantFor: 'subsidies',
				}),
				true,
			)
			assert.strictEqual(
				answersInItsApp({
					applicationSlug: 'pipelinq',
					appAssistantFor: 'subsidies',
				}),
				false,
			)
			assert.strictEqual(
				answersInItsApp({ applicationSlug: '', appAssistantFor: '' }),
				false,
			)
		},
	)

	if (failures.length > 0) {
		process.exit(1)
	}
})()
