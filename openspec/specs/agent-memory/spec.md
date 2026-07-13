# Agent Memory Specification

**Status**: active (management surface live-verified; run-loop consumption is an OR seam)

**Feature tier**: V1

**OpenSpec changes:** `agent-memory` — DONE: Memory/UserProfile/agentsession/agentsessionturn schemas; MemoryService char-budget write path (append flags `needsConsolidation` over budget, never truncates) + tenant-scoped OR-search recall; MemoryController endpoints; AgentMemory UI (Playwright-verified). Run-loop consumption (recordTurn/recall/consolidate during an agent turn) is an OpenRegister seam.

## Purpose

Gives Hermiq agents persistent memory that survives across runs and sessions, ported from Hermes'
`MEMORY.md`/`USER.md` files and SQLite FTS5 session search. Memory, user profile, and session/turn
history are modeled as OpenRegister objects rather than local flat files or SQLite, so recall is
tenant-scoped, audited, and searchable through OR's existing search and vectorization stack instead
of a second search engine.
## Requirements
### Requirement: Memory objects with char-budget consolidation
The system MUST persist agent memory as `Memory` and `UserProfile` OpenRegister objects with entries
stored as JSON arrays, and MUST enforce a character budget per object, prompting a consolidation pass
when the budget is exceeded rather than silently truncating history.

#### Scenario: Memory entry pushes an agent's Memory object over its char budget
- GIVEN an agent's `Memory` object is within 90% of its configured character budget
- WHEN a new memory entry is appended during a run
- THEN the system MUST persist the new entry
- AND the system MUST flag the object for a consolidation nudge instead of silently dropping older entries

### Requirement: Cross-session recall via OR search
The system MUST make prior `Session`/`SessionTurn` objects recallable by an agent through OpenRegister's
existing search and `VectorizationService`, without introducing a separate SQLite FTS5 index.

#### Scenario: Agent looks up relevant history from an earlier session
- GIVEN a tenant has multiple past `Session` objects containing `SessionTurn` entries
- WHEN the agent's run loop requests relevant prior context for the current prompt
- THEN the system MUST query OR search/`VectorizationService` scoped to the requesting tenant's organisation
- AND the system MUST NOT return `SessionTurn` objects belonging to a different organisation

### Requirement: Per-tenant memory scoping
The system MUST scope all `Memory`, `UserProfile`, `Session`, and `SessionTurn` objects to the owning
organisation using OR's native `organisation`/`owner`/`groups` fields, so no tenant can read or write
another tenant's agent memory.

#### Scenario: A user from a different organisation requests another tenant's memory
- GIVEN `Memory` object M belongs to organisation A
- WHEN a user belonging only to organisation B requests M via the API
- THEN the system MUST deny access
- AND the system MUST NOT leak M's contents in the response

### Requirement: Agent self-service memory write tool
The system MUST expose an MCP tool (`hermiq.rememberMemory`) that lets an agent append a
durable fact to its own memory during a run, taking a `content` string and a `scope` of
either `agent` (appended to the agent's `Memory` object) or `user` (appended to the
acting user's `UserProfile` object), reusing `MemoryService::appendMemoryEntry()`/
`appendUserProfileEntry()` unchanged apart from redaction (see the redaction
requirement below).

#### Scenario: An agent remembers a fact about itself
- GIVEN an agent is running inside `ToolLoop` and its `Agent.tools` allowlist includes
  `hermiq.rememberMemory` (or is empty)
- WHEN the agent calls `hermiq.rememberMemory` with `content` and `scope: agent`
- THEN the system MUST append the entry to that agent's `Memory` object via
  `MemoryService::appendMemoryEntry()`
- AND the system MUST flag `needsConsolidation` when the resulting entries exceed the
  object's `charBudget`, without dropping any existing entry

#### Scenario: An agent remembers a fact about the person it serves
- GIVEN an agent is running inside `ToolLoop` on behalf of acting user U
- WHEN the agent calls `hermiq.rememberMemory` with `content` and `scope: user`
- THEN the system MUST append the entry to U's `UserProfile` object for that agent via
  `MemoryService::appendUserProfileEntry()`

### Requirement: Agent self-service memory recall tool
The system MUST expose an MCP tool (`hermiq.recallMemory`) that lets an agent search its
own `Memory`/`UserProfile` entries and past `SessionTurn`s for a query, reusing the
existing OpenRegister search substrate `MemoryService::recallSessions()` already calls
(`ObjectService::findAll()` with a `search` config key) — the system MUST NOT introduce a
second search index or vector store for this tool.

#### Scenario: An agent recalls relevant memory and history
- GIVEN an agent has prior `Memory`/`UserProfile` entries and `SessionTurn`s matching a
  query string
- WHEN the agent calls `hermiq.recallMemory` with that query
- THEN the system MUST return matching `Memory`/`UserProfile` entries and matching
  `SessionTurn`s in one combined result
- AND the system MUST scope every match to the caller's own tenant (organisation), never
  returning another organisation's entries or turns

### Requirement: Agent self-service memory forget tool (soft delete only)
The system MUST expose an MCP tool (`hermiq.forgetMemory`) that lets an agent retract one
memory entry it no longer believes, identified by a stable entry `id`. The system MUST
NEVER hard-delete the entry: it MUST mark the entry with a `deletedAt` timestamp,
excluding it from future `hermiq.recallMemory` results and from `needsConsolidation`
character-budget counting, while the entry remains present in the stored object (and
therefore in OpenRegister's AuditTrail history) for audit purposes. A `forgetMemory` call
whose `id` does not match any entry in the agent's own `Memory` object or the acting
user's own `UserProfile` object MUST return a structured not-found result and MUST NOT
throw.

#### Scenario: An agent forgets a fact it no longer believes
- GIVEN an agent's `Memory` object contains an entry with id `E`
- WHEN the agent calls `hermiq.forgetMemory` with `id: E`
- THEN the system MUST set that entry's `deletedAt` to the current time
- AND the entry MUST NOT be removed from the object's stored `entries` array
- AND subsequent `hermiq.recallMemory` calls MUST NOT return that entry

#### Scenario: An agent tries to forget an id that does not exist
- GIVEN an agent's `Memory` and `UserProfile` objects contain no entry with id `E`
- WHEN the agent calls `hermiq.forgetMemory` with `id: E`
- THEN the system MUST return a structured "not found" result
- AND the system MUST NOT throw or fail the agent's turn

### Requirement: Memory writes are redacted before persist
The system MUST apply `RedactionService`'s secret/PII redaction to memory entry text
before it is persisted via `MemoryService::appendEntry()`, for every caller of that
method — the new `hermiq.rememberMemory` tool AND the existing operator-facing
`MemoryController::addMemory()` endpoint — so a secret or PII value can never enter
memory (and therefore can never enter OpenRegister's append-only AuditTrail) unredacted.

#### Scenario: An agent tries to remember a value containing a secret
- GIVEN a `content` string passed to `hermiq.rememberMemory` contains a recognised
  credential pattern (e.g. an API key)
- WHEN the entry is persisted
- THEN the system MUST mask the recognised credential substring before the entry is
  stored
- AND the surrounding fact text MUST be preserved unmasked

### Requirement: Memory tool governance is fully inherited, not reimplemented
The system MUST NOT introduce a new audit-write mechanism, a new tracing mechanism, or a
new authorization layer for the three memory tools. Every memory tool call MUST be
dispatched through the same `FacadeToolInvoker`/`ToolRegistryFacade` path as every other
Hermiq MCP tool (so it is timed as a `tool` step by the existing `RunTraceCollector`
whenever one is attached), every memory write/forget MUST persist through the unchanged
`ObjectService::saveObject()` write-path (so OpenRegister's existing hash-chained
AuditTrail records it automatically), and an Agent's existing `tools` allowlist MUST be
sufficient to deny an agent any of the three memory tools with zero additional code.

#### Scenario: An org denies an agent write access to memory
- GIVEN an `Agent` whose `tools` array lists other tool ids but omits
  `hermiq.rememberMemory` and `hermiq.forgetMemory`
- WHEN a chat turn assembles the LLM's available functions
- THEN `hermiq.rememberMemory` and `hermiq.forgetMemory` MUST NOT be offered to the model
  for that agent
- AND `hermiq.recallMemory` MUST remain available if listed (or if the allowlist is
  empty)

#### Scenario: A memory tool call is captured in the run trace
- GIVEN a run has a `RunTraceCollector` attached
- WHEN the agent calls any of `hermiq.rememberMemory`, `hermiq.recallMemory`, or
  `hermiq.forgetMemory`
- THEN the call MUST appear as one `tool`-type step in the run's trace timeline, exactly
  as any other tool call does

## User Stories

- As an agent builder, I want my agent to remember facts about the user across separate runs so that I don't have to re-explain context every time.
- As an agent builder, I want old memory to be consolidated instead of silently lost so that important long-term facts survive.
- As a tenant admin, I want my organisation's agent memory isolated from other tenants so that sensitive context never leaks.
- As a developer, I want memory search to reuse OR's existing vectorization instead of a new search engine so that the fleet keeps one search abstraction.

## Acceptance Criteria

- [ ] `Memory`, `UserProfile`, `Session`, `SessionTurn` schemas exist as OpenRegister objects
- [ ] Memory entries are stored as JSON arrays with an enforced character budget
- [ ] Exceeding the budget triggers a consolidation nudge (not silent truncation)
- [ ] Cross-session recall uses OR search/`VectorizationService`, not a bespoke SQLite/FTS5 store
- [ ] All memory objects are scoped by organisation/owner/groups and cross-tenant reads are denied

## Notes

Depends on OpenRegister's `ObjectEntity` (owner/organisation/groups/version) and `VectorizationService`.
Related: ADR-003 (memory & skills as OR objects), ADR-001 (Option C+ boundary — Hermiq owns UX, OR owns
storage/search substrate). Consolidation strategy (summarization vs. pruning) is an open question for
the `planned` spec.
