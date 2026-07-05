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
