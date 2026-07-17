# Tasks: session-store-consolidation

## Implementation Tasks

### Task 1: Repoint `listSessions()` at the conversation store
- **spec_ref**: `openspec/changes/session-store-consolidation/specs/agent-memory/spec.md#requirement-session-listing-reads-the-live-conversation-store`
- **files**: `lib/Service/MemoryService.php`
- **acceptance_criteria**:
  - GIVEN a user has conversations with an agent WHEN `GET /api/agents/{agentId}/sessions` is called THEN their `Conversation` objects are returned (non-empty against the reference instance's 184 conversation rows)
  - GIVEN a second user's conversations exist for the same agent WHEN the first user lists sessions THEN none of the second user's conversations appear
  - GIVEN no user resolves from the session WHEN `listSessions()` is called THEN it returns `[]` and issues no unscoped query
  - Owner scoping uses the `Conversation.userId` PROPERTY, never `_owner` (silently ignored) and never `@self.owner`
- **notes**:
  - CORRECTED from the original criterion, which mandated `@self.owner` and forbade `userId`. That was right for `Session`, which carried no user property — it is wrong for `Conversation`, which does. Live data decides it: all 184 conversations have `userId = admin`, but only 49 have `_owner = admin`; the other 135 are owned by `__system__` because the engine writes them from paths with no session user. `@self.owner` would hide 73% of a user's own sessions, silently — the exact failure mode this change exists to remove. `userId` is still filtered server-side from the resolved session UID and is written by the engine from that session, never from request input, so the cross-user guarantee is unchanged.
- [x] Implement
- [x] Test

### Task 2: Repoint `recallSessions()` at the message store via a conversation join
- **spec_ref**: `openspec/changes/session-store-consolidation/specs/agent-memory/spec.md#requirement-cross-session-recall-via-or-search`
- **files**: `lib/Service/MemoryService.php`
- **acceptance_criteria**:
  - GIVEN `Message` has no `agentId` property WHEN recall runs THEN the agent binding is resolved through the caller's `Conversation` objects (`agentId` + `userId`) and the message search is restricted to those conversation ids
  - GIVEN the caller has zero conversations with the agent WHEN recall runs THEN it returns `[]` immediately and does NOT issue a message query with an empty `conversationId` list
  - GIVEN no user resolves from the session WHEN recall runs THEN it returns `[]` (fail closed — recall searches conversation content)
  - GIVEN the caller has more than 1000 conversations WHEN recall runs THEN the `conversationId` filter list is chunked so it never exceeds OpenRegister's 1000-expression `IN ()` cap
  - No more than two OpenRegister queries are issued per recall (conversations, then messages)
  - GIVEN recall now reads a POPULATED store WHEN a query matches nothing THEN it returns zero turns — a recall assertion, since a repoint that returned rows regardless of the term would look like a fix while being a list
- **notes**:
  - `@self.owner` replaced by `userId` for the same reason as Task 1 — see its note.
- [x] Implement
- [x] Test

### Task 3: Delete the orphaned session writers and correct the MCP docblock
- **spec_ref**: `openspec/changes/session-store-consolidation/specs/agent-memory/spec.md#requirement-session-write-path-startsession-recordturn`
- **files**: `lib/Service/MemoryService.php`, `lib/Mcp/HermiqToolProvider.php`
- **acceptance_criteria**:
  - GIVEN `startSession()` (line 287) and `recordTurn()` (line 315) have zero callers WHEN the change lands THEN `grep -rn "startSession\|recordTurn" lib/ src/` returns no results
  - GIVEN `HermiqToolProvider.php` lines 832-833 describe the `SessionTurn` recall substrate WHEN the change lands THEN the docblock names the conversation/message store instead
  - GIVEN `hermiq.recallMemory` merges memory and conversation matches WHEN an agent queries a phrase from a real prior conversation THEN the tool returns that message — the first time this tool has ever returned a turn
  - GIVEN `Message` has no `createdAt` PROPERTY (the timestamp is OpenRegister `_created` metadata) WHEN a turn is shaped THEN its timestamp comes from the entity, not from `$data['createdAt']`, which would be empty on every row
- [x] Implement
- [x] Test

### Task 4: Delete the AgentSessions view and its API helpers
- **spec_ref**: `openspec/changes/session-store-consolidation/specs/agent-memory/spec.md#requirement-session-listing-reads-the-live-conversation-store`
- **files**: `src/views/AgentSessions.vue`, `src/api/memory.js`
- **acceptance_criteria**:
  - GIVEN `AgentSessions.vue` imports `listSessions`/`recall` from `../api/memory.js` (line 104) WHEN the change lands THEN the view file is deleted and both helpers (`memory.js` lines 77 and 100) are removed
  - GIVEN the view is deleted WHEN the app is built THEN no import of `AgentSessions.vue` remains and the build emits no unresolved-module warning
  - The manifest still references the `AgentSessions` page at this point — removing it is `session-nav-schema-retirement`'s job; this task MUST NOT edit `src/manifest.json`
- [ ] Implement
- [ ] Test

### Task 5: Rename user-facing chat wording to "session"
- **spec_ref**: `openspec/changes/session-store-consolidation/specs/agent-memory/spec.md#requirement-session-listing-reads-the-live-conversation-store`
- **files**: `src/views/Chat.vue`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN `Chat.vue` renders strings such as `New conversation` (line 43), `No conversations yet. Start one to chat with an agent.` (line 78) and `Archive conversation` (line 100) WHEN the change lands THEN the user-facing wording reads "session"
  - GIVEN i18n keys are the ENGLISH source string (ADR-005) WHEN a string is renamed THEN both the key and the value are updated in `l10n/en.json` and the key is updated with a Dutch value in `l10n/nl.json`
  - GIVEN routes are internal WHEN the change lands THEN `/api/chat/*` and `/api/agents/{agentId}/sessions` are unchanged and no schema slug is renamed
- [ ] Implement
- [ ] Test

## Quality checklist

- PHPUnit unit tests for `listSessions()`/`recallSessions()` covering: happy path, wrong-owner exclusion, null-user fail-closed, and the empty-conversation-set guard (`tests/Unit/`)
- Existing tests asserting `startSession`/`recordTurn` or `SessionTurn` recall shape are removed or repointed — a test that still passes against the old store proves nothing
- Newman/Postman coverage for `GET /api/agents/{agentId}/sessions` and `GET /api/agents/{agentId}/recall` now asserting non-empty payloads
- Playwright browser test confirming the Chat page renders the new wording and the Sessions page is gone from the build
- The `hermiq.recallMemory` MCP tool is exercised live against a real conversation — a unit test cannot prove the tool stopped being orphaned
- All tests pass (`composer test`, `composer check:strict`, `newman run`)
- Feature documentation updated in `docs/` for the session wording (ADR-010)
- Dutch (`nl_NL`) and English (`en_US`) translation strings added/renamed (ADR-007)
- `openspec validate session-store-consolidation --strict` passes
