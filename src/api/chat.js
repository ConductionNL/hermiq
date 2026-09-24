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
import { generateUrl } from '@nextcloud/router'

/** Hermiq chat API base path (agent-engine-port routes). */
const CHAT_BASE = '/apps/hermiq/api/chat'
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
}) {
	const payload = { message }
	if (sessionUuid) {
		payload.conversation = sessionUuid // wire key: ChatController reads getParam('conversation')
	} else if (agentUuid) {
		payload.agentUuid = agentUuid
	}
	if (Array.isArray(views) && views.length > 0) {
		payload.views = views
	}
	if (Array.isArray(tools) && tools.length > 0) {
		payload.tools = tools
	}
	if (ragSettings && typeof ragSettings === 'object') {
		const { includeObjects, includeFiles, numSourcesFiles, numSourcesObjects } =
			ragSettings
		if (includeObjects !== undefined) {
			payload.includeObjects = includeObjects
		}
		if (includeFiles !== undefined) {
			payload.includeFiles = includeFiles
		}
		if (numSourcesFiles !== undefined) {
			payload.numSourcesFiles = numSourcesFiles
		}
		if (numSourcesObjects !== undefined) {
			payload.numSourcesObjects = numSourcesObjects
		}
	}
	const response = await axios.post(generateUrl(`${CHAT_BASE}/send`), payload)
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
 * @param {object} [handlers] Event handlers.
 * @param {(delta: string) => void} [handlers.onToken] Incremental assistant text.
 * @param {(payload: object) => void} [handlers.onToolCall] A tool invocation started.
 * @param {(payload: object) => void} [handlers.onToolResult] A tool invocation finished.
 * @param {() => void} [handlers.onHeartbeat] Liveness signal, keeps the UI alive.
 * @return {Promise<object>} Resolves with the `final` payload
 *   ({messageId, sessionUuid, fullText, context}).
 * @throws {ChatStreamError} transport=true on handshake/connection failure
 *   (caller falls back to sendChatMessage()); transport=false on a terminal
 *   `error` event (no fallback — the turn failed server-side).
 */
export async function streamChatMessage(
	{ message, sessionUuid, agentUuid },
	handlers = {},
) {
	const body = { message }
	if (sessionUuid) {
		body.conversationUuid = sessionUuid // wire key: the stream endpoint reads conversationUuid
	} else if (agentUuid) {
		body.agentUuid = agentUuid
	}

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

	if (!response.ok || !response.body) {
		throw new ChatStreamError(
			`Stream endpoint returned HTTP ${response.status}`,
			{ transport: true },
		)
	}

	const reader = response.body.getReader()
	const decoder = new TextDecoder()
	let buffer = ''
	let finalPayload = null
	let errorPayload = null

	const handleFrame = (rawFrame) => {
		const frame = parseSseFrame(rawFrame)
		if (!frame) {
			return
		}
		switch (frame.event) {
			case 'token':
				handlers.onToken?.(frame.data.delta || '')
				break
			case 'tool_call':
				handlers.onToolCall?.(frame.data)
				break
			case 'tool_result':
				handlers.onToolResult?.(frame.data)
				break
			case 'heartbeat':
				handlers.onHeartbeat?.()
				break
			case 'final':
				finalPayload = frame.data
				break
			case 'error':
				errorPayload = frame.data
				break
		}
	}

	try {
		for (;;) {
			const { done, value } = await reader.read()
			if (done) {
				break
			}
			buffer += decoder.decode(value, { stream: true })
			let boundary = buffer.search(/\r?\n\r?\n/)
			while (boundary !== -1) {
				const rawFrame = buffer.slice(0, boundary)
				buffer = buffer.slice(boundary).replace(/^\r?\n\r?\n/, '')
				handleFrame(rawFrame)
				boundary = buffer.search(/\r?\n\r?\n/)
			}
		}
	} catch (e) {
		throw new ChatStreamError(e?.message || 'Stream connection dropped', {
			transport: true,
		})
	}

	// Flush a trailing frame that arrived without the final blank line.
	if (buffer.trim() !== '') {
		handleFrame(buffer)
	}

	if (errorPayload !== null) {
		throw new ChatStreamError(errorPayload.message || 'The chat turn failed', {
			transport: false,
			code: errorPayload.code || '',
		})
	}

	if (finalPayload === null) {
		// The stream ended without a terminal event — treat as a transport
		// failure so the caller retries over POST /send (ADR-034 ladder).
		throw new ChatStreamError('Stream ended without a terminal event', {
			transport: true,
		})
	}

	return finalPayload
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
