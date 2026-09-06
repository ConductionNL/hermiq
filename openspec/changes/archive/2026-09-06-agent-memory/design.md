# Design: agent-memory

## Context

Ports Hermes' memory (`MEMORY.md`/`USER.md` + SQLite FTS5 session search) to OpenRegister
objects. ADR-001 Option C+: Hermiq owns the management surface and the objects; OR owns
the run loop and the search/vectorization substrate. ADR-003: memory & skills are OR
objects. This change is the Hermiq-owned surface only.

## Decisions

**Four schemas, not one.** `Memory` (agent long-term facts, the `MEMORY.md` port) and
`UserProfile` (facts about a subject user, the `USER.md` port) are separate so recall and
budgets differ per kind. `Session` + `SessionTurn` model conversation history (the FTS5
port) as parent/child objects so a turn is individually searchable and org-scoped.

**Entries are a JSON array with a character budget — a nudge, not a cap.** Appending
always persists. After appending, the service recomputes the total character count of
`entries`; if it exceeds `charBudget`, it sets `needsConsolidation=true`. It never drops
older entries (Hermes silently truncated; this spec explicitly forbids that). A later
`consolidate()` call replaces `entries` with a summarised set and clears the flag. WHO
runs consolidation (an agent summarisation turn vs. an operator action) is the OR
run-loop's business; Hermiq exposes the flag + the consolidate write path and a manual
"Consolidate" affordance in the UI.

**Recall reuses OR search — no second engine.** `recallSessions()` queries `SessionTurn`
objects via OR `ObjectService` search (`_search` over `content`), scoped to the caller's
organisation (RBAC/multitenancy ON, or an explicit `@self.organisation` filter). This is
the same substrate OR's `VectorizationService` builds on; Hermiq does NOT create a
SQLite/FTS5 index. When OR exposes a semantic/vector recall entrypoint, `recallSessions`
delegates to it — the method is the seam.

**Tenant scoping is native.** All four objects are written owner-impersonated through
`ObjectService`, so `owner`/`organisation`/`groups` come from OR (same pattern as
`ScheduleService`). Reads go through the RBAC-on path; a cross-tenant read returns 404
with no content (mirrors the `human-approval-gate` IDOR guard). Memory keys on the OR
organisation UUID, not an NC group (see the tenant-model correction in
`human-approval-gate-enforcement`).

## Integration seam (OR-owned, NOT implemented here)

The agent run loop is OR's `ChatService`/agent core. During a turn it would:
`startSession` → `recordTurn(user)` → recall relevant prior turns via `recallSessions` →
run → `recordTurn(assistant)` → append durable facts via `appendMemoryEntry` and act on
`needsConsolidation`. Hermiq exposes every one of these as a service method + endpoint,
but wiring them INTO an OR agent turn is an OR change (blocked behind the same agent-core
integration as `nc-native-tools`). This change delivers and verifies the management
surface; the run-loop consumption is called out, not stubbed.

## Risks / Trade-offs

- **Char count vs. token budget.** [Hermes budgets by tokens; we budget by characters] →
  Characters are deterministic and dependency-free; the budget is a nudge, not a hard
  limit, so approximation is acceptable. Documented on the schema.
- **Recall without vectorization yet.** [OR semantic recall may not be wired] → Fall back
  to `_search` substring/keyword scoping; the method is the delegation seam for when
  `VectorizationService` exposes a query entrypoint.
- **Unbounded entries array.** [entries[] grows until consolidation] → The budget flag is
  the backpressure signal; the UI surfaces it and the consolidate path bounds it. No
  silent truncation by contract.

## Open Questions

- **Open — consolidation strategy.** Summarisation vs. pruning is left to the OR run loop
  (or a future Hermiq consolidation job); this change only flags + exposes the write path.
- **RESOLVED — no second search engine.** Recall reuses OR `ObjectService` search /
  `VectorizationService`, never SQLite/FTS5.
