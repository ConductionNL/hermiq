/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * WorkspaceFilesPanel (tools-agent-workspace-and-installed-tools REQ-WKSP-003):
 * the conversation's workspace files with size, changed time, a download link
 * and "Save to my Files", and nothing at all for a conversation without files.
 * The API module is doubled; NcButton is a stub that emits click.
 */

import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

vi.mock('../../src/api/workspaceFiles.js', () => ({
	listWorkspaceFiles: vi.fn(),
	saveWorkspaceFile: vi.fn(),
	workspaceFileDownloadUrl: (sessionId, path) => `/dl/${sessionId}?path=${encodeURIComponent(path)}`,
}))

const { listWorkspaceFiles, saveWorkspaceFile } = await import('../../src/api/workspaceFiles.js')
const { default: WorkspaceFilesPanel } = await import('../../src/components/WorkspaceFilesPanel.vue')

const NcButton = defineComponent({
	name: 'NcButton',
	inheritAttrs: false,
	emits: ['click'],
	render() {
		return h('button', { 'data-testid': this.$attrs['data-testid'], disabled: this.$attrs.disabled, onClick: () => this.$emit('click') }, this.$slots.default?.())
	},
})

const REPORT = { path: 'rapportage-q3.pdf', size: 2048, modified: 1700000000 }

/**
 * Mount the panel for a session.
 *
 * @param {string} sessionId The session.
 * @return {Promise<object>} The wrapper.
 */
async function panel(sessionId = 'conv-1') {
	const wrapper = mount(WorkspaceFilesPanel, {
		props: { sessionId },
		global: {
			stubs: { NcButton },
			mocks: { t: (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (m, k) => String(vars[k] ?? m)) },
		},
	})
	await flushPromises()
	return wrapper
}

afterEach(() => {
	vi.clearAllMocks()
})

describe('WorkspaceFilesPanel', () => {
	it('lists the workspace files with size, a download link and a save action', async () => {
		listWorkspaceFiles.mockResolvedValue({ files: [REPORT, { path: 'out/data.csv', size: 12, modified: 1700000000 }], truncated: false })

		const wrapper = await panel()

		expect(listWorkspaceFiles).toHaveBeenCalledWith('conv-1')
		const rows = wrapper.findAll('[data-testid="workspace-files-row"]')
		expect(rows).toHaveLength(2)
		expect(rows[0].find('.workspace-files__name').text()).toBe('rapportage-q3.pdf')
		expect(rows[0].find('.workspace-files__meta').text()).toMatch(/^2\.0 KB · /)
		expect(rows[1].find('.workspace-files__meta').text()).toMatch(/^12 B · /)
		expect(rows[0].find('[data-testid="workspace-files-download"]').attributes('href')).toBe('/dl/conv-1?path=rapportage-q3.pdf')
		expect(wrapper.text()).not.toContain('Only the first files are shown.')
	})

	it('shows nothing for a conversation without workspace files, or when the list fails', async () => {
		listWorkspaceFiles.mockResolvedValue({ files: [], truncated: false })
		expect((await panel()).find('[data-testid="workspace-files"]').exists()).toBe(false)

		listWorkspaceFiles.mockRejectedValue(new Error('404'))
		expect((await panel()).find('[data-testid="workspace-files"]').exists()).toBe(false)
	})

	it('saves a file to the person\'s Files and says where it went', async () => {
		listWorkspaceFiles.mockResolvedValue({ files: [REPORT], truncated: true })
		saveWorkspaceFile.mockResolvedValue({ fileId: 42, name: 'rapportage-q3.pdf', path: 'Hermiq/rapportage-q3.pdf' })
		const wrapper = await panel()

		await wrapper.find('[data-testid="workspace-files-save"]').trigger('click')
		await flushPromises()

		expect(saveWorkspaceFile).toHaveBeenCalledWith('conv-1', 'rapportage-q3.pdf')
		expect(wrapper.find('[data-testid="workspace-files-message"]').text()).toBe('Saved to your Files as Hermiq/rapportage-q3.pdf.')
		expect(wrapper.text()).toContain('Only the first files are shown.')
	})

	it('shows the server\'s reason when the save fails', async () => {
		listWorkspaceFiles.mockResolvedValue({ files: [REPORT], truncated: false })
		saveWorkspaceFile.mockRejectedValue({ response: { data: { error: 'The file was not saved: it could not be marked as written by an agent.' } } })
		const wrapper = await panel()

		await wrapper.find('[data-testid="workspace-files-save"]').trigger('click')
		await flushPromises()

		expect(wrapper.find('[data-testid="workspace-files-message"]').text())
			.toBe('The file was not saved: it could not be marked as written by an agent.')
	})

	it('reloads for another conversation and on refresh', async () => {
		listWorkspaceFiles.mockResolvedValue({ files: [REPORT], truncated: false })
		const wrapper = await panel('conv-1')

		await wrapper.setProps({ sessionId: 'conv-2' })
		await flushPromises()
		expect(listWorkspaceFiles).toHaveBeenLastCalledWith('conv-2')

		await wrapper.find('[data-testid="workspace-files-refresh"]').trigger('click')
		await flushPromises()
		expect(listWorkspaceFiles).toHaveBeenCalledTimes(3)
	})
})
