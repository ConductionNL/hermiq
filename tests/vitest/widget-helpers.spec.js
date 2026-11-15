/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The plain helpers behind the eval-run and run-history widgets: rate and
 * delta labels, dates, the agent picker, the baseline cost note, run lists
 * and step labels.
 */

import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/l10n', () => ({
	t: (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (match, key) => (key in vars ? String(vars[key]) : match)),
}))

const {
	agentBaselineMode,
	agentPickerOptions,
	asList,
	baselineCostNote,
	deltaLabel,
	formatDate,
	latestDatasetRuns,
	loadErrorMessage,
	passRateLabel,
} = await import('../../src/widgets/evalRunLabels.js')
const { stepTypeLabel } = await import('../../src/widgets/runStepLabels.js')

describe('passRateLabel', () => {
	it('shows a rounded percentage', () => {
		expect(passRateLabel(0.876)).toBe('88%')
		expect(passRateLabel(0)).toBe('0%')
		expect(passRateLabel(1)).toBe('100%')
	})

	it('shows a dash without a number', () => {
		expect(passRateLabel(null)).toBe('—')
		expect(passRateLabel('0.5')).toBe('—')
	})
})

describe('deltaLabel', () => {
	it('signs the rounded percentage points', () => {
		expect(deltaLabel(0.124)).toBe('+12 pp')
		expect(deltaLabel(-0.05)).toBe('−5 pp')
	})

	it('shows a delta that rounds to zero as ±0', () => {
		expect(deltaLabel(0)).toBe('±0 pp')
		expect(deltaLabel(0.004)).toBe('±0 pp')
		expect(deltaLabel(-0.004)).toBe('±0 pp')
	})

	it('shows a dash without a number', () => {
		expect(deltaLabel(undefined)).toBe('—')
	})
})

describe('formatDate', () => {
	it('formats a valid date in the local format', () => {
		const value = '2026-03-01T10:00:00Z'

		expect(formatDate(value)).toBe(new Date(value).toLocaleString())
	})

	it('keeps an unparseable value and dashes an empty one', () => {
		expect(formatDate('soon')).toBe('soon')
		expect(formatDate('')).toBe('—')
		expect(formatDate(null)).toBe('—')
	})
})

describe('agent picker', () => {
	const agents = [
		{ uuid: 'u-1', id: 1, name: 'Writer', evalBaselineMode: 'per-skill' },
		{ id: 2 },
		{ uuid: 'u-3', evalBaselineMode: 'joint' },
	]

	it('labels by name, else uuid, else id, and values by uuid, else id', () => {
		expect(agentPickerOptions(agents)).toEqual([
			{ label: 'Writer', value: 'u-1' },
			{ label: 2, value: 2 },
			{ label: 'u-3', value: 'u-3' },
		])
	})

	it('reads the selected agent baseline mode, defaulting to joint', () => {
		expect(agentBaselineMode(agents, 'u-1')).toBe('per-skill')
		expect(agentBaselineMode(agents, 2)).toBe('joint')
		expect(agentBaselineMode(agents, 'u-3')).toBe('joint')
		expect(agentBaselineMode(agents, 'missing')).toBe('joint')
	})
})

describe('baselineCostNote', () => {
	it('names the per-skill multiplier', () => {
		const note = baselineCostNote('per-skill', 4)

		expect(note).toContain('every case runs 4 times')
		expect(note).toContain('about 4x the token cost')
	})

	it('describes the joint baseline otherwise', () => {
		expect(baselineCostNote('joint', 4)).toMatch(/^Joint baseline: every case runs twice/)
	})
})

describe('loadErrorMessage', () => {
	it('prefers the server error, then the message, then the fallback', () => {
		expect(loadErrorMessage({ response: { data: { error: 'Denied' } }, message: 'x' }, 'f')).toBe('Denied')
		expect(loadErrorMessage({ message: 'Network' }, 'f')).toBe('Network')
		expect(loadErrorMessage(null, 'Could not load')).toBe('Could not load')
	})
})

describe('asList', () => {
	it('keeps arrays and turns everything else into an empty list', () => {
		const list = [1]

		expect(asList(list)).toBe(list)
		expect(asList(null)).toEqual([])
		expect(asList({ length: 1 })).toEqual([])
	})
})

describe('latestDatasetRuns', () => {
	it('keeps the dataset runs, newest first, at most ten', () => {
		const runs = [
			...Array.from({ length: 12 }, (_, i) => ({ datasetId: 'd', startedAt: `2026-01-${String(i + 1).padStart(2, '0')}` })),
			{ datasetId: 'other', startedAt: '2027-01-01' },
			{ datasetId: 'd' },
		]

		const latest = latestDatasetRuns(runs, 'd')

		expect(latest).toHaveLength(10)
		expect(latest[0].startedAt).toBe('2026-01-12')
		expect(latest[9].startedAt).toBe('2026-01-03')
		expect(latest.every((run) => run.datasetId === 'd')).toBe(true)
	})

	it('does not reorder the input', () => {
		const runs = [{ datasetId: 'd', startedAt: '1' }, { datasetId: 'd', startedAt: '2' }]

		latestDatasetRuns(runs, 'd')

		expect(runs[0].startedAt).toBe('1')
	})
})

describe('stepTypeLabel', () => {
	it('labels the known step types', () => {
		expect(stepTypeLabel('gate_wait')).toBe('Awaiting approval')
		expect(stepTypeLabel('llm')).toBe('LLM')
		expect(stepTypeLabel('delivery')).toBe('Delivery')
	})

	it('shows an unknown type as is, and a missing one as a dash', () => {
		expect(stepTypeLabel('custom')).toBe('custom')
		expect(stepTypeLabel('')).toBe('—')
		expect(stepTypeLabel(undefined)).toBe('—')
	})
})
