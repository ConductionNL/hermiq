---
kind: code
depends_on: [agent-engine-schemas, agent-engine-port, agent-capability-profile]
---

# Proposal: agent-context-system

## Why

`SPECTR-NEXTCLOUD-PLAN.md` §6.4: a net-new `Context` entity — a named, budgeted bundle of
OR files + object-queries + view refs — resolved at run start into a text preamble
prepended to the system prompt, "Specter's pure DB assembly" (no LLM, no silent
truncation). Models on the already-shipped `MemoryService` char-budget/`needsConsolidation`
pattern (`agent-memory`) and the Agent `views`/RAG plumbing (`agent-engine-port`).

## What Changes

- `lib/Settings/hermiq_register.json` (register `info.version` 0.8.0 → 0.9.0):
  - ADD **`Context`** schema (slug `context`; no fleet-wide slug collision at HEAD):
    `name` (required), `description`, `files` (array of `{path, description}` — Nextcloud
    user-folder-relative file references), `objectQueries` (array of `{register, schema,
    filters, search, limit}` — OpenRegister query refs), `viewRefs` (array of uuid — View
    filter refs, mirrors `Agent.views`), `charBudget` (int, default 8000), `needsConsolidation`
    (bool, default false, derived).
  - ADD **`Agent.contextRefs`** (array of uuid, default `[]`): Context objects attached to
    this agent, resolved and prepended at every run.
- `lib/Service/Engine/ContextAssembler.php` (new, modeled on `MemoryService`): resolves one
  or more `Context` objects into a single budgeted preamble string:
  - runs each `objectQueries` entry through `ObjectService` (the same public surface
    `ContextRetrievalHandler`/`MemoryService` already use — no OR-internal reach-in);
  - reads each `files` entry from the acting user's Nextcloud folder via `IRootFolder`
    (the same public OCP surface `HermiqToolProvider::readFile()` already uses);
  - concatenates everything with `Source:` headers, counts characters, and — when the
    total exceeds the Context's `charBudget` — persists `needsConsolidation=true` on the
    Context object (a nudge; the assembled text is NEVER truncated, matching the Memory
    contract);
  - `viewRefs` resolution is deferred (see design.md — Agent.views has the identical gap
    today).
- `lib/Service/Engine/Engine.php`: `processMessage()` resolves the bound agent's
  `contextRefs` via `ContextAssembler` and passes the assembled preamble into
  `ResponseGenerationHandler::generateResponse()`.
- `lib/Service/Engine/ResponseGenerationHandler.php`: `generateResponse()` gains a
  `contextPreamble` parameter, prepended into the system prompt immediately after
  `Agent.prompt` — before the CnAiContext snapshot and the RAG context block.

### MCP coverage

No MCP surface — `Context` is a config/data object with no independent action; resolution
is an internal Engine step, not a user-invoked tool.

## Impact

- Affected specs: NEW `agent-context-system` capability.
- Affected code: `lib/Settings/hermiq_register.json`, `lib/Service/Engine/ContextAssembler.php`
  (new), `lib/Service/Engine/Engine.php`, `lib/Service/Engine/ResponseGenerationHandler.php`,
  plus their existing test files (extended).
- NOT delivered (explicit deferred seam): `viewRefs` resolution into an actual data filter
  (Agent's own `views` field has the same documented gap in `ContextRetrievalHandler` at
  HEAD — "TODO: Apply view filters here when view filtering is implemented"); a Context
  management UI (create/edit Context objects, attach to an agent) — backend/config-first
  per the brief, matching how `agent-memory` shipped its UI in a later, dedicated task set
  while this program is schema+engine-first.
