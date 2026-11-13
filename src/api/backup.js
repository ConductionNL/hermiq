// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Fetch helpers for the admin backup section (operations-backup-and-restore).
// Every route is for a Nextcloud administrator only. Backs
// src/modals/BackupSettingsModal.vue and the Backups section of AdminRoot.vue.

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * Read the backup settings.
 *
 * @return {Promise<{enabled: boolean, folder: string, hour: number, keep: number, includeSessions: boolean, ownerUid: string}>} The settings.
 */
export async function getBackupSettings() {
	const { data } = await axios.get(generateUrl('/apps/hermiq/api/settings/backup'))
	return data
}

/**
 * Save the backup settings; the folder is in the saving administrator's Files.
 *
 * @param {{enabled: boolean, folder: string, hour: number, keep: number, includeSessions: boolean}} settings The settings.
 * @return {Promise<object>} The saved settings.
 */
export async function saveBackupSettings(settings) {
	const { data } = await axios.put(generateUrl('/apps/hermiq/api/settings/backup'), settings)
	return data
}

/**
 * Queue a backup now; the server answers at once.
 *
 * @return {Promise<object>} `{ queued: true }`.
 */
export async function backupNow() {
	const { data } = await axios.post(generateUrl('/apps/hermiq/api/backup/now'))
	return data
}

/**
 * The form an administrator edits, from stored settings.
 *
 * @param {object|null} settings The stored settings.
 * @return {{enabled: boolean, folder: string, hour: string, keep: string, includeSessions: boolean}} The form.
 */
export function backupForm(settings) {
	const source = settings || {}
	return {
		enabled: source.enabled === true,
		folder: typeof source.folder === 'string' && source.folder !== '' ? source.folder : 'Hermiq backups',
		hour: String(Number.isInteger(source.hour) ? source.hour : 2),
		keep: String(Number.isInteger(source.keep) ? source.keep : 14),
		includeSessions: source.includeSessions === true,
	}
}

/**
 * The payload to save from the form, with whole numbers for hour and keep.
 *
 * @param {object} form The form.
 * @return {{enabled: boolean, folder: string, hour: number, keep: number, includeSessions: boolean}} The payload.
 */
export function backupPayload(form) {
	return {
		enabled: form.enabled === true,
		folder: String(form.folder || '').trim(),
		hour: Number.parseInt(form.hour, 10),
		keep: Number.parseInt(form.keep, 10),
		includeSessions: form.includeSessions === true,
	}
}
