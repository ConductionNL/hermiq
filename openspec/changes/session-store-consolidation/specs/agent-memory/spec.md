# Agent Memory Specification

**Status**: in-progress
**Scope**: hermiq
**OpenSpec changes**:
- `session-store-consolidation` — session listing + cross-session recall repointed from the
  write-less `agentsession`/`agentsessionturn` store onto the live `conversation`/`message`
  store; the unreferenced session write path removed (kind: code)

## Purpose

Delta for `openspec/specs/agent-memory/spec.md`. The capability's session half was specified
against `Session`/`SessionTurn` objects whose writers (`MemoryService::startSession()`,
`MemoryService::recordTurn()`) were implemented but never called from anywhere in `lib/` or
`src/`. Both schemas hold 0 rows; the live conversation history lives in `conversation` (180
rows) and `message` (289 rows). This delta moves the recall and listing requirements onto the
store that has the data and removes the write requirements that never had a caller. See
`design.md` for the ADR-003 deviation this implies.

## MODIFIED Requirements

### Requirement: Cross-session recall via OR search
The system MUST make a user's prior `Conversation` objects and their `Message` objects
recallable by an agent through OpenRegister's existing search substrate, without introducing a
separate SQLite FTS5 index. `Message` carries no `agentId` property, so the system MUST resolve
the agent binding through the caller's own `Conversation` objects (which carry `agentId`) and
MUST restrict the message search to those conversations. The system MUST scope every match to
the calling user via the `Conversation.userId` property, and MUST return an empty result
when no user can be resolved from the session. It MUST NOT scope on `@self.owner`: the engine
writes conversations from paths with no session user, so 135 of the reference instance's 184
conversations are owned by `__system__` while all 184 carry `userId`, and owner scoping would
silently hide most of a user's own history.

Previous behavior: recall queried `Session`/`SessionTurn` objects, filtering `SessionTurn` by
its own `agentId` property. Those objects have never been written, so recall has always
returned an empty set.

#### Scenario: Agent looks up relevant history from an earlier session
- GIVEN a user has multiple past `Conversation` objects for an agent, containing `Message` objects
- WHEN the agent's run loop requests relevant prior context for the current prompt
- THEN the system MUST resolve the caller's `Conversation` objects for that agent
- AND the system MUST query OpenRegister search over the `Message` objects of those conversations only
- AND the system MUST NOT return `Message` objects belonging to another user or another organisation

#### Scenario: The caller has no conversations with this agent
- GIVEN a user has never conversed with a given agent
- WHEN recall is requested for that agent
- THEN the system MUST return an empty result
- AND the system MUST NOT issue a message query with an empty conversation-id filter list

#### Scenario: Recall runs with no resolvable user
- GIVEN a run loop invokes recall with no user in the session (a scheduled or background run)
- WHEN the system evaluates the recall request
- THEN the system MUST return an empty result
- AND the system MUST NOT return any user's `Message` objects

### Requirement: Agent self-service memory recall tool
The system MUST expose an MCP tool (`hermiq.recallMemory`) that lets an agent search its own
`Memory`/`UserProfile` entries and the calling user's past conversation `Message`s for a query,
reusing the existing OpenRegister search substrate — the system MUST NOT introduce a second
search index or vector store for this tool. The message half of the result MUST be produced by
the same repointed `MemoryService::recallSessions()` seam the `/api/agents/{agentId}/recall`
endpoint uses, so the tool and the endpoint can never diverge.

Previous behavior: the tool merged `Memory`/`UserProfile` matches with `SessionTurn` matches.
Because no `SessionTurn` has ever been written, the tool has never returned a conversation turn
— a partial, silent failure (the tool appeared to work, but could not recall conversations).

#### Scenario: An agent recalls relevant memory and history
- GIVEN an agent has prior `Memory`/`UserProfile` entries and the calling user has `Message` objects matching a query string
- WHEN the agent calls `hermiq.recallMemory` with that query
- THEN the system MUST return matching `Memory`/`UserProfile` entries and matching `Message` objects in one combined result
- AND the system MUST scope every match to the caller's own tenant and to the caller as owner, never returning another user's entries or messages

#### Scenario: An agent recalls conversation history that exists
- GIVEN the calling user has an earlier conversation with the agent containing a message matching the query
- WHEN the agent calls `hermiq.recallMemory` with that query
- THEN the result MUST contain that message
- AND the message MUST expose its `role` and `content`

## ADDED Requirements

### Requirement: Session listing reads the live conversation store
The system MUST serve `GET /api/agents/{agentId}/sessions` from the caller's own `Conversation`
objects for that agent, filtered by `agentId` and the `userId` property, and MUST return
an empty result when no user can be resolved from the session. It MUST NOT scope on the
`@self.owner` meta-filter: `Conversation` carries `userId` (unlike the retired `Session`, which
did not), and the engine writes conversations from paths with no session user, so owner scoping
would hide the caller's own sessions.

#### Scenario: A user lists their sessions for an agent
- GIVEN a user has existing conversations with an agent
- WHEN the user requests `GET /api/agents/{agentId}/sessions`
- THEN the system MUST return that user's `Conversation` objects for that agent
- AND the system MUST NOT return another user's conversations

## REMOVED Requirements

### Requirement: Session write path (`startSession` / `recordTurn`)
**Reason**: Orphaned capability. `MemoryService::startSession()` (line 287) and
`MemoryService::recordTurn()` (line 315) have zero callers anywhere in `lib/` or `src/`. The
`agentsession`/`agentsessionturn` stores they write to hold 0 rows and always have. The job they
were specified to do — persisting conversation turns — is already performed by the
`conversation`/`message` store, which holds the live data. Retaining both would fork
persistence, the anti-pattern ADR-003 itself argues against ("no duplicate persistence layer;
one write path through `ObjectService`").

**Migration**: None required — both schemas hold 0 rows, so there is no data to move. Conversation
turns are already persisted by the chat engine into `Conversation`/`Message` objects; readers are
repointed there by this change. Consumers that expected `Session`/`SessionTurn` object shapes read
`Conversation`/`Message` instead: `SessionTurn.sessionId` → `Message.conversationId`;
`role`/`content` are unchanged; `Session.startedAt`/`lastActivityAt` and `SessionTurn.createdAt`
have no schema-property equivalent and are read from OpenRegister object `created`/`updated`
metadata. Removal of the schemas themselves from `lib/Settings/hermiq_register.json` is performed
by the dependent change `session-nav-schema-retirement`.

## Non-Functional Requirements

- **Performance:** the repointed recall path MUST NOT issue more than two OpenRegister queries
  per recall (one for the caller's conversations, one for the messages within them), and MUST
  chunk the `conversationId` filter list so it never exceeds OpenRegister's 1000-expression
  `IN ()` cap.
- **Accessibility:** no new UI is introduced; the surviving Chat page MUST retain its existing
  WCAG 2.1 AA posture after the wording change.
- **Internationalization:** Dutch and English MUST be supported (ADR-005). Renamed user-facing
  strings MUST be present in both `l10n/en.json` and `l10n/nl.json`, keyed by the English source
  string.

## Acceptance Criteria

- `GET /api/agents/{agentId}/sessions` returns a non-empty list for a user who has conversations with that agent.
- `hermiq.recallMemory` returns at least one message for a query matching a real conversation of the calling user.
- `grep -rn "startSession\|recordTurn" lib/ src/` returns no results.
- A user cannot recall another user's messages, and a null-user recall returns `[]`.
- `src/views/AgentSessions.vue` no longer exists and no import of it remains.

## Notes

- Related ADRs: ADR-003 (memory/skills as OpenRegister objects — this change deviates from its
  mandated `Session`/`SessionTurn` schema pair and proposes an amendment; see `design.md`),
  ADR-023 (action authorization — recall scopes to the acting user's identity, never stale
  authority), ADR-022 (apps consume OpenRegister abstractions).
- ADR-024 is explicitly NOT related: it owns `ContextAssembler`, a different context path. Do not
  conflate it with the `ContextRetrievalHandler` seam touched by `session-context-performance`.
- No schema slug is renamed by this change. The slug `session` is already owned by `scholiq`
  (schema id 1286); a hermiq `session` schema would be a cross-app slug collision.
