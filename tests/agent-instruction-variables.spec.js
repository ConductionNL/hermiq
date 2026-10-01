#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// agent-instruction-variables.spec.js: the chat page's start fields (which
// fields to ask, when the first message may go, what the session header
// shows) and the agent form's placeholder menu (agents-instruction-variables).
//
// Usage:
//   node tests/agent-instruction-variables.spec.js
//
// @spec openspec/specs/agent-management-ui/spec.md#requirement-an-agent-can-ask-for-fields-before-a-conversation-starts-req-agvar-002

'use strict'

const assert = require('assert')
const path = require('path')
const { pathToFileURL } = require('url')

const ROOT = path.resolve(__dirname, '..')

;(async () => {
	const {
		PLACEHOLDERS,
		insertPlaceholder,
		missingRequired,
		pendingStartFields,
		startFieldsOf,
		startValueSummary,
		initialAnswers,
	} = await import(
		pathToFileURL(path.join(ROOT, 'src/utils/instructionVariables.js')).href
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

	const agent = {
		startFields: [
			{
				key: 'department',
				label: 'Department',
				type: 'select',
				options: ['Permits', 'Taxes'],
				required: true,
			},
			{ key: 'channel', label: 'Channel', type: 'text', default: 'counter' },
			{ key: 'Bad Key', label: 'Refused', type: 'text' },
		],
	}

	check('only well-formed fields are asked', () => {
		assert.deepStrictEqual(
			startFieldsOf(agent).map((f) => f.key),
			['department', 'channel'],
		)
		assert.deepStrictEqual(startFieldsOf({}), [])
	})

	check('a new session asks for the fields before its first message', () => {
		assert.strictEqual(
			pendingStartFields(agent, { startValues: {} }, 0).length,
			2,
		)
	})

	check('a session with answers or messages asks for nothing', () => {
		assert.deepStrictEqual(
			pendingStartFields(agent, { startValues: { department: 'Permits' } }, 0),
			[],
		)
		assert.deepStrictEqual(pendingStartFields(agent, { startValues: {} }, 3), [])
		assert.deepStrictEqual(pendingStartFields(null, { startValues: {} }, 0), [])
	})

	check('the first message waits for every required field', () => {
		const fields = startFieldsOf(agent)
		assert.deepStrictEqual(missingRequired(fields, {}), ['department'])
		assert.deepStrictEqual(missingRequired(fields, { department: '  ' }), [
			'department',
		])
		assert.deepStrictEqual(
			missingRequired(fields, { department: 'Permits' }),
			[],
		)
	})

	check('the answers start from each field default', () => {
		assert.deepStrictEqual(initialAnswers(startFieldsOf(agent)), {
			department: '',
			channel: 'counter',
		})
	})

	check('the session header shows each answer with its label', () => {
		assert.deepStrictEqual(
			startValueSummary(startFieldsOf(agent), { department: 'Permits' }),
			['Department: Permits'],
		)
		assert.deepStrictEqual(startValueSummary([], { department: 'Permits' }), [
			'department: Permits',
		])
	})

	check('the menu lists every placeholder the engine fills in', () => {
		assert.deepStrictEqual(
			PLACEHOLDERS.map((p) => p.token),
			[
				'{{user.displayName}}',
				'{{user.id}}',
				'{{user.language}}',
				'{{organisation.name}}',
				'{{today}}',
				'{{now}}',
				'{{agent.name}}',
				'{{app.id}}',
			],
		)
	})

	check('a placeholder goes in at the cursor, replacing a selection', () => {
		assert.deepStrictEqual(insertPlaceholder('Hi .', '{{user.id}}', 3, 3), {
			text: 'Hi {{user.id}}.',
			cursor: 14,
		})
		assert.deepStrictEqual(insertPlaceholder('Hi you.', '{{user.id}}', 3, 6), {
			text: 'Hi {{user.id}}.',
			cursor: 14,
		})
		assert.deepStrictEqual(insertPlaceholder('Hi', '{{today}}'), {
			text: 'Hi{{today}}',
			cursor: 11,
		})
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
