#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// agent-sharing.spec.js: the three sharing choices of the agent form and the
// three stored fields they write (agents-sharing-and-catalog-columns).
//
// Usage:
//   node tests/agent-sharing.spec.js
//
// @spec openspec/changes/agents-sharing-and-catalog-columns/specs/agent-management-ui/spec.md#requirement-an-agent-owner-decides-who-can-use-the-agent-req-agshare-001

'use strict'

const assert = require('assert')
const path = require('path')
const { pathToFileURL } = require('url')

const ROOT = path.resolve(__dirname, '..')

;(async () => {
	const { sharingOf, sharingFields } = await import(
		pathToFileURL(path.join(ROOT, 'src/utils/agentSharing.js')).href
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

	check(
		'a new or unset agent reads as only me when private, organisation otherwise',
		() => {
			assert.strictEqual(sharingOf({ isPrivate: true }), 'only-me')
			assert.strictEqual(sharingOf({}), 'organisation')
			assert.strictEqual(
				sharingOf({ isPrivate: true, groups: ['planning-desk'] }),
				'people-and-groups',
			)
		},
	)

	check('only me clears the people and the groups', () => {
		assert.deepStrictEqual(
			sharingFields('only-me', ['carol'], ['planning-desk']),
			{ isPrivate: true, invitedUsers: [], groups: [] },
		)
	})

	check('people and groups stores both lists, private', () => {
		assert.deepStrictEqual(
			sharingFields('people-and-groups', ['carol'], ['planning-desk']),
			{ isPrivate: true, invitedUsers: ['carol'], groups: ['planning-desk'] },
		)
	})

	check('everyone in the organisation keeps the lists and opens the agent', () => {
		assert.deepStrictEqual(sharingFields('organisation', ['carol'], []), {
			isPrivate: false,
			invitedUsers: ['carol'],
			groups: [],
		})
	})

	if (failures.length > 0) {
		process.exit(1)
	}
})()
