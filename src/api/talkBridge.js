// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Thin fetch helpers for the admin Talk-bridge panel (talk-chat-bridge).
// Backs src/components/settings/TalkBridgeSettings.vue.
//
// GET reports the bridge's EFFECTIVE state — is the bot installed, which rooms
// are bound, is the bot actually enabled in each of them, and which hand-off
// path turns take. It never fails on a Talk-less instance: it reports
// `talkAvailable: false` and an empty room list so the panel still renders.

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * Read the Talk bridge's effective configuration.
 *
 * @return {Promise<object>} `{ talkAvailable, botUrl, botInstalled, groupingEnabled, handOffPath, rooms }`.
 */
export async function getTalkBridgeStatus() {
	const { data } = await axios.get(
		generateUrl('/apps/hermiq/api/settings/talk-bridge'),
	)
	return data
}

/**
 * Bind a Talk room to an agent, or unbind it.
 *
 * @param {string} token   The Talk room token.
 * @param {string} agentId The agent uuid, or '' to remove the binding.
 * @return {Promise<object>} The updated bridge status.
 */
export async function bindTalkRoom(token, agentId) {
	const { data } = await axios.put(
		generateUrl('/apps/hermiq/api/settings/talk-bridge/room'),
		{ token, agentId },
	)
	return data
}
