# Proposal: agent-memory-tools

## Summary
Give Hermiq agents three self-service MCP tools — `rememberMemory`, `recallMemory`,
`forgetMemory` — so an agent can deliberately decide, mid-run, to write a durable fact,
search its own memory, or retract a fact it no longer believes, instead of memory only
ever being written by app-level summarisation/curation code. This is the MemGPT/Letta
"self-editing memory" insight (agent-directed memory tool calls, not host-directed), built
entirely on Hermiq's existing `agent-memory` surface (`MemoryService`, the `Memory`/
`UserProfile`/`Session`/`SessionTurn` OpenRegister schemas) and existing MCP plumbing
(`HermiqToolProvider`, `Agent.tools` allowlist, `RunTraceCollector`) — no new store, no
new search engine, no bespoke tiered-memory subsystem.

## Motivation
`agent-memory` (shipped) already gives Hermiq durable MEMORY/USER content, cross-session
recall via OpenRegister search, and a char-budget-aware consolidation nudge. But every
write path today is operator- or app-driven: `MemoryController::addMemory()` is an
operator-seeding endpoint, and `ContextAssembler`/summarisation flows are the only other
writers. The agent itself — the thing actually doing the work inside `ToolLoop` — has no
way to say "remember this" or "recall what I know" or "I was wrong, forget that" as part
of its own reasoning loop. Competitor analysis (Letta/MemGPT, AutoGPT, SuperAGI — Spectr
DB `competitor_features` WHERE `app_slug='hermiq' AND resolved_by LIKE '%memory/exec
depth%'`) converges on exactly this gap: self-editing memory via agent-issued tool calls
is what makes memory actually useful across long horizons, distinct from (and cheaper
than) MemGPT's bespoke tiered store, which Hermiq deliberately does not copy (see
Approach).

## Affected Projects
- [ ] Project: `hermiq` — add `rememberMemory`/`recallMemory`/`forgetMemory` tool
  descriptors + handlers to `HermiqToolProvider`; extend `MemoryService` with an
  entry-id-addressable soft-delete and a combined entries+turns recall; apply
  `RedactionService` to memory writes; add `id`/`deletedAt` to the `Memory`/`UserProfile`
  entry item schemas (schema version bump + `info.xml` patch); l10n additions for any
  new operator-facing strings (e.g. a "forgotten" indicator on the existing memory panel).

## Scope

### In Scope
- Three new MCP tools on `HermiqToolProvider`, invocable by the agent itself inside
  `ToolLoop` (and directly, like every other Hermiq tool):
  - `rememberMemory(content, scope)` — `scope: agent` appends to the agent's `Memory`
    object; `scope: user` appends to the acting user's `UserProfile` object. Reuses
    `MemoryService::appendMemoryEntry()`/`appendUserProfileEntry()` verbatim, with
    `RedactionService::redact()` applied to `content` first.
  - `recallMemory(query)` — searches the agent's own `Memory`/`UserProfile` entries AND
    past `SessionTurn`s for `query`, via the **same** `ObjectService` search substrate
    `MemoryService::recallSessions()` already uses (`findMany()` with a `search` config
    key) — no second index, no vector store of our own.
  - `forgetMemory(id)` — soft-deletes one memory entry by its (newly-added) stable entry
    id: sets `deletedAt`, never removes the entry from the stored array. Excluded from
    future recall/budget counting; visible to an operator/auditor via the unchanged
    OpenRegister AuditTrail (every `saveObject` write is already audit-trailed — no new
    audit mechanism needed).
- A simple, explicit promotion rule (not LLM salience scoring), stated honestly against
  what HEAD actually does: `ContextAssembler` only ever injects `Context` objects into
  the system prompt at run start — `Memory`/`UserProfile` entries are NOT auto-injected
  anywhere today (grep-verified: nothing outside `MemoryController` calls
  `getMemory()`/`getUserProfile()`). This change does not add that auto-injection path
  (see design.md); instead it gives the agent a deliberate, MemGPT-style alternative —
  `recallMemory` — so relevant facts surface on demand per turn rather than through a
  second always-on preamble mechanism duplicating `ContextAssembler`. Auto-injection
  remains a deferred, explicitly-named gap (see design.md Decisions and
  DEFERRED_DECISIONS). This proposal states plainly that OpenRegister's object store +
  its search/vectorization substrate is Hermiq's "archival tier" — Hermiq does not
  reimplement Letta's core/recall/archival hierarchy.
- Governance, all by reuse: memory writes/forgets are ordinary `ObjectService::saveObject`
  calls (OR's hash-chained AuditTrail already records them — ADR-004), the tool
  invocation itself is already timed as a `tool` step by `RunTraceCollector` because it
  is dispatched through the same `FacadeToolInvoker`/`ToolRegistryFacade` path as every
  other tool, tenant scoping is inherited from `ObjectService`'s caller-context RBAC
  (unchanged), and an agent can already be denied any tool — including these three — via
  the existing `Agent.tools` allowlist (`agent-capability-profile`, shipped).

### Out of Scope
- Shared memory between multiple agents (Letta's multi-agent shared memory blocks) — a
  follow-up that belongs on top of `sub-agent-delegation`, not this change.
- Automatic LLM-driven salience scoring of what to keep/promote/forget — this change
  specs deterministic recency + the existing budget/pinning nudge only; an
  intelligence-scored promotion policy is a future, separate change.
- A bespoke tiered memory store (Letta's core/recall/archival RAM-vs-cold-storage
  design) — explicitly rejected; OpenRegister's object store + search/vectorization IS
  the archival tier.
- Any change to how `ContextAssembler`/the Engine's system-prompt assembly reads memory
  at run start — that injection path is unchanged; this change only adds agent-initiated
  read/write/forget tool calls mid-run.

## Approach
Add three tool descriptors to `HermiqToolProvider::TOOL_DESCRIPTORS` (namespaced
`hermiq.rememberMemory`/`hermiq.recallMemory`/`hermiq.forgetMemory`, matching the
provider's existing `{appId}.{verbNoun}` id convention) and three `invokeTool()` branches
that delegate to `MemoryService`. `MemoryService` gains: (1) a `RedactionService`
collaborator applied inside `appendEntry()` before persist; (2) an entry `id` (uuid,
generated at append time) and `deletedAt` (nullable ISO-8601) on both `Memory.entries`
and `UserProfile.entries` item schemas; (3) a `forgetEntry(agentId, subjectUid, entryId)`
method that locates the entry across the agent's `Memory` object and (when `subjectUid`
is supplied) its `UserProfile` object, and soft-deletes it; (4) a `recallEntries()`
method mirroring `recallSessions()`'s `findMany()` call but against the `memory`/
`userprofile` schemas, whose results `recallMemory` merges with `recallSessions()`'s
turn matches into one tool-result payload. No new register, no new schema, no new
search engine — every new method is a thin composition of `ObjectService` calls the
class already makes.

## New Dependencies
None.

## Impact
- `lib/Mcp/HermiqToolProvider.php` — 3 new tool descriptors + `invokeTool()` branches,
  DI of `MemoryService` and `RedactionService`.
- `lib/Service/MemoryService.php` — `RedactionService` collaborator; `forgetEntry()`;
  `recallEntries()`; entry-id generation in `appendEntry()`; soft-delete-aware
  `countCharacters()`/`normaliseEntries()`.
- `lib/Settings/hermiq_register.json` — `Memory`/`UserProfile` `entries.items.properties`
  gain `id` and `deletedAt`; schema `version` bump on both; `info.xml` patch bump.
- `lib/Controller/MemoryController.php` — unchanged (operator surface stays as-is; the
  new tools are an agent-facing addition, not a replacement).
- `src/components/AgentMemoryPanel.vue` (or wherever the existing memory panel renders
  entries) — a "forgotten" visual treatment so an operator can see a soft-deleted entry
  was retracted, not that it silently vanished.
- `l10n/en.json` / `l10n/nl.json` — any new operator-facing strings from the above.

## Cross-Project Dependencies
None. This is entirely internal to Hermiq's existing `agent-memory` surface; it depends
on OpenRegister's `ObjectService` search/audit substrate exactly as `MemoryService`
already does today, and on `agent-capability-profile`'s `Agent.tools` allowlist, both
already shipped.

## Risks

### Risk 1: Redacting memory content could silently mask a fact the agent meant to keep
**Severity:** Medium — **Mitigation:** `RedactionService::redact()` only ever masks
recognised secret/PII patterns (API keys, DB DSNs, JWTs, phone numbers, etc.), replacing
the matched substring with a placeholder — it never drops or rewrites the surrounding
fact. A memory entry like "the client's Stripe key is sk-live-xxx" is stored as "the
client's Stripe key is sk-l...xxx", not discarded.

### Risk 2: Entry-id backfill for existing Memory/UserProfile objects
**Severity:** Low — **Mitigation:** Entries created before this change have no `id`.
`forgetMemory` treats a match-by-id search that finds nothing as "not found" (soft
failure, not fatal — mirrors every other `MemoryService`/`ContextAssembler` lookup
miss); pre-existing entries remain forgettable only through the existing
`consolidate()` operator path until they are naturally re-appended. No migration is
required because the array is JSON inside a single OpenRegister object (no rigid
per-row schema to backfill).

## Rollback Strategy
Remove the three tool descriptors from `HermiqToolProvider::TOOL_DESCRIPTORS` (or add
them to no agent's `Agent.tools` allowlist, which already fully disables them per-agent
with zero code change). The `MemoryService` additions (`forgetEntry`/`recallEntries`,
the `id`/`deletedAt` fields) are additive and backward compatible — the existing
`MemoryController` operator endpoints and `ContextAssembler` injection path are
untouched, so reverting the tool wiring alone fully restores today's behavior.

## Open Questions
None — the brief resolves the scope/design tradeoffs (tiering, out-of-scope items,
governance reuse) explicitly.
