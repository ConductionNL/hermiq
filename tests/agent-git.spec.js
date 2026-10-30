#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// agent-git.spec.js: which "Keep in git" actions the agent page offers, and the
// repository link it shows (agents-export-import-and-git-sync, task 5).
//
// Usage:
//   node tests/agent-git.spec.js
//
// @spec openspec/specs/agent-template-github-store/spec.md#requirement-an-agent-owner-can-keep-an-agent-in-a-git-repository-req-agexp-004

'use strict'

const assert = require('assert')
const path = require('path')
const { pathToFileURL } = require('url')

const ROOT = path.resolve(__dirname, '..')

;(async () => {
	const { gitActions, repoLink } = await import(
		pathToFileURL(path.join(ROOT, 'src/utils/agentGit.js')).href
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

	check('an agent not kept in git offers publish only', () => {
		assert.deepStrictEqual(gitActions({}), ['publish'])
		assert.deepStrictEqual(gitActions({ gitOwner: 'gemeente-x' }), ['publish'])
	})

	check(
		'an agent kept in git offers push and pull, never a second publish',
		() => {
			assert.deepStrictEqual(
				gitActions({
					gitOwner: 'gemeente-x',
					gitRepo: 'complaint-router-agent',
				}),
				['push', 'pull'],
			)
		},
	)

	check('the repository link is built from the stamp only', () => {
		assert.strictEqual(
			repoLink({ gitOwner: 'gemeente-x', gitRepo: 'complaint-router-agent' }),
			'https://github.com/gemeente-x/complaint-router-agent',
		)
		assert.strictEqual(repoLink({}), '')
	})

	if (failures.length > 0) {
		console.log(`\n${failures.length} failing`)
		process.exit(1)
	}
	console.log('\nall passing')
})().catch((e) => {
	console.error(e)
	process.exit(1)
})
