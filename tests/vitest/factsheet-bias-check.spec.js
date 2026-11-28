/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The factsheet's bias check line (reuse-scheduled-bias-evals, REQ-BIASEVAL-004).
 *
 * @spec openspec/changes/archive/2026-11-24-reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-the-latest-bias-check-is-visible-as-evidence-req-biaseval-004
 */

import { describe, expect, it, vi } from 'vitest'

vi.mock('../../src/api/compliance.js', () => ({ getAgentFactsheet: vi.fn() }))

const { default: AgentFactsheetDialog } = await import('../../src/dialogs/AgentFactsheetDialog.vue')

/**
 * Translate as the identity, filling {placeholders}.
 *
 * @param {string} app The app id.
 * @param {string} text The text.
 * @param {object} vars The placeholders.
 * @return {string} The filled text.
 */
function t(app, text, vars = {}) {
	return text.replace(/\{(\w+)\}/g, (match, key) => String(vars[key] ?? match))
}

describe('AgentFactsheetDialog bias check', () => {
	it('reads the latest bias check as pass rate on date', () => {
		const label = AgentFactsheetDialog.methods.biasCheckLabel
		expect(label.call({ t }, { passRate: 0.95, date: '2026-09-01' })).toBe('95% on 2026-09-01')
		expect(label.call({ t }, { passRate: 0.834, date: '2026-10-01' })).toBe('83% on 2026-10-01')
	})

	it('does not invent a rate or a date', () => {
		const label = AgentFactsheetDialog.methods.biasCheckLabel
		expect(label.call({ t }, {})).toBe('0% on —')
	})
})
