/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The bulk delete confirmation: it names the count, deletes the given sessions
 * in one bulk request, hands the per-session answer to the page, and keeps the
 * modal open with the reason when the request fails.
 *
 * @spec openspec/changes/chat-session-search-and-bulk-delete/specs/session-surface/spec.md#requirement-a-person-can-archive-restore-or-delete-several-sessions-in-one-action-req-ssrch-002
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../../src/api/chat.js', () => ({ bulkSessions: vi.fn() }))

const chat = await import('../../src/api/chat.js')
const { default: SessionBulkDeleteModal } = await import('../../src/modals/SessionBulkDeleteModal.vue')

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
 * Pick the singular or plural form, filling %n.
 *
 * @param {string} app The app id.
 * @param {string} singular The singular form.
 * @param {string} plural The plural form.
 * @param {number} count The count.
 * @return {string} The filled text.
 */
const n = (app, singular, plural, count) => (count === 1 ? singular : plural).replace('%n', String(count))

/**
 * Mount the modal over the given uuids.
 *
 * @param {Array<string>} uuids The sessions to delete.
 * @return {object} The wrapper.
 */
function mountIt(uuids) {
	return mount(SessionBulkDeleteModal, { props: { show: true, uuids }, global: { mocks: { t, n } } })
}

/**
 * Click the button whose text is given.
 *
 * @param {object} w The wrapper.
 * @param {string} text The button text.
 * @return {Promise<void>}
 */
async function click(w, text) {
	const found = w.findAllComponents({ name: 'NcButton' }).find((b) => b.text().trim() === text)
	if (!found) {
		throw new Error(`no button ${text}`)
	}
	found.vm.$emit('click')
	await flushPromises()
}

beforeEach(() => {
	vi.clearAllMocks()
})

describe('SessionBulkDeleteModal', () => {
	it('names the count in the question', () => {
		expect(mountIt(['a', 'b', 'c']).get('[data-testid="session-bulk-delete-question"]').text()).toBe('Delete 3 sessions? This cannot be undone.')
		expect(mountIt(['a']).get('[data-testid="session-bulk-delete-question"]').text()).toBe('Delete 1 session? This cannot be undone.')
	})

	it('deletes the sessions in one bulk request and hands the answer to the page', async () => {
		const answer = [{ uuid: 'a', status: 'ok' }, { uuid: 'b', status: 'not_found' }]
		chat.bulkSessions.mockResolvedValue(answer)
		const w = mountIt(['a', 'b'])

		await click(w, 'Delete permanently')

		expect(chat.bulkSessions).toHaveBeenCalledWith('delete', ['a', 'b'])
		expect(w.emitted('deleted')).toEqual([[answer]])
		expect(w.emitted('close')).toHaveLength(1)
	})

	it('stays open with the reason when the request fails', async () => {
		chat.bulkSessions.mockRejectedValue({ response: { data: { error: 'At most 100 sessions per request' } } })
		const w = mountIt(['a'])

		await click(w, 'Delete permanently')

		expect(w.emitted('deleted')).toBeUndefined()
		expect(w.emitted('close')).toBeUndefined()
		expect(w.text()).toContain('At most 100 sessions per request')
	})

	it('sends nothing for an empty selection, and cancel closes', async () => {
		const w = mountIt([])
		await w.vm.confirmDelete()
		expect(chat.bulkSessions).not.toHaveBeenCalled()
		await click(w, 'Cancel')
		expect(w.emitted('close')).toHaveLength(1)
	})
})
