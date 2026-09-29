#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// record-chat-prompts.spec.js — the record chat offers the prompt library for
// its record type (record-chat-ready-made-prompts): the scoped read, the order
// kept, a failure offering nothing, and picking a prompt filling the message
// box without sending. Plus the wiring in CnAgentChatTab.vue.
//
// Usage:
//   node tests/record-chat-prompts.spec.js
//
// Exit codes:
//   0 — every assertion holds.
//   1 — one or more assertions failed.
//
// @spec openspec/changes/record-chat-ready-made-prompts/specs/ai-feature-admin-surface/spec.md#requirement-the-record-chat-offers-the-ready-made-prompts-for-its-record-type-req-rcprompt-001

'use strict'

const assert = require('assert')
const fs = require('fs')
const path = require('path')
const { pathToFileURL } = require('url')

const ROOT = path.resolve(__dirname, '..')
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
 * Load the module under test and run every check.
 */
async function main() {
	globalThis.window ??= globalThis
	globalThis.OC ??= { webroot: '', config: { modRewriteWorking: true } }
	globalThis._oc_webroot ??= ''
	const mod = await import(
		pathToFileURL(path.join(ROOT, 'src', 'utils', 'readyMadePrompts.js')).href
	)

	await check(
		'a person picks a ready-made prompt on a record: the scoped read, order kept, text unchanged',
		async () => {
			const calls = []
			const fetchImpl = async (url, init) => {
				calls.push({ url, init })
				return {
					ok: true,
					json: async () => ({
						results: [
							{
								id: 'p2',
								label: 'Summarise this case',
								prompt: 'Summarise this case in five lines.',
							},
							{
								id: 'p1',
								label: 'List open tasks',
								prompt: 'Which tasks on this case are open?',
							},
							{ id: 'p3', label: 'Empty', prompt: '' },
						],
					}),
				}
			}
			const prompts = await mod.loadReadyMadePrompts('case', fetchImpl)
			assert.strictEqual(calls.length, 1)
			assert.match(
				calls[0].url,
				/\/apps\/hermiq\/api\/assistant-prompts\?scope=case$/,
			)
			assert.strictEqual(calls[0].init.method, 'GET')
			assert.deepStrictEqual(
				prompts.map((p) => p.id),
				['p2', 'p1'],
				'the server order is kept and an empty prompt is dropped',
			)
			assert.strictEqual(
				prompts[0].prompt,
				'Summarise this case in five lines.',
			)
			assert.strictEqual(
				mod.withPrompt('', prompts[0].prompt),
				'Summarise this case in five lines.',
			)
			assert.strictEqual(
				mod.withPrompt('About the deadline.', 'Which tasks are open?'),
				'About the deadline.\nWhich tasks are open?',
			)
		},
	)

	await check(
		'a prompt for another record type is not offered: the scope goes to the server, which filters',
		async () => {
			let url = ''
			await mod.loadReadyMadePrompts('case', async (u) => {
				url = u
				return { ok: true, json: async () => ({ results: [] }) }
			})
			assert.ok(url.endsWith('scope=case'), url)
			assert.deepStrictEqual(
				await mod.loadReadyMadePrompts('', async () => {
					throw new Error('no read without a type')
				}),
				[],
			)
		},
	)

	await check('a failed read offers nothing', async () => {
		assert.deepStrictEqual(
			await mod.loadReadyMadePrompts('case', async () => ({
				ok: false,
				json: async () => ({}),
			})),
			[],
		)
		assert.deepStrictEqual(
			await mod.loadReadyMadePrompts('case', async () => {
				throw new Error('offline')
			}),
			[],
		)
	})

	await check(
		'CnAgentChatTab offers the prompts for its record type and picking fills the draft only',
		() => {
			const tab = fs.readFileSync(
				path.join(
					ROOT,
					'src',
					'components',
					'CnAgentChatTab',
					'CnAgentChatTab.vue',
				),
				'utf8',
			)
			assert.ok(
				/loadReadyMadePrompts\(\s*this\.objectType \|\| this\.schema/.test(
					tab,
				),
				'the read is keyed to the record type',
			)
			assert.ok(
				/v-for="prompt in readyMadePrompts"/.test(tab),
				'one button per prompt',
			)
			const pick = tab.match(/pickPrompt\(prompt\) \{([\s\S]*?)\n\t\t\},/)
			assert.ok(pick, 'a pickPrompt method exists')
			assert.ok(
				/withPrompt\(this\.draft, prompt\.prompt\)/.test(pick[1]),
				'the prompt text goes into the draft',
			)
			assert.ok(!/this\.send\(/.test(pick[1]), 'picking never sends')
		},
	)

	if (failures.length > 0) {
		console.log(`\n${failures.length} check(s) failed`)
		process.exit(1)
	}
	console.log('\nall checks passed')
}

main().catch((e) => {
	console.error(e.message)
	process.exit(1)
})
