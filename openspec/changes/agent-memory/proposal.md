---
kind: code
---

# Proposal: agent-memory

## Why

Hermes gives agents persistent memory (`MEMORY.md` / `USER.md`) plus SQLite FTS5
session search so an agent remembers facts across runs. The `agent-memory` capability
spec (V1, status: idea) ports this to OpenRegister objects so recall is tenant-scoped,
audited, and searchable through OR's existing search/vectorization stack — no second
search engine, no local flat files. This change builds the **Hermiq-owned management
surface** for that memory: the durable schemas, a char-budget-aware write path with a
consolidation nudge (never silent truncation), tenant-scoped reads, a recall endpoint
that reuses OR search, and a UI to view an agent's memory.

Per ADR-001 (Option C+), Hermiq owns the management UX and the memory objects; OR owns
the agent run loop that *consumes* recall. The run-loop wiring (an agent turn calling
recall / appending a turn) is an OR integration seam, called out explicitly and not
implemented here.

## What Changes

- Add four declarative OpenRegister schemas to `lib/Settings/hermiq_register.json`:
  **`Memory`** (agent long-term memory: `agentId`, `entries[]`, `charBudget`,
  `needsConsolidation`), **`UserProfile`** (facts the agent keeps about a subject user:
  `agentId`, `subjectUid`, `entries[]`, `charBudget`, `needsConsolidation`),
  **`Session`** (`agentId`, `title`, `startedAt`, `lastActivityAt`), and **`SessionTurn`**
  (`sessionId`, `agentId`, `role`, `content`, `createdAt`).
- Add `lib/Service/MemoryService.php`: `appendMemoryEntry` / `appendUserProfileEntry`
  (idempotent get-or-create, recompute char count, flag `needsConsolidation` when the
  budget is exceeded — persist the entry, never drop older ones), `consolidate`
  (replace entries with a consolidated set, clear the flag), `getMemory` /
  `getUserProfile`, `recordTurn` (append a `SessionTurn`, touch the `Session`), and
  `recallSessions` (tenant-scoped OR search over `SessionTurn` content). All writes go
  through OR `ObjectService`, owner-impersonated so `owner`/`organisation` are inherited.
- Add `lib/Controller/MemoryController.php` (`@NoAdminRequired`, `@NoCSRFRequired`):
  read an agent's memory / user profiles / sessions, trigger a consolidation, and run a
  recall query — each RBAC-scoped so no tenant reads another tenant's memory.
- Register the routes in `appinfo/routes.php`; add an **Agent Memory** view to
  `src/manifest.json` + `src/registry.js` + `src/customComponents.js`
  (`src/views/AgentMemory.vue`, `src/api/memory.js`) showing the memory entries, the
  char-budget bar, the consolidation flag, and a session list.

## Impact

- Affected specs: `agent-memory` (idea → building; changes link added).
- Affected code: `lib/Settings/hermiq_register.json`, `lib/Service/MemoryService.php`,
  `lib/Controller/MemoryController.php`, `appinfo/routes.php`, `src/manifest.json`,
  `src/registry.js`, `src/customComponents.js`, `src/views/AgentMemory.vue`,
  `src/api/memory.js`, `tests/Unit/Service/MemoryServiceTest.php`.
- Integration seam (NOT implemented — OR-owned): the agent run loop calling
  `recordTurn` / `recallSessions` and honouring `needsConsolidation`. Documented in design.
