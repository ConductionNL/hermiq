/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Golden: the evidence line of each maturity level on the skill scorecard.
 * Dates pass through a marker, so the line does not depend on the time zone.
 */

import { describe, expect, it, vi } from 'vitest'

vi.mock('../../src/store/store.js', () => ({ useSkillStore: () => ({}) }))

const { default: SkillMaturityScorecard } =
	await import('../../src/widgets/SkillMaturityScorecard.vue')

const evidence = {
	l1: { checkedAt: 'd1' },
	l2: {},
	l3: { checkedAt: 'd3' },
	l4: { attestedBy: 'alice', attestedAt: 'd4', note: 'Looks fine' },
	l5: { lastValidated: 'd5', passRate: 0.9, baselineDelta: 0 },
	l6: { lastConsolidatedAt: 'd6', learningsCount: 3 },
	l7: { lastExecutedAt: 'd7' },
}

const sparse = {
	l4: { attestedBy: 'bob', attestedAt: 'd4' },
	l5: { lastValidated: 'd5' },
	l6: { lastConsolidatedAt: 'd6' },
}

/**
 * The line for one level, with placeholders written out by the double.
 *
 * @param {number} level The level.
 * @param {object} data The stored evidence.
 * @return {string} The line.
 */
function line(level, data) {
	return SkillMaturityScorecard.methods.evidenceSummary.call(
		{
			t: (app, text, vars = {}) => text + ' ' + JSON.stringify(vars),
			formatDate: (value) => `date:${value}`,
		},
		level,
		data,
	)
}

describe('SkillMaturityScorecard evidence line', () => {
	it('says what was recorded for each level', () => {
		const lines = {}
		for (const level of [0, 1, 2, 3, 4, 5, 6, 7, 8]) {
			lines[level] = [
				line(level, evidence),
				line(level, sparse),
				line(level, {}),
			]
		}

		expect(lines).toEqual({
			0: ['', '', ''],
			1: ['Checked {date} {"date":"date:d1"}', '', ''],
			2: ['', '', ''],
			3: ['Checked {date} {"date":"date:d3"}', '', ''],
			4: [
				'Attested by {user} on {date} {"user":"alice","date":"date:d4"} — Looks fine',
				'Attested by {user} on {date} {"user":"bob","date":"date:d4"}',
				'',
			],
			5: [
				'Pass rate {rate}, baseline delta {delta}, validated {date} {"rate":"0.9","delta":"0","date":"date:d5"}',
				'Pass rate {rate}, baseline delta {delta}, validated {date} {"rate":"—","delta":"—","date":"date:d5"}',
				'',
			],
			6: [
				'{count} learnings, consolidated {date} {"count":"3","date":"date:d6"}',
				'{count} learnings, consolidated {date} {"count":"0","date":"date:d6"}',
				'',
			],
			7: ['Chain executed {date} {"date":"date:d7"}', '', ''],
			8: ['', '', ''],
		})
	})
})
