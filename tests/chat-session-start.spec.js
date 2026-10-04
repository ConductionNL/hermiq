#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// chat-session-start.spec.js — a failed "Start session" on the chat page says
// why, and keeps saying it on the page (hermiq#1086).
//
// A user who is not an admin clicked "Start session", the backend answered 500,
// and the page looked unchanged. The backend now refuses with a 4xx and a
// reason; this proves the page turns that into a sentence and shows it next to
// the agent list instead of only in a toast.
//
// Usage:
//   node tests/chat-session-start.spec.js
//
// Exit codes:
//   0 — every assertion holds.
//   1 — one or more assertions failed.
//
// @spec openspec/changes/session-frontend-rename/specs/session-surface/spec.md#requirement-starting-a-new-session-must-produce-a-visible-result

'use strict'

const assert = require('assert')
const fs = require('fs')
const path = require('path')
const { pathToFileURL } = require('url')

const ROOT = path.resolve(__dirname, '..')
const read = (...parts) => fs.readFileSync(path.join(ROOT, ...parts), 'utf8')

const failures = []

/**
 * Run one named check and record its failure instead of stopping.
 *
 * @param {string} name What the check proves.
 * @param {function(): Promise<void>|void} fn The assertions.
 */
async function check(name, fn) {
	try {
		await fn()
		console.log(`  ok   ${name}`)
	} catch (e) {
		failures.push(name)
		console.log(
			`  FAIL ${name}\n       ${String(e.message).split('\n').join('\n       ')}`,
		)
	}
}

/**
 * A translate function that interpolates like @nextcloud/l10n does for an
 * untranslated locale, so a check reads the sentence a user reads.
 *
 * @param {string} app The app id.
 * @param {string} text The source string.
 * @param {object} [vars] The placeholders.
 * @return {string} The interpolated string.
 */
function t(app, text, vars = {}) {
	assert.strictEqual(app, 'hermiq')
	return text.replace(/\{(\w+)\}/g, (match, key) =>
		key in vars ? String(vars[key]) : match,
	)
}

/**
 * An axios-shaped error.
 *
 * @param {number} status The HTTP status.
 * @param {object} data The response body.
 * @return {Error} The error.
 */
function httpError(status, data) {
	const error = new Error(`Request failed with status code ${status}`)
	error.response = { status, data }
	return error
}

/**
 * Load the module under test and run every check.
 */
async function main() {
	const { sessionStartErrorMessage } = await import(
		pathToFileURL(path.join(ROOT, 'src', 'utils', 'sessionStartError.js')).href
	)

	await check('a 404 says the agent is missing or not shared', () => {
		assert.strictEqual(
			sessionStartErrorMessage(
				httpError(404, {
					error: 'Agent not found',
					message: 'This agent does not exist or is not shared with you.',
				}),
				t,
			),
			'This agent does not exist or is not shared with you.',
		)
	})

	await check('a 403 says the user may not start this session', () => {
		assert.strictEqual(
			sessionStartErrorMessage(
				httpError(403, {
					error: 'Failed to create conversation',
					message:
						"User 'kq' does not have permission to 'create' objects in schema 'Session'",
				}),
				t,
			),
			'You do not have permission to start a session with this agent.',
		)
	})

	await check('a 400 asks for an agent', () => {
		assert.strictEqual(
			sessionStartErrorMessage(httpError(400, { error: 'Agent required' }), t),
			'Choose an agent to start a session with.',
		)
	})

	await check('any other failure carries the server reason', () => {
		assert.strictEqual(
			sessionStartErrorMessage(
				httpError(500, {
					error: 'Failed to create conversation',
					message: 'Database is gone',
				}),
				t,
			),
			'Could not start the session: Database is gone',
		)
	})

	await check('a failure without a reason still says something', () => {
		assert.strictEqual(
			sessionStartErrorMessage(new Error('Network Error'), t),
			'Could not start the session.',
		)
		assert.strictEqual(
			sessionStartErrorMessage(httpError(502, { message: '  ' }), t),
			'Could not start the session.',
		)
	})

	await check(
		'Chat.vue keeps the reason on the page, next to the agent list',
		() => {
			const chat = read('src', 'views', 'Chat.vue')
			const start = chat.indexOf('async startWithAgent(agent)')
			assert.ok(start > -1, 'startWithAgent() is gone from Chat.vue')
			const body = chat.slice(start, chat.indexOf('\n\t\t},', start))
			assert.match(
				body,
				/this\.startError = ''/,
				'A new attempt must clear the previous reason.',
			)
			assert.match(
				body,
				/this\.startError = sessionStartErrorMessage\(e, this\.t\)/,
				'A failed start must store the reason sessionStartErrorMessage() gives.',
			)
			assert.match(
				chat,
				/<NcNoteCard\s+v-if="startError"\s+type="error"[\s\S]*?\{\{ startError \}\}[\s\S]*?<\/NcNoteCard>\s*<AgentSelector/,
				'The reason must render in an error NcNoteCard right above the AgentSelector.',
			)
			assert.match(
				chat,
				/startError: '',/,
				'startError must be reactive data.',
			)
		},
	)

	if (failures.length > 0) {
		console.log(`\n${failures.length} check(s) failed`)
		process.exit(1)
	}
	console.log('\nall checks passed')
}

main().catch((e) => {
	console.error(e)
	process.exit(1)
})
