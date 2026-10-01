#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// record-summary.spec.js: when the agent leaf shows the record summary and its
// button, and how the AI label reads its date (agents-bound-to-their-app).
//
// Usage:
//   node tests/record-summary.spec.js
//
// @spec openspec/changes/agents-bound-to-their-app/specs/agent-object-leaf/spec.md#requirement-a-record-page-can-show-an-ai-written-summary-of-the-record-req-appag-004

'use strict'

const assert = require('assert')
const path = require('path')
const { pathToFileURL } = require('url')

const ROOT = path.resolve(__dirname, '..')

;(async () => {
	const { showsSummarySection, offersSummaryButton, summaryDate, summaryRefusal } =
		await import(
			pathToFileURL(path.join(ROOT, 'src/utils/recordSummary.js')).href
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

	const agent = { id: 'a1', name: 'Subsidy desk helper' }
	const stored = { summary: 'Short.', generatedAt: '2026-10-01T08:00:00+00:00' }

	check('no status (an unreadable record) shows nothing', () => {
		assert.strictEqual(showsSummarySection(null), false)
		assert.strictEqual(offersSummaryButton(null), false)
	})

	check('a switched-off feature shows nothing, even with a stored summary', () => {
		const status = { enabled: false, agent: null, summary: stored }
		assert.strictEqual(showsSummarySection(status), false)
		assert.strictEqual(offersSummaryButton(status), false)
	})

	check(
		'no agent for the app and nothing stored hides the button and the section',
		() => {
			const status = { enabled: true, agent: null, summary: null }
			assert.strictEqual(showsSummarySection(status), false)
			assert.strictEqual(offersSummaryButton(status), false)
		},
	)

	check('an agent and nothing stored offers the button', () => {
		const status = { enabled: true, agent, summary: null }
		assert.strictEqual(showsSummarySection(status), true)
		assert.strictEqual(offersSummaryButton(status), true)
	})

	check('a current stored summary is shown without the button', () => {
		const status = { enabled: true, agent, summary: stored }
		assert.strictEqual(showsSummarySection(status), true)
		assert.strictEqual(offersSummaryButton(status), false)
	})

	check('the label date is the day the summary was written', () => {
		assert.strictEqual(
			summaryDate('2026-10-01T08:00:00+00:00', 'en-GB'),
			'1 October 2026',
		)
		assert.strictEqual(summaryDate('', 'en-GB'), '')
		assert.strictEqual(summaryDate('not a date', 'en-GB'), '')
	})

	check('each refusal has its own reason', () => {
		assert.strictEqual(summaryRefusal(404), 'not-found')
		assert.strictEqual(summaryRefusal(403), 'switched-off')
		assert.strictEqual(summaryRefusal(409), 'no-agent')
		assert.strictEqual(summaryRefusal(422), 'blocked')
		assert.strictEqual(summaryRefusal(500), 'failed')
	})

	if (failures.length > 0) {
		console.log(`\n${failures.length} failing`)
		process.exit(1)
	}
	console.log('\nall passing')
})()
