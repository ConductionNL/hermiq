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
vi.mock('../../src/modals/BackupSettingsModal.vue', () => ({ default: { name: 'BackupSettingsModal', props: ['show', 'settings'], emits: ['close', 'saved'], render: () => null } }))
vi.mock('../../src/modals/RestoreDialog.vue', () => ({ default: { name: 'RestoreDialog', props: ['show'], emits: ['close'], render: () => null } }))
vi.mock('../../src/modals/WebResearchSettingsModal.vue', () => ({ default: { name: 'WebResearchSettingsModal', props: ['show'], emits: ['close', 'saved'], render: () => null } }))
vi.mock('../../src/views/AiFeatureRegister.vue', () => ({ default: { name: 'AiFeatureRegister', render: () => null } }))
vi.mock('../../src/api/llm.js', () => ({ getLlmSettings: vi.fn() }))
vi.mock('../../src/api/webResearch.js', () => ({ getWebResearchSettings: vi.fn() }))
vi.mock('../../src/api/backup.js', () => ({ getBackupSettings: vi.fn(), backupNow: vi.fn() }))

const llm = await import('../../src/api/llm.js')
const webResearch = await import('../../src/api/webResearch.js')
const backup = await import('../../src/api/backup.js')
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
	'BackupSettingsModal',
	'IntakeSettings',
	'LlmProviderModal',
	'ReportGroupingSettings',
	'RestoreDialog',
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
	backup.getBackupSettings.mockResolvedValue({ enabled: false, folder: 'Hermiq backups', hour: 2, keep: 14, includeSessions: false, ownerUid: '' })
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
			'Backups',
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

	describe('backups (operations-backup-and-restore)', () => {
		/**
		 * The "Back up now" button.
		 *
		 * @param {object} w The wrapper.
		 * @return {object} The button.
		 */
		const backUpNow = (w) => w.findAllComponents({ name: 'NcButton' }).find((b) => b.text().includes('Back up now') || b.text().includes('Backup queued'))

		it('opens the restore dialog and closes it again', async () => {
			llm.getLlmSettings.mockResolvedValue({})
			webResearch.getWebResearchSettings.mockResolvedValue({})
			const w = await mountPanel()
			const dialog = () => w.findComponent({ name: 'RestoreDialog' })

			expect(dialog().props('show')).toBe(false)
			await w.findAllComponents({ name: 'NcButton' }).find((b) => b.attributes('data-data-testid') === 'restore-open').vm.$emit('click')
			expect(dialog().props('show')).toBe(true)
			await dialog().vm.$emit('close')
			expect(dialog().props('show')).toBe(false)
		})

		it('says backups are off and offers no backup before settings are saved', async () => {
			llm.getLlmSettings.mockResolvedValue({})
			webResearch.getWebResearchSettings.mockResolvedValue({})
			const w = await mountPanel()

			expect(backup.getBackupSettings).toHaveBeenCalledTimes(1)
			expect(w.find('[data-testid="backup-summary"]').text()).toBe('Nightly backups are off.')
			expect(backUpNow(w).vm.$attrs.disabled).toBe(true)
		})

		it('summarises the schedule and queues a backup now', async () => {
			llm.getLlmSettings.mockResolvedValue({})
			webResearch.getWebResearchSettings.mockResolvedValue({})
			backup.getBackupSettings.mockResolvedValue({ enabled: true, folder: 'Back-ups', hour: 3, keep: 7, includeSessions: false, ownerUid: 'admin' })
			backup.backupNow.mockResolvedValue({ queued: true })
			const w = await mountPanel()

			expect(w.find('[data-testid="backup-summary"]').text()).toBe('Every night at 03:00 into "Back-ups", keeping 7 per organisation.')
			await backUpNow(w).vm.$emit('click')
			await flushPromises()
			expect(backup.backupNow).toHaveBeenCalledTimes(1)
			expect(backUpNow(w).text()).toBe('Backup queued')
			expect(backUpNow(w).vm.$attrs.disabled).toBe(true)
		})

		it('opens the backup modal with the stored settings and shows what it saved', async () => {
			llm.getLlmSettings.mockResolvedValue({})
			webResearch.getWebResearchSettings.mockResolvedValue({})
			const w = await mountPanel()
			const modal = () => w.findComponent({ name: 'BackupSettingsModal' })

			expect(modal().props('show')).toBe(false)
			await w.findAllComponents({ name: 'NcButton' }).find((b) => b.text().includes('Configure backups')).vm.$emit('click')
			expect(modal().props('show')).toBe(true)
			expect(modal().props('settings').folder).toBe('Hermiq backups')

			await modal().vm.$emit('saved', { enabled: true, folder: 'X', hour: 23, keep: 1, includeSessions: true, ownerUid: 'admin' })
			expect(w.find('[data-testid="backup-summary"]').text()).toBe('Every night at 23:00 into "X", keeping 1 per organisation.')
			expect(backUpNow(w).vm.$attrs.disabled).toBe(false)
		})

		it('reads a failure to load the settings as off', async () => {
			llm.getLlmSettings.mockResolvedValue({})
			webResearch.getWebResearchSettings.mockResolvedValue({})
			backup.getBackupSettings.mockRejectedValue(new Error('403'))
			const w = await mountPanel()

			expect(w.find('[data-testid="backup-summary"]').text()).toBe('Nightly backups are off.')
		})
	})
})
