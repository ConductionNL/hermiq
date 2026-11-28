/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The session and agent delete dialogs, the MCP tool list, the schedule webhook
 * secret dialog, the draft reject dialog, the approval preview and the agent
 * factsheet, mounted with their API doubled.
 */

import { flushPromises, shallowMount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const toasts = []
vi.mock('@nextcloud/dialogs', () => ({ showSuccess: (m) => toasts.push(['success', m]) }))
vi.mock('@conduction/nextcloud-vue', () => ({
	CnDataTable: { name: 'CnDataTable', render: () => null },
	CnDeleteDialog: { name: 'CnDeleteDialog', render: () => null },
}))
vi.mock('../../src/api/chat.js', () => ({ deleteSessionPermanent: vi.fn() }))
vi.mock('../../src/api/agents.js', () => ({ listTools: vi.fn() }))
vi.mock('../../src/api/agentAvailability.js', () => ({ getAvailability: vi.fn() }))
vi.mock('../../src/api/compliance.js', () => ({ getAgentFactsheet: vi.fn() }))
vi.mock('../../src/api/scheduleWebhookSecret.js', () => ({
	getScheduleWebhookSecretStatus: vi.fn(),
	mintScheduleWebhookSecret: vi.fn(),
	revokeScheduleWebhookSecret: vi.fn(),
	rotateScheduleWebhookSecret: vi.fn(),
}))
vi.mock('../../src/modals/secretReveal.js', () => ({ copySecret: vi.fn(async () => 'copied'), selectAll: vi.fn() }))

const chat = await import('../../src/api/chat.js')
const agents = await import('../../src/api/agents.js')
const availability = await import('../../src/api/agentAvailability.js')
const compliance = await import('../../src/api/compliance.js')
const secrets = await import('../../src/api/scheduleWebhookSecret.js')
const reveal = await import('../../src/modals/secretReveal.js')
const { default: SessionDeleteModal } = await import('../../src/modals/SessionDeleteModal.vue')
const { default: McpTools } = await import('../../src/views/McpTools.vue')
const { default: AgentDeleteDialog } = await import('../../src/dialogs/AgentDeleteDialog.vue')
const { default: ScheduleWebhookSecretDialog } = await import('../../src/modals/ScheduleWebhookSecretDialog.vue')
const { default: SkillDraftRejectModal } = await import('../../src/modals/SkillDraftRejectModal.vue')
const { default: ApprovalPreviewModal } = await import('../../src/modals/ApprovalPreviewModal.vue')
const { default: AgentFactsheetDialog } = await import('../../src/dialogs/AgentFactsheetDialog.vue')

/**
 * Translate as the identity, filling {placeholders}.
 *
 * @param {string} app The app id.
 * @param {string} text The text.
 * @param {object} vars The placeholders.
 * @return {string} The filled text.
 */
const t = (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m))

/**
 * Mount a component and let it load.
 *
 * @param {object} component The component.
 * @param {object} [props] Its props.
 * @return {Promise<object>} The wrapper.
 */
async function mountIt(component, props = {}) {
	const w = shallowMount(component, { props, global: { mocks: { t, $route: { params: { id: 'a1' } } } } })
	await flushPromises()
	return w
}

beforeEach(() => {
	toasts.length = 0
	vi.clearAllMocks()
})

describe('SessionDeleteModal', () => {
	it('deletes the session for good and reports it', async () => {
		const w = await mountIt(SessionDeleteModal, { show: false, session: { uuid: 's1' } })
		await w.setProps({ show: true })
		await w.vm.confirmDelete()
		expect(chat.deleteSessionPermanent).toHaveBeenCalledWith('s1')
		expect(w.emitted('deleted')).toEqual([[{ uuid: 's1' }]])
		expect(w.emitted('close')).toHaveLength(1)
	})

	it('deletes nothing without a session, and shows a refusal', async () => {
		const w = await mountIt(SessionDeleteModal, { session: null })
		await w.vm.confirmDelete()
		expect(chat.deleteSessionPermanent).not.toHaveBeenCalled()
		await w.setProps({ session: { uuid: 's1' } })
		chat.deleteSessionPermanent.mockRejectedValueOnce({ response: { data: { message: 'Not yours' } } })
		await w.vm.confirmDelete()
		expect(w.vm.error).toBe('Not yours')
		chat.deleteSessionPermanent.mockRejectedValueOnce({})
		await w.vm.confirmDelete()
		expect([w.vm.error, w.vm.deleting]).toEqual(['Unknown error', false])
		await w.setProps({ show: true })
		expect(w.vm.error).toBe('')
	})
})

describe('McpTools', () => {
	it('lists the tools with a name, description and provider, falling back per field', async () => {
		agents.listTools.mockResolvedValueOnce([
			{ id: 'mail.send', name: 'Send mail', description: 'Sends', appId: 'mail' },
			{ id: 'x', provider: 'p' },
			{},
		])
		const w = await mountIt(McpTools)
		expect(w.vm.columns.map((c) => c.key)).toEqual(['name', 'description', 'source'])
		expect(w.vm.rows).toEqual([
			{ id: 'mail.send', name: 'Send mail', description: 'Sends', source: 'mail' },
			{ id: 'x', name: 'x', description: '—', source: 'p' },
			{ id: 'tool-2', name: 'Unnamed tool', description: '—', source: '—' },
		])
	})

	it('shows a failed load', async () => {
		agents.listTools.mockRejectedValueOnce(new Error('Tools down'))
		const w = await mountIt(McpTools)
		expect([w.vm.error, w.vm.loading]).toEqual(['Tools down', false])
	})
})

describe('AgentDeleteDialog', () => {
	it('warns how many schedules go with the agent', async () => {
		availability.getAvailability.mockResolvedValueOnce({ scheduleCount: 1 })
		const confirm = vi.fn()
		const close = vi.fn()
		const w = await mountIt(AgentDeleteDialog, { item: { id: 'a1' }, confirm, close })
		expect(availability.getAvailability).toHaveBeenCalledWith('a1')
		expect(w.vm.warningText).toBe('Delete "{name}"? This cannot be undone. 1 schedule is deleted with this agent.')
		availability.getAvailability.mockResolvedValueOnce({ scheduleCount: 3 })
		await w.setProps({ item: { '@self': { id: 'a2' } } })
		await flushPromises()
		expect(w.vm.warningText).toBe('Delete "{name}"? This cannot be undone. 3 schedules are deleted with this agent.')
		await w.vm.onConfirm('a2')
		expect(confirm).toHaveBeenCalledWith('a2')
		expect(close).toHaveBeenCalled()
	})

	it('warns about the deletion only, without an item or when the count cannot be read', async () => {
		const w = await mountIt(AgentDeleteDialog, { item: null, confirm: vi.fn(), close: vi.fn() })
		expect(availability.getAvailability).not.toHaveBeenCalled()
		expect(w.vm.warningText).toBe('Delete "{name}"? This cannot be undone.')
		availability.getAvailability.mockRejectedValueOnce(new Error('x'))
		await w.setProps({ item: { uuid: 'a3' } })
		await flushPromises()
		expect(w.vm.scheduleCount).toBe(0)
	})
})

describe('ScheduleWebhookSecretDialog', () => {
	/**
	 * Mount the dialog closed, then open it.
	 *
	 * @return {Promise<object>} The wrapper.
	 */
	async function openDialog() {
		const w = shallowMount(ScheduleWebhookSecretDialog, { props: { show: false, scheduleId: 's1' }, global: { mocks: { t } } })
		await w.setProps({ show: true })
		await flushPromises()
		return w
	}

	it('reads the status when opened, unconfigured when it cannot', async () => {
		secrets.getScheduleWebhookSecretStatus.mockRejectedValueOnce(new Error('x'))
		const w = await openDialog()
		expect(secrets.getScheduleWebhookSecretStatus).toHaveBeenCalledWith('s1')
		expect(w.vm.status).toEqual({ configured: false })
	})

	it('mints and rotates a secret, revealing it once, and revokes it', async () => {
		secrets.getScheduleWebhookSecretStatus.mockResolvedValueOnce({ configured: false })
		secrets.mintScheduleWebhookSecret.mockResolvedValueOnce({ secret: 'abc', rotatedAt: 't1' })
		secrets.rotateScheduleWebhookSecret.mockResolvedValueOnce({ secret: 'def', rotatedAt: 't2' })
		secrets.revokeScheduleWebhookSecret.mockResolvedValueOnce({ configured: false })
		const w = await openDialog()
		await w.vm.mint()
		expect([w.vm.revealedSecret, w.vm.status]).toEqual(['abc', { configured: true, rotatedAt: 't1' }])
		expect(await w.vm.copy()).toBe('copied')
		expect(reveal.copySecret).toHaveBeenCalledWith('abc')
		await w.vm.rotate()
		expect(w.vm.revealedSecret).toBe('def')
		await w.vm.revoke()
		expect([w.vm.revealedSecret, w.vm.status]).toEqual(['', { configured: false }])
		expect(toasts).toEqual([['success', 'Webhook secret revoked.']])
	})

	it.each([
		['mint', 'mintScheduleWebhookSecret', 'Could not mint webhook secret'],
		['rotate', 'rotateScheduleWebhookSecret', 'Could not rotate webhook secret'],
		['revoke', 'revokeScheduleWebhookSecret', 'Could not revoke webhook secret'],
	])('%s shows the server reason or its own failure', async (method, call, failure) => {
		secrets.getScheduleWebhookSecretStatus.mockResolvedValueOnce({ configured: true })
		const w = await openDialog()
		secrets[call].mockRejectedValueOnce({ response: { data: { error: 'Locked' } } })
		await w.vm[method]()
		expect(w.vm.error).toBe('Locked')
		secrets[call].mockRejectedValueOnce({})
		await w.vm[method]()
		expect([w.vm.error, w.vm.busy]).toEqual([failure, false])
	})
})

describe('SkillDraftRejectModal', () => {
	it('marks and unmarks learnings to reject, once each', async () => {
		const w = await mountIt(SkillDraftRejectModal, { learningRefs: ['l1', 'l2'] })
		w.vm.toggle('l1', true)
		w.vm.toggle('l1', true)
		w.vm.toggle('l2', true)
		expect(w.vm.marked).toEqual(['l1', 'l2'])
		w.vm.toggle('l1', false)
		expect(w.vm.marked).toEqual(['l2'])
	})
})

describe('ApprovalPreviewModal', () => {
	it('explains why a tool call or a run is held, and lists the tools', async () => {
		const w = await mountIt(ApprovalPreviewModal, { approval: { preview: { kind: 'toolcall', tools: [{ id: 'mail.send' }] } } })
		expect(w.vm.heldBecause).toBe('This tool needs a reviewer before the agent may call it.')
		expect(w.vm.tools).toEqual([{ id: 'mail.send' }])
		await w.setProps({ approval: {} })
		expect([w.vm.heldBecause, w.vm.tools]).toEqual(['This run needs a reviewer before it starts.', []])
	})

	it('labels effects and reach, and pretty-prints arguments', async () => {
		const w = await mountIt(ApprovalPreviewModal, { approval: {} })
		expect(['reads', 'changes', 'sends', 'deletes', 'other'].map(w.vm.effectLabel)).toEqual(['Reads', 'Changes', 'Sends', 'Deletes', 'other'])
		expect(['self', 'user', 'instance', 'external', 'x'].map(w.vm.reachLabel)).toEqual([
			'Only the agent itself', 'Your own files and data', 'Everyone on this Nextcloud', 'Outside this Nextcloud', 'Reach unknown',
		])
		expect(w.vm.formatArguments({ to: 'a' })).toBe('{\n  "to": "a"\n}')
	})
})

describe('AgentFactsheetDialog', () => {
	it('loads the factsheet of the routed agent when shown', async () => {
		compliance.getAgentFactsheet.mockResolvedValueOnce({ name: 'Bot' })
		const w = await mountIt(AgentFactsheetDialog, { show: true })
		expect(compliance.getAgentFactsheet).toHaveBeenCalledWith('a1')
		expect(w.vm.factsheet).toEqual({ name: 'Bot' })
	})

	it('tells a missing factsheet from a failed read', async () => {
		compliance.getAgentFactsheet.mockRejectedValueOnce({ response: { status: 404 } })
		const w = await mountIt(AgentFactsheetDialog, { show: true, agentId: 'a2' })
		expect([w.vm.notAvailable, w.vm.error]).toEqual([true, ''])
		compliance.getAgentFactsheet.mockRejectedValueOnce({ response: { status: 500 } })
		await w.vm.load()
		expect([w.vm.notAvailable, w.vm.error, w.vm.factsheet]).toEqual([false, 'Unknown error', null])
		expect([w.vm.formatDate(''), w.vm.formatDate('nope')]).toEqual(['—', '—'])
	})

	it('reads nothing without an agent', async () => {
		const w = shallowMount(AgentFactsheetDialog, { props: { show: true }, global: { mocks: { t, $route: { params: {} } } } })
		await flushPromises()
		expect(compliance.getAgentFactsheet).not.toHaveBeenCalled()
		expect(w.vm.factsheet).toBeNull()
	})
})
