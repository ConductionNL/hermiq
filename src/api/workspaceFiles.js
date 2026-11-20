// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The chat's Files panel over the caller's session workspace
// (tools-agent-workspace-and-installed-tools REQ-WKSP-003). The server keys
// the workspace by the session's agent, the session and the signed-in user, so
// these calls only ever reach the caller's own files.

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * The base path of one session's workspace routes.
 *
 * @param {string} sessionId The session UUID.
 * @return {string} The path.
 */
function base(sessionId) {
	return `/apps/hermiq/api/sessions/${encodeURIComponent(sessionId)}/workspace`
}

/**
 * The files of the caller's workspace in a session.
 *
 * @param {string} sessionId The session UUID.
 * @return {Promise<{files: Array<{path: string, size: number, modified: number}>, truncated: boolean}>} The listing.
 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-workspace/spec.md#requirement-the-user-sees-the-workspace-and-can-keep-a-file-req-wksp-003
 */
export async function listWorkspaceFiles(sessionId) {
	const response = await axios.get(generateUrl(`${base(sessionId)}/files`))
	return {
		files: Array.isArray(response.data?.files) ? response.data.files : [],
		truncated: response.data?.truncated === true,
	}
}

/**
 * The download link of one workspace file.
 *
 * @param {string} sessionId The session UUID.
 * @param {string} path The file's path inside the workspace.
 * @return {string} The URL.
 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-workspace/spec.md#requirement-the-user-sees-the-workspace-and-can-keep-a-file-req-wksp-003
 */
export function workspaceFileDownloadUrl(sessionId, path) {
	return `${generateUrl(`${base(sessionId)}/download`)}?path=${encodeURIComponent(path)}`
}

/**
 * Save one workspace file to the caller's Files under "Hermiq".
 *
 * @param {string} sessionId The session UUID.
 * @param {string} path The file's path inside the workspace.
 * @return {Promise<{fileId: number, name: string, path: string}>} The saved file.
 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-workspace/spec.md#requirement-the-user-sees-the-workspace-and-can-keep-a-file-req-wksp-003
 */
export async function saveWorkspaceFile(sessionId, path) {
	const response = await axios.post(generateUrl(`${base(sessionId)}/save`), { path })
	return response.data
}
