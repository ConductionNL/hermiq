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
  - Form built: owners (people and groups), file name pattern and modified after/before in `src/modals/AgentFormModal.vue`, read and written by `src/utils/retrievalScope.js`, with en/nl strings. A default the form leaves alone is not written onto an agent that never held it.
  - Open: handing the set to the vector facade's `fileIds`. `vector-rag` has not shipped a public facade yet, so retrieval still takes the keyword path, where file rows outside the set are already dropped.
- [x] Test (PHPUnit on the query built and on the empty case)
  - `FileScopeResolverTest`, `ContextRetrievalFileScopeTest`.

### Task 2: The validated tag setting
- **spec_ref**: `openspec/changes/memory-retrieval-scope/specs/retrieval-scope/spec.md#requirement-validated-documents-carry-a-restricted-nextcloud-tag-req-rscope-002`
- **files**: `lib/Controller/Settings/ValidatedTagSettingsController.php`, `appinfo/routes.php`, `src/views/AdminRoot.vue`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN an administrator WHEN they create the tag and restrict it to a group THEN `validated_tag_id` is set and the tag's groups are that group
  - GIVEN a non-admin WHEN they call the route THEN the answer is 403
- [x] Implement
  - Backend built: `GET|PUT|POST /api/settings/validated-tag` (`ValidatedTagSettingsController`, admin only) over `ValidatedTagSettings` (stores `validated_tag_id` only; creates the tag restricted and sets its groups through `ISystemTagManager`), documented in `openapi.json`.
  - Admin section "Validated documents" in `src/views/AdminRoot.vue` (`src/components/settings/ValidatedTagSettings.vue`, `src/api/validatedTag.js`): shows the tag, creates it restricted with the groups that may assign it, and saves those groups; en/nl strings.
- [ ] Test (PHPUnit; Playwright under `tests/e2e/spec-coverage/retrieval-scope.spec.ts`)
  - PHPUnit done: `ValidatedTagSettingsTest`, `ValidatedTagSettingsControllerTest`; vitest `validated-tag-settings.spec.js`. Playwright needs a live instance.

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
- [x] Implement
  - Backend built: `SessionTurn.retrievalScope` (register 0.56.0) holds the file filters, `validated`, `floor`, `passed`, `bestSimilarity` and `abstained`, stored on every assistant turn by `MessageHistoryHandler::storeMessage()` and returned in the turn's envelope.
  - `SessionController::serializeMessage()` returns the record on every stored turn (null without one), so the Chat page reads it after reloading the thread.
  - Display under an answer in `src/views/Chat.vue`: the abstention line, the mode with its floor, the number of passages that passed and the best similarity, and a note when file filters applied (`scopeDisplay()` in `src/utils/retrievalScope.js`). The validated-mode fields (switch, minimum similarity, abstention message) are on the agent form. en/nl strings.
  - Tests: vitest `retrieval-scope.spec.js`, `agent-form-retrieval-scope.spec.js`; PHPUnit `SessionControllerUncoveredPathsTest::testMessagesReturnTheRetrievalScope`.
- [ ] Test (Playwright under `tests/e2e/spec-coverage/retrieval-scope.spec.ts` with a seeded abstained turn)
  - 2026-11-21: `tests/e2e/spec-coverage/retrieval-scope.spec.ts` written from the scenarios (seeded objects and `page.route` stubs where a model, sidecar or Talk is needed); not run here, it needs a running Nextcloud.

## Verification
- [x] `openspec validate memory-retrieval-scope --type change --strict` passes
  - 2026-11-21: valid under `@fission-ai/openspec@1.2.0`, the version hydra's apply loop pins.
- [ ] PHPUnit, Newman and the Playwright file run, exit codes read
- [ ] A live check on the dev instance: tag one file validated, ask a question it answers and one it does not
