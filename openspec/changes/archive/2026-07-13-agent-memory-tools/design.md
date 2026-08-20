# Design: agent-memory-tools

## Architecture Overview
`agent-memory` (shipped) gave Hermiq `Memory`/`UserProfile`/`Session`/`SessionTurn`
OpenRegister objects and a `MemoryService` that reads/writes them with a char-budget
nudge and OR-search-backed recall. Every write path today is operator- or app-driven:
`MemoryController::addMemory()` is an operator-seeding endpoint; nothing in `lib/`
outside `MemoryController` calls `MemoryService::getMemory()`/`getUserProfile()`
(grep-verified at HEAD) — the agent's own run loop never reads or writes memory.

This change adds three MCP tools to `HermiqToolProvider` — the same class, same
`IMcpToolProvider` mechanism, same `invokeTool()`-never-throws contract that already
exposes `hermiq.listFiles`/`readFile`/`searchContacts`/`listCalendarEvents`/`sendMail`/
`listDeckBoards` — so an agent running inside `ToolLoop` can call
`hermiq.rememberMemory`, `hermiq.recallMemory`, `hermiq.forgetMemory` exactly like any
other tool. `FacadeToolInvoker`/`ToolRegistryFacade`/`RunTraceCollector` do not change
at all: they already treat every tool id uniformly, so the new tools inherit tracing,
streaming, and the `Agent.tools` allowlist gate for free.

```
ToolLoop (agent decides to call a tool)
  -> FacadeToolInvoker::__call()          [unchanged; times a 'tool' trace step]
    -> ToolRegistryFacade::invokeTool()   [unchanged; OR's tool dispatch]
      -> HermiqToolProvider::invokeTool() [+3 new case branches]
        -> MemoryService::appendMemoryEntry() / appendUserProfileEntry()  [+ RedactionService::redact() first]
        -> MemoryService::recallEntries() + recallSessions()             [new method + existing method, merged]
        -> MemoryService::forgetEntry()                                  [new method]
          -> ObjectService::saveObject()  [unchanged OR write-path; OR's AuditTrail records it automatically]
```

## Goals / Non-Goals

**Goals**
- Let the agent itself decide, mid-run, to remember/recall/forget — the MemGPT
  "self-editing memory" mechanism — using only tool calls, no new subsystem.
- Reuse every existing seam: `MemoryService`'s append/consolidate machinery, OR's
  `ObjectService` search substrate (the same one `recallSessions()` already calls),
  `RedactionService`'s existing pattern set, `Agent.tools` as the governance gate, OR's
  AuditTrail as the write-audit mechanism, `RunTraceCollector` as the trace mechanism.
- Make forgetting safe: a soft delete only, never a hard delete, and auditable through
  the unchanged AuditTrail (every `saveObject` diff, including a `deletedAt` flip, is
  already recorded there).

**Non-Goals**
- Do not build a tiered memory store (Letta's core/recall/archival hierarchy). The
  "archival tier" IS OpenRegister's object store + its search/vectorization substrate —
  restated explicitly so nobody re-derives a second one later.
- Do not add LLM-driven salience scoring of what to keep. This change specs
  deterministic behavior only: append-on-remember, char-budget nudge (existing),
  soft-delete-on-forget.
- Do not wire automatic system-prompt injection of Memory/UserProfile entries
  (mirroring `ContextAssembler`'s Context-object preamble). See Decision below — this
  is a real, named gap, not a silent omission.
- Do not touch multi-agent shared memory (Letta's memory blocks) — that is
  `sub-agent-delegation` territory.

## Decisions

### Decision 1: No system-prompt auto-injection of Memory in this change
`ContextAssembler` assembles `Context` objects (a distinct schema: `objectQueries` +
`files` + `viewRefs`) into a budgeted preamble at run start. It was tempting to add a
parallel `MemoryPreambleAssembler` doing the same thing for `Memory`/`UserProfile`
entries, mirroring the "recent/high-salience memories injected into the system prompt"
framing from the competitor research. Two things argue against doing that HERE:
1. **Scope discipline**: this change's brief and seam list is about the three tools;
   Engine-level prompt assembly is a different call chain (`Engine::processMessage()`)
   not listed as a seam to touch, and folding it in would make the change multi-concern
   and harder to review/test as one PR.
2. **The tool is a better mechanism for the same goal, and MemGPT agrees**: the whole
   point of MemGPT's function-calling insight is that the agent decides what enters its
   own context, rather than a host-side heuristic silently prepending "recent" facts
   the agent may not need this turn. Shipping `recallMemory` first and observing how
   agents actually use it is the honest sequencing — a host-side auto-injection pass
   can be layered on top later without changing anything this change ships.

The "promotion rule" this change actually specs is therefore: an entry is either (a)
in `Memory`/`UserProfile` and reachable via `recallMemory`, or (b) soft-deleted
(`deletedAt` set) and excluded from `recallMemory` and from `charBudget` counting. There
is no third "hot" tier — recency ordering inside `recallMemory`'s results (most
recently created entries/turns first) is the only "recent-first" behavior this change
provides, and it is deterministic sort order, not salience scoring. Auto-injection is
recorded as a deferred gap (DEFERRED_DECISIONS).

### Decision 2: Entry-level `id` + `deletedAt`, not a separate OR object per entry
`Memory`/`UserProfile` currently store `entries` as a JSON array inside ONE OpenRegister
object per agent (or per agent+subject-user). Making each entry individually
addressable for `forgetMemory(id)` has two options: (a) promote each entry to its own
OR object (like `SessionTurn` is to `Session`), or (b) add `id`/`deletedAt` fields to
the existing entry item schema and keep the array-of-JSON-objects shape.

(b) is chosen: entries are small, append-only, bounded by `charBudget`/
`needsConsolidation` (the object flags itself once entries get large, prompting
consolidation — the existing mechanism already handles the "too many entries" case).
Promoting to per-entry objects would multiply OR object counts, add a new
`agentId`+`entryType` filter surface, and duplicate `SessionTurn`'s pattern for no
behavioral gain, since forgetting one entry is a whole-object rewrite either way
(`saveObject()` on the parent `Memory`/`UserProfile` object — array mutation, then
persist). Each entry gets a `uuid` string `id` generated at append time
(`Ramsey\Uuid` — already a Hermiq dependency via OpenRegister — or PHP's
`bin2hex(random_bytes(16))` formatted as a UUIDv4-shaped string if no vendor UUID
helper is already imported in `MemoryService`; the apply step should reuse whatever
Hermiq already uses elsewhere for id generation, verified at implementation time) and
a nullable `deletedAt` (ISO-8601 string, `null`/absent = active).

### Decision 3: `forgetMemory(id)` searches the agent's own Memory + the acting user's own UserProfile, not every UserProfile
The brief's tool signature is `memory.forget(id)` — no `scope` parameter. Since a
`Memory` id and a `UserProfile` id could theoretically collide across different
subject users (each is a distinct object), and the tool has no `subjectUid` argument,
`forgetEntry()` scopes UserProfile search to the ACTING user only (the same `uid` the
provider already resolves via `IUserSession` for `sendMail`/`listFiles`/etc.) — never
every UserProfile the agent has ever maintained. This keeps the IDOR posture already
established for every other tool in `HermiqToolProvider` (scope strictly to the acting
user's own resources) and avoids a cross-subject-user forget by construction. If the
matching id is not found in either object, `forgetMemory` returns a structured
"not found" result — never fatal, matching the class's `invokeTool()`-never-throws
contract, and matching `ContextAssembler`'s "one bad reference is skipped, not fatal"
posture.

### Decision 4: Redact before the FIRST persist, inside `MemoryService`, not inside the tool provider
`RedactionService::redact()` (the `MODE_FORCE` entry point) is already the sanctioned
"apply before an immutable/append-only write" call, currently used only for
`ApprovalService`'s AuditTrail writes. `MemoryService::appendEntry()` is the single
private method both `appendMemoryEntry()` and `appendUserProfileEntry()` funnel
through — redacting there (rather than in `HermiqToolProvider::invokeTool()`) means
EVERY memory write is redacted regardless of caller: the new `rememberMemory` tool, the
existing operator-facing `MemoryController::addMemory()` endpoint, and any future
caller all get the same guarantee for free, closing a gap that exists in the CURRENT
codebase (operator-seeded memory via `addMemory()` is not redacted today).

### Decision 5: `recallMemory` merges two existing search calls; it does not add a new one
`recallMemory(query)` calls (1) a new `MemoryService::recallEntries()` — a thin
`findMany()` call against the `memory`/`userprofile` schemas filtered by `agentId`
(and, for the user-profile half, the acting `subjectUid`) with `search: query`, exactly
mirroring `recallSessions()`'s existing `findMany()` call shape — and (2) the existing
`recallSessions()` unchanged. The tool result merges both into one payload
(`{ memoryEntries: [...], userProfileEntries: [...], sessionTurns: [...] }`) so the
agent gets one call surface instead of three. No second search index, no vector store:
both calls go through the identical `ObjectService::setRegister()->setSchema()
->findAll(config: ['search' => ...])` substrate `MemoryService` already uses.
Recall is tenant-scoped for free (unchanged `ObjectService` caller-context RBAC).

### Decision 6: Governance is 100% reuse — no new mechanism is introduced
| Governance concern (brief) | Mechanism | Status |
|---|---|---|
| Audit entry on every write/forget | OR `ObjectService::saveObject()` → OR's hash-chained AuditTrail | Already automatic (ADR-004); no new code |
| Trace step on every tool call | `FacadeToolInvoker::__call()` + `RunTraceCollector` | Already automatic for ANY tool id; no new code |
| Redaction before persist | `RedactionService::redact()` inside `MemoryService::appendEntry()` | New call site (Decision 4), existing service |
| Tenant scoping | `ObjectService` caller-context RBAC | Already automatic; unchanged |
| Agent may be denied memory-write | `Agent.tools` allowlist (`agent-capability-profile`, shipped) | Already automatic — omit `hermiq.rememberMemory`/etc. from an Agent's `tools` array |

No new table, no new audit writer, no new trace mechanism, no new authorization layer.

## Security Considerations
- **IDOR**: `forgetMemory` and `rememberMemory(scope=user)` never accept a `subjectUid`
  argument from the tool call — the acting user is always resolved server-side via
  `IUserSession`, matching every other `HermiqToolProvider` tool's IDOR posture.
- **Redaction**: applied before persist (Decision 4), closing the existing gap where
  operator-seeded memory bypasses redaction entirely.
- **Soft delete only**: `forgetMemory` never removes an entry from the stored array —
  it sets `deletedAt` and the entry is excluded from future `recallMemory` results and
  `needsConsolidation` character counting. The AuditTrail retains the full history
  (including what was "forgotten" and when), matching ADR-004's append-only invariant
  and the brief's "never a silent hard delete."
- **Capability denial**: an org that wants an agent that can read files but never
  writes memory sets `Agent.tools = ["hermiq.listFiles", ...]` without
  `hermiq.rememberMemory` — no code path change needed; `ToolLoop` already enforces
  this allowlist at turn assembly.
- **No new attack surface**: all three tools operate exclusively on OpenRegister
  objects already scoped to the agent + acting user; no filesystem, no outbound HTTP,
  no new OCP capability is introduced.

## Nextcloud Integration
- Controllers: none added (no HTTP surface — these are MCP tool calls only, invoked
  through OR's existing MCP dispatch, exactly like the other `HermiqToolProvider`
  tools).
- Services: `MemoryService` (extended), `RedactionService` (new collaborator wired into
  `MemoryService`), `HermiqToolProvider` (extended).
- Mappers/Entities: none — everything is `ObjectService`-mediated OpenRegister objects,
  as today.
- Events/Hooks: none.

## File Structure
```
lib/
  Mcp/
    HermiqToolProvider.php     # +3 TOOL_DESCRIPTORS, +3 invokeTool() branches, +DI (MemoryService, RedactionService)
  Service/
    MemoryService.php          # +RedactionService DI, +forgetEntry(), +recallEntries(), entry id/deletedAt handling
  Settings/
    hermiq_register.json       # Memory.entries.items + UserProfile.entries.items gain id/deletedAt; version bump both; info.xml patch bump
src/
  components/
    AgentMemoryPanel.vue       # forgotten-entry visual treatment (verify exact file at apply time)
l10n/
  en.json / nl.json            # new operator-facing strings (English keys)
```

## Seed Data
No new schema is introduced (`Memory`/`UserProfile` already exist and already have seed
data from `agent-memory`); this change only adds two optional item properties
(`id`, `deletedAt`) to existing entry objects. No seed-data section is required — the
existing seeded `Memory`/`UserProfile` example objects remain valid (absent `id`/
`deletedAt` on old entries is the expected pre-migration state per Risk 2 in the
proposal).

## Trade-offs
- **Per-object soft-delete vs. per-entry OR object** (Decision 2): chosen for
  simplicity and consistency with the existing append-only array shape; the
  trade-off is that `forgetEntry()` must load and rewrite the whole parent object
  (acceptable — `Memory`/`UserProfile` objects are small, budget-bounded documents,
  not large collections).
- **No auto-injection of Memory into the system prompt** (Decision 1): the trade-off
  is that an agent that never calls `recallMemory` gets zero benefit from its own
  memory writes. This is accepted as the honest state of a tools-first design and is
  called out as a deferred gap rather than silently left unspecified.
- **UserProfile forget scoped to acting user only** (Decision 3): an agent cannot use
  `forgetMemory` to retract a fact it holds about a DIFFERENT user than the one
  currently chatting with it. Accepted — that capability would need a `subjectUid`
  argument and a matching authorization question (can this agent forget facts about
  ANY user it has ever profiled?) that is out of scope for this change.

## Open Questions
None outstanding — see DEFERRED_DECISIONS in the final task-completion report for
follow-up-worthy items (auto-injection, cross-user forget, id-generation helper choice).
