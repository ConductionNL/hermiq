/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The Talk bridge and Talk delivery settings, the skill provenance panel, the
 * eval baseline mode widget and the template import dialog, mounted with
 * their API and stores doubled.
 */

import { flushPromises, shallowMount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const toasts = []
vi.mock('@nextcloud/dialogs', () => ({
	showError: (m) => toasts.push(['error', m]),
	showSuccess: (m) => toasts.push(['success', m]),
}))
vi.mock('../../src/api/talkBridge.js', () => ({ bindTalkRoom: vi.fn(), getTalkBridgeStatus: vi.fn() }))
vi.mock('../../src/api/talk.js', () => ({ createRoom: vi.fn(), getDeliveryTarget: vi.fn(), listRooms: vi.fn(), setDeliveryTarget: vi.fn() }))
vi.mock('../../src/api/agentTemplates.js', () => ({ importAgentTemplate: vi.fn() }))
const skillStore = { registerObjectType: vi.fn(), fetchObject: vi.fn() }
const agentStore = { registerObjectType: vi.fn(), fetchObject: vi.fn(), saveObject: vi.fn() }
vi.mock('../../src/store/store.js', () => ({ useSkillStore: () => skillStore, useAgentStore: () => agentStore }))

const bridge = await import('../../src/api/talkBridge.js')
const talk = await import('../../src/api/talk.js')
const templates = await import('../../src/api/agentTemplates.js')
const { default: TalkBridgeSettings } = await import('../../src/components/settings/TalkBridgeSettings.vue')
const { default: TalkDeliverySettings } = await import('../../src/components/TalkDeliverySettings.vue')
const { default: SkillProvenance } = await import('../../src/widgets/SkillProvenance.vue')
const { default: AgentEvalBaselineWidget } = await import('../../src/widgets/AgentEvalBaselineWidget.vue')
const { default: TemplateImportModal } = await import('../../src/modals/TemplateImportModal.vue')

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
 * Mount a component on the route of object x1 and let it load.
 *
 * @param {object} component The component.
 * @param {object} [props] Its props.
 * @return {Promise<object>} The wrapper.
 */
async function mountIt(component, props = {}) {
	const w = shallowMount(component, { props, global: { mocks: { t, $route: { params: { id: 'x1' } } } } })
	await flushPromises()
	return w
}

beforeEach(() => {
	toasts.length = 0
	vi.clearAllMocks()
})

describe('TalkBridgeSettings', () => {
	it('reads the bridge status on mount and binds a trimmed room to an agent', async () => {
		bridge.getTalkBridgeStatus.mockResolvedValueOnce({ talkAvailable: true, rooms: [] })
		bridge.bindTalkRoom.mockResolvedValueOnce({ talkAvailable: true, rooms: [{ token: 'tok' }] })
		const w = await mountIt(TalkBridgeSettings)
		expect(w.vm.status.talkAvailable).toBe(true)
		expect(w.vm.canBind).toBe(false)
		w.vm.newToken = ' tok '
		w.vm.newAgentId = ' a1 '
		expect(w.vm.canBind).toBe(true)
		await w.vm.bind()
		expect(bridge.bindTalkRoom).toHaveBeenCalledWith('tok', 'a1')
		expect([w.vm.newToken, w.vm.newAgentId, w.vm.status.rooms]).toEqual(['', '', [{ token: 'tok' }]])
	})

	it('unbinds by binding to no agent, and reports each failure', async () => {
		bridge.getTalkBridgeStatus.mockRejectedValueOnce(new Error('x'))
		const w = await mountIt(TalkBridgeSettings)
		expect(w.vm.error).toBe('Could not read the Talk bridge configuration.')
		bridge.bindTalkRoom.mockResolvedValueOnce({ rooms: [] })
		await w.vm.unbind('tok')
		expect(bridge.bindTalkRoom).toHaveBeenCalledWith('tok', '')
		bridge.bindTalkRoom.mockRejectedValueOnce(new Error('x'))
		await w.vm.unbind('tok')
		expect(w.vm.error).toBe('Could not remove that binding.')
		bridge.bindTalkRoom.mockRejectedValueOnce(new Error('x'))
		w.vm.newToken = 'a'
		await w.vm.bind()
		expect([w.vm.error, w.vm.newToken, w.vm.saving]).toEqual(['Could not bind that conversation.', 'a', false])
	})
})

describe('TalkDeliverySettings', () => {
	beforeEach(() => {
		talk.listRooms.mockResolvedValue([{ token: 't1', name: 'Team' }])
	})

	it('selects the stored room, offering note to self first', async () => {
		talk.getDeliveryTarget.mockResolvedValueOnce('t1')
		const w = await mountIt(TalkDeliverySettings)
		expect(w.vm.options.map((o) => o.token)).toEqual(['', 't1'])
		expect(w.vm.selected).toEqual({ token: 't1', name: 'Team' })
		expect(w.vm.savedMessage).toBe('Saved — runs deliver to “Team”.')
	})

	it('falls back to note to self for an unknown target, and saves a choice', async () => {
		talk.getDeliveryTarget.mockResolvedValueOnce('gone')
		const w = await mountIt(TalkDeliverySettings)
		expect(w.vm.selected.token).toBe('')
		expect(w.vm.savedMessage).toBe('Saved — runs deliver to your Note to self.')
		await w.vm.onSelect({ token: 't1', name: 'Team' })
		expect(talk.setDeliveryTarget).toHaveBeenCalledWith('t1')
		expect(w.vm.saved).toBe(true)
		await w.vm.onSelect(null)
		expect(talk.setDeliveryTarget).toHaveBeenLastCalledWith('')
	})

	it('creates a room, selects it and saves it', async () => {
		talk.getDeliveryTarget.mockResolvedValueOnce('')
		talk.createRoom.mockResolvedValueOnce({ token: 'new', name: 'Runs' })
		const w = await mountIt(TalkDeliverySettings)
		w.vm.newName = ' Runs '
		await w.vm.onCreate()
		expect(talk.createRoom).toHaveBeenCalledWith('Runs')
		expect(w.vm.rooms[0]).toEqual({ token: 'new', name: 'Runs' })
		expect([w.vm.selected.token, w.vm.newName, w.vm.creating]).toEqual(['new', '', false])
		expect(talk.setDeliveryTarget).toHaveBeenCalledWith('new')
	})

	it('reports failures to load, create and save', async () => {
		talk.listRooms.mockRejectedValueOnce({ response: { data: { message: 'Talk is off' } } })
		const w = await mountIt(TalkDeliverySettings)
		expect(w.vm.error).toBe('Talk is off')
		talk.createRoom.mockRejectedValueOnce({ response: { data: { ocs: { meta: { message: 'Name taken' } } } } })
		await w.vm.onCreate()
		expect(w.vm.error).toBe('Name taken')
		talk.createRoom.mockRejectedValueOnce({})
		await w.vm.onCreate()
		expect(w.vm.error).toBe('Could not create the room')
		talk.setDeliveryTarget.mockRejectedValueOnce({})
		await w.vm.save('t1')
		expect([w.vm.error, w.vm.saved]).toEqual(['Could not save', false])
		talk.listRooms.mockRejectedValueOnce({})
		await w.vm.load()
		expect(w.vm.error).toBe('Could not load your Talk rooms')
	})
})

describe('SkillProvenance', () => {
	it('shows a quarantined skill with its reason and source', async () => {
		skillStore.fetchObject.mockResolvedValueOnce({ state: 'quarantined', quarantineReason: 'Unsigned', sourceUrl: 'https://hub' })
		const w = await mountIt(SkillProvenance)
		expect(skillStore.fetchObject).toHaveBeenCalledWith('agentskill', 'x1')
		expect([w.vm.isQuarantined, w.vm.quarantineReason, w.vm.sourceUrl, w.vm.stateLabel]).toEqual([true, 'Unsigned', 'https://hub', 'Quarantined — awaiting review'])
	})

	it('flags learnings accepted after the source last synced', async () => {
		skillStore.fetchObject.mockResolvedValueOnce({ state: 'active', lastAcceptedVersionAt: '2026-02-01' })
		const w = await mountIt(SkillProvenance)
		expect([w.vm.stateLabel, w.vm.learningsAheadOfSource]).toEqual(['active', true])
		w.vm.skill = { lastAcceptedVersionAt: '2026-02-01', sourceUpdatedAt: '2026-03-01' }
		expect(w.vm.learningsAheadOfSource).toBe(false)
		w.vm.skill = { lastAcceptedVersionAt: '2026-04-01', sourceUpdatedAt: '2026-03-01' }
		expect(w.vm.learningsAheadOfSource).toBe(true)
		w.vm.skill = null
		expect([w.vm.learningsAheadOfSource, w.vm.stateLabel, w.vm.isQuarantined, w.vm.quarantineReason, w.vm.sourceUrl]).toEqual([false, 'Unknown', false, '', ''])
	})

	it('shows a failed load and formats dates', async () => {
		skillStore.fetchObject.mockRejectedValueOnce({})
		const w = await mountIt(SkillProvenance)
		expect(w.vm.error).toBe('Could not load the skill.')
		expect([w.vm.formatDate(''), w.vm.formatDate('nope')]).toEqual(['—', '—'])
		expect(w.vm.formatDate('2026-01-01T00:00:00Z')).toBe(new Date('2026-01-01T00:00:00Z').toLocaleString())
	})
})

describe('AgentEvalBaselineWidget', () => {
	it('selects the stored mode, joint when unset', async () => {
		agentStore.fetchObject.mockResolvedValueOnce({ id: 'x1' })
		const w = await mountIt(AgentEvalBaselineWidget)
		expect([w.vm.storedMode, w.vm.selectedMode.value, w.vm.isDirty]).toEqual(['joint', 'joint', false])
		expect(w.vm.currentModeSummary).toContain('one shared without-skill half')
		expect(w.vm.modeDescription).toContain("'per-skill'")
		w.vm.selectedMode = w.vm.modeOptions[1]
		expect(w.vm.isDirty).toBe(true)
	})

	it('saves the chosen mode onto the agent and reloads', async () => {
		agentStore.fetchObject.mockResolvedValueOnce({ id: 'x1', name: 'A' }).mockResolvedValueOnce({ id: 'x1', evalBaselineMode: 'per-skill' })
		const w = await mountIt(AgentEvalBaselineWidget)
		w.vm.selectedMode = { value: 'per-skill' }
		await w.vm.save()
		expect(agentStore.saveObject).toHaveBeenCalledWith('agent', { id: 'x1', name: 'A', evalBaselineMode: 'per-skill' })
		expect([w.vm.storedMode, w.vm.currentModeSummary]).toEqual(['per-skill', 'Paired evals run one without-skill half per linked skill — true per-skill deltas at (N+1)x token cost.'])
		expect(toasts).toEqual([['success', 'Eval baseline mode saved.']])
	})

	it('saves nothing without an agent, and reports load and save failures', async () => {
		agentStore.fetchObject.mockRejectedValueOnce({})
		const w = await mountIt(AgentEvalBaselineWidget)
		expect(w.vm.error).toBe('Could not load the agent.')
		await w.vm.save()
		expect(agentStore.saveObject).not.toHaveBeenCalled()
		w.vm.agent = { id: 'x1' }
		w.vm.selectedMode = { value: 'joint' }
		agentStore.saveObject.mockRejectedValueOnce(new Error('x'))
		await w.vm.save()
		expect(toasts).toEqual([['error', 'Could not save the eval baseline mode.']])
		expect(w.vm.saving).toBe(false)
	})
})

describe('TemplateImportModal', () => {
	/**
	 * A file input change event carrying one file with the given text.
	 *
	 * @param {string|null} text The file text, or null for no file.
	 * @return {object} The event.
	 */
	const fileEvent = (text) => ({ target: { files: text === null ? [] : [{ text: async () => text }] } })

	it('reads an exported agent file into the import text', async () => {
		const w = await mountIt(TemplateImportModal, { mode: 'agent' })
		expect(w.vm.agentMode).toBe(true)
		await w.vm.readFile(fileEvent(null))
		expect(w.vm.importText).toBe('')
		await w.vm.readFile(fileEvent('{"name":"Bot"}'))
		expect([w.vm.importText, w.vm.error]).toEqual(['{"name":"Bot"}', ''])
	})

	it('refuses a file without a name, or one that is not an agent', async () => {
		const w = await mountIt(TemplateImportModal)
		expect(w.vm.agentMode).toBe(false)
		await w.vm.readFile(fileEvent('{"name":""}'))
		expect([w.vm.importText, w.vm.error]).toEqual(['', 'This agent file has no name.'])
		await w.vm.readFile(fileEvent('nope'))
		expect(w.vm.error).toBe('This file is not an exported agent.')
	})

	it('imports the trimmed package from the given source, and nothing blank', async () => {
		const w = await mountIt(TemplateImportModal)
		await w.vm.run('org')
		expect(templates.importAgentTemplate).not.toHaveBeenCalled()
		w.vm.importText = ' {"name":"T"} '
		await w.vm.run('hub')
		expect(templates.importAgentTemplate).toHaveBeenCalledWith('{"name":"T"}', 'hub')
		expect(w.emitted('imported')).toHaveLength(1)
		expect(w.emitted('close')).toHaveLength(1)
		templates.importAgentTemplate.mockRejectedValueOnce({})
		await w.vm.run('org')
		expect([w.vm.error, w.vm.busy]).toEqual(['Could not import the template', false])
		expect(w.emitted('imported')).toHaveLength(1)
	})
})
