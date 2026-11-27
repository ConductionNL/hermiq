/**
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * What the Talk bridge admin panel (src/components/settings/TalkBridgeSettings.vue)
 * shows for each state of the bridge: Talk missing, bot not registered or
 * registered, no bound rooms, and each room's answering status. The note
 * cards render their slot, so the assertions read the text an admin sees.
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('../../src/api/talkBridge.js', () => ({ bindTalkRoom: vi.fn(), getTalkBridgeStatus: vi.fn() }))

const bridge = await import('../../src/api/talkBridge.js')
const { default: TalkBridgeSettings } = await import('../../src/components/settings/TalkBridgeSettings.vue')

const t = (app, text) => text

/**
 * A stub that renders its default slot inside an element naming the stub.
 *
 * @param {string} name The stub name.
 * @return {object} The stub component.
 */
function slotStub (name) {
  return defineComponent({
	name,
	props: { type: { type: String, default: '' } },
	setup(props, { slots }) {
		return () => h('div', { class: name, 'data-type': props.type }, slots.default?.())
	},
})
}

/**
 * Mount the panel over a bridge status and let it load.
 *
 * @param {object} status The status the API answers.
 * @return {Promise<object>} The wrapper.
 */
async function panel(status) {
	bridge.getTalkBridgeStatus.mockResolvedValueOnce(status)
	const w = mount(TalkBridgeSettings, {
		global: {
			mocks: { t },
			stubs: {
				NcNoteCard: slotStub('note'),
				NcButton: slotStub('button'),
				NcTextField: true,
				NcLoadingIcon: slotStub('loading'),
			},
		},
	})
	await flushPromises()
	return w
}

/**
 * The note cards shown, as [type, text] pairs.
 *
 * @param {object} w The wrapper.
 * @return {Array<Array<string>>} The notes.
 */
const notes = (w) => w.findAll('.note').map((note) => [note.attributes('data-type'), note.text()])

beforeEach(() => {
	bridge.getTalkBridgeStatus.mockReset()
})

describe('TalkBridgeSettings states', () => {
	it('shows a loading icon until the status arrives', async () => {
		let resolve
		bridge.getTalkBridgeStatus.mockReturnValueOnce(new Promise((r) => { resolve = r }))
		const w = mount(TalkBridgeSettings, { global: { mocks: { t }, stubs: { NcLoadingIcon: slotStub('loading'), NcNoteCard: true } } })
		expect(w.find('.loading').exists()).toBe(true)
		resolve({ talkAvailable: false, rooms: [] })
		await flushPromises()
		expect(w.find('.loading').exists()).toBe(false)
	})

	it('says plainly that Talk is not installed, and offers no binding form', async () => {
		const w = await panel({ talkAvailable: false, botInstalled: false, rooms: [] })
		expect(notes(w)).toEqual([[
			'warning',
			'Nextcloud Talk is not installed on this instance, so agents cannot be reached from a conversation. Everything else in Hermiq is unaffected.',
		]])
		expect(w.find('.talk-bridge-settings__bind').exists()).toBe(false)
	})

	it('warns when the bot is not registered, and says when no room is bound', async () => {
		const w = await panel({ talkAvailable: true, botInstalled: false, rooms: [] })
		expect(notes(w)).toEqual([
			['warning', 'The Hermiq bot is not registered with Talk. It is normally registered automatically when the app is installed or upgraded.'],
			['info', 'No conversation is bound to an agent yet. Bind one below using its Talk conversation token.'],
		])
		expect(w.find('table').exists()).toBe(false)
		expect(w.find('.talk-bridge-settings__bind').exists()).toBe(true)
	})

	it('lists each bound room with why it does or does not answer', async () => {
		const w = await panel({
			talkAvailable: true,
			botInstalled: true,
			rooms: [
				{ token: 'r1', name: 'Team', agentId: 'a1', agentName: 'Helper', botEnabled: false, agentActive: true },
				{ token: 'r2', name: 'Ops', agentId: 'a2', botEnabled: true, agentActive: false },
				{ token: 'r3', name: 'Desk', agentId: 'a3', agentName: 'Desk bot', botEnabled: true, agentActive: true },
			],
		})

		expect(notes(w)).toEqual([[
			'success',
			'The Hermiq bot is registered. It answers only in conversations where a moderator has enabled it AND an opted-in agent is bound below.',
		]])
		const rows = w.findAll('tbody tr').map((row) => row.findAll('td').slice(0, 3).map((cell) => cell.text()))
		expect(rows).toEqual([
			['Teamr1', 'Helper', 'The bot is not enabled in this conversation, so nothing will answer here.'],
			['Opsr2', 'a2', 'The bound agent is missing or has not opted in to Talk, so nothing will answer here.'],
			['Deskr3', 'Desk bot', 'Answering'],
		])
	})

	it('unbinds the room whose button is clicked', async () => {
		const w = await panel({
			talkAvailable: true,
			botInstalled: true,
			rooms: [{ token: 'r1', name: 'Team', agentId: 'a1', botEnabled: true, agentActive: true }],
		})
		bridge.bindTalkRoom.mockResolvedValueOnce({ talkAvailable: true, botInstalled: true, rooms: [] })

		await w.find('tbody .button').trigger('click')
		await flushPromises()

		expect(bridge.bindTalkRoom).toHaveBeenCalledWith('r1', '')
		expect(w.find('table').exists()).toBe(false)
	})

	it('shows a failed binding as an error card under the form', async () => {
		const w = await panel({ talkAvailable: true, botInstalled: true, rooms: [] })
		bridge.bindTalkRoom.mockRejectedValueOnce(new Error('x'))
		w.vm.newToken = 'tok'
		w.vm.newAgentId = 'a1'
		await w.vm.$nextTick()

		await w.find('.talk-bridge-settings__bind .button').trigger('click')
		await flushPromises()

		expect(notes(w)).toContainEqual(['error', 'Could not bind that conversation.'])
	})
})
