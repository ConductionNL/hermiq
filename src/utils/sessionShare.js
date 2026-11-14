/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Read-only session shares (chat-fork-and-share-link): who is offered the
 * share dialog, the link the dialog copies and the key of a share entry.
 *
 * @spec openspec/changes/chat-fork-and-share-link/specs/session-sharing/spec.md#requirement-the-owner-shares-a-session-with-named-users-or-groups-req-sshare-001
 */

/**
 * Whether the share dialog is offered: to the owner of an open session.
 *
 * @param {object|null} session The session row.
 * @return {boolean}
 * @spec openspec/changes/chat-fork-and-share-link/specs/session-sharing/spec.md#requirement-the-owner-shares-a-session-with-named-users-or-groups-req-sshare-001
 */
export function canShareSession(session) {
	return Boolean(session?.uuid) && (session.role ?? 'owner') === 'owner' && !session.deletedAt
}

/**
 * The app path of a shared session's read-only page.
 *
 * @param {string} uuid The session UUID.
 * @return {string} `/apps/hermiq/chat/shared/<uuid>`.
 * @spec openspec/changes/chat-fork-and-share-link/specs/session-sharing/spec.md#requirement-the-owner-can-copy-a-link-to-a-shared-session-req-sshare-004
 */
export function sharedSessionPath(uuid) {
	return `/apps/hermiq/chat/shared/${encodeURIComponent(uuid)}`
}

/**
 * The absolute link the dialog copies, on this instance's address.
 *
 * @param {string} origin The page origin, for example `https://cloud.example`.
 * @param {string} webroot The instance's web root ('' or for example `/nextcloud`).
 * @param {string} uuid The session UUID.
 * @return {string}
 * @spec openspec/changes/chat-fork-and-share-link/specs/session-sharing/spec.md#requirement-the-owner-can-copy-a-link-to-a-shared-session-req-sshare-004
 */
export function sharedSessionLink(origin, webroot, uuid) {
	const root = (webroot || '').replace(/\/+$/, '')
	return `${origin}${root}${sharedSessionPath(uuid)}`
}

/**
 * A stable key for a share entry or a share option.
 *
 * @param {{type: string, id: string}} share The share.
 * @return {string} `user:<uid>` or `group:<gid>`.
 * @spec openspec/changes/chat-fork-and-share-link/specs/session-sharing/spec.md#requirement-the-owner-shares-a-session-with-named-users-or-groups-req-sshare-001
 */
export function shareKey(share) {
	return `${share.type}:${share.id}`
}

/**
 * The search options left once the existing shares and the owner are taken out.
 *
 * @param {Array<{type: string, id: string}>} options The search results.
 * @param {Array<{type: string, id: string}>} shares The current shares.
 * @param {string} ownerUid The owner, never offered.
 * @return {Array<object>}
 * @spec openspec/changes/chat-fork-and-share-link/specs/session-sharing/spec.md#requirement-the-owner-shares-a-session-with-named-users-or-groups-req-sshare-001
 */
export function openShareOptions(options, shares, ownerUid) {
	const taken = new Set(shares.map(shareKey))
	return options.filter((option) => !taken.has(shareKey(option)) && !(option.type === 'user' && option.id === ownerUid))
}

/**
 * The name of a source in a shared turn, for the read-only page.
 *
 * @param {object} source A RAG source.
 * @return {string}
 * @spec openspec/changes/chat-fork-and-share-link/specs/session-sharing/spec.md#requirement-a-share-recipient-can-read-and-do-nothing-else-req-sshare-002
 */
export function sharedSourceName(source) {
	return String(source?.title || source?.name || source?.fileName || source?.objectTitle || '')
}

/**
 * The parts of the "Shared by ... on ..." line, or null without a share.
 *
 * @param {{sharedBy?: string, sharedByDisplayName?: string, sharedAt?: string}|null} share The share that admits the reader.
 * @param {string} [locale] The locale to write the date in.
 * @return {{name: string, date: string}|null}
 * @spec openspec/changes/chat-fork-and-share-link/specs/session-sharing/spec.md#requirement-a-share-recipient-can-read-and-do-nothing-else-req-sshare-002
 */
export function sharedByParts(share, locale) {
	if (!share) {
		return null
	}
	const name = share.sharedByDisplayName || share.sharedBy || ''
	const at = share.sharedAt ? new Date(share.sharedAt) : null
	const date = at && !Number.isNaN(at.getTime())
		? at.toLocaleDateString(locale, { day: 'numeric', month: 'long' })
		: ''
	return { name, date }
}
