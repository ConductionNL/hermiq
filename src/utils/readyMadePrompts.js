// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The prompt library as the record chat offers it (record-chat-ready-made-prompts).
// Plain functions so tests/record-chat-prompts.spec.js runs them in node. The
// record chat reads with fetch, like its other reads, because the agent leaf
// mounts it inside other apps.

import { getRequestToken } from '@nextcloud/auth'
import { generateUrl } from '@nextcloud/router'

/**
 * Read the prompts offered on one record type, in the order the library keeps.
 *
 * The server drops prompts scoped to another type and disabled ones; this keeps
 * its order and drops only entries with no text. No type, or any failure, offers
 * nothing, and the chat works as before.
 *
 * @param {string} scope The record type the chat is open on.
 * @param {function(string, object): Promise<object>} [fetchImpl] The fetch function (the browser's by default).
 * @return {Promise<Array<{id: string, label: string, prompt: string}>>} The prompts to offer.
 * @spec openspec/changes/record-chat-ready-made-prompts/specs/ai-feature-admin-surface/spec.md#requirement-the-record-chat-offers-the-ready-made-prompts-for-its-record-type-req-rcprompt-001
 */
export async function loadReadyMadePrompts(scope, fetchImpl = globalThis.fetch) {
	if (!scope) {
		return []
	}
	try {
		const res = await fetchImpl(
			generateUrl(
				`/apps/hermiq/api/assistant-prompts?scope=${encodeURIComponent(scope)}`,
			),
			{ method: 'GET', headers: { requesttoken: getRequestToken() ?? '' } },
		)
		if (!res.ok) {
			return []
		}
		const body = await res.json()
		const results = Array.isArray(body?.results) ? body.results : []
		return results
			.filter((p) => typeof p?.prompt === 'string' && p.prompt.trim() !== '')
			.map((p) => ({
				id: String(p.id ?? p.uuid ?? p.label),
				label: String(p.label || p.prompt),
				prompt: p.prompt,
			}))
	} catch {
		return []
	}
}

/**
 * Put a picked prompt's text in the message box: as the draft when it is empty,
 * on a new line after what was typed otherwise. The text is not changed.
 *
 * @param {string} draft The message box content.
 * @param {string} text The prompt's text.
 * @return {string} The new message box content.
 * @spec openspec/changes/record-chat-ready-made-prompts/specs/ai-feature-admin-surface/spec.md#requirement-the-record-chat-offers-the-ready-made-prompts-for-its-record-type-req-rcprompt-001
 */
export function withPrompt(draft, text) {
	const before = (draft || '').replace(/\s+$/, '')
	return before ? `${before}\n${text}` : text
}
