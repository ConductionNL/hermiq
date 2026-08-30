# Design: agent-context-system

## Context

`SPECTR-NEXTCLOUD-PLAN.md` §6.4. Ports Specter's "pure DB assembly" context-brief step
(no LLM) into a reusable, budgeted Hermiq primitive. Sits alongside `agent-capability-profile`
in Phase 5 — both extend the Agent schema and both were asked for on one branch because
they're small, tightly-coupled agent-config surfaces.

## Decisions

**Four bundle shapes, one schema — mirrors `Memory`'s char-budget contract exactly.**
`Context` reuses the `charBudget`/`needsConsolidation` pair verbatim from `Memory`
(`agent-memory`): appending/growing the assembled content never truncates; exceeding budget
flags a nudge. The difference from `Memory` is WHEN the budget check happens: `Memory`
checks on every `appendMemoryEntry()` write; `Context`'s content is not stored directly —
it's recomputed from live queries/files at *assembly time* (run start) — so the check (and
the flag write-back) happens inside `ContextAssembler::assemble()`, not a dedicated append
method. The flag is only re-persisted when it actually changes (read-then-compare before
write) so a chatty agent does not turn every single turn into an extra `Context` save.

**Object queries reuse `ObjectService` exactly like `MemoryService`/`ContextRetrievalHandler`
— never an OR-internal class.** Each `objectQueries` entry is `{register, schema, filters,
search, limit}`; `ContextAssembler` calls
`objectService->setRegister($r)->setSchema($s)->findAll(config: [...])` — the identical
public chain already used throughout this codebase. No new OR dependency surface.

**Files are read via the public `IRootFolder` OCP API, the same as `HermiqToolProvider`.**
"OR file refs" in the plan's wording resolves, at HEAD, to Nextcloud user-folder-relative
paths — the only file-reference shape already load-bearing in this codebase
(`HermiqToolProvider::readFile()`/`listFiles()`, IDOR-guarded to the acting user's own
folder). `ContextAssembler` reads files the same way: `IRootFolder::getUserFolder($actingUserId)`,
`nodeExists()`/instanceof `File` guards, size-capped read. A missing file or a path that
resolves to a folder is skipped (logged), not fatal — one bad file reference must not blank
the whole context.

**No text-extraction pipeline.** The plan's "pure DB assembly" framing (Specter had no LLM
in this step either) and the brief's "thin code" scope both point away from wiring OR's
`TextExtractionService` (an OR-internal class, not a published cross-app facade — the same
gate-27 concern `ContextRetrievalHandler`'s docblock already raised for vector search).
Files are read as raw text content; binary/non-text files will read as their raw bytes,
which is a known, documented limitation, not silently masked.

**`viewRefs` resolution is deferred, consistently.** `Agent.views` — the identical
"UUIDs of views that filter which data is accessible" concept — already has an
unimplemented resolution step at HEAD (`ContextRetrievalHandler::resolveViewFilters()`
computes the effective set but the original ported TODO to apply it to the search was
preserved verbatim: "TODO: Apply view filters here when view filtering is implemented").
Since the very field this schema mirrors is not yet wired end-to-end, inventing a
different, actually-working view-filter mechanism just for `Context` would create two
inconsistent view-filtering behaviors in the same codebase. `viewRefs` is declared,
collected, and logged (count only) — not applied — with the exact same "deferred, not
stubbed" framing as the existing TODO.

**Preamble placement: right after `Agent.prompt`, before RAG/CnAiContext.** The system
prompt in `ResponseGenerationHandler::generateResponse()` is assembled in this order today:
`Agent.prompt` → CnAiContext snapshot → RAG context block. The Context preamble is
instruction-shaped (closer to "who you are / what you know statically"), same category as
`Agent.prompt`, so it is appended immediately after it — before the per-turn CnAiContext
and RAG blocks, which are request-shaped. This matches the brief: "prepends to the system
prompt (like `Agent.prompt`)."

**Multiple `contextRefs`, one preamble.** `Agent.contextRefs` is an array (plan: "attachable
to agent... or run" — this change delivers the agent attachment only). `ContextAssembler`
exposes `assembleForAgent(?ObjectEntity $agent, string $actingUserId): string`, which loops
`contextRefs`, assembles each, and concatenates with a `Context: {name}` header per bundle
— Engine calls this ONE method, keeping its own wiring a two-line addition (thin code).

## Integration seam (NOT implemented here)

- **`viewRefs` → actual query filtering.** Deferred alongside `Agent.views` (see above).
- **Run-level `contextRefs`** (the plan's "or run" attachment point) — only the agent
  attachment is delivered; a run/conversation-level override is a natural follow-up once a
  concrete UI need for it exists.
- **Context management UI** — create/edit/attach Context objects. Config/engine-first per
  the brief; `agent-memory`'s own UI shipped in a dedicated, later task set, same pattern.

## Risks / Trade-offs

- **Character count vs. token budget.** Same as `Memory` — characters are deterministic and
  dependency-free; the budget is a nudge, not a hard cap.
- **Raw file reads, no extraction.** [PDFs/DOCX would read as binary noise] → Documented
  limitation; `agentskills.io`-style text files (`.md`/`.txt`) are the intended shape today;
  wiring a text-extraction facade is future work once OR publishes one (mirrors
  `ContextRetrievalHandler`'s existing semantic-search deferral for the same reason).
- **Extra `saveObject()` only on flag flip.** Read-then-compare avoids a write per turn;
  accepted minor extra read per assembly.

## Open Questions

- **Open — should a bad `objectQueries` entry (unknown register/schema) abort the whole
  context or degrade that one entry?** Resolved: degrade per-entry (try/catch around each
  query, logged, skipped) — mirrors `ContextRetrievalHandler::retrieveContext()`'s
  never-throws contract; one bad query must not blank an otherwise-good context.
