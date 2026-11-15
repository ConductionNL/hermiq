/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The admin settings blocks (prompt library, intake threshold, report grouping,
 * run retention, Talk bridge) mounted: what each renders from the loaded values,
 * the error card a failure shows, which controls are disabled when, and what
 * a click sends.
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../../src/api/assistantPrompts.js', () => ({
	disableAllAssistantPrompts: vi.fn(),
	listAssistantPrompts: vi.fn(),
	saveAssistantPrompt: vi.fn(),
}))
vi.mock('../../src/api/intake.js', () => ({ getIntakeSettings: vi.fn(), setIntakeSettings: vi.fn() }))
vi.mock('../../src/api/reportSimilarity.js', () => ({
	getReportSimilaritySettings: vi.fn(),
	setReportSimilaritySettings: vi.fn(),
}))
vi.mock('../../src/api/runRetention.js', () => ({ getRunRetention: vi.fn(), setRunRetention: vi.fn() }))
vi.mock('../../src/api/talkBridge.js', () => ({ bindTalkRoom: vi.fn(), getTalkBridgeStatus: vi.fn() }))

const prompts = await import('../../src/api/assistantPrompts.js')
const intake = await import('../../src/api/intake.js')
const similarity = await import('../../src/api/reportSimilarity.js')
const retention = await import('../../src/api/runRetention.js')
const bridge = await import('../../src/api/talkBridge.js')
const { default: AssistantPromptLibrary } = await import('../../src/components/settings/AssistantPromptLibrary.vue')
const { default: IntakeSettings } = await import('../../src/components/settings/IntakeSettings.vue')
const { default: ReportGroupingSettings } = await import('../../src/components/settings/ReportGroupingSettings.vue')
const { default: RunRetentionSettings } = await import('../../src/components/settings/RunRetentionSettings.vue')
const { default: TalkBridgeSettings } = await import('../../src/components/settings/TalkBridgeSettings.vue')

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
 * Mount a settings block and let its first load settle.
 *
 * @param {object} component The component.
 * @return {Promise<object>} The wrapper.
 */
async function mounted(component) {
	const w = mount(component, { global: { mocks: { t } } })
	await flushPromises()
	return w
}

/**
 * The stand-in elements of one Nextcloud component.
 *
 * @param {object} w The wrapper.
 * @param {string} tag The stand-in tag, e.g. 'nc-button'.
 * @return {Array<object>} The matching DOM wrappers.
 */
const all = (w, tag) => w.findAll(tag)

beforeEach(() => {
	vi.clearAllMocks()
})

describe('IntakeSettings rendered', () => {
	it('shows the loaded threshold in an enabled field and no error', async () => {
		intake.getIntakeSettings.mockResolvedValue({ abstentionThreshold: 0.65 })

		const w = await mounted(IntakeSettings)

		const field = w.get('nc-textfield')
		expect(field.attributes('data-modelvalue')).toBe('0.65')
		expect(field.attributes('data-label')).toBe('File only when this certain')
		expect(field.attributes('data-disabled')).toBe('false')
		expect(w.get('nc-button').attributes('data-disabled')).toBe('false')
		expect(w.find('nc-notecard').exists()).toBe(false)
	})

	it('saves the edited threshold as a number when the button is clicked', async () => {
		intake.getIntakeSettings.mockResolvedValue({ abstentionThreshold: 0.8 })
		intake.setIntakeSettings.mockResolvedValue({ abstentionThreshold: 0.7 })
		const w = await mounted(IntakeSettings)

		w.getComponent({ name: 'NcTextField' }).vm.pick('0.7')
		await w.getComponent({ name: 'NcButton' }).vm.$emit('click')
		await flushPromises()

		expect(intake.setIntakeSettings).toHaveBeenCalledWith(0.7)
		expect(w.get('nc-textfield').attributes('data-modelvalue')).toBe('0.7')
	})

	it('shows the server message in an error card and keeps the controls disabled while loading', async () => {
		let fail
		intake.getIntakeSettings.mockReturnValue(new Promise((resolve, reject) => {
			fail = reject
		}))
		const w = mount(IntakeSettings, { global: { mocks: { t } } })
		await flushPromises()

		expect(w.get('nc-textfield').attributes('data-disabled')).toBe('true')
		expect(w.get('nc-button').attributes('data-disabled')).toBe('true')

		fail({ response: { data: { error: 'Not allowed' } } })
		await flushPromises()

		const card = w.get('nc-notecard')
		expect(card.attributes('data-type')).toBe('error')
		expect(card.attributes('data-heading')).toBe('Intake settings error')
		expect(card.text()).toBe('Not allowed')
		expect(w.get('nc-button').attributes('data-disabled')).toBe('false')
	})
})

describe('ReportGroupingSettings rendered', () => {
	it('shows both thresholds and the comparison window from the server', async () => {
		similarity.getReportSimilaritySettings.mockResolvedValue({ upper: 0.9, lower: 0.5, defaultWindowMinutes: 60 })

		const w = await mounted(ReportGroupingSettings)

		const fields = all(w, 'nc-textfield')
		expect(fields.map((f) => f.attributes('data-modelvalue'))).toEqual(['0.9', '0.5'])
		expect(fields.map((f) => f.attributes('data-label'))).toEqual(['Same event at or above', 'A report of its own below'])
		expect(w.text()).toContain('Reports are only compared against others from the last 60 minutes.')
	})

	it('saves both edited thresholds as numbers', async () => {
		similarity.getReportSimilaritySettings.mockResolvedValue({ upper: 0.85, lower: 0.55, defaultWindowMinutes: 1440 })
		similarity.setReportSimilaritySettings.mockResolvedValue({ upper: 0.8, lower: 0.4 })
		const w = await mounted(ReportGroupingSettings)

		const [upper, lower] = w.findAllComponents({ name: 'NcTextField' })
		upper.vm.pick('0.8')
		lower.vm.pick('0.4')
		await w.getComponent({ name: 'NcButton' }).vm.$emit('click')
		await flushPromises()

		expect(similarity.setReportSimilaritySettings).toHaveBeenCalledWith({ upper: 0.8, lower: 0.4 })
		expect(all(w, 'nc-textfield').map((f) => f.attributes('data-modelvalue'))).toEqual(['0.8', '0.4'])
	})

	it('shows a failed save in an error card', async () => {
		similarity.getReportSimilaritySettings.mockResolvedValue({ upper: 0.85, lower: 0.55, defaultWindowMinutes: 1440 })
		similarity.setReportSimilaritySettings.mockRejectedValue(new Error('Lower must be below upper'))
		const w = await mounted(ReportGroupingSettings)

		await w.getComponent({ name: 'NcButton' }).vm.$emit('click')
		await flushPromises()

		expect(w.get('nc-notecard').attributes('data-heading')).toBe('Grouping settings error')
		expect(w.get('nc-notecard').text()).toBe('Lower must be below upper')
	})
})

describe('RunRetentionSettings rendered', () => {
	const loaded = {
		defaultDays: 90,
		minimumDays: 1,
		maximumDays: 3650,
		lastCleanup: { ran: true, at: '2026-11-01 03:00', removed: 12 },
	}

	it('shows the days with their bounds, the last cleanup, and save disabled until changed', async () => {
		retention.getRunRetention.mockResolvedValue(loaded)

		const w = await mounted(RunRetentionSettings)

		const field = w.get('nc-textfield')
		expect(field.attributes('data-modelvalue')).toBe('90')
		expect(field.attributes('data-min')).toBe('1')
		expect(field.attributes('data-max')).toBe('3650')
		expect(w.get('nc-button').attributes('data-disabled')).toBe('true')
		expect(w.get('.run-retention__report').text()).toBe('Retention last ran on 2026-11-01 03:00 and removed 12 run records.')
	})

	it('says when retention has not run yet', async () => {
		retention.getRunRetention.mockResolvedValue({ ...loaded, lastCleanup: { ran: false, at: '', removed: null } })

		const w = await mounted(RunRetentionSettings)

		expect(w.get('.run-retention__report').text()).toContain('Retention has not run yet on this instance.')
	})

	it('enables save after an edit and stores the new number of days', async () => {
		retention.getRunRetention.mockResolvedValue(loaded)
		retention.setRunRetention.mockResolvedValue({ defaultDays: 30, lastCleanup: { ran: false, at: '', removed: null } })
		const w = await mounted(RunRetentionSettings)

		w.getComponent({ name: 'NcTextField' }).vm.pick('30')
		await flushPromises()
		expect(w.get('nc-button').attributes('data-disabled')).toBe('false')

		await w.getComponent({ name: 'NcButton' }).vm.$emit('click')
		await flushPromises()

		expect(retention.setRunRetention).toHaveBeenCalledWith(30)
		expect(w.get('nc-textfield').attributes('data-modelvalue')).toBe('30')
		expect(w.get('nc-button').attributes('data-disabled')).toBe('true')
		expect(w.get('.run-retention__report').text()).toContain('Retention has not run yet')
	})

	it('shows a failed load in an error card', async () => {
		retention.getRunRetention.mockRejectedValue({})

		const w = await mounted(RunRetentionSettings)

		expect(w.get('nc-notecard').attributes('data-heading')).toBe('Retention error')
		expect(w.get('nc-notecard').text()).toBe('Unknown error')
	})
})

describe('AssistantPromptLibrary rendered', () => {
	it('shows the empty state and a disabled disable-all button without prompts', async () => {
		prompts.listAssistantPrompts.mockResolvedValue([])

		const w = await mounted(AssistantPromptLibrary)

		expect(w.get('nc-emptycontent').attributes('data-name')).toBe('No prompts yet')
		expect(w.find('.prompt-library__list').exists()).toBe(false)
		expect(w.get('.prompt-library__actions nc-button').attributes('data-disabled')).toBe('true')
	})

	it('lists every prompt in full with its scope and an enable or disable action', async () => {
		prompts.listAssistantPrompts.mockResolvedValue([
			{ id: 'p1', label: 'Summarise', usageScope: 'zaak', prompt: 'Summarise this case\nin full.', enabled: true },
			{ id: 'p2', label: 'Translate', usageScope: '', prompt: 'Translate it.', enabled: false },
		])

		const w = await mounted(AssistantPromptLibrary)

		const items = w.findAll('.prompt-library__item')
		expect(items).toHaveLength(2)
		expect(items[0].get('.prompt-library__label').text()).toBe('Summarise')
		expect(items[0].get('.prompt-library__scope').text()).toBe('zaak')
		expect(items[0].get('pre').text()).toBe('Summarise this case\nin full.')
		expect(items[0].get('nc-button').text()).toBe('Disable')
		expect(items[0].classes()).not.toContain('prompt-library__item--disabled')
		expect(items[1].get('.prompt-library__scope').text()).toBe('Everywhere')
		expect(items[1].get('nc-button').text()).toBe('Enable')
		expect(items[1].classes()).toContain('prompt-library__item--disabled')
		expect(w.find('nc-emptycontent').exists()).toBe(false)
	})

	it('toggles a prompt from its row and reloads the list', async () => {
		prompts.listAssistantPrompts.mockResolvedValueOnce([
			{ id: 'p2', label: 'Translate', usageScope: '', prompt: 'Translate it.', enabled: false },
		]).mockResolvedValueOnce([
			{ id: 'p2', label: 'Translate', usageScope: '', prompt: 'Translate it.', enabled: true },
		])
		prompts.saveAssistantPrompt.mockResolvedValue({})
		const w = await mounted(AssistantPromptLibrary)

		await w.findAllComponents({ name: 'NcButton' })[0].vm.$emit('click')
		await flushPromises()

		expect(prompts.saveAssistantPrompt).toHaveBeenCalledWith('p2', { enabled: true })
		expect(prompts.listAssistantPrompts).toHaveBeenCalledTimes(2)
		expect(w.get('.prompt-library__item nc-button').text()).toBe('Disable')
	})

	it('disables every prompt from the action bar and shows a failure', async () => {
		prompts.listAssistantPrompts.mockResolvedValue([
			{ id: 'p1', label: 'Summarise', usageScope: 'zaak', prompt: 'x', enabled: true },
		])
		prompts.disableAllAssistantPrompts.mockRejectedValue({ response: { data: { error: 'Locked' } } })
		const w = await mounted(AssistantPromptLibrary)

		const buttons = w.findAllComponents({ name: 'NcButton' })
		await buttons[buttons.length - 1].vm.$emit('click')
		await flushPromises()

		expect(prompts.disableAllAssistantPrompts).toHaveBeenCalledTimes(1)
		expect(w.get('nc-notecard').attributes('data-heading')).toBe('Prompt library error')
		expect(w.get('nc-notecard').text()).toBe('Locked')
	})
})

describe('TalkBridgeSettings rendered', () => {
	const ready = {
		talkAvailable: true,
		botInstalled: true,
		botUrl: 'https://cloud.test/hermiq/talk',
		groupingEnabled: true,
		handOffPath: 'triggered',
		rooms: [],
	}

	/**
	 * The note cards shown, as [type, text] pairs.
	 *
	 * @param {object} w The wrapper.
	 * @return {Array<Array<string>>} The cards.
	 */
	const cards = (w) => all(w, 'nc-notecard').map((c) => [c.attributes('data-type'), c.text()])

	it('shows only a loading icon until the status arrives', async () => {
		bridge.getTalkBridgeStatus.mockReturnValue(new Promise(() => {}))

		const w = mount(TalkBridgeSettings, { global: { mocks: { t } } })
		await flushPromises()

		expect(w.find('nc-loadingicon').exists()).toBe(true)
		expect(w.find('nc-notecard').exists()).toBe(false)
		expect(w.find('.talk-bridge-settings__facts').exists()).toBe(false)
	})

	it('says Talk is missing and shows nothing else', async () => {
		bridge.getTalkBridgeStatus.mockResolvedValue({ ...ready, talkAvailable: false })

		const w = await mounted(TalkBridgeSettings)

		expect(cards(w)).toHaveLength(1)
		expect(cards(w)[0][0]).toBe('warning')
		expect(cards(w)[0][1]).toContain('Nextcloud Talk is not installed on this instance')
		expect(w.find('.talk-bridge-settings__facts').exists()).toBe(false)
	})

	it('warns about an unregistered bot and describes queued delivery without grouping', async () => {
		bridge.getTalkBridgeStatus.mockResolvedValue({ ...ready, botInstalled: false, groupingEnabled: false, handOffPath: 'queued' })

		const w = await mounted(TalkBridgeSettings)

		expect(cards(w)[0][0]).toBe('warning')
		expect(cards(w)[0][1]).toContain('The Hermiq bot is not registered with Talk.')
		const facts = w.get('.talk-bridge-settings__facts').text()
		expect(facts).toContain('Background jobs — replies arrive on the next background-job run')
		expect(facts).toContain('Unavailable: this version of Talk does not support conversation tags.')
		expect(cards(w)[1]).toEqual(['info', 'No conversation is bound to an agent yet. Bind one below using its Talk conversation token.'])
		expect(w.find('table').exists()).toBe(false)
	})

	it('confirms a registered bot with its address, immediate delivery and grouping', async () => {
		bridge.getTalkBridgeStatus.mockResolvedValue(ready)

		const w = await mounted(TalkBridgeSettings)

		expect(cards(w)[0][0]).toBe('success')
		const facts = w.get('.talk-bridge-settings__facts')
		expect(facts.get('code').text()).toBe('https://cloud.test/hermiq/talk')
		expect(facts.text()).toContain('Immediate — a runner picks each turn up as it arrives.')
		expect(facts.text()).toContain('Agent rooms are filed under a personal “Hermiq” tag for each participant.')
	})

	it('lists each bound room with why it does or does not answer', async () => {
		bridge.getTalkBridgeStatus.mockResolvedValue({
			...ready,
			rooms: [
				{ token: 't1', name: 'Front desk', agentName: 'Clerk', agentId: 'a1', botEnabled: false, agentActive: true },
				{ token: 't2', name: 'Permits', agentName: '', agentId: 'a2', botEnabled: true, agentActive: false },
				{ token: 't3', name: 'Archive', agentName: 'Archivist', agentId: 'a3', botEnabled: true, agentActive: true },
			],
		})

		const w = await mounted(TalkBridgeSettings)

		const rows = w.findAll('tbody tr')
		expect(rows).toHaveLength(3)
		expect(rows[0].findAll('td')[0].text()).toContain('Front desk')
		expect(rows[0].get('code').text()).toBe('t1')
		expect(rows[0].findAll('td')[1].text()).toBe('Clerk')
		expect(rows[0].get('.talk-bridge-settings__warn').text()).toBe('The bot is not enabled in this conversation, so nothing will answer here.')
		expect(rows[1].findAll('td')[1].text()).toBe('a2')
		expect(rows[1].get('.talk-bridge-settings__warn').text()).toBe('The bound agent is missing or has not opted in to Talk, so nothing will answer here.')
		expect(rows[2].find('.talk-bridge-settings__warn').exists()).toBe(false)
		expect(rows[2].get('.talk-bridge-settings__ok').text()).toBe('Answering')
	})

	it('unbinds a room from its row', async () => {
		bridge.getTalkBridgeStatus.mockResolvedValue({
			...ready,
			rooms: [{ token: 't3', name: 'Archive', agentName: 'Archivist', agentId: 'a3', botEnabled: true, agentActive: true }],
		})
		bridge.bindTalkRoom.mockResolvedValue({ ...ready, rooms: [] })
		const w = await mounted(TalkBridgeSettings)

		await w.get('tbody').findComponent({ name: 'NcButton' }).vm.$emit('click')
		await flushPromises()

		expect(bridge.bindTalkRoom).toHaveBeenCalledWith('t3', '')
		expect(w.find('table').exists()).toBe(false)
	})

	it('enables Bind only with both a token and an agent, and binds what was typed', async () => {
		bridge.getTalkBridgeStatus.mockResolvedValue(ready)
		bridge.bindTalkRoom.mockResolvedValue({ ...ready, rooms: [{ token: 'tok', name: 'New', agentId: 'a9', botEnabled: true, agentActive: true }] })
		const w = await mounted(TalkBridgeSettings)
		const bindButton = () => w.findAllComponents({ name: 'NcButton' }).at(-1)
		const [tokenField, agentField] = w.findAllComponents({ name: 'NcTextField' })

		expect(bindButton().attributes('data-disabled')).toBe('true')
		await tokenField.vm.$emit('update:value', ' tok ')
		expect(bindButton().attributes('data-disabled')).toBe('true')
		await agentField.vm.$emit('update:value', 'a9')
		expect(bindButton().attributes('data-disabled')).toBe('false')

		await bindButton().vm.$emit('click')
		await flushPromises()

		expect(bridge.bindTalkRoom).toHaveBeenCalledWith('tok', 'a9')
		expect(w.findAll('tbody tr')).toHaveLength(1)
	})

	it('shows a failed bind in an error card below the form', async () => {
		bridge.getTalkBridgeStatus.mockResolvedValue(ready)
		bridge.bindTalkRoom.mockRejectedValue(new Error('x'))
		const w = await mounted(TalkBridgeSettings)
		const [tokenField, agentField] = w.findAllComponents({ name: 'NcTextField' })
		await tokenField.vm.$emit('update:value', 'tok')
		await agentField.vm.$emit('update:value', 'a9')

		await w.findAllComponents({ name: 'NcButton' }).at(-1).vm.$emit('click')
		await flushPromises()

		expect(cards(w).at(-1)).toEqual(['error', 'Could not bind that conversation.'])
	})
})
