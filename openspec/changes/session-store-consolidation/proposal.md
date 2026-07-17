---
kind: code
depends_on: []
chain:
  - session-store-consolidation   # this spec (hermiq, code)
  - session-nav-schema-retirement # hermiq, config
---

# Proposal: session-store-consolidation

## Summary

Hermiq has two parallel conversation stores. The `conversation`/`message` store is live and
carries all real traffic (180 conversations, 289 messages on the reference instance). The
`Session`/`SessionTurn` store is empty — not because a job is off, but because its only two
writers, `MemoryService::startSession()` (line 287) and `MemoryService::recordTurn()` (line 315),
have **zero callers** anywhere in `lib/` or `src/`. Its readers, however, are wired up and
user-reachable: `listSessions()` backs `GET /api/agents/{agentId}/sessions`
(`MemoryController.php:182`) and `recallSessions()` backs both `GET /api/agents/{agentId}/recall`
(`MemoryController.php:252`) **and the `hermiq.recallMemory` MCP tool**
(`HermiqToolProvider.php:857`). Every one of those readers queries an empty store and always has.
This change repoints the readers at the live `conversation`/`message` store, deletes the dead
writers and the session UI that fronted them, and aligns user-facing wording on "session".

## Motivation

This is a textbook **orphaned capability**: implemented, specced, tests green, nothing invokes
it. Three concrete harms exist at HEAD:

1. **The `hermiq.recallMemory` MCP tool has never returned a turn.** An agent asking "what did we
   discuss before?" gets an empty result set, silently. The tool merges `recallEntries()` (Memory
   objects — those do work) with `recallSessions()` (SessionTurn — always empty), so the failure
   is partial and therefore invisible: the tool "works", it just cannot recall conversations.
   Repointing `recallSessions()` at the message store is the fix.
2. **`GET /api/agents/{agentId}/sessions` and the AgentSessions page always render empty.** The
   page ships in the main navigation and is dead on arrival for every user.
3. **Two stores, one concept.** Contributors cannot tell which store to write to. The register
   declares `Conversation`, `Message`, `Session` and `SessionTurn` as four schemas for what
   users experience as one thing.

The honest resolution is not "wire up the writers" — the `conversation`/`message` store already
does that job, and duplicating writes would fork persistence (the exact anti-pattern ADR-003
argues against). It is to make the live store the single store and retire the empty one.

## Affected Projects

- [ ] Project: `hermiq` — `MemoryService` session readers repointed at `conversation`/`message`;
  dead writers `startSession`/`recordTurn` deleted; `src/views/AgentSessions.vue` deleted;
  session functions removed from `src/api/memory.js`; "chat" → "session" wording in `Chat.vue`
  + `l10n/en.json` + `l10n/nl.json`.

## Scope

### In Scope

- Repoint `MemoryService::listSessions()` at the `Conversation` schema, filtered `agentId` +
  `@self.owner` — the same tenant/actor scoping it uses today.
- Repoint `MemoryService::recallSessions()` at the `Message` schema. `Message` carries no
  `agentId` (its properties are `conversationId`, `role`, `content`, `sources`, `context`), so
  the agent binding resolves through the caller's own `Conversation` objects for that agent.
- Delete `MemoryService::startSession()` and `MemoryService::recordTurn()` — zero callers.
- Delete `src/views/AgentSessions.vue` and the `listSessions`/`recall` session helpers in
  `src/api/memory.js`.
- Rename user-facing "chat"/"conversation" wording to "session" in the surviving `Chat.vue`
  page and both catalogues (`l10n/en.json`, `l10n/nl.json`). i18n keys stay the ENGLISH source
  string (ADR-005 / feedback_i18n-keys-english).

### Out of Scope

- **Renaming any schema slug.** The slug `session` is already owned by `scholiq` (schema id
  1286). See design.md — this is a hard blocker, not a preference. "Session" is a UI word only.
- **Route renaming.** `/api/chat/*` and `/api/agents/{agentId}/sessions` keep their paths. Routes
  are an internal/consumer contract; renaming them buys nothing and breaks clients.
- **Removing the `Session`/`SessionTurn` schemas from the register and the `AgentSessions` page
  from the manifest** — that is `session-nav-schema-retirement` (kind: config), which depends on
  this change.
- Data migration. Both retired schemas hold 0 rows; there is nothing to migrate.

## Approach

Two seams, no new abstraction:

1. **Service layer.** `listSessions()`/`recallSessions()` keep their signatures and their
   fail-closed `IUserSession` guard, and swap the schema constant + filter shape underneath.
   `MemoryController` and `HermiqToolProvider` are untouched at the call site — they already
   pass `agentId` and (for recall) `query`, and already `shape()` whatever `ObjectEntity`s come
   back. The recall path gains one lookup (caller's conversations for the agent) before the
   message search.
2. **Frontend.** Delete the page and its API helpers; retitle the surviving one.

## New Dependencies

None.

## Impact

- `lib/Service/MemoryService.php` — two methods deleted, two repointed, schema constants adjusted.
- `lib/Controller/MemoryController.php` — no signature change; its `sessions()` and `recall()`
  endpoints start returning real data for the first time.
- `lib/Mcp/HermiqToolProvider.php` — no code change required; `hermiq.recallMemory` starts
  returning conversation turns. Its docblock (lines 832-833) describes the SessionTurn substrate
  and must be corrected.
- `src/views/AgentSessions.vue` — deleted.
- `src/api/memory.js` — `listSessions()` (line 77) and `recall()` (line 100) removed.
- `src/views/Chat.vue`, `l10n/en.json`, `l10n/nl.json` — wording.
- Tests referencing `startSession`/`recordTurn` or asserting SessionTurn recall shape.

## Cross-Project Dependencies

None. `openregister` is consumed through the existing `ObjectService` seam only — no new
dependency, no OR change required.

## Risks

### Risk 1: Recall scoping regression across users
**Severity:** High — **Mitigation:** `recallSessions()` today scopes to the caller via
`@self.owner` on `SessionTurn` (which carries no owner property) and fails CLOSED when no user
resolves. The repointed path MUST preserve both properties exactly: resolve only the *caller's*
`Conversation` objects, search only messages within those, and return `[]` when
`IUserSession::getUser()` is null. A scheduled run with no resolvable actor recalls nothing —
an intentional loss, per the existing body comment. This is the single highest-value test in the
change.

### Risk 2: Recall result shape changes for the MCP tool
**Severity:** Medium — **Mitigation:** `HermiqToolProvider` merges recall results into one list
and `MemoryController::recall()` maps them through `shape()`. `Message` and `SessionTurn` share
`role` and `content` but differ elsewhere (`conversationId` vs `sessionId`; `Message` has no
`createdAt` property, relying on OR object metadata). Assert the shaped payload in a unit test
so the MCP tool's contract is pinned rather than discovered at runtime.

### Risk 3: ADR-003 deviation
**Severity:** Low — **Mitigation:** ADR-003 mandates a `Session`/`SessionTurn` schema pair. This
change retires them. ADR-003 is `Status: proposed` and its session writers were never
implemented, so no accepted decision is being violated — but the deviation must be recorded and
an amendment proposed. design.md does both.

## Rollback Strategy

Revert the commit. Both retired writers are dead code and both retired schemas hold 0 rows, so
there is no data to restore and no forward migration to undo. The repointed readers are pure
reads. Because `session-nav-schema-retirement` removes the schemas from the register, rolling
back this change after that one has shipped requires reverting both, oldest-last.

## Capabilities

### Modified Capabilities

- `agent-memory`: session listing and cross-session recall move from the write-less
  `agentsession`/`agentsessionturn` store to the live `conversation`/`message` store; the
  unreferenced session write path is removed from the capability's surface.
