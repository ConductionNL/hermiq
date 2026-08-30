# Tasks: agent-context-system

## 1. Schema (register patch)

- [x] 1.1 Add a `Context` schema to `lib/Settings/hermiq_register.json` (slug `context`):
      required `name`; `description`; `files` (array of `{path, description}`, default
      `[]`); `objectQueries` (array of `{register, schema, filters, search, limit}`,
      default `[]`); `viewRefs` (array of uuid, default `[]`); `charBudget` (int, default
      8000); `needsConsolidation` (bool, default false). Flat, no `if`/`then`.
- [x] 1.2 Add `Agent.contextRefs` (array of uuid, default `[]`): Context objects attached to
      the agent.
- [x] 1.3 Bump register `info.version` 0.8.0 → 0.9.0.

## 2. ContextAssembler

- [x] 2.1 Create `lib/Service/Engine/ContextAssembler.php` (SPDX docblock, models on
      `MemoryService`): `assemble(string $contextId, string $actingUserId): array{text:
      string, needsConsolidation: bool}` — resolves ONE Context object.
- [x] 2.2 `objectQueries` resolution: for each entry, query via `ObjectService`
      (`setRegister`/`setSchema`/`findAll`), format each result as a `Source:` text block;
      a failing/unknown register+schema degrades that one entry (logged), never aborts the
      whole assembly.
- [x] 2.3 `files` resolution: read each `{path}` from the acting user's folder via
      `IRootFolder` (mirrors `HermiqToolProvider::readFile()` — nodeExists/`File`-type
      guards); a missing file/non-file path is skipped (logged), never fatal.
- [x] 2.4 Character-budget check: sum the assembled text length; when it exceeds
      `charBudget`, set `needsConsolidation=true` and persist it (read-then-compare —
      only write when the stored flag differs from the computed one); the text itself is
      NEVER truncated.
- [x] 2.5 `assembleForAgent(?ObjectEntity $agent, string $actingUserId): string`: loop the
      agent's `contextRefs`, `assemble()` each, concatenate with a `Context: {name}` header
      per bundle; null agent or empty `contextRefs` returns `''`.
- [x] 2.6 `viewRefs`: collected and logged (count) only — deferred alongside `Agent.views`'s
      identical unresolved TODO (see design.md); do not implement filtering.

## 3. Engine wiring

- [x] 3.1 `Engine::processMessage()`: after resolving `$agent`, call
      `contextAssembler->assembleForAgent($agent, $userId)` and forward the result into
      `ResponseGenerationHandler::generateResponse()` as `contextPreamble`.
- [x] 3.2 `ResponseGenerationHandler::generateResponse()`: add a `contextPreamble` parameter;
      when non-empty, append it to `$systemPrompt` immediately after `Agent.prompt`, before
      the CnAiContext snapshot and the RAG context block.

## 4. Verify

- [x] 4.1 Unit tests (php:8.3-cli, the CI way): `ContextAssemblerTest` (new) — objectQueries
      resolve and format; files resolve (and a missing file is skipped, not fatal); under
      budget leaves `needsConsolidation` false with no extra save; over budget flips the
      flag AND persists it; an already-true flag under budget flips back to false and
      persists; `assembleForAgent` concatenates multiple contexts / returns `''` for a null
      agent or empty `contextRefs`. `EngineTest`/`ResponseGenerationHandlerTest` extended:
      the preamble is forwarded and lands in the system prompt before the RAG block.
- [x] 4.2 Fresh containerized PHPUnit run vs. the `agent-capability-profile` baseline —
      report both counts.
- [x] 4.3 `openspec validate agent-context-system --strict`; phpcs/psalm/phpstan clean;
      hydra gates diff-scoped vs `origin/development` — report results.

## Acceptance criteria

- A `Context` object resolves into a budgeted text preamble: object-query results and file
  contents are concatenated, never silently truncated, and `needsConsolidation` flags (and
  persists) an over-budget bundle.
- An agent's `contextRefs` are assembled and prepended to the system prompt at run start,
  ahead of the RAG/CnAiContext blocks.
- A missing file or an unresolvable object query degrades gracefully (skipped, logged) —
  it never blanks the whole context or fails the run.

## Quality reminders

- SPDX tags in each PHP docblock; `@spec` tags referencing this change.
- No sed/awk/scripts on code — Edit tool only.
- Config-then-code: the `Context`/`contextRefs` schema is declarative; assembly is the code
  path. Single write-path: all persistence via `ObjectService`.
- `viewRefs` filtering and a Context management UI are explicit, undelivered seams — do not
  stub them.
