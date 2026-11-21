#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// agent-draft.spec.js: finding an agent draft in an assistant message and
// turning the checked draft into the agent form and the schedule form
// (agents-plain-language-builder).
//
// Usage:
//   node tests/agent-draft.spec.js
//
// @spec openspec/changes/agents-plain-language-builder/specs/agent-management-ui/spec.md#requirement-a-draft-opens-in-the-full-agent-form-after-a-check-req-agbuild-002

'use strict'

const assert = require('assert')
const path = require('path')
const { pathToFileURL } = require('url')

const ROOT = path.resolve(__dirname, '..')

;(async () => {
	const {
		agentFromDraft,
		draftBlockOf,
		findingsByField,
		scheduleFromDraft,
		sharingChoiceOf,
	} = await import(pathToFileURL(path.join(ROOT, 'src/utils/agentDraft.js')).href)
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

	const draft = {
		name: 'Objections digest',
		description: 'Summarises new objections every Monday.',
		prompt: 'Summarise the objections.',
		provider: 'ollama',
		model: 'qwen2.5',
		tools: ['openregister.searchObjects'],
		sharing: { mode: 'groups', groups: ['legal'] },
		schedule: {
			kind: 'cron',
			cronExpr: '0 8 * * 1',
			prompt: 'Write the digest.',
		},
		startFields: [],
	}

	check('a draft block is found in backtick and tilde fences', () => {
		const json = '{"name": "Objections digest"}'
		assert.strictEqual(
			draftBlockOf(
				`Here is the agent.\n\n\`\`\`hermiq-agent-draft\n${json}\n\`\`\`\nOpen it to check.`,
			),
			json,
		)
		assert.strictEqual(draftBlockOf(`~~~hermiq-agent-draft\n${json}\n~~~`), json)
	})

	check('a message without a draft block offers nothing', () => {
		assert.strictEqual(draftBlockOf('```json\n{"name": "x"}\n```'), '')
		assert.strictEqual(draftBlockOf(''), '')
		assert.strictEqual(draftBlockOf(null), '')
	})

	check('the form opens with every field of the draft and no id', () => {
		const agent = agentFromDraft(draft)
		assert.strictEqual(agent.name, 'Objections digest')
		assert.strictEqual(agent.model, 'qwen2.5')
		assert.deepStrictEqual(agent.tools, ['openregister.searchObjects'])
		assert.deepStrictEqual(agent.groups, ['legal'])
		assert.strictEqual(agent.isPrivate, true)
		assert.strictEqual(agent.id, undefined)
		assert.strictEqual(agent.uuid, undefined)
	})

	check('sharing maps to the form choices, narrowest when unclear', () => {
		assert.deepStrictEqual(sharingChoiceOf({ mode: 'organisation' }), {
			choice: 'organisation',
			groups: [],
		})
		assert.deepStrictEqual(sharingChoiceOf({ mode: 'groups', groups: [] }), {
			choice: 'only-me',
			groups: [],
		})
		assert.deepStrictEqual(sharingChoiceOf(undefined), {
			choice: 'only-me',
			groups: [],
		})
	})

	check('the proposed schedule fills the schedule form', () => {
		assert.deepStrictEqual(scheduleFromDraft(draft), {
			name: 'Objections digest',
			kind: 'cron',
			cronExpr: '0 8 * * 1',
			intervalMinutes: null,
			runAt: '',
			prompt: 'Write the digest.',
		})
		assert.strictEqual(scheduleFromDraft({ ...draft, schedule: null }), null)
	})

	check('findings are grouped by field', () => {
		assert.deepStrictEqual(
			findingsByField([
				{
					field: 'model',
					message: 'Not allowed by your organisation',
					suggestion: 'ollama/qwen2.5',
				},
				{ field: 'tools', message: 'No tool is called a.', suggestion: '' },
				{ field: 'tools', message: 'No tool is called b.', suggestion: '' },
			]),
			{
				model: [
					{
						message: 'Not allowed by your organisation',
						suggestion: 'ollama/qwen2.5',
					},
				],
				tools: [
					{ message: 'No tool is called a.', suggestion: '' },
					{ message: 'No tool is called b.', suggestion: '' },
				],
			},
		)
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
