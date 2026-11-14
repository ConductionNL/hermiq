/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Characterisation of the client-side JSON downloads on the compliance pages
 * and the run trace: a pretty-printed application/json blob behind an object
 * URL, a temporary link clicked once and removed again, then the URL revoked,
 * in that order.
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@conduction/nextcloud-vue', () => ({
	CnDataTable: { name: 'CnDataTable', render: () => null },
	createObjectStore: () => () => ({}),
}))
vi.mock('../../src/store/store.js', () => ({}))

const ComplianceDashboard = (await import('../../src/views/ComplianceDashboard.vue')).default
const ComplianceOperations = (await import('../../src/widgets/ComplianceOperations.vue')).default
const AgentRunHistoryWidget = (await import('../../src/widgets/AgentRunHistoryWidget.vue')).default

let events
let blobs

beforeEach(() => {
	events = []
	blobs = []
	vi.spyOn(URL, 'createObjectURL').mockImplementation((blob) => {
		blobs.push(blob)
		events.push('create')
		return 'blob:hermiq/1'
	})
	vi.spyOn(URL, 'revokeObjectURL').mockImplementation((url) => events.push(`revoke ${url}`))
	vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function() {
		events.push(`click ${this.getAttribute('href')} ${this.download} attached=${document.body.contains(this)}`)
	})
	const append = document.body.appendChild.bind(document.body)
	const remove = document.body.removeChild.bind(document.body)
	vi.spyOn(document.body, 'appendChild').mockImplementation((node) => {
		events.push(`append ${node.tagName}`)
		return append(node)
	})
	vi.spyOn(document.body, 'removeChild').mockImplementation((node) => {
		events.push(`remove ${node.tagName}`)
		return remove(node)
	})
})

afterEach(() => {
	vi.restoreAllMocks()
})

const data = { pack: 'x', items: [1, { a: null }] }

describe.each([
	['ComplianceDashboard', ComplianceDashboard],
	['ComplianceOperations', ComplianceOperations],
])('%s downloadJson', (name, component) => {
	it('downloads the payload as pretty JSON under the given name', async () => {
		component.methods.downloadJson.call({}, data, 'pack.json')
		expect(events).toEqual([
			'create',
			'append A',
			'click blob:hermiq/1 pack.json attached=true',
			'remove A',
			'revoke blob:hermiq/1',
		])
		expect(blobs[0].type).toBe('application/json')
		expect(await blobs[0].text()).toBe(JSON.stringify(data, null, 2))
		expect(document.body.querySelector('a')).toBeNull()
	})
})

describe('AgentRunHistoryWidget downloadTrace', () => {
	it('downloads the expanded trace as run-trace-<id>.json', async () => {
		AgentRunHistoryWidget.methods.downloadTrace.call({ runTraces: { 7: data } }, { id: 7 })
		expect(events).toEqual([
			'create',
			'append A',
			'click blob:hermiq/1 run-trace-7.json attached=true',
			'remove A',
			'revoke blob:hermiq/1',
		])
		expect(blobs[0].type).toBe('application/json')
		expect(await blobs[0].text()).toBe(JSON.stringify(data, null, 2))
	})

	it('does nothing while no trace is loaded for the run', () => {
		AgentRunHistoryWidget.methods.downloadTrace.call({ runTraces: {} }, { id: 7 })
		expect(events).toEqual([])
	})
})
