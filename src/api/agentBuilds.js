// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// An agent's published builds (tools-agent-workspace-and-installed-tools,
// REQ-BUILD-001): the builds with the pinned one, and publishing a build from
// the caller's own workspace in one of their conversations.

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * The builds route of one agent.
 *
 * @param {string} agentId The agent UUID.
 * @return {string} The URL.
 */
function buildsUrl(agentId) {
	return generateUrl(`/apps/hermiq/api/agents/${encodeURIComponent(agentId)}/builds`)
}

/**
 * The agent's builds, newest first, with the pin and whether it changed since.
 *
 * @param {string} agentId The agent UUID.
 * @return {Promise<{builds: Array<object>, pinnedBuildId: ?string, pinnedBuildNumber: ?number, unpublishedChanges: boolean, canPublish: boolean}>} The overview.
 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-versioning/spec.md#scenario-edits-after-publishing-are-visible-to-the-owner
 */
export async function listAgentBuilds(agentId) {
	const response = await axios.get(buildsUrl(agentId))
	const data = response.data || {}
	return {
		builds: Array.isArray(data.builds) ? data.builds : [],
		pinnedBuildId: data.pinnedBuildId ?? null,
		pinnedBuildNumber: data.pinnedBuildNumber ?? null,
		unpublishedChanges: data.unpublishedChanges === true,
		canPublish: data.canPublish === true,
	}
}

/**
 * Publish a build from the caller's workspace in one conversation.
 *
 * @param {string} agentId The agent UUID.
 * @param {string} sessionId The caller's conversation.
 * @param {string} note The note on the build.
 * @return {Promise<object>} The published build.
 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-versioning/spec.md#requirement-an-agent-owner-can-publish-a-build-that-pins-files-and-installed-tools-req-build-001
 */
export async function publishAgentBuild(agentId, sessionId, note) {
	const response = await axios.post(buildsUrl(agentId), { sessionId, note })
	return response.data
}
