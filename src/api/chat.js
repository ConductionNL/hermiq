// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Plain (non-Pinia) API helper for the Hermiq chat surface (agent-engine-port
// task 5.1) — the session lifecycle + chat operations behind the Chat page.
//
// STORE-VS-HELPER SPLIT (documented per the agent-engine-port design):
//
//   - Agent CRUD goes through createObjectStore (src/store/store.js →
//     useAgentStore) because an Agent is a plain OR object in the hermiq
//     register — see src/api/agents.js.
//   - EVERYTHING session/chat-shaped lives HERE and hits the
//     /apps/hermiq/api/{chat,sessions} routes (chunk 2's ported
//     controllers), NOT the generic objects path. That includes the
//     session list/read/create/rename: the Hermiq SessionController
//     is not plain object CRUD — it user-scopes the list (the generic
//     org-scoped objects path would leak org-mates' threads), partitions
//     active vs archived on the payload-level archive marker
//     (`metadata.deletedAt`/`metadata.deletedBy` — the hermiq `agentsession`
//     schema has no deletedAt column and ObjectService exposes no restore for
//     its entity-envelope soft delete), generates unique titles via the
//     Engine, whitelists writable fields on update, and enforces per-object
//     ownership guards. Driving sessions through createObjectStore would
//     silently bypass all of that (and archive/restore would simply not work).
//
// SOFT-DELETE SEMANTICS (must match lib/Controller/SessionController.php):
//   - DELETE /api/sessions/{uuid} on an ACTIVE session sets the
//     archive marker (soft delete, restorable).
//   - DELETE /api/sessions/{uuid} on an ARCHIVED session deletes it
//     permanently (OR's two-step destroy mirror).
//   - POST /api/sessions/{uuid}/restore clears the marker.
//   - DELETE /api/sessions/{uuid}/permanent hard-deletes (messages first).
//
// This is deliberately a set of stateless functions (no defineStore) — the
// hard rule is "no custom Pinia stores". axios from @nextcloud/axios attaches
// the CSRF requesttoken automatically; the SSE stream uses fetch() (axios
// cannot consume a streaming body) and attaches the token explicitly.

import { getRequestToken } from '@nextcloud/auth'
import axios from '@nextcloud/axios'
import { generateOcsUrl, generateUrl } from '@nextcloud/router'

/** Hermiq chat API base path (agent-engine-port routes). */
const CHAT_BASE = '/apps/hermiq/api/chat'

// The RAG settings a synchronous turn may carry, in payload order.
const RAG_FIELDS = [
	'includeObjects',
	'includeFiles',
	'numSourcesFiles',
	'numSourcesObjects',
]

// What each non-terminal stream event hands to its handler.
const FRAME_HANDLERS = new Map([
	['token', (handlers, data) => handlers.onToken?.(data.delta || '')],
	['tool_call', (handlers, data) => handlers.onToolCall?.(data)],
	['tool_result', (handlers, data) => handlers.onToolResult?.(data)],
	['heartbeat', (handlers) => handlers.onHeartbeat?.()],
])
/**
 * Hermiq sessions API base path.
 *
 * ⚠️ `/api/conversations/*` still answers, as a deprecated alias onto the same controller
 * methods (session-api-rename), and it is the rollback path for this change. Pointing
 * back at it is a one-line revert.
 */
const SESSIONS_BASE = '/apps/hermiq/api/sessions'

/**
 * List the current user's sessions (server-side user-scoped).
 *
 * @param {object} [options] List options.
 * @param {boolean} [options.archived] True to list archived (soft-deleted) sessions.
 * @param {number} [options.limit] Page size (default 50).
 * @param {number} [options.offset] Page offset (default 0).
 * @return {Promise<{results: Array<object>, total: number}>} The page envelope.
 */
export async function listSessions({
	archived = false,
	limit = 50,
	offset = 0,
} = {}) {
	const response = await axios.get(generateUrl(SESSIONS_BASE), {
		params: { _deleted: archived ? 'true' : 'false', limit, offset },
	})
	return {
		results: Array.isArray(response.data?.results) ? response.data.results : [],
		total: response.data?.total ?? 0,
	}
}

/**
 * Read one session (without its messages; includes messageCount).
 *
 * @param {string} uuid The session UUID.
 * @return {Promise<object>} The session.
 */
export async function getSession(uuid) {
	const response = await axios.get(generateUrl(`${SESSIONS_BASE}/${uuid}`))
	return response.data
}

/**
 * Read a session's messages, oldest first.
 *
 * @param {string} uuid The session UUID.
 * @param {object} [options] Pagination options.
 * @param {number} [options.limit] Page size (default 50).
 * @param {number} [options.offset] Page offset (default 0).
 * @return {Promise<{results: Array<object>, total: number}>} The page envelope.
 */
export async function listMessages(uuid, { limit = 50, offset = 0 } = {}) {
	const response = await axios.get(
		generateUrl(`${SESSIONS_BASE}/${uuid}/messages`),
		{
			params: { limit, offset },
		},
	)
	return {
		results: Array.isArray(response.data?.results) ? response.data.results : [],
		total: response.data?.total ?? 0,
	}
}

/**
 * Create a session bound to an agent. The backend generates a unique
 * title via the Engine when none is provided.
 *
 * @param {string} agentUuid The agent object UUID.
 * @param {string} [title] Optional explicit title.
 * @return {Promise<object>} The created session.
 */
export async function createSession(agentUuid, title) {
	const payload = { agentUuid }
	if (title) {
		payload.title = title
	}
	const response = await axios.post(generateUrl(SESSIONS_BASE), payload)
	return response.data
}

/**
 * Rename a session (only `title`/`metadata` are writable server-side).
 *
 * @param {string} uuid The session UUID.
 * @param {string} title The new title.
 * @return {Promise<object>} The updated session.
 */
export async function renameSession(uuid, title) {
	const response = await axios.patch(generateUrl(`${SESSIONS_BASE}/${uuid}`), {
		title,
	})
	return response.data
}

/**
 * Store the answers to the agent's start fields on a session, once, before its
 * first message (agents-instruction-variables). A refused answer comes back as
 * a 422 whose `problems` name the reason per field key.
 *
 * @param {string} uuid The session UUID.
 * @param {object} values The answers per field key.
 * @return {Promise<{uuid: string, startValues: object}>} The stored answers.
 * @spec openspec/specs/agent-management-ui/spec.md#requirement-an-agent-can-ask-for-fields-before-a-conversation-starts-req-agvar-002
 */
export async function answerStartFields(uuid, values) {
	const response = await axios.put(
		generateUrl(`${SESSIONS_BASE}/${encodeURIComponent(uuid)}/start-values`),
		{ values },
	)
	return response.data
}

/**
 * The colleagues invited into a session (owner only).
 *
 * @param {string} uuid The session UUID.
 * @return {Promise<Array<{uid: string, displayName: string}>>} The participants.
 * @spec openspec/specs/session-participants/spec.md#requirement-the-owner-invites-colleagues-into-a-session-req-spart-001
 */
export async function listParticipants(uuid) {
	const response = await axios.get(
		generateUrl(`${SESSIONS_BASE}/${uuid}/participants`),
	)
	return response.data.results || []
}

/**
 * Invite a colleague into a session (owner only).
 *
 * @param {string} uuid The session UUID.
 * @param {string} uid The colleague's user id.
 * @return {Promise<Array<{uid: string, displayName: string}>>} The participants after the change.
 * @spec openspec/specs/session-participants/spec.md#requirement-the-owner-invites-colleagues-into-a-session-req-spart-001
 */
export async function addParticipant(uuid, uid) {
	const response = await axios.post(
		generateUrl(`${SESSIONS_BASE}/${uuid}/participants`),
		{ uid },
	)
	return response.data.results || []
}

/**
 * Take a colleague off a session (owner only).
 *
 * @param {string} uuid The session UUID.
 * @param {string} uid The colleague's user id.
 * @return {Promise<Array<{uid: string, displayName: string}>>} The participants after the change.
 * @spec openspec/specs/session-participants/spec.md#requirement-the-owner-invites-colleagues-into-a-session-req-spart-001
 */
export async function removeParticipant(uuid, uid) {
	const response = await axios.delete(
		generateUrl(
			`${SESSIONS_BASE}/${uuid}/participants/${encodeURIComponent(uid)}`,
		),
	)
	return response.data.results || []
}

/**
 * Search the instance's groups by name, for the agent form's sharing choice.
 *
 * @param {string} search The typed text.
 * @return {Promise<Array<{gid: string, displayName: string}>>} Matching groups.
 * @spec openspec/changes/agents-sharing-and-catalog-columns/specs/agent-management-ui/spec.md#requirement-an-agent-owner-decides-who-can-use-the-agent-req-agshare-001
 */
export async function searchGroups(search) {
	const response = await axios.get(generateOcsUrl('core/autocomplete/get'), {
		params: { search, itemType: '', itemId: '', shareTypes: [1], limit: 10 },
	})
	return (response.data?.ocs?.data || []).map((group) => ({
		gid: group.id,
		displayName: group.label || group.id,
	}))
}

/**
 * Search the instance's users by name, for the invite dialog.
 *
 * @param {string} search The typed text.
 * @return {Promise<Array<{uid: string, displayName: string}>>} Matching users.
 * @spec openspec/specs/session-participants/spec.md#requirement-the-owner-invites-colleagues-into-a-session-req-spart-001
 */
export async function searchUsers(search) {
	const response = await axios.get(generateOcsUrl('core/autocomplete/get'), {
		params: { search, itemType: '', itemId: '', shareTypes: [0], limit: 10 },
	})
	return (response.data?.ocs?.data || []).map((user) => ({
		uid: user.id,
		displayName: user.label || user.id,
	}))
}

/**
 * Archive a session (soft delete via the payload archive marker).
 * NOTE: calling this on an already-archived session permanently deletes
 * it (the backend's two-step destroy) — the Chat page only calls it on
 * active sessions and uses deleteSessionPermanent() for hard deletes.
 *
 * @param {string} uuid The session UUID.
 * @return {Promise<object>} The confirmation envelope.
 */
export async function archiveSession(uuid) {
	const response = await axios.delete(generateUrl(`${SESSIONS_BASE}/${uuid}`))
	return response.data
}

/**
 * Restore an archived session (clears the archive marker).
 *
 * @param {string} uuid The session UUID.
 * @return {Promise<object>} The restored session.
 */
export async function restoreSession(uuid) {
	const response = await axios.post(
		generateUrl(`${SESSIONS_BASE}/${uuid}/restore`),
	)
	return response.data
}

/**
 * Permanently delete a session (turns first, then the thread).
 *
 * @param {string} uuid The session UUID.
 * @return {Promise<object>} The confirmation envelope.
 */
export async function deleteSessionPermanent(uuid) {
	const response = await axios.delete(
		generateUrl(`${SESSIONS_BASE}/${uuid}/permanent`),
	)
	return response.data
}

/**
 * Send a chat message synchronously (POST /api/chat/send) — the ADR-034
 * fallback path, and the primary path when per-session view/tool/RAG
 * settings are customised (the stream endpoint does not accept them).
 *
 * @param {object} options Send options.
 * @param {string} options.message The user message text.
 * @param {string} [options.sessionUuid] Existing session UUID.
 * @param {string} [options.agentUuid] Agent UUID (only when creating a new session).
 * @param {Array<string>} [options.views] Selected view UUIDs for RAG context.
 * @param {Array<string>} [options.tools] Selected tool ids for this turn.
 * @param {object} [options.ragSettings] RAG settings (includeObjects, includeFiles,
 *   numSourcesFiles, numSourcesObjects).
 * @param {Array<{fileId: number, origin: string}>} [options.attachments] Files on
 *   this turn, by file id; the server reads each one as the sender.
 * @return {Promise<object>} The engine result ({message, messageId, sources, usage,
 *   session}).
 */
export async function sendChatMessage({
	message,
	sessionUuid,
	agentUuid,
	views,
	tools,
	ragSettings,
	attachments,
}) {
	// wire key: ChatController reads getParam('conversation')
	const payload = {
		...turnBody(
			{ message, sessionUuid, agentUuid, attachments },
			'conversation',
		),
		...nonEmptyLists({ views, tools }),
		...ragFields(ragSettings),
	}
	const response = await axios.post(generateUrl(`${CHAT_BASE}/send`), payload)
	return response.data
}

/**
 * The part of a turn both send paths carry: the message, the files on it, and
 * the session it continues or, without one, the agent that starts a new one.
 *
 * @param {object} turn The turn.
 * @param {string} turn.message The user message text.
 * @param {string} [turn.sessionUuid] Existing session UUID.
 * @param {string} [turn.agentUuid] Agent UUID (only when no session exists yet).
 * @param {Array<{fileId: number, origin: string}>} [turn.attachments] Files on this turn.
 * @param {string} sessionKey The wire key the endpoint reads the session from.
 * @return {object} The request body.
 */
function turnBody({ message, sessionUuid, agentUuid, attachments }, sessionKey) {
	const body = { message }
	// Files on this turn, by file id (chat-attachments-and-images); omitted when none.
	if (Array.isArray(attachments) && attachments.length > 0) {
		body.attachments = attachments
	}
	if (sessionUuid) {
		body[sessionKey] = sessionUuid
	} else if (agentUuid) {
		body.agentUuid = agentUuid
	}
	return body
}

/**
 * The given lists that hold at least one entry.
 *
 * @param {object} lists Named lists, e.g. `{views, tools}`.
 * @return {object} Only the non-empty lists, in the given order.
 */
function nonEmptyLists(lists) {
	return Object.fromEntries(
		Object.entries(lists).filter(
			([, list]) => Array.isArray(list) && list.length > 0,
		),
	)
}

/**
 * The RAG settings a turn sets, as top-level payload fields.
 *
 * @param {object} [ragSettings] `{includeObjects, includeFiles, numSourcesFiles, numSourcesObjects}`.
 * @return {object} The settings that are not undefined.
 */
function ragFields(ragSettings) {
	if (!ragSettings || typeof ragSettings !== 'object') {
		return {}
	}
	return Object.fromEntries(
		RAG_FIELDS.map((key) => [key, ragSettings[key]]).filter(
			([, value]) => value !== undefined,
		),
	)
}

/**
 * Upload one file from the device into the person's own Files
 * (POST /api/chat/attachments). A refused type or size answers 400 with
 * `{ error }` in the person's language.
 *
 * @param {File} file The file the person chose.
 * @return {Promise<{path: string, name: string, fileId: number, mimeType: string, size: number}>}
 * @spec openspec/changes/chat-attachments-and-images/specs/chat-attachments/spec.md#requirement-a-person-can-attach-a-file-they-already-have-in-files-req-catt-002
 */
export async function uploadChatAttachment(file) {
	const form = new FormData()
	form.append('file', file)
	const response = await axios.post(generateUrl(`${CHAT_BASE}/attachments`), form)
	return response.data
}

/**
 * Whether the Chat page shows "Create an image": the feature is enabled and a
 * text-to-image provider is installed (chat-attachments-and-images D7).
 *
 * @return {Promise<boolean>} True when the action may be shown.
 * @spec openspec/changes/chat-attachments-and-images/specs/image-generation/spec.md#scenario-no-provider-no-button
 */
export async function getImageAvailability() {
	const response = await axios.get(generateUrl(`${CHAT_BASE}/images/availability`))
	return response.data?.available === true
}

/**
 * Create an image from a description; the description and the image are
 * stored as two turns of the session.
 *
 * @param {string} sessionId The session UUID.
 * @param {string} prompt What the image shows.
 * @return {Promise<{userTurn: object, assistantTurn: object}>} The two turns.
 * @spec openspec/changes/chat-attachments-and-images/specs/image-generation/spec.md#requirement-a-created-image-shows-in-the-answer-req-cimg-004
 */
export async function createChatImage(sessionId, prompt) {
	const response = await axios.post(generateUrl(`${CHAT_BASE}/images`), {
		sessionId,
		prompt,
	})
	return response.data
}

/**
 * Error thrown by streamChatMessage(). `transport === true` means the SSE
 * handshake/connection itself failed (HTTP error, network drop, unparsable
 * stream) and the caller SHOULD fall back to sendChatMessage() per the
 * ADR-034 fallback ladder. `transport === false` means the engine emitted a
 * terminal `error` event — the turn failed server-side and retrying over
 * POST /send would duplicate the user message, so no fallback.
 */
export class ChatStreamError extends Error {
	/**
	 * @param {string} message Human-readable error message.
	 * @param {object} [options] Error options.
	 * @param {boolean} [options.transport] True for handshake/connection failures.
	 * @param {string} [options.code] Backend error code (e.g. 'forbidden').
	 */
	constructor(message, { transport = false, code = '' } = {}) {
		super(message)
		this.name = 'ChatStreamError'
		this.transport = transport
		this.code = code
	}
}

/**
 * Parse one SSE frame ("event: x\ndata: {...}") into { event, data }.
 *
 * @param {string} frame The raw frame text (no trailing blank line).
 * @return {{event: string, data: object}|null} Parsed frame, or null when
 *   the frame carries no data line.
 */
function parseSseFrame(frame) {
	let event = 'message'
	const dataLines = []
	for (const line of frame.split(/\r?\n/)) {
		if (line.startsWith('event:')) {
			event = line.slice(6).trim()
		} else if (line.startsWith('data:')) {
			dataLines.push(line.slice(5).trim())
		}
	}
	if (dataLines.length === 0) {
		return null
	}
	try {
		return { event, data: JSON.parse(dataLines.join('\n')) }
	} catch {
		return null
	}
}

/**
 * Send a chat message over the SSE streaming endpoint
 * (POST /apps/hermiq/api/chat/stream) and consume the six-event envelope
 * (hydra ADR-034 Decision 6): `token`, `tool_call`, `tool_result`,
 * `heartbeat`, and exactly one terminal `final` or `error`.
 *
 * Uses fetch() with a ReadableStream reader (POST + JSON body + requesttoken
 * header — EventSource cannot POST). Non-streaming providers degrade to zero
 * `token` events plus one `final` carrying the full text; the handlers make
 * that transparent to the caller.
 *
 * @param {object} options Stream options.
 * @param {string} options.message The user message text.
 * @param {string} [options.sessionUuid] Existing session UUID.
 * @param {string} [options.agentUuid] Agent UUID (only when no session exists yet).
 * @param {Array<{fileId: number, origin: string}>} [options.attachments] Files on
 *   this turn, by file id; the server reads each one as the sender.
 * @param {object} [handlers] Event handlers.
 * @param {Function} [handlers.onToken] (delta: string) — incremental assistant text.
 * @param {Function} [handlers.onToolCall] (payload: object) — a tool invocation started.
 * @param {Function} [handlers.onToolResult] (payload: object) — a tool invocation finished.
 * @param {Function} [handlers.onHeartbeat] () — liveness signal (keep the UI alive).
 * @return {Promise<object>} Resolves with the `final` payload
 *   ({messageId, sessionUuid, fullText, context, attachmentNotices}).
 * @throws {ChatStreamError} transport=true on handshake/connection failure
 *   (caller falls back to sendChatMessage()); transport=false on a terminal
 *   `error` event (no fallback — the turn failed server-side).
 */
export async function streamChatMessage(
	{ message, sessionUuid, agentUuid, attachments },
	handlers = {},
) {
	// wire key: the stream endpoint reads conversationUuid
	const body = turnBody(
		{ message, sessionUuid, agentUuid, attachments },
		'conversationUuid',
	)
	const response = await openStream(body)
	const outcome = { final: null, error: null }
	const handleFrame = (rawFrame) => dispatchFrame(rawFrame, { handlers, outcome })
	const rest = await readFrames(response.body.getReader(), handleFrame)

	// Flush a trailing frame that arrived without the final blank line.
	if (rest.trim() !== '') {
		handleFrame(rest)
	}
	return settledPayload(outcome)
}

/**
 * POST the turn to the stream endpoint and return the open response.
 *
 * @param {object} body The request body.
 * @return {Promise<Response>} The response, OK and with a body.
 * @throws {ChatStreamError} transport=true when the connection or the handshake fails.
 */
async function openStream(body) {
	let response
	try {
		response = await fetch(generateUrl(`${CHAT_BASE}/stream`), {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				Accept: 'text/event-stream',
				requesttoken: getRequestToken() ?? '',
			},
			body: JSON.stringify(body),
		})
	} catch (e) {
		throw new ChatStreamError(e?.message || 'Stream connection failed', {
			transport: true,
		})
	}

	assertStreamOpen(response)
	return response
}

/**
 * Refuse a handshake that did not open a readable stream.
 *
 * @param {Response} response The stream endpoint's response.
 * @return {void}
 * @throws {ChatStreamError} transport=true on a non-OK status or a missing body.
 */
function assertStreamOpen(response) {
	if (!response.ok || !response.body) {
		throw new ChatStreamError(
			`Stream endpoint returned HTTP ${response.status}`,
			{ transport: true },
		)
	}
}

/**
 * Hand one raw frame to its handler, or record it as the terminal outcome.
 *
 * @param {string} rawFrame The frame text.
 * @param {object} stream The stream's state.
 * @param {object} stream.handlers The caller's event handlers.
 * @param {{final: object|null, error: object|null}} stream.outcome Takes a terminal payload.
 * @return {void}
 */
function dispatchFrame(rawFrame, { handlers, outcome }) {
	const frame = parseSseFrame(rawFrame)
	if (!frame) {
		return
	}
	if (frame.event === 'final' || frame.event === 'error') {
		outcome[frame.event] = frame.data
		return
	}
	FRAME_HANDLERS.get(frame.event)?.(handlers, frame.data)
}

/**
 * Read the stream to its end, handing every complete frame over as it arrives.
 *
 * @param {ReadableStreamDefaultReader} reader The body reader.
 * @param {function(string): void} handleFrame Called with each complete raw frame.
 * @return {Promise<string>} What is left after the last blank line.
 * @throws {ChatStreamError} transport=true when the connection drops.
 */
async function readFrames(reader, handleFrame) {
	const decoder = new TextDecoder()
	let buffer = ''
	try {
		for (;;) {
			const { done, value } = await reader.read()
			if (done) {
				return buffer
			}
			buffer += decoder.decode(value, { stream: true })
			buffer = handleCompleteFrames(buffer, handleFrame)
		}
	} catch (e) {
		throw new ChatStreamError(e?.message || 'Stream connection dropped', {
			transport: true,
		})
	}
}

/**
 * Hand every frame the buffer completes to the handler.
 *
 * @param {string} buffer The text read so far.
 * @param {function(string): void} handleFrame Called with each complete raw frame.
 * @return {string} The incomplete rest.
 */
function handleCompleteFrames(buffer, handleFrame) {
	let rest = buffer
	let boundary = rest.search(/\r?\n\r?\n/)
	while (boundary !== -1) {
		const rawFrame = rest.slice(0, boundary)
		rest = rest.slice(boundary).replace(/^\r?\n\r?\n/, '')
		handleFrame(rawFrame)
		boundary = rest.search(/\r?\n\r?\n/)
	}
	return rest
}

/**
 * The `final` payload, or the error the stream ended with.
 *
 * @param {{final: object|null, error: object|null}} outcome The terminal events seen.
 * @return {object} The `final` payload.
 * @throws {ChatStreamError} transport=false on an `error` event; transport=true
 *   when the stream ended without a terminal event, so the caller retries over
 *   POST /send (ADR-034 ladder).
 */
function settledPayload({ final, error }) {
	if (error !== null) {
		throw new ChatStreamError(error.message || 'The chat turn failed', {
			transport: false,
			code: error.code || '',
		})
	}

	if (final === null) {
		throw new ChatStreamError('Stream ended without a terminal event', {
			transport: true,
		})
	}

	return final
}

/**
 * Record thumbs up/down feedback (optionally with a comment) on a message.
 *
 * @param {string} sessionUuid The session UUID.
 * @param {string} messageId The message UUID.
 * @param {object} options Feedback options.
 * @param {string} options.type 'positive' or 'negative'.
 * @param {string} [options.comment] Optional free-text elaboration.
 * @return {Promise<object>} The stored feedback.
 */
export async function sendMessageFeedback(
	sessionUuid,
	messageId,
	{ type, comment },
) {
	const payload = { type }
	if (comment) {
		payload.comment = comment
	}
	const response = await axios.post(
		generateUrl(
			`${SESSIONS_BASE}/${sessionUuid}/messages/${messageId}/feedback`,
		),
		payload,
	)
	return response.data
}

/**
 * Read a session's newest standing goal (agents-standing-goal).
 *
 * @param {string} sessionUuid The session UUID.
 * @return {Promise<object|null>} The goal, or null when the session has none.
 * @spec openspec/changes/agents-standing-goal/specs/agent-schedule/spec.md#requirement-a-person-can-give-an-agent-a-standing-goal-with-a-check-req-aggoal-001
 */
export async function getSessionGoal(sessionUuid) {
	const response = await axios.get(
		generateUrl(`${SESSIONS_BASE}/${sessionUuid}/goal`),
	)
	return response.data?.goal ?? null
}

/**
 * Set a standing goal on a session.
 *
 * @param {string} sessionUuid The session UUID.
 * @param {object} goal The goal: statement, check, intervalMinutes, maxTurns.
 * @return {Promise<object>} The stored goal.
 * @spec openspec/changes/agents-standing-goal/specs/agent-schedule/spec.md#requirement-a-person-can-give-an-agent-a-standing-goal-with-a-check-req-aggoal-001
 */
export async function setSessionGoal(sessionUuid, goal) {
	const response = await axios.post(
		generateUrl(`${SESSIONS_BASE}/${sessionUuid}/goal`),
		goal,
	)
	return response.data
}

/**
 * Stop a standing goal; only who set it and the agent's owner may.
 *
 * @param {string} goalId The goal UUID.
 * @return {Promise<object>} The stopped goal.
 * @spec openspec/changes/agents-standing-goal/specs/agent-schedule/spec.md#requirement-a-goal-can-be-stopped-by-its-owner-or-the-agent-owner-req-aggoal-003
 */
export async function stopGoal(goalId) {
	const response = await axios.post(
		generateUrl(`/apps/hermiq/api/goals/${goalId}/stop`),
	)
	return response.data
}

/**
 * Start an agent's pooled CLI process for a session before its first question
 * (warm-start-and-cli-step-visibility). Called when a session opens or starts
 * with an agent, so the process start happens while the person is still typing.
 *
 * Never throws: a warm-up is an optimisation the next turn does not depend on,
 * so any failure, including a refused or unreachable request, reads as "not
 * warmed" and is not shown.
 *
 * @param {string} agentUuid The session's agent.
 * @param {string} sessionUuid The session.
 * @return {Promise<boolean>} Whether a process was warmed.
 * @spec openspec/changes/warm-start-and-cli-step-visibility/specs/warm-start-and-cli-step-visibility/spec.md#requirement-the-cli-process-is-warmed-before-the-first-question-not-by-it
 */
export async function warmChat(agentUuid, sessionUuid) {
	if (!agentUuid || !sessionUuid) {
		return false
	}
	try {
		// wire key: ChatController::warm() reads getParam('conversation')
		const response = await axios.post(generateUrl(`${CHAT_BASE}/warm`), {
			agentUuid,
			conversation: sessionUuid,
		})
		return response?.data?.warmed === true
	} catch {
		return false
	}
}
