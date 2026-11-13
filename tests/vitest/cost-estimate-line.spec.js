/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Characterisation of the pre-run cost estimate line of the schedule form and
 * the run operations widget: hidden without an estimate, the trailing average
 * when one is available, and the "not enough history" note otherwise.
 */

import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'

vi.mock('@conduction/nextcloud-vue', () => ({ createObjectStore: () => () => ({}) }))
vi.mock('@nextcloud/event-bus', () => ({ emit: vi.fn(), subscribe: vi.fn(), unsubscribe: vi.fn() }))
vi.mock('../../src/store/store.js', () => ({
	useScheduleStore: () => ({ registerObjectType: () => {}, fetchCollection: async () => [], saveObject: async () => ({}) }),
}))
vi.mock('../../src/api/agents.js', () => ({ dryRunSchedule: vi.fn(), runScheduleNow: vi.fn() }))
vi.mock('../../src/api/budgets.js', () => ({ getBudgetEstimate: async () => null, getBudgetStatus: async () => null }))
vi.mock('../../src/api/webhooks.js', () => ({
	createWebhookSecret: vi.fn(),
	getWebhookStatus: async () => null,
	revokeWebhookSecret: vi.fn(),
	rotateWebhookSecret: vi.fn(),
}))
vi.mock('../../src/api/scheduleWebhookSecret.js', () => ({
	getScheduleWebhookSecretStatus: async () => ({ configured: false }),
	mintScheduleWebhookSecret: vi.fn(),
	revokeScheduleWebhookSecret: vi.fn(),
	rotateScheduleWebhookSecret: vi.fn(),
}))

const { default: ScheduleFormModal } = await import('../../src/modals/ScheduleFormModal.vue')
const { default: AgentRunOperationsWidget } = await import('../../src/widgets/AgentRunOperationsWidget.vue')

/**
 * A t() that fills placeholders, like the real one.
 *
 * @param {string} app The app id.
 * @param {string} text The source text.
 * @param {object} vars The placeholder values.
 * @return {string} The text.
 */
function t(app, text, vars = {}) {
	return text.replace(/\{(\w+)\}/g, (match, key) => (key in vars ? String(vars[key]) : match))
}

describe.each([
	['ScheduleFormModal', ScheduleFormModal, { agentId: 'a-1', show: false }, '.schedule-form__estimate'],
	['AgentRunOperationsWidget', AgentRunOperationsWidget, {}, '.agent-run-ops-widget__estimate'],
])('%s', (name, component, props, selector) => {
	it.each([
		['no estimate', null],
		['an estimate', { available: true, avgTotalTokens: 1234, sampleSize: 5 }],
		['too little history', { available: false }],
	])('shows the line for %s', async (label, estimate) => {
		const wrapper = mount(component, {
			props,
			global: { mocks: { t, $route: { params: { id: 'a-1' } } } },
		})
		await flushPromises()
		await wrapper.setData({ estimate })
		expect(wrapper.findAll(selector).map((p) => p.html())).toMatchSnapshot()
	})
})
