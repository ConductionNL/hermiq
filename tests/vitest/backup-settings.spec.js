/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * operations-backup-and-restore: the backup settings form an administrator
 * edits, and the payload it sends.
 */

import { describe, expect, it } from 'vitest'
import { backupForm, backupPayload } from '../../src/api/backup.js'

describe('the backup settings form', () => {
	it('starts from the defaults when nothing is stored', () => {
		expect(backupForm(null)).toEqual({ enabled: false, folder: 'Hermiq backups', hour: '2', keep: '14', includeSessions: false })
	})

	it('is seeded from the stored settings', () => {
		expect(backupForm({ enabled: true, folder: 'Back-ups', hour: 0, keep: 30, includeSessions: true, ownerUid: 'admin' }))
			.toEqual({ enabled: true, folder: 'Back-ups', hour: '0', keep: '30', includeSessions: true })
	})

	it('sends whole numbers and a trimmed folder, never an owner', () => {
		const payload = backupPayload({ enabled: true, folder: '  Back-ups ', hour: '23', keep: '7', includeSessions: false, ownerUid: 'someone' })

		expect(payload).toEqual({ enabled: true, folder: 'Back-ups', hour: 23, keep: 7, includeSessions: false })
	})

	it('sends what it cannot read as not a number, so the server refuses it', () => {
		expect(Number.isNaN(backupPayload({ hour: 'two', keep: '' }).hour)).toBe(true)
	})
})
