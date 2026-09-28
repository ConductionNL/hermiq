# Tasks: models-prompt-and-answer-cache

Kind: code. Size M. Rows `hermiq:dm-prompt-cache`, `integriq:gw-ai-cache`.

## Implementation tasks

### Task 1: Cache breakpoints on Anthropic requests
- **spec_ref**: `openspec/changes/models-prompt-and-answer-cache/specs/provider-prompt-caching/spec.md#requirement-anthropic-requests-carry-cache-breakpoints-by-default-req-pcache-001`
- **files**: `lib/Service/Llm/ProviderFactory.php` (callAnthropicChat, buildAnthropicTools), `lib/Service/Llm/LlmSettingsHandler.php`, `src/modals/LlmProviderModal.vue`
- **acceptance_criteria**:
  - GIVEN caching on WHEN a request with tools, system and history is built THEN it has three breakpoints, and in a tool round the last one sits on the last tool result
  - GIVEN caching off WHEN a request is built THEN no cache_control is present
- [ ] Implement
- [ ] Test (PHPUnit on the payload builder)

### Task 2: A stable and a per-turn system block
- **spec_ref**: `openspec/changes/models-prompt-and-answer-cache/specs/provider-prompt-caching/spec.md#requirement-the-stable-part-of-the-system-prompt-comes-first-and-stays-the-same-req-pcache-002`
- **files**: `lib/Service/Engine/ResponseGenerationHandler.php`, `lib/Service/Llm/ProviderFactory.php` (mapHistoryToAnthropicMessages)
- **acceptance_criteria**:
  - GIVEN two turns with different retrieved context WHEN both requests are built THEN the stable block is byte-identical
- [ ] Implement
- [ ] Test (PHPUnit on prompt assembly for both provider paths)

### Task 3: Cache tokens on the run
- **spec_ref**: `openspec/changes/models-prompt-and-answer-cache/specs/provider-prompt-caching/spec.md#requirement-the-run-reports-cache-tokens-req-pcache-003`
- **files**: `lib/Service/Llm/ProviderFactory.php` (parseAnthropicResponse), `lib/Service/Engine/ResponseGenerationHandler.php`, `src/views/Runs.vue`
- **acceptance_criteria**:
  - GIVEN a response with cache_read_input_tokens WHEN the run is recorded THEN cacheReadTokens is set and promptTokens includes it
- [ ] Implement
- [ ] Test (PHPUnit on parsing; one live Anthropic check)

### Task 4: The AnswerCacheEntry schema and the agent setting
- **spec_ref**: `openspec/changes/models-prompt-and-answer-cache/specs/answer-cache/spec.md#requirement-the-answer-cache-is-opt-in-per-agent-and-only-for-turns-without-tools-req-acache-001`
- **files**: `lib/Settings/hermiq_register.json` (AnswerCacheEntry, Agent.answerCache), `src/modals/AgentFormModal.vue`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN an agent owner WHEN they switch the cache on with a lifetime THEN it is stored within bounds, and organisation scope is disabled while retrieval is on
- [ ] Implement
- [ ] Test (register validation test; Playwright under tests/e2e/spec-coverage/)

### Task 5: Exact lookup and store
- **spec_ref**: `openspec/changes/models-prompt-and-answer-cache/specs/answer-cache/spec.md#requirement-an-exact-repeat-within-one-organisation-gets-the-stored-answer-req-acache-002`
- **files**: `lib/Service/AnswerCache/AnswerCacheService.php`, `lib/Service/Engine/ResponseGenerationHandler.php`, `lib/Controller/ChatStreamController.php`
- **acceptance_criteria**:
  - GIVEN an eligible turn that repeats a stored input WHEN it runs THEN no model is called and the final event has fromCache true
  - GIVEN a turn with tools, a dry run or another organisation WHEN it runs THEN the cache is not read
  - GIVEN an entry whose model the policy no longer allows WHEN it would hit THEN it is deleted and the model is called
- [ ] Implement
- [ ] Test (PHPUnit on eligibility, key and policy re-check; Newman that a second organisation reads no entry)

### Task 6: The similarity layer through the vector facade
- **spec_ref**: `openspec/changes/models-prompt-and-answer-cache/specs/answer-cache/spec.md#requirement-similar-questions-match-only-through-openregisters-vector-facade-req-acache-003`
- **files**: `lib/Service/AnswerCache/AnswerCacheService.php`
- **acceptance_criteria**:
  - GIVEN the facade is absent WHEN a first question arrives THEN only the exact layer runs
  - GIVEN the facade returns a row of another user under user scope WHEN hermiq re-checks it THEN it is ignored
- [ ] Implement
- [ ] Test (PHPUnit with a stubbed facade)

### Task 7: Clearing, expiry and the chat note
- **spec_ref**: `openspec/changes/models-prompt-and-answer-cache/specs/answer-cache/spec.md#requirement-cached-answers-can-be-cleared-and-expire-req-acache-004`
- **files**: `lib/BackgroundJob/AnswerCachePurgeJob.php`, `lib/Controller/ChatController.php` (feedback), `lib/Controller/AgentsController.php`, `src/views/Chat.vue`
- **acceptance_criteria**:
  - GIVEN a served answer WHEN it gets a thumbs down THEN its entry is gone
  - GIVEN an expired entry WHEN the daily job runs THEN it is removed
  - GIVEN "Answered from cache" WHEN the user presses "Ask the model again" THEN the model is called and the cache bypassed
- [ ] Implement
- [ ] Test (PHPUnit on the job and the feedback hook; Playwright under tests/e2e/spec-coverage/ for the note and the button)

## Verification
- [ ] `openspec validate models-prompt-and-answer-cache --type change --strict` passes
- [ ] PHPUnit, Newman and Playwright run once before push, exit codes read
- [ ] One live Anthropic session shows cache read tokens on its second turn
