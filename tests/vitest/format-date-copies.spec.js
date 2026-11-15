/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Characterisation of the date labels of the factsheet, the agent version
 * history and the approval inbox: an em dash for an absent date, a localised
 * date otherwise, and for an unparseable value an em dash (factsheet, version
 * history) or the value itself as text (approval inbox).
 */

import { describe, expect, it, vi } from 'vitest'

vi.mock('@conduction/nextcloud-vue', () => ({
	CnDataTable: { name: 'CnDataTable', render: () => null },
	createObjectStore: () => () => ({}),
}))
vi.mock('../../src/store/store.js', () => ({}))
vi.mock('@nextcloud/auth', () => ({ getCurrentUser: () => null }))

const AgentFactsheetDialog = (await import('../../src/dialogs/AgentFactsheetDialog.vue')).default
const AgentVersionHistoryDialog = (await import('../../src/dialogs/agents/AgentVersionHistoryDialog.vue')).default
const ApprovalInbox = (await import('../../src/views/ApprovalInbox.vue')).default

const iso = '2026-03-04T05:06:07Z'
const localised = new Date(iso).toLocaleString()
const values = ['', null, undefined, 0, iso, 1700000000000, 'not a date', { at: 'x' }]

describe.each([
	['AgentFactsheetDialog', AgentFactsheetDialog, ['—', '—', '—', '—', localised, new Date(1700000000000).toLocaleString(), '—', '—']],
	['AgentVersionHistoryDialog', AgentVersionHistoryDialog, ['—', '—', '—', '—', localised, new Date(1700000000000).toLocaleString(), '—', '—']],
	['ApprovalInbox', ApprovalInbox, ['—', '—', '—', '—', localised, new Date(1700000000000).toLocaleString(), 'not a date', '[object Object]']],
])('%s formatDate', (name, component, expected) => {
	it('labels each kind of value', () => {
		expect(values.map((value) => component.methods.formatDate.call({}, value))).toEqual(expected)
	})
})
