/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * operations-backup-and-restore: the restore dialog shows the preview per
 * schema before any restore starts, and the helpers it builds the point and
 * the preview table with.
 */

import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import RestoreDialog from '../../src/modals/RestoreDialog.vue'
import { backupOrganisations, previewRestore, previewRows, restorePoint } from '../../src/api/backup.js'

const t = (app, text) => text

const FILE = 'hermiq-backup-tilburg-2026-09-28-0200.json.gz'

const PREVIEW = {
	point: { kind: 'backup', organisation: 'tilburg', file: FILE, at: null, removeCreatedAfter: false },
	organisation: 'tilburg',
	refused: null,
	schemas: {
		agent: { putBack: 0, changed: 12, unchanged: 3, createdAfter: 1, missing: 0, noHistory: 0, notRestorable: [] },
		agentskill: { putBack: 0, changed: 0, unchanged: 5, createdAfter: 0, missing: 0, noHistory: 0, notRestorable: [] },
	},
	writes: 12,
	createdAfter: 1,
}

beforeEach(() => {
	vi.restoreAllMocks()
})

describe('the restore point', () => {
	it('sends a backup by its file', () => {
		expect(restorePoint({ kind: 'backup', file: FILE, removeCreatedAfter: true })).toEqual({ kind: 'backup', file: FILE, removeCreatedAfter: true })
	})

	it('sends a moment in UTC with the organisation, and nothing it cannot read', () => {
		const point = restorePoint({ kind: 'moment', organisation: ' tilburg ', at: '2026-09-28T02:00:00Z' })
		expect(point).toEqual({ kind: 'moment', organisation: 'tilburg', at: '2026-09-28T02:00:00.000Z', removeCreatedAfter: false })
		expect(restorePoint({ kind: 'moment', organisation: 'tilburg', at: 'soon' }).at).toBe('')
	})
})

describe('the preview table', () => {
	it('lists only the schemas where something changes', () => {
		expect(previewRows(PREVIEW)).toEqual([
			{ schema: 'agent', putBack: 0, changed: 12, createdAfter: 1, missing: 0, noHistory: 0, notRestorable: [] },
		])
	})

	it('keeps a schema whose properties cannot be restored', () => {
		const rows = previewRows({ schemas: { agent: { unchanged: 2, notRestorable: ['voiceMode'] } } })
		expect(rows).toEqual([{ schema: 'agent', putBack: 0, changed: 0, createdAfter: 0, missing: 0, noHistory: 0, notRestorable: ['voiceMode'] }])
		expect(previewRows(null)).toEqual([])
	})

	it('offers each organisation the backups name once', () => {
		expect(backupOrganisations([{ manifest: { organisation: 'tilburg' } }, { manifest: {} }, { manifest: { organisation: 'breda' } }, { manifest: { organisation: 'tilburg' } }]))
			.toEqual(['breda', 'tilburg'])
	})

	it('returns a refused preview instead of throwing it', async () => {
		vi.spyOn(axios, 'post').mockRejectedValue({ response: { status: 409, data: { ...PREVIEW, refused: 'newer register version' } } })
		expect((await previewRestore({ kind: 'backup', file: FILE })).refused).toBe('newer register version')

		axios.post.mockRejectedValue({ response: { status: 404, data: { error: 'gone' } } })
		await expect(previewRestore({ kind: 'backup', file: FILE })).rejects.toBeTruthy()
	})
})

describe('the restore dialog', () => {
	/**
	 * Mount the dialog open, over one backup.
	 *
	 * @return {Promise<object>} The wrapper.
	 */
	async function open() {
		vi.spyOn(axios, 'get').mockResolvedValue({ data: { backups: [{ name: FILE, manifest: { organisation: 'tilburg', createdAt: '2026-09-28T02:00:00+00:00' } }] } })
		const w = mount(RestoreDialog, { props: { show: false }, global: { mocks: { t } } })
		await w.setProps({ show: true })
		await flushPromises()
		return w
	}

	const button = (w, id) => w.findAllComponents({ name: 'NcButton' }).find((b) => b.attributes('data-data-testid') === id)

	it('shows the preview per schema before any restore starts', async () => {
		const w = await open()
		const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: PREVIEW })

		expect(button(w, 'restore-start').vm.$attrs.disabled).toBe(true)
		const select = w.findAllComponents({ name: 'NcSelect' }).find((s) => s.attributes('data-data-testid') === 'restore-backup')
		expect(JSON.parse(select.attributes('data-options'))).toEqual([{ value: FILE, label: 'tilburg · 2026-09-28T02:00:00+00:00' }])
		await select.vm.pick({ value: FILE, label: 'tilburg' })
		await button(w, 'restore-preview').vm.$emit('click')
		await flushPromises()

		expect(post).toHaveBeenCalledTimes(1)
		expect(post.mock.calls[0][0]).toBe('/index.php/apps/hermiq/api/backup/restore/preview')
		expect(post.mock.calls[0][1]).toEqual({ kind: 'backup', file: FILE, removeCreatedAfter: false })
		const cells = w.find('[data-testid="restore-preview-table"] tbody tr').findAll('th, td').map((c) => c.text())
		expect(cells).toEqual(['agent', '0', '12', '1', '0', '0', ''])
		expect(button(w, 'restore-start').vm.$attrs.disabled).toBe(false)
	})

	it('queues the restore with the choice for later objects', async () => {
		const w = await open()
		const post = vi.spyOn(axios, 'post').mockResolvedValueOnce({ data: PREVIEW }).mockResolvedValueOnce({ data: { queued: true } })
		await w.findAllComponents({ name: 'NcSelect' })[0].vm.pick({ value: FILE, label: 'tilburg' })
		await button(w, 'restore-preview').vm.$emit('click')
		await flushPromises()

		const later = w.findAllComponents({ name: 'NcCheckboxRadioSwitch' }).find((s) => s.attributes('data-data-testid') === 'restore-remove-later')
		await later.vm.pick(true)
		await button(w, 'restore-start').vm.$emit('click')
		await flushPromises()

		expect(post.mock.calls[1]).toEqual(['/index.php/apps/hermiq/api/backup/restore', { kind: 'backup', file: FILE, removeCreatedAfter: true }])
		expect(w.find('[data-data-testid="restore-queued"]').exists()).toBe(true)
		expect(button(w, 'restore-start').vm.$attrs.disabled).toBe(true)
	})

	it('shows a refusal and offers no restore', async () => {
		const w = await open()
		vi.spyOn(axios, 'post').mockRejectedValue({ response: { status: 409, data: { ...PREVIEW, refused: 'This backup was written by register version 0.60.0' } } })
		await w.findAllComponents({ name: 'NcSelect' })[0].vm.pick({ value: FILE, label: 'tilburg' })
		await button(w, 'restore-preview').vm.$emit('click')
		await flushPromises()

		expect(w.find('[data-data-testid="restore-refused"]').text()).toContain('0.60.0')
		expect(w.find('[data-testid="restore-preview-table"]').exists()).toBe(false)
		expect(button(w, 'restore-start').vm.$attrs.disabled).toBe(true)
	})
})
