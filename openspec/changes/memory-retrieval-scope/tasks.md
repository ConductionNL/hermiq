# Tasks: memory-retrieval-scope

Kind: code. Size M. Rows `hermiq:dm-metadata-filter`, `td-validated-sources`.

## Implementation tasks

### Task 1: File filters on the agent and their resolution
- **spec_ref**: `openspec/changes/memory-retrieval-scope/specs/retrieval-scope/spec.md#requirement-an-owner-can-limit-file-retrieval-by-owner-file-name-and-modified-date-req-rscope-001`
- **files**: `lib/Settings/hermiq_register.json`, `lib/Service/Engine/FileScopeResolver.php`, `lib/Service/Engine/ContextRetrievalHandler.php`, `src/modals/AgentFormModal.vue`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN an owner filter on a group and a date WHEN a turn retrieves THEN the facade's `fileIds` holds only matching files the person can read
  - GIVEN filters that match nothing WHEN a turn retrieves THEN no file search runs
- [ ] Implement
  - Backend built: `Agent.ragFileFilters` (register 0.53.0), `lib/Service/RetrievalScope/FileScopeResolver.php` (one Nextcloud file search as the session user, owners expanded from groups, name LIKE, mtime range; null when unset, an empty set when nothing can match) and `ContextRetrievalHandler` (an empty set switches file search off; file rows outside the set are dropped).
  - Open: the agent form fields in `src/modals/AgentFormModal.vue` with their l10n strings, and handing the set to the vector facade's `fileIds` once `vector-rag` ships the facade.
- [x] Test (PHPUnit on the query built and on the empty case)
  - `FileScopeResolverTest`, `ContextRetrievalFileScopeTest`.

### Task 2: The validated tag setting
- **spec_ref**: `openspec/changes/memory-retrieval-scope/specs/retrieval-scope/spec.md#requirement-validated-documents-carry-a-restricted-nextcloud-tag-req-rscope-002`
- **files**: `lib/Controller/Settings/ValidatedTagSettingsController.php`, `appinfo/routes.php`, `src/views/AdminRoot.vue`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN an administrator WHEN they create the tag and restrict it to a group THEN `validated_tag_id` is set and the tag's groups are that group
  - GIVEN a non-admin WHEN they call the route THEN the answer is 403
- [ ] Implement
  - Backend built: `GET|PUT|POST /api/settings/validated-tag` (`ValidatedTagSettingsController`, admin only) over `ValidatedTagSettings` (stores `validated_tag_id` only; creates the tag restricted and sets its groups through `ISystemTagManager`), documented in `openapi.json`.
  - Open: the admin page section in `src/views/AdminRoot.vue` with its l10n strings.
- [ ] Test (PHPUnit; Playwright under `tests/e2e/spec-coverage/retrieval-scope.spec.ts`)
  - PHPUnit done: `ValidatedTagSettingsTest`, `ValidatedTagSettingsControllerTest`. Playwright needs a live instance.

### Task 3: Validated mode and the deterministic abstention
- **spec_ref**: `openspec/changes/memory-retrieval-scope/specs/retrieval-scope/spec.md#requirement-an-agent-in-validated-mode-refuses-without-asking-the-model-when-nothing-validated-matches-req-rscope-003`
- **files**: `lib/Settings/hermiq_register.json`, `lib/Service/Engine/Engine.php`, `lib/Service/Engine/ContextRetrievalHandler.php`, `lib/Service/Engine/ResponseGenerationHandler.php`
- **acceptance_criteria**:
  - GIVEN validated mode and no passage above the floor WHEN a turn runs THEN the abstention is stored and the fake driver records zero calls
  - GIVEN validated mode WHEN tools are resolved THEN the list is empty
- [x] Implement
  - `Agent.answerOnlyFromValidated`, `ragMinSimilarity`, `abstentionMessage` (register 0.56.0). The rules are `lib/Service/RetrievalScope/ValidatedMode.php`; the tagged files come from `ValidatedFileSet` (the files carrying `validated_tag_id`, none when no tag is set). `ContextRetrievalHandler` limits a validated turn to tagged files within the agent's own file set, searches no objects or views, keeps only file passages at or above the floor, and reports `abstained` when none pass. `Engine::answerOrAbstain()` then stores the abstention without calling the model; `ResponseGenerationHandler` resolves no tools for a validated agent.
- [x] Test (PHPUnit on the engine with a counting fake driver)
  - `ValidatedModeTest`, `ValidatedFileSetTest`, `ContextRetrievalValidatedModeTest`, `EngineValidatedAbstentionTest` (the model is never asked), `ResponseGenerationValidatedToolsTest` (no tools listed).

### Task 4: Sessions cannot widen the scope
- **spec_ref**: `openspec/changes/memory-retrieval-scope/specs/retrieval-scope/spec.md#requirement-an-agent-in-validated-mode-refuses-without-asking-the-model-when-nothing-validated-matches-req-rscope-003`
- **files**: `lib/Controller/ChatController.php`, `lib/Controller/ChatStreamController.php`, `lib/Service/Engine/ContextRetrievalHandler.php`
- **acceptance_criteria**:
  - GIVEN validated mode WHEN a turn arrives with `includeObjects: true` or an extra view THEN neither takes effect
- [x] Implement
  - Enforced in `ContextRetrievalHandler::retrieveContext()`, which every turn path reaches (chat, stream and the Talk bridge), so the controllers stay unchanged: `includeObjects`, the session's views and a session file or folder `scope` are dropped for a validated agent.
- [ ] Test (PHPUnit; Newman with a widening request)
  - PHPUnit done: `ContextRetrievalValidatedModeTest` (objects switched back on, a session scope to another file). Newman needs a live instance.

### Task 5: The scope record and its display
- **spec_ref**: `openspec/changes/memory-retrieval-scope/specs/retrieval-scope/spec.md#requirement-each-answer-records-the-scope-it-was-given-req-rscope-004`
- **files**: `lib/Settings/hermiq_register.json`, `lib/Service/Engine/Engine.php`, `src/views/Chat.vue`, `src/modals/AgentFormModal.vue`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN an abstained turn WHEN the session is opened THEN the turn shows the mode, the floor and the best similarity
- [ ] Implement
  - Backend built: `SessionTurn.retrievalScope` (register 0.56.0) holds the file filters, `validated`, `floor`, `passed`, `bestSimilarity` and `abstained`, stored on every assistant turn by `MessageHistoryHandler::storeMessage()` and returned in the turn's envelope.
  - Open: the display under an answer in `src/views/Chat.vue` and the validated-mode fields in `src/modals/AgentFormModal.vue`, with their l10n strings.
- [ ] Test (Playwright under `tests/e2e/spec-coverage/retrieval-scope.spec.ts` with a seeded abstained turn)

## Verification
- [ ] `openspec validate memory-retrieval-scope --type change --strict` passes
- [ ] PHPUnit, Newman and the Playwright file run, exit codes read
- [ ] A live check on the dev instance: tag one file validated, ask a question it answers and one it does not
