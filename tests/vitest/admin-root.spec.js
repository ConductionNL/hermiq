/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The admin settings panel: the current AI provider and search backend it
 * reads on mount, the two configuration modals it opens, and the
 * organisation credentials section.
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@conduction/nextcloud-vue', () => ({
	CnCredentials: { name: 'CnCredentials', props: ['scope', 'appId', 'appName', 'appCredentials'], render: () => null },
}))
vi.mock('../../src/components/settings/AssistantPromptLibrary.vue', () => ({ default: { name: 'AssistantPromptLibrary', render: () => null } }))
vi.mock('../../src/components/settings/IntakeSettings.vue', () => ({ default: { name: 'IntakeSettings', render: () => null } }))
vi.mock('../../src/components/settings/ReportGroupingSettings.vue', () => ({ default: { name: 'ReportGroupingSettings', render: () => null } }))
vi.mock('../../src/components/settings/RunRetentionSettings.vue', () => ({ default: { name: 'RunRetentionSettings', render: () => null } }))
vi.mock('../../src/components/settings/TalkBridgeSettings.vue', () => ({ default: { name: 'TalkBridgeSettings', render: () => null } }))
vi.mock('../../src/modals/LlmProviderModal.vue', () => ({ default: { name: 'LlmProviderModal', props: ['show'], emits: ['close', 'saved'], render: () => null } }))
vi.mock('../../src/modals/WebResearchSettingsModal.vue', () => ({ default: { name: 'WebResearchSettingsModal', props: ['show'], emits: ['close', 'saved'], render: () => null } }))
vi.mock('../../src/views/AiFeatureRegister.vue', () => ({ default: { name: 'AiFeatureRegister', render: () => null } }))
vi.mock('../../src/api/llm.js', () => ({ getLlmSettings: vi.fn() }))
vi.mock('../../src/api/webResearch.js', () => ({ getWebResearchSettings: vi.fn() }))

const llm = await import('../../src/api/llm.js')
const webResearch = await import('../../src/api/webResearch.js')
const { default: manifest } = await import('../../src/manifest.json')
const { default: AdminRoot } = await import('../../src/views/AdminRoot.vue')

/**
 * Translate as the identity, filling {placeholders}.
 *
 * @param {string} app The app id.
 * @param {string} text The text.
 * @param {object} vars The placeholders.
 * @return {string} The filled text.
 */
const t = (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m))

const CHILDREN = [
	'AiFeatureRegister',
	'AssistantPromptLibrary',
	'IntakeSettings',
	'LlmProviderModal',
	'ReportGroupingSettings',
	'RunRetentionSettings',
	'TalkBridgeSettings',
	'WebResearchSettingsModal',
]

/**
 * Mount the panel with its child sections stubbed and let it load.
 *
 * @return {Promise<object>} The wrapper.
 */
async function mountPanel() {
	const w = mount(AdminRoot, { global: { mocks: { t } } })
	await flushPromises()
	return w
}

/**
 * The two "current" labels: AI provider and search backend.
 *
 * @param {object} w The wrapper.
 * @return {Array<string>} The labels.
 */
function current(w) {
	return w.findAll('.hermiq-admin-settings__provider strong').map((s) => s.text())
}

beforeEach(() => {
	vi.clearAllMocks()
})

describe('AdminRoot', () => {
	it.each([
		['openai', 'searxng', ['OpenAI', 'SearXNG (self-hosted)']],
		['nextcloud', 'generic-json', ['Nextcloud Assistant (TaskProcessing)', 'Generic JSON search API']],
		['mistral', 'brave', ['mistral', 'brave']],
		['', undefined, ['Not configured', 'Not configured']],
	])('names provider %p and search backend %p', async (chatProvider, searchProvider, labels) => {
		llm.getLlmSettings.mockResolvedValue({ chatProvider })
		webResearch.getWebResearchSettings.mockResolvedValue({ searchProvider })

		const w = await mountPanel()

		expect(llm.getLlmSettings).toHaveBeenCalledTimes(1)
		expect(webResearch.getWebResearchSettings).toHaveBeenCalledTimes(1)
		expect(current(w)).toEqual(labels)
	})

	it('reads both as not configured when the settings cannot be read', async () => {
		llm.getLlmSettings.mockRejectedValue(new Error('down'))
		webResearch.getWebResearchSettings.mockRejectedValue(new Error('down'))

		const w = await mountPanel()

		expect(current(w)).toEqual(['Not configured', 'Not configured'])
	})

	it('opens the provider modal and shows what it saved', async () => {
		llm.getLlmSettings.mockResolvedValue({ chatProvider: 'openai' })
		webResearch.getWebResearchSettings.mockResolvedValue({})
		const w = await mountPanel()
		const modal = () => w.findComponent({ name: 'LlmProviderModal' })

		expect(modal().props('show')).toBe(false)
		await w.findAllComponents({ name: 'NcButton' })[0].vm.$emit('click')
		expect(modal().props('show')).toBe(true)

		await modal().vm.$emit('saved', 'ollama')
		await modal().vm.$emit('close')
		expect(modal().props('show')).toBe(false)
		expect(current(w)[0]).toBe('Ollama (local)')
	})

	it('opens the web research modal and shows what it saved, or nothing', async () => {
		llm.getLlmSettings.mockResolvedValue({})
		webResearch.getWebResearchSettings.mockResolvedValue({ searchProvider: 'searxng' })
		const w = await mountPanel()
		const modal = () => w.findComponent({ name: 'WebResearchSettingsModal' })

		await w.findAllComponents({ name: 'NcButton' })[1].vm.$emit('click')
		expect(modal().props('show')).toBe(true)

		await modal().vm.$emit('saved', 'generic-json')
		expect(current(w)[1]).toBe('Generic JSON search API')
		await modal().vm.$emit('saved', '')
		expect(current(w)[1]).toBe('Not configured')
		await modal().vm.$emit('close')
		expect(modal().props('show')).toBe(false)
	})

	it('hosts every section and manages organisation credentials for the app', async () => {
		llm.getLlmSettings.mockResolvedValue({})
		webResearch.getWebResearchSettings.mockResolvedValue({})
		const w = await mountPanel()

		expect(w.findAll('nc-settingssection').map((s) => s.attributes('data-name'))).toEqual([
			'AI provider',
			'Web research',
			'AI features',
			'Conversational intake',
			'Report grouping',
			'Assistant prompts',
			'AI run retention',
			'Talk chat bridge',
			'Organisation credentials',
		])
		for (const name of CHILDREN) {
			expect(w.findComponent({ name }).exists(), name).toBe(true)
		}
		const credentials = w.findComponent({ name: 'CnCredentials' })
		expect(credentials.props()).toEqual({
			scope: 'organisation',
			appId: 'hermiq',
			appName: 'Hermiq',
			appCredentials: manifest.credentials || [],
		})
	})
})
