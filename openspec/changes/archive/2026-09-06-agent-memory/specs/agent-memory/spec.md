# agent-memory (delta)

This change implements the Hermiq-owned management surface of the `agent-memory`
capability. It ADDs the schemas, the char-budget write path, tenant-scoped recall, and
the memory UI. The agent-run-loop consumption remains an OR seam (see design.md).

## ADDED Requirements

### Requirement: Memory objects with char-budget consolidation
The system MUST persist agent memory as `Memory` and `UserProfile` OpenRegister objects
with entries stored as JSON arrays, and MUST enforce a character budget per object,
setting `needsConsolidation` when the budget is exceeded rather than silently truncating.

#### Scenario: Memory entry pushes an agent's Memory object over its char budget
- **GIVEN** an agent's `Memory` object is within 90% of its configured character budget
- **WHEN** a new memory entry is appended during a run
- **THEN** the system MUST persist the new entry
- **AND** the system MUST set `needsConsolidation=true` instead of dropping older entries

### Requirement: Cross-session recall via OR search
The system MUST make prior `Session`/`SessionTurn` objects recallable via OpenRegister's
`ObjectService` search / `VectorizationService`, without a separate SQLite/FTS5 index.

#### Scenario: Agent looks up relevant history from an earlier session
- **GIVEN** a tenant has multiple past `Session` objects containing `SessionTurn` entries
- **WHEN** relevant prior context is requested for the current prompt
- **THEN** the system MUST query OR search scoped to the requesting tenant's organisation
- **AND** the system MUST NOT return `SessionTurn` objects belonging to a different organisation

### Requirement: Per-tenant memory scoping
The system MUST scope all `Memory`, `UserProfile`, `Session`, and `SessionTurn` objects to
the owning organisation via OR's native `organisation`/`owner`/`groups`, so no tenant can
read or write another tenant's agent memory.

#### Scenario: A user from a different organisation requests another tenant's memory
- **GIVEN** a `Memory` object M belongs to organisation A
- **WHEN** a user belonging only to organisation B requests M via the API
- **THEN** the system MUST deny access (404) and MUST NOT leak M's contents

### Requirement: Memory management view
The system MUST provide a nav-reachable Memory view that shows a selected agent's memory
entries, its character-budget usage, its `needsConsolidation` state with a manual
consolidate action, and its session list — consuming the memory endpoints only (no new
write path), with an `inputLabel` on every `NcSelect` (ADR-004).

#### Scenario: An operator reviews an agent's memory
- **GIVEN** an agent with a `Memory` object and one or more `Session` objects
- **WHEN** the operator opens the Memory view and selects that agent
- **THEN** the view MUST render the memory entries, the budget usage, the consolidation
  state, and the sessions, without console errors
