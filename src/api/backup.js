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

/**
 * The backups in the backup folder, newest first, each with its manifest.
 *
 * @return {Promise<Array<{name: string, manifest: object}>>} The backups.
 * @spec openspec/changes/operations-backup-and-restore/specs/backup-restore/spec.md#requirement-a-restore-starts-with-a-preview-of-what-will-change-req-bkup-002
 */
export async function listBackups() {
	const { data } = await axios.get(generateUrl('/apps/hermiq/api/backup/files'))
	return Array.isArray(data?.backups) ? data.backups : []
}

/**
 * What a restore would change; the server writes nothing.
 *
 * A refused point (a backup from a newer register version) answers 409 with the
 * preview and its `refused` sentence, which is returned rather than thrown.
 *
 * @param {object} point The restore point, from `restorePoint()`.
 * @return {Promise<object>} The preview.
 * @spec openspec/changes/operations-backup-and-restore/specs/backup-restore/spec.md#requirement-a-restore-starts-with-a-preview-of-what-will-change-req-bkup-002
 */
export async function previewRestore(point) {
	try {
		const { data } = await axios.post(generateUrl('/apps/hermiq/api/backup/restore/preview'), point)
		return data
	} catch (e) {
		if (e?.response?.status === 409 && e.response.data?.refused) {
			return e.response.data
		}
		throw e
	}
}

/**
 * Queue the restore; the server answers at once.
 *
 * @param {object} point The restore point, from `restorePoint()`.
 * @return {Promise<object>} `{ queued: true, organisation, writes }`.
 * @spec openspec/changes/operations-backup-and-restore/specs/backup-restore/spec.md#requirement-a-restore-stops-the-organisations-agents-and-writes-through-the-audit-trail-req-bkup-003
 */
export async function startRestore(point) {
	const { data } = await axios.post(generateUrl('/apps/hermiq/api/backup/restore'), point)
	return data
}

/**
 * The restore point the server reads, from the dialog's form.
 *
 * A backup sends its file; a moment sends the organisation and the moment as
 * an ISO 8601 time in UTC (the form holds the browser's local time).
 *
 * @param {{kind: string, file: string, organisation: string, at: string, removeCreatedAfter: boolean}} form The form.
 * @return {object} The point.
 * @spec openspec/changes/operations-backup-and-restore/specs/backup-restore/spec.md#requirement-a-restore-starts-with-a-preview-of-what-will-change-req-bkup-002
 */
export function restorePoint(form) {
	const removeCreatedAfter = form.removeCreatedAfter === true
	if (form.kind === 'moment') {
		const at = form.at ? new Date(form.at) : null
		return {
			kind: 'moment',
			organisation: String(form.organisation || '').trim(),
			at: at && !Number.isNaN(at.getTime()) ? at.toISOString() : '',
			removeCreatedAfter,
		}
	}
	return { kind: 'backup', file: String(form.file || ''), removeCreatedAfter }
}

/**
 * One row per schema with something to say, for the preview table.
 *
 * Schemas where every object stays as it is are left out, so the table lists
 * only what will change.
 *
 * @param {object|null} preview The preview.
 * @return {Array<{schema: string, putBack: number, changed: number, createdAfter: number, missing: number, noHistory: number, notRestorable: string[]}>} The rows.
 * @spec openspec/changes/operations-backup-and-restore/specs/backup-restore/spec.md#requirement-a-restore-starts-with-a-preview-of-what-will-change-req-bkup-002
 */
export function previewRows(preview) {
	const schemas = preview?.schemas || {}
	return Object.keys(schemas)
		.sort()
		.map((schema) => {
			const counts = schemas[schema] || {}
			return {
				schema,
				putBack: counts.putBack || 0,
				changed: counts.changed || 0,
				createdAfter: counts.createdAfter || 0,
				missing: counts.missing || 0,
				noHistory: counts.noHistory || 0,
				notRestorable: Array.isArray(counts.notRestorable) ? counts.notRestorable : [],
			}
		})
		.filter((row) => row.putBack + row.changed + row.createdAfter + row.missing + row.noHistory > 0 || row.notRestorable.length > 0)
}

/**
 * The organisations the backups belong to, for the moment-in-time choice.
 *
 * @param {Array<{manifest: object}>} backups The backups.
 * @return {string[]} The organisations, sorted, once each.
 * @spec openspec/changes/operations-backup-and-restore/specs/backup-restore/spec.md#requirement-a-restore-starts-with-a-preview-of-what-will-change-req-bkup-002
 */
export function backupOrganisations(backups) {
	const names = (backups || []).map((backup) => backup?.manifest?.organisation).filter((name) => typeof name === 'string' && name !== '')
	return [...new Set(names)].sort()
}
