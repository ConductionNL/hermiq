/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The dictate and read-aloud buttons, the tool invocation table and tool
 * governance widget, the agent memory page, the chat settings dialog, and the
 * small approval, template, session, image and availability dialogs, mounted
 * with their API and stores doubled.
 */

import { flushPromises, shallowMount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const toasts = []
const user = { current: { uid: 'owner' } }
vi.mock('@nextcloud/auth', () => ({ getCurrentUser: () => user.current }))
vi.mock('@nextcloud/dialogs', () => ({
	showError: (m) => toasts.push(['error', m]),
	showSuccess: (m) => toasts.push(['success', m]),
}))
vi.mock('../../src/api/speech.js', () => ({ transcribe: vi.fn(), synthesise: vi.fn() }))
vi.mock('../../src/api/toolOversight.js', () => ({ getToolInvocations: vi.fn(), toolInvocationsExportUrl: (id, f) => `/export/${id}.${f}` }))
vi.mock('../../src/api/approvals.js', () => ({ denyApproval: vi.fn() }))
vi.mock('../../src/api/agents.js', () => ({ previewPrompt: vi.fn() }))
vi.mock('../../src/api/agentTemplates.js', () => ({ collectAppTemplates: vi.fn() }))
vi.mock('../../src/api/chat.js', () => ({ renameSession: vi.fn(), createChatImage: vi.fn() }))
vi.mock('../../src/api/agentAvailability.js', () => ({ getAvailability: vi.fn(), setAvailability: vi.fn() }))
const agentStore = { registerObjectType: vi.fn(), fetchObject: vi.fn(), fetchCollection: vi.fn(), errors: {} }
vi.mock('../../src/store/store.js', () => ({ useAgentStore: () => agentStore }))

const speechApi = await import('../../src/api/speech.js')
const oversight = await import('../../src/api/toolOversight.js')
const approvals = await import('../../src/api/approvals.js')
const agentsApi = await import('../../src/api/agents.js')
const templates = await import('../../src/api/agentTemplates.js')
const chat = await import('../../src/api/chat.js')
const availability = await import('../../src/api/agentAvailability.js')
const { default: DictateButton } = await import('../../src/components/DictateButton.vue')
const { default: ReadAloudButton } = await import('../../src/components/ReadAloudButton.vue')
const { default: ToolInvocationTable } = await import('../../src/components/ToolInvocationTable.vue')
const { default: AgentToolGovernanceWidget } = await import('../../src/widgets/AgentToolGovernanceWidget.vue')
const { default: AgentMemory } = await import('../../src/views/AgentMemory.vue')
const { default: ChatSettingsModal } = await import('../../src/modals/ChatSettingsModal.vue')
const { default: ApprovalDenyModal } = await import('../../src/modals/ApprovalDenyModal.vue')
const { default: PromptPlaceholderTools } = await import('../../src/components/PromptPlaceholderTools.vue')
const { default: AppTemplateCollectModal } = await import('../../src/modals/AppTemplateCollectModal.vue')
const { default: SessionRenameModal } = await import('../../src/modals/SessionRenameModal.vue')
const { default: GenerateImageModal } = await import('../../src/modals/GenerateImageModal.vue')
const { default: AgentAvailabilityDialog } = await import('../../src/dialogs/AgentAvailabilityDialog.vue')

/**
 * Translate as the identity, filling {placeholders}.
 *
 * @param {string} app The app id.
 * @param {string} text The text.
 * @param {object} vars The placeholders.
 * @return {string} The filled text.
 */
const t = (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m))
globalThis.t = t

/**
 * Mount a component and let it load.
 *
 * @param {object} component The component.
 * @param {object} [props] Its props.
 * @param {object} [options] Extra mount options (stubs, attachTo).
 * @return {Promise<object>} The wrapper.
 */
async function mountIt(component, props = {}, options = {}) {
	const w = shallowMount(component, { props, ...options, global: { mocks: { t, $route: { params: { id: 'a1' } } }, ...(options.global || {}) } })
	await flushPromises()
	return w
}

/**
 * Mount a dialog closed, then open it.
 *
 * @param {object} component The dialog.
 * @param {object} props Its other props.
 * @return {Promise<object>} The wrapper.
 */
async function openDialog(component, props) {
	const w = shallowMount(component, { props: { show: false, ...props }, global: { mocks: { t, $route: { params: { id: 'a1' } } } } })
	await w.setProps({ show: true })
	await flushPromises()
	return w
}

beforeEach(() => {
	toasts.length = 0
	vi.clearAllMocks()
	user.current = { uid: 'owner' }
	agentStore.errors = {}
})

afterEach(() => {
	vi.unstubAllGlobals()
})

describe('DictateButton', () => {
	it('records, transcribes and emits the words, labelling each state', async () => {
		const tracks = [{ stop: vi.fn() }]
		vi.stubGlobal('navigator', { mediaDevices: { getUserMedia: vi.fn(async () => ({ getTracks: () => tracks })) } })
		vi.stubGlobal('MediaRecorder', class {
			constructor() { this.mimeType = '' }
			start() { this.ondataavailable({ data: new Blob(['a']) }); this.ondataavailable({ data: { size: 0 } }) }
			stop() { this.onstop() }
		})
		speechApi.transcribe.mockResolvedValueOnce('hello there')
		const w = await mountIt(DictateButton)
		expect(w.vm.label).toBe('Dictate a message')
		await w.vm.toggle()
		expect(w.vm.label).toBe('Listening, press to stop')
		await w.vm.toggle()
		expect(speechApi.transcribe.mock.calls[0][0].type).toBe('audio/webm')
		expect(tracks[0].stop).toHaveBeenCalled()
		expect(w.emitted('transcript')).toEqual([['hello there']])
		w.vm.state = 'transcribing'
		expect(w.vm.label).toBe('Transcribing')
	})

	it('explains a refused microphone and an unavailable speech service', async () => {
		vi.stubGlobal('navigator', { mediaDevices: { getUserMedia: vi.fn(async () => { throw new Error('denied') }) } })
		const w = await mountIt(DictateButton)
		await w.vm.toggle()
		expect(w.emitted('error')).toEqual([['Microphone access was refused.']])
		vi.stubGlobal('navigator', { mediaDevices: { getUserMedia: vi.fn(async () => ({ getTracks: () => [] })) } })
		vi.stubGlobal('MediaRecorder', class { start() {} stop() { this.onstop() } })
		speechApi.transcribe.mockRejectedValueOnce(new Error('down'))
		await w.vm.toggle()
		await w.vm.toggle()
		expect(w.emitted('error')[1]).toEqual(['The speech service is unavailable.'])
	})
})

describe('ReadAloudButton', () => {
	beforeEach(() => {
		URL.createObjectURL = vi.fn(() => 'blob:a')
		URL.revokeObjectURL = vi.fn()
		vi.stubGlobal('Audio', class {
			constructor(url) { this.url = url; this.listeners = {} }
			addEventListener(name, fn) { this.listeners[name] = fn }
			async play() {}
			pause() { this.listeners.pause?.() }
		})
	})

	it('reads the plain text aloud and stops on a second press, and when unmounted', async () => {
		speechApi.synthesise.mockResolvedValue(new Blob(['x']))
		const w = await mountIt(ReadAloudButton, { messageKey: 'm1', text: '**Hello** [there](x)' })
		expect(w.vm.label).toBe('Read aloud')
		await w.vm.toggle()
		expect(speechApi.synthesise).toHaveBeenCalledWith('Hello there')
		expect([w.vm.playing, w.vm.label]).toEqual([true, 'Stop reading'])
		await w.vm.toggle()
		expect(w.vm.playing).toBe(false)
		expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:a')
		await w.vm.toggle()
		w.unmount()
		const other = await mountIt(ReadAloudButton, { messageKey: 'm2', text: 'x' })
		expect(other.vm.playing).toBe(false)
	})

	it('explains an answer too long to read, and an unavailable service', async () => {
		const w = await mountIt(ReadAloudButton, { messageKey: 'm3', text: 'x' })
		speechApi.synthesise.mockRejectedValueOnce({ response: { status: 400 } })
		await w.vm.toggle()
		speechApi.synthesise.mockRejectedValueOnce(new Error('down'))
		await w.vm.toggle()
		expect(toasts).toEqual([['error', 'This answer is too long to read aloud.'], ['error', 'The speech service is unavailable.']])
	})
})

describe('ToolInvocationTable', () => {
	it('loads the invocations of the agent and labels each result', async () => {
		oversight.getToolInvocations.mockResolvedValueOnce({ retention: '30 days', rows: [] })
		const w = await mountIt(ToolInvocationTable, { agentId: 'a1' })
		expect(oversight.getToolInvocations).toHaveBeenCalledWith('a1')
		expect(w.vm.retentionLabel).toBe('Retention: 30 days')
		expect([
			{},
			{ resultSummary: { isError: true, status: 'x' } },
			{ resultSummary: { status: 'queued' } },
			{ resultSummary: { count: 0 } },
			{ resultSummary: { id: 'o1' } },
			{ resultSummary: { other: 1 } },
		].map(w.vm.resultLabel)).toEqual(['—', 'Error', 'queued', '0 result(s)', 'Object o1', 'OK'])
		expect(w.vm.resultClass({ resultSummary: { isError: true } })).toContain('--error')
		expect(w.vm.resultClass({})).toContain('--ok')
		expect([w.vm.shortDigest('abc'), w.vm.shortDigest('0123456789abcdef')]).toEqual(['abc', '0123456789ab…'])
		expect(w.vm.formatDate('')).toBe('—')
	})

	it('shows a load failure and exports through the download URL', async () => {
		oversight.getToolInvocations.mockRejectedValueOnce({ response: { data: { error: 'Forbidden' } } })
		const w = await mountIt(ToolInvocationTable, { agentId: 'a1' })
		expect(w.vm.error).toBe('Forbidden')
		oversight.getToolInvocations.mockRejectedValueOnce(new Error('Network'))
		await w.vm.load()
		expect([w.vm.error, w.vm.loading]).toEqual(['Network', false])
		vi.stubGlobal('location', { href: '' })
		const original = window.location
		Object.defineProperty(window, 'location', { value: { href: '' }, configurable: true })
		w.vm.exportAs('csv')
		expect(window.location.href).toBe('/export/a1.csv')
		Object.defineProperty(window, 'location', { value: original, configurable: true })
	})
})

describe('AgentToolGovernanceWidget', () => {
	it('lets only the owner edit the grants', async () => {
		agentStore.fetchObject.mockResolvedValueOnce({ '@self': { owner: 'owner' } })
		const w = await mountIt(AgentToolGovernanceWidget)
		expect(w.vm.isOwner).toBe(true)
		expect(w.vm.tabs.map((tab) => tab.id)).toEqual(['grants', 'activity'])
		w.vm.agent = { owner: 'someone' }
		expect(w.vm.isOwner).toBe(false)
		user.current = null
		expect(w.vm.isOwner).toBe(false)
	})

	it('moves between tabs with the arrow, Home and End keys', async () => {
		agentStore.fetchObject.mockRejectedValueOnce(new Error('x'))
		const w = await mountIt(AgentToolGovernanceWidget)
		expect(w.vm.agent).toBeNull()
		const key = (k) => ({ key: k, preventDefault: vi.fn() })
		const right = key('ArrowRight')
		w.vm.onTabKeydown(right, 1)
		expect([w.vm.activeTab, right.preventDefault.mock.calls.length]).toEqual(['grants', 1])
		w.vm.onTabKeydown(key('ArrowLeft'), 0)
		expect(w.vm.activeTab).toBe('activity')
		w.vm.onTabKeydown(key('Home'), 1)
		expect(w.vm.activeTab).toBe('grants')
		w.vm.onTabKeydown(key('End'), 0)
		expect(w.vm.activeTab).toBe('activity')
		const other = key('a')
		w.vm.onTabKeydown(other, 0)
		expect(other.preventDefault).not.toHaveBeenCalled()
	})

	it('saves the grants through the matrix and resets it', async () => {
		const matrix = { persist: vi.fn(async () => {}), reset: vi.fn() }
		const ctx = {
			...AgentToolGovernanceWidget.methods,
			$refs: { matrix },
			loadAgent: vi.fn(async () => {}),
			saving: false,
		}
		await ctx.onSave()
		expect(matrix.persist).toHaveBeenCalled()
		expect(ctx.loadAgent).toHaveBeenCalledTimes(1)
		expect(toasts).toEqual([['success', 'Tool grants saved']])
		matrix.persist.mockRejectedValueOnce({ response: { data: { error: 'Grant refused' } } })
		await ctx.onSave()
		matrix.persist.mockRejectedValueOnce(new Error('Network'))
		await ctx.onSave()
		expect(toasts.slice(1)).toEqual([['error', 'Grant refused'], ['error', 'Network']])
		expect(ctx.loadAgent).toHaveBeenCalledTimes(1)
		ctx.onReset()
		expect(matrix.reset).toHaveBeenCalled()
		expect(ctx.saving).toBe(false)
	})
})

describe('AgentMemory', () => {
	it('selects the first agent, and shows the store error', async () => {
		agentStore.fetchCollection.mockResolvedValueOnce([{ uuid: 'a1', name: 'Bot' }, { id: 'a2' }])
		const w = await mountIt(AgentMemory)
		expect(w.vm.agentOptions).toEqual([{ label: 'Bot', value: 'a1' }, { label: 'a2', value: 'a2' }])
		expect(w.vm.selectedAgent).toEqual({ label: 'Bot', value: 'a1' })
		agentStore.fetchCollection.mockResolvedValueOnce(null)
		agentStore.errors = { agent: {} }
		await w.vm.loadAgents()
		expect([w.vm.agents, w.vm.error]).toEqual([[], 'Unknown error'])
		agentStore.fetchCollection.mockRejectedValueOnce(new Error('Store down'))
		await w.vm.loadAgents()
		expect([w.vm.error, w.vm.loading]).toEqual(['Store down', false])
	})
})

describe('ChatSettingsModal', () => {
	const value = { views: ['v1'], tools: [], numSourcesFiles: 5 }

	it('emits a copy with one setting changed', async () => {
		const w = await mountIt(ChatSettingsModal, { value })
		w.vm.set('includeFiles', false)
		w.vm.toggle('views', 'v1')
		w.vm.toggle('tools', 't1')
		w.vm.toggle('other', 'x')
		expect(w.emitted('input').map((e) => e[0])).toEqual([
			{ ...value, includeFiles: false },
			{ ...value, views: [] },
			{ ...value, tools: ['t1'] },
			{ ...value, other: ['x'] },
		])
		expect(value.views).toEqual(['v1'])
	})

	it('keeps a source count between one and twenty, ignoring non-numbers', async () => {
		const w = await mountIt(ChatSettingsModal, { value })
		w.vm.setNumber('numSourcesFiles', 'abc')
		w.vm.setNumber('numSourcesFiles', '0')
		w.vm.setNumber('numSourcesFiles', '99')
		w.vm.setNumber('numSourcesFiles', '7.6')
		expect(w.emitted('input').map((e) => e[0].numSourcesFiles)).toEqual([1, 20, 8])
	})
})

describe('ApprovalDenyModal', () => {
	it('denies with the reason and names the schedule or prompt', async () => {
		const w = await openDialog(ApprovalDenyModal, { approval: { id: 'p1', prompt: 'Send mail' } })
		expect([w.vm.heading, w.vm.scheduleName]).toEqual(['Deny approval', 'Send mail'])
		w.vm.reason = 'Not now'
		await w.vm.confirm()
		expect(approvals.denyApproval).toHaveBeenCalledWith('p1', 'Not now')
		expect(w.emitted('denied')).toEqual([['p1']])
		await w.setProps({ approval: { id: 'p2', scheduleName: 'Daily', prompt: 'x' } })
		expect(w.vm.scheduleName).toBe('Daily')
	})

	it('denies nothing without an approval, and shows a refusal', async () => {
		const w = await openDialog(ApprovalDenyModal, { approval: null })
		expect(w.vm.scheduleName).toBe('')
		await w.vm.confirm()
		expect(approvals.denyApproval).not.toHaveBeenCalled()
		await w.setProps({ approval: { id: 'p1' } })
		approvals.denyApproval.mockRejectedValueOnce({ response: { data: { error: 'Expired' } } })
		await w.vm.confirm()
		expect([w.vm.error, w.vm.saving]).toEqual(['Expired', false])
		expect(w.emitted('close')).toBeUndefined()
	})
})

describe('PromptPlaceholderTools', () => {
	it('lists the fixed placeholders and one per valid start field', async () => {
		const w = await mountIt(PromptPlaceholderTools, { startFields: [{ key: 'topic', label: 'Topic' }, { key: 'Bad' }] })
		const items = w.vm.items
		expect(items).toHaveLength(9)
		expect(items[0]).toEqual({ token: '{{user.displayName}}', label: 'Name of the person' })
		expect(items.at(-1)).toEqual({ token: '{{field.topic}}', label: 'Answer to "Topic"' })
	})

	it('previews the instructions with sample answers, or says it could not', async () => {
		agentsApi.previewPrompt.mockResolvedValueOnce({ text: 'Hello Topic', unknown: [] })
		const w = await mountIt(PromptPlaceholderTools, { agentId: 'a1', prompt: 'Hello {{field.topic}}', startFields: [{ key: 'topic', default: 'x' }] })
		await w.vm.preview()
		expect(agentsApi.previewPrompt).toHaveBeenCalledWith('a1', {
			prompt: 'Hello {{field.topic}}',
			startFields: [{ key: 'topic', label: 'topic', type: 'text', options: [], required: false, default: 'x' }],
			sampleValues: { topic: 'x' },
		})
		expect(w.vm.result).toEqual({ text: 'Hello Topic', unknown: [] })
		agentsApi.previewPrompt.mockRejectedValueOnce(new Error('x'))
		await w.vm.preview()
		expect([w.vm.result, w.vm.error, w.vm.loading]).toEqual([null, 'Could not preview the instructions.', false])
	})
})

describe('AppTemplateCollectModal', () => {
	it('summarises the collected templates and reports new or updated ones', async () => {
		const w = await mountIt(AppTemplateCollectModal)
		expect(w.vm.summary).toBe('0 new, 0 updated, 0 unchanged, 0 refused.')
		templates.collectAppTemplates.mockResolvedValueOnce({ imported: 2, updated: 0, unchanged: 1, refused: 1 })
		await w.vm.run()
		expect(w.vm.summary).toBe('2 new, 0 updated, 1 unchanged, 1 refused.')
		expect(w.emitted('imported')).toHaveLength(1)
		templates.collectAppTemplates.mockResolvedValueOnce({ unchanged: 3 })
		await w.vm.run()
		expect(w.emitted('imported')).toHaveLength(1)
	})

	it('shows why the apps could not be checked', async () => {
		const w = await mountIt(AppTemplateCollectModal)
		templates.collectAppTemplates.mockRejectedValueOnce({ response: { data: { error: 'Not admin' } } })
		await w.vm.run()
		templates.collectAppTemplates.mockRejectedValueOnce({})
		await w.vm.run()
		expect([w.vm.error, w.vm.result, w.vm.busy]).toEqual(['Could not check the apps for templates', null, false])
	})
})

describe('SessionRenameModal', () => {
	it('opens with the title and saves a trimmed new one', async () => {
		chat.renameSession.mockResolvedValueOnce({ uuid: 's1', title: 'New' })
		const w = await openDialog(SessionRenameModal, { session: { uuid: 's1', title: 'Old' } })
		expect(w.vm.title).toBe('Old')
		w.vm.title = ' New '
		await w.vm.save()
		expect(chat.renameSession).toHaveBeenCalledWith('s1', 'New')
		expect(w.emitted('saved')).toEqual([[{ uuid: 's1', title: 'New' }]])
		expect(w.emitted('close')).toHaveLength(1)
	})

	it('saves no blank title, and shows a refusal', async () => {
		const w = await openDialog(SessionRenameModal, { session: { uuid: 's1' } })
		await w.vm.save()
		expect(chat.renameSession).not.toHaveBeenCalled()
		w.vm.title = 'X'
		chat.renameSession.mockRejectedValueOnce({ response: { data: { message: 'Too long' } } })
		await w.vm.save()
		expect(w.vm.error).toBe('Too long')
		chat.renameSession.mockRejectedValueOnce({})
		await w.vm.save()
		expect([w.vm.error, w.vm.saving]).toEqual(['Unknown error', false])
	})
})

describe('GenerateImageModal', () => {
	it('creates an image from a trimmed description', async () => {
		chat.createChatImage.mockResolvedValueOnce({ userTurn: {}, assistantTurn: {} })
		const w = await openDialog(GenerateImageModal, { session: { uuid: 's1' } })
		await w.vm.create()
		expect(chat.createChatImage).not.toHaveBeenCalled()
		w.vm.prompt = ' a red bike '
		await w.vm.create()
		expect(chat.createChatImage).toHaveBeenCalledWith('s1', 'a red bike')
		expect(w.emitted('created')).toEqual([[{ userTurn: {}, assistantTurn: {} }]])
		chat.createChatImage.mockRejectedValueOnce({ response: { data: { error: 'No provider' } } })
		await w.vm.create()
		chat.createChatImage.mockRejectedValueOnce({})
		await w.vm.create()
		expect([w.vm.error, w.vm.creating]).toEqual(['The image could not be created.', false])
	})
})

describe('AgentAvailabilityDialog', () => {
	it('reads the availability of the routed agent and switches it off with a reason', async () => {
		availability.getAvailability.mockResolvedValueOnce({ active: true })
		availability.setAvailability.mockResolvedValueOnce({ active: false, reason: 'Holiday' })
		const w = await mountIt(AgentAvailabilityDialog, { show: true })
		expect(availability.getAvailability).toHaveBeenCalledWith('a1')
		w.vm.reason = ' Holiday '
		await w.vm.switchTo(false)
		expect(availability.setAvailability).toHaveBeenCalledWith('a1', false, 'Holiday')
		expect([w.vm.state, w.vm.reason]).toEqual([{ active: false, reason: 'Holiday' }, ''])
		availability.setAvailability.mockResolvedValueOnce({ active: true })
		w.vm.reason = 'ignored'
		await w.vm.switchTo(true)
		expect(availability.setAvailability).toHaveBeenLastCalledWith('a1', true, '')
	})

	it('tells an agent without availability from a failed read, and shows a failed switch', async () => {
		availability.getAvailability.mockRejectedValueOnce({ response: { status: 404 } })
		const w = await mountIt(AgentAvailabilityDialog, { show: true })
		expect([w.vm.notAvailable, w.vm.error]).toEqual([true, ''])
		availability.getAvailability.mockRejectedValueOnce({ response: { status: 500, data: { error: 'Broken' } } })
		await w.vm.load()
		expect([w.vm.notAvailable, w.vm.error]).toEqual([false, 'Broken'])
		availability.setAvailability.mockRejectedValueOnce(new Error('Network'))
		await w.vm.switchTo(true)
		expect([w.vm.error, w.vm.saving]).toEqual(['Network', false])
		expect([w.vm.formatDate(''), w.vm.formatDate('2026-01-01T00:00:00Z')]).toEqual(['', new Date('2026-01-01T00:00:00Z').toLocaleString()])
	})

	it('reads nothing without an agent', async () => {
		const w = shallowMount(AgentAvailabilityDialog, { props: { show: true }, global: { mocks: { t, $route: { params: {} } } } })
		await flushPromises()
		expect(availability.getAvailability).not.toHaveBeenCalled()
		expect(w.vm.state).toBeNull()
	})
})
