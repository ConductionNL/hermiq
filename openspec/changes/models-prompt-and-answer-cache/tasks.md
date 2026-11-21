# Tasks: models-prompt-and-answer-cache

Kind: code. Size M. Rows `hermiq:dm-prompt-cache`, `integriq:gw-ai-cache`.

## Implementation tasks

### Task 1: Cache breakpoints on Anthropic requests
- **spec_ref**: `openspec/changes/models-prompt-and-answer-cache/specs/provider-prompt-caching/spec.md#requirement-anthropic-requests-carry-cache-breakpoints-by-default-req-pcache-001`
- **files**: `lib/Service/Llm/ProviderFactory.php` (callAnthropicChat, buildAnthropicTools), `lib/Service/Llm/LlmSettingsHandler.php`, `src/modals/LlmProviderModal.vue`
- **acceptance_criteria**:
  - GIVEN caching on WHEN a request with tools, system and history is built THEN it has three breakpoints, and in a tool round the last one sits on the last tool result
  - GIVEN caching off WHEN a request is built THEN no cache_control is present
- [x] Implement
- [x] Test (PHPUnit on the payload builder)

### Task 2: A stable and a per-turn system block
- **spec_ref**: `openspec/changes/models-prompt-and-answer-cache/specs/provider-prompt-caching/spec.md#requirement-the-stable-part-of-the-system-prompt-comes-first-and-stays-the-same-req-pcache-002`
- **files**: `lib/Service/Engine/ResponseGenerationHandler.php`, `lib/Service/Llm/ProviderFactory.php` (mapHistoryToAnthropicMessages)
- **acceptance_criteria**:
  - GIVEN two turns with different retrieved context WHEN both requests are built THEN the stable block is byte-identical
- [x] Implement
- [x] Test (PHPUnit on prompt assembly for both provider paths)

### Task 3: Cache tokens on the run
- **spec_ref**: `openspec/changes/models-prompt-and-answer-cache/specs/provider-prompt-caching/spec.md#requirement-the-run-reports-cache-tokens-req-pcache-003`
- **files**: `lib/Service/Llm/ProviderFactory.php` (parseAnthropicResponse), `lib/Service/Engine/ResponseGenerationHandler.php`, `src/views/Runs.vue`
- **acceptance_criteria**:
  - GIVEN a response with cache_read_input_tokens WHEN the run is recorded THEN cacheReadTokens is set and promptTokens includes it
- [x] Implement
  > 2026-11-13: the Anthropic half is built: `parseAnthropicResponse()` reads `cache_read_input_tokens` and `cache_creation_input_tokens`, `promptTokens` includes them, and the turn's usage carries `cacheReadTokens` / `cacheWriteTokens` summed over the tool loop (`ProviderFactoryAnthropicCachingTest`). Left: the line on `src/views/Runs.vue`.
  > 2026-11-14: the OpenAI half is built: `LlphantChatCall::openAiUsage()` reads `usage.prompt_tokens_details.cached_tokens` of the last response as `cacheReadTokens` (OpenAI's `prompt_tokens` already includes it, so `promptTokens` does too; `LlphantChatCallTest::testOpenAiCachedPromptTokensAreReported`). Left: `src/views/Runs.vue` shows no token counts yet, so the cache line needs the usage columns first.
  > 2026-11-14: the Runs page shows it: each run row carries the run's recorded token counts (`AnalyticsService::runUsage()`, `AnalyticsServiceTest::testARunRowCarriesTheRecordedTokenCounts`) and the summary cell shows "1,840 of 2,300 input tokens from the provider’s cache" (`src/utils/runUsage.js`, `tests/vitest/run-usage.spec.js`).
- [ ] Test (PHPUnit on parsing; one live Anthropic check)
  > PHPUnit on parsing is done (`ProviderFactoryAnthropicCachingTest`, `LlphantChatCallTest`); the live Anthropic check needs an account and stays open.

### Task 4: The AnswerCacheEntry schema and the agent setting
- **spec_ref**: `openspec/changes/models-prompt-and-answer-cache/specs/answer-cache/spec.md#requirement-the-answer-cache-is-opt-in-per-agent-and-only-for-turns-without-tools-req-acache-001`
- **files**: `lib/Settings/hermiq_register.json` (AnswerCacheEntry, Agent.answerCache), `src/modals/AgentFormModal.vue`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN an agent owner WHEN they switch the cache on with a lifetime THEN it is stored within bounds, and organisation scope is disabled while retrieval is on
- [x] Implement
- [ ] Test (register validation test; Playwright under tests/e2e/spec-coverage/)
  > 2026-11-14: the register validation test is done (`tests/Unit/Settings/AnswerCacheSchemaTest.php`) and the form's setting is covered by `tests/vitest/answer-cache-form.spec.js`; the Playwright check needs a live instance and stays open. The similarity choice is disabled with its note until OpenRegister exposes the vector facade.
  > 2026-11-21: `tests/e2e/spec-coverage/answer-cache.spec.ts` written for the setting (saved for real) and the disabled similarity choice; not run here, it needs a running Nextcloud. Open: no screen shows the scenario's "Answer cache: on, 7 days" line yet, so that assertion fails until the agent page shows it.

### Task 5: Exact lookup and store
- **spec_ref**: `openspec/changes/models-prompt-and-answer-cache/specs/answer-cache/spec.md#requirement-an-exact-repeat-within-one-organisation-gets-the-stored-answer-req-acache-002`
- **files**: `lib/Service/AnswerCache/AnswerCacheService.php`, `lib/Service/Engine/ResponseGenerationHandler.php`, `lib/Controller/ChatStreamController.php`
- **acceptance_criteria**:
  - GIVEN an eligible turn that repeats a stored input WHEN it runs THEN no model is called and the final event has fromCache true
  - GIVEN a turn with tools, a dry run or another organisation WHEN it runs THEN the cache is not read
  - GIVEN an entry whose model the policy no longer allows WHEN it would hit THEN it is deleted and the model is called
- [x] Implement
  > 2026-11-14: `AnswerCacheService` (eligibility, the SHA-256 key over organisation, agent, version, provider, model, temperature and the assembled input, the re-checked lookup, the store) runs in `ResponseGenerationHandler::runTurn()` right before the model call; a hit returns the stored answer with zero model tokens and sets `lastCache`, which the Engine returns as `cache` and the stream's `final` frame as `fromCache`.
- [ ] Test (PHPUnit on eligibility, key and policy re-check; Newman that a second organisation reads no entry)
  > PHPUnit is done (`AnswerCacheServiceTest`, `ResponseAnswerCacheTest`); the Newman check needs a live instance with two organisations and stays open.

### Task 6: The similarity layer through the vector facade
- **spec_ref**: `openspec/changes/models-prompt-and-answer-cache/specs/answer-cache/spec.md#requirement-similar-questions-match-only-through-openregisters-vector-facade-req-acache-003`
- **files**: `lib/Service/AnswerCache/AnswerCacheService.php`
- **acceptance_criteria**:
  - GIVEN the facade is absent WHEN a first question arrives THEN only the exact layer runs
  - GIVEN the facade returns a row of another user under user scope WHEN hermiq re-checks it THEN it is ignored
- [x] Implement
  > 2026-11-16: OpenRegister main publishes `VectorSearchFacade`; hermiq reaches it only through `SimilarQuestionSearch` (resolved lazily from the container, so hermiq loads without it). `AnswerCacheService::lookup()` tries the exact layer first; on a miss, for the first question of a conversation with `similarity` `user` or `organisation` (organisation refused while `enableRag` is on, `similarityScope()`), it asks the facade's `searchSemantic()` for the nearest questions with the redacted question and re-checks every row in hermiq: an object of this agent's cache with a stored question, same organisation, same agent version, not expired, in scope (`user`: stored by this person), similarity at least 0.95, and the model policy. The best passing row is served as `layer: similar` with its score, which the run records in `cache`. `store()` keeps the question, redacted through `RedactionService`, only when the layer is on and it is the first question; without a redactor nothing is stored or searched. The agent form reads `vector_search_available` from the page state (`DashboardPageState`) instead of a fixed false. Live check left: the facade returns only rows the caller may read, and `AnswerCacheEntry` is admin-read, so whether non-admin callers get rows needs a live instance with an embedding backend.
- [x] Test (PHPUnit with a stubbed facade)
  > `AnswerCacheSimilarityTest` (served with its score, best passing row, user scope, every re-check, organisation scope refused with retrieval, first question only, no facade, no redactor, the redacted stored question), `SimilarQuestionSearchTest`, `ResponseAnswerCacheTest::testASimilarHitRecordsItsScore`, `DashboardControllerTest::testVectorSearchAvailabilityTracksTheFacade`, `tests/vitest/answer-cache-vector-search.spec.js`.

### Task 7: Clearing, expiry and the chat note
- **spec_ref**: `openspec/changes/models-prompt-and-answer-cache/specs/answer-cache/spec.md#requirement-cached-answers-can-be-cleared-and-expire-req-acache-004`
- **files**: `lib/BackgroundJob/AnswerCachePurgeJob.php`, `lib/Controller/ChatController.php` (feedback), `lib/Controller/AgentsController.php`, `src/views/Chat.vue`
- **acceptance_criteria**:
  - GIVEN a served answer WHEN it gets a thumbs down THEN its entry is gone
  - GIVEN an expired entry WHEN the daily job runs THEN it is removed
  - GIVEN "Answered from cache" WHEN the user presses "Ask the model again" THEN the model is called and the cache bypassed
- [x] Implement
  > 2026-11-14: the daily `AnswerCachePurgeJob` removes expired entries (`AnswerCacheService::purgeExpired()`, `AnswerCachePurgeJobTest`). Left: the thumbs-down hook in `ChatController` and the note with "Ask the model again" in `Chat.vue` (the turn already honours `ResponseTurnRun::$answerCacheBypass`).
  > 2026-11-14: "Clear cached answers" is `DELETE /api/agents/{id}/answer-cache` (`AgentAnswerCacheController`, owner-only like deleting the agent; `AnswerCacheService::clearForAgent()`), documented in `openapi.json` and pinned in `AgentAnswerCacheControllerTest` and `AnswerCacheServiceTest`. The button on the agent page and the thumbs-down hook (a message does not yet record which entry answered it) are left.
  > 2026-11-15: the thumbs-down hook: an answer served from the cache is stored with `answerCache: {layer, entryId}` (`SessionTurn` schema 0.6.0, `MessageHistoryHandler::storeMessage(answerCache:)`, passed by `Engine::storeAnswer()`), and `ChatController::sendFeedback()` deletes that entry on a `negative` rating (`ChatFeedbackAnswerCacheTest`, `MessageAnswerCacheRecordTest`, `EngineTest::testACachedAnswerIsStoredWithItsServingEntry`). Left: the note with "Ask the model again" in `Chat.vue` and the Clear button on the agent page.
  > 2026-11-15: the chat note and the Clear button. A session's messages say `fromCache` for an answer the cache served (`SessionController`, without exposing the entry); `Chat.vue` shows "Answered from cache" under it with "Ask the model again", which resends the question before it with `answerCacheBypass: true` over the stream or the send path (`src/utils/answerCache.js::questionBefore()`, `src/api/chat.js`). Both endpoints pass the flag to `Engine::processMessage(answerCacheBypass:)`, which sets `ResponseTurnRun::$answerCacheBypass`, so the cache is neither read nor written for that turn. The agent page has a "Clear cached answers" header action opening `AgentAnswerCacheClearDialog` over `DELETE /api/agents/{id}/answer-cache`. Tests: `ResponseAnswerCacheTest`, `EngineTest::testProcessMessageThreadsTheAnswerCacheBypass`, `ChatControllerTest`, `ChatStreamControllerTest`, `SessionControllerUncoveredPathsTest`, `tests/vitest/answer-cache-clear.spec.js`, `chat-page.spec.js`, `api-chat.spec.js`, `answer-cache-form.spec.js`.
- [ ] Test (PHPUnit on the job and the feedback hook; Playwright under tests/e2e/spec-coverage/ for the note and the button)
  > 2026-11-21: `tests/e2e/spec-coverage/answer-cache.spec.ts` written for the "Answered from cache" note, "Ask the model again" (sends `answerCacheBypass: true`) and the thumbs down, with the session's messages answered by page.route; not run here, it needs a running Nextcloud.

## Verification
- [x] `openspec validate models-prompt-and-answer-cache --type change --strict` passes
  - 2026-11-21: valid under `@fission-ai/openspec@1.2.0`, the version hydra's apply loop pins.
- [ ] PHPUnit, Newman and Playwright run once before push, exit codes read
- [ ] One live Anthropic session shows cache read tokens on its second turn
