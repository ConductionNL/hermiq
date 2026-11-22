# Tasks: vector-rag

## 0. Cross-repo prerequisite (OpenRegister — tracked, not built here)

- [x] 0.1 File/confirm the OpenRegister change implementing the public vector facade
      (`searchSemantic`/`searchHybrid`/`embedTexts`/`isAvailable`, superset row
      shape, views parameter, acting-user RBAC on rows) plus configurable
      local-first embedding backends (Ollama embedding model default) for its
      existing vectorization pipeline. Hermiq tasks 2.x/3.x merge before it lands
      and stay on the degraded tier until it does; task 5.3 runs only after it lands.
      Confirmed 2026-11-17: OpenRegister main ships `OCA\OpenRegister\Service\Search\VectorSearchFacade` with `isAvailable`, `searchSemantic`, `searchHybrid` and `embedTexts` (views parameter, the superset row shape). It names no embedding model, which the tool-descriptor cache keys on: `embeddingModelId()` is added in its own OpenRegister pull request (branch build/vector-rag); until it lands Hermiq keys the cache on `default`.

## 1. Facade consumption guard

- [x] 1.1 Add a small Hermiq-side resolver (SPDX docblock, `@spec` tags) that probes
      the facade with `class_exists()` and container resolution — the `TalkBridge`
      lazy-guard pattern — returning null when absent; add a facade stub under
      `tests/Stubs/` mirroring the contract in design.md.
      Built: `lib/Service/Engine/VectorSearch.php` (the facade resolved lazily behind `class_exists()`, three keyword tiers with their own log lines); stub `tests/Stubs/Service/Search/VectorSearchFacadeStub.php`.

## 2. ContextRetrievalHandler wiring

- [x] 2.1 Re-point the `semantic`/`hybrid` branch of `retrieveContext()`: facade
      present AND `isAvailable()` → `searchSemantic()`/`searchHybrid()` with the
      resolved `$viewFilters` passed through; update the class docblock's
      ground-truth adaptation note (the deferral it documents is being closed).
- [x] 2.2 Keep the empty-views gate in front of ALL modes (`searchScoped()`
      unchanged in behaviour); the facade is never called with an empty view set.
- [x] 2.3 Fallback tiers: facade absent / unavailable / throwing → the existing
      keyword path; three distinct log messages (not-present vs no-backend vs
      error); never-throws contract preserved.
- [x] 2.4 Confirm the consuming loop needs no change for `entity_type: file` rows
      (it already branches on file sources) — extend it only if a gap shows in tests.
      No change needed: `RetrievalSourceCollector` already reads `chunk_text`, `similarity` and `metadata`, and points file rows at their file (`ContextRetrievalVectorTest::testSemanticReadsTheFacadeWithTheViews`).

## 3. ToolSearchService ranking

- [x] 3.1 `search()`: when `embedTexts()` is available, rank the RESOLVED set by
      cosine similarity (query embedding vs cached descriptor embeddings), floor
      from `IAppConfig('hermiq', 'tools.searchSimilarityFloor')` (safe default),
      cap at `MAX_MATCHES`; iterate only `$this->resolved` so the outside-the-set
      invariant holds by construction.
- [x] 3.2 Descriptor embedding cache keyed on hash(descriptor ids) + embedding model
      id; recompute on resolved-set or model change.
- [x] 3.3 No backend ⇒ the existing substring path unchanged (same method, same cap).

## 4. Admin visibility

- [x] 4.1 Surface the current retrieval tier (vector-live / keyword-degraded, with
      reason) on the existing Hermiq settings read-out; read-only — no Hermiq-side
      embedding configuration is added.
      Built: `retrievalTier` in `GET /api/settings` (`SettingsService::retrievalTier()`), shown read-only as "Semantic search" in the AI provider section of the admin settings.

## 5. Verify

- [x] 5.1 Unit tests (php:8.3-cli, the CI way): handler — semantic routes to the
      stub facade with views passed through; hybrid routes to `searchHybrid`; empty
      views skips the facade in every mode; absent facade / `isAvailable() false` /
      throwing facade each degrade to keyword with the right log; file rows format
      as file sources. ToolSearchService — similarity ranking floor + cap; paraphrase
      match via stubbed embeddings; substring fallback; a descriptor outside the
      resolved set is never returned on either path; cache invalidation on model
      change.
      Tests: `tests/Unit/Service/Engine/VectorSearchTest.php`, `ContextRetrievalVectorTest.php`, `tests/Unit/Service/ToolSearchServiceSimilarityTest.php`, `SettingsServiceTest::testTheReadOutNamesTheRetrievalTier`; the context-retrieval golden fixture re-recorded (log lines only).
- [x] 5.2 Fresh containerized PHPUnit run vs. the current baseline — report both
      counts.
      PHP 8.3 CLI, `phpunit-unit.xml`: 5736 tests, 0 failures on development; 5757 tests, 0 failures on the branch.
- [ ] 5.3 Live-verify (AFTER the OR facade change lands): on a dev instance with an
      Ollama embedding model configured, run one agent turn in semantic mode and one
      in hybrid mode and confirm facade-sourced results (similarity < 1.0 present);
      then unset the backend and confirm the same agent still answers on the keyword
      tier with the degradation visible in settings.
- [x] 5.4 `openspec validate vector-rag --strict`; phpcs/psalm/phpstan clean; hydra
      gates diff-scoped vs `origin/development` — report results. Explicitly grep
      for `OCA\OpenRegister\Service\Vectorization` in `lib/` and report zero hits.
  - 2026-11-22: `openspec validate vector-rag --strict` valid (openspec 1.2.0); phpcs,
    psalm and phpstan report no errors on the changed lib files; zero
    `OCA\OpenRegister\Service\Vectorization` hits in `lib/`.
  - Hydra diff-check of the build PR (#1852, its merge against its first parent):
    php -l, phpstan, phpmd, eslint and stylelint pass. NEW findings: gate 122 flagged
    `VectorSearch` lines 249-310 as a copy of `VectorFacadeProbe` (fixed: both now use
    `VectorFacadeLocator`, `VectorFacadeLocatorTest`); the constructors of
    `ContextRetrievalHandler` (9 parameters) and `SettingsService` (7) grew by one each;
    the unit-complexity rating went 4.55 to 4.53; 23 phpcs findings sit in the test files,
    which the app's `composer phpcs` (lib only) does not check; gate 23 counts the
    app-local Tenant classes, which this change does not touch; one PHPUnit failure was
    the register version assertion of that day (0.64.0 against 0.82.0), green on
    development now.

## Acceptance criteria

- With a configured backend, semantic and hybrid modes retrieve through the facade,
  view-scoped, with real similarity scores; without one, the current keyword
  behaviour is preserved verbatim and visibly reported — the turn never fails
  either way.
- `hermiq.searchTools` finds granted tools by meaning when embeddings are available
  and by substring when not; on no path can it return a tool outside the resolved,
  default-denied set.
- Hermiq contains no reference to OR vectorization internals and no second
  embedding-configuration surface.

## Quality reminders

- SPDX tags in each PHP docblock; `@spec` tags referencing this change.
- No sed/awk/scripts on code — Edit tool only.
- The empty-views gate is load-bearing (documented 71s fan-out) — do not relax it
  for any mode.
- The OR facade and backend configuration are an explicit cross-repo seam — do not
  stub them into Hermiq, and do not call Ollama directly from Hermiq for embeddings.
