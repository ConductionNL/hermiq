/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Characterisation of the date and trace-step labels the run and operations
 * pages show: an em dash for an absent or unparseable date, a localised one
 * otherwise, and a label per trace-step type.
 */

import { describe, expect, it, vi } from 'vitest'

vi.mock('@conduction/nextcloud-vue', () => ({
	CnDataTable: { name: 'CnDataTable', render: () => null },
	createObjectStore: () => () => ({}),
}))
vi.mock('../../src/store/store.js', () => ({}))

const widgets = {
	AgentRunHistoryWidget: (await import('../../src/widgets/AgentRunHistoryWidget.vue')).default,
	AgentRunOperationsWidget: (await import('../../src/widgets/AgentRunOperationsWidget.vue')).default,
	TenantOps: (await import('../../src/views/TenantOps.vue')).default,
	ComplianceOperations: (await import('../../src/widgets/ComplianceOperations.vue')).default,
}

const context = { t: (app, text) => text }

describe.each(Object.entries(widgets))('%s', (name, component) => {
	it('formats a date, or an em dash', () => {
		const format = component.methods.formatDate
		expect(format.call(context, '')).toBe('—')
		expect(format.call(context, null)).toBe('—')
		expect(format.call(context, 'not a date')).toBe('—')
		expect(format.call(context, '2026-03-04T05:06:07Z')).toBe(new Date('2026-03-04T05:06:07Z').toLocaleString())
	})
})

describe.each([['AgentRunHistoryWidget'], ['AgentRunOperationsWidget']])('%s step types', (name) => {
	it('labels each trace step type', () => {
		const label = widgets[name].methods.stepTypeLabel
		expect(['gate_wait', 'context', 'history', 'llm', 'tool', 'delivery', 'custom', '', undefined].map((type) => label.call(context, type)))
			.toEqual(['Awaiting approval', 'Context', 'History', 'LLM', 'Tool', 'Delivery', 'custom', '—', '—'])
	})
})
