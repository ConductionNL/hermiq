# Tasks: models-several-models-per-turn

Kind: code. Size L. Rows `hermiq:mo-failover`, `hermiq:dm-model-ensemble`, `integriq:gw-ai-proxy`.

## Implementation tasks

### Task 1: The fallback chain on the model policy
- **spec_ref**: `openspec/changes/models-several-models-per-turn/specs/tenant-model-policy/spec.md#requirement-a-model-policy-may-name-a-fallback-chain-req-mfail-001`
- **files**: `lib/Settings/hermiq_register.json` (ModelPolicy), `lib/Service/TenantModelPolicyService.php`, `lib/Controller/TenantModelPolicyController.php`
- **acceptance_criteria**:
  - GIVEN a policy allowing anthropic and ollama WHEN an organisation admin saves an ollama fallback THEN the effective policy returns it
  - GIVEN a policy allowing only ollama WHEN an openai fallback is saved THEN the save is refused and nothing changes
- [x] Implement — `ModelPolicy` 0.4.0 `fallbackChain` (register 0.55.0); `OrganisationModelPolicyService::normaliseFallbackChain()` refuses an entry the same policy does not allow ("This fallback is not allowed by the model policy.", 400 through `OrganisationModelPolicyController`), more than four entries, or a malformed list; an update without `fallbackChain` keeps the stored chain; `EffectiveModelPolicy::shape()` returns it, so `GET /api/model-policy/effective` does. (The service is `OrganisationModelPolicyService`; there is no `TenantModelPolicyService`.)
- [x] Test (PHPUnit on TenantModelPolicyService validation; Newman on PUT and GET /api/model-policy) — PHPUnit done (`OrganisationModelPolicyWriteTest`, `EffectiveModelPolicyTest`); the Newman run needs a live instance and is left
  - 2026-11-24: Newman folder "Model policy fallback chain" in `tests/integration/hermiq.postman_collection.json` written, runs in nightly (UNBLOCK rule 3): POST and GET return the ollama fallback, a PUT naming an openai fallback on an ollama-only policy answers 400 and changes nothing, an update without `fallbackChain` keeps it, five fallbacks answer 400. Not verified against a live instance here.

### Task 2: Typed provider failures
- **spec_ref**: `openspec/changes/models-several-models-per-turn/specs/tenant-model-policy/spec.md#requirement-a-refusal-or-a-configuration-error-never-triggers-a-fallback-req-mfail-003`
- **files**: `lib/Service/Llm/ProviderCallFailedException.php`, `lib/Service/Llm/ProviderFactory.php` (postToAnthropic, callFireworksChat), `lib/Service/Engine/ResponseGenerationHandler.php` (invokeChat)
- **acceptance_criteria**:
  - GIVEN a provider answer of 429, 500, 401 or 400 WHEN the call fails THEN the exception carries kind rate_limit, server, auth or bad_request, and retry-after when sent
- [x] Implement
- [x] Test (PHPUnit per status code on both direct-HTTP paths and the LLPhant wrapper) — both direct-HTTP paths in `ProviderCallFailedTypingTest`; the wrapper through `LlphantChatCall::invokeChat()` in `LlphantChatCallTest` (429, 503, 401, 403, 400, timeout, connection; hermiq's own exceptions keep their class)

### Task 3: The fallback loop with the same gates per hop
- **spec_ref**: `openspec/changes/models-several-models-per-turn/specs/tenant-model-policy/spec.md#requirement-a-temporary-provider-failure-moves-the-turn-to-the-next-allowed-hop-req-mfail-002`
- **files**: `lib/Service/Engine/ResponseGenerationHandler.php`, `lib/Service/Llm/ProviderFactory.php` (createChatDriver overrides), `lib/Service/Engine/StreamYieldChannel.php`
- **acceptance_criteria**:
  - GIVEN a 429 on the first hop and nothing shown yet WHEN the turn runs THEN the next allowed hop answers
  - GIVEN a hop outside the feature's residency WHEN the loop reaches it THEN it is skipped without a call
  - GIVEN a tool already ran WHEN the provider fails THEN no hop is tried
- [x] Implement — `ProviderFallbackLoop` around the first call in `ResponseGenerationHandler::answerTurn()`; each hop goes through `createChatDriver()` with the new `providerOverride`/`modelOverride`, so model policy, residency and redaction run again and a refused hop is recorded `skipped` with its step; only temporary kinds move a turn; `StreamYieldChannel::answerShown()`/`toolCalled()` and `RunTraceCollector::hasToolStep()` keep a turn where it is ("Not retried: …"); the chain comes from `OrganisationModelPolicyService::fallbackChainFor()`; an exhausted chain fails with "No provider in the fallback chain is available right now."
- [x] Test (PHPUnit with stubbed drivers for each branch) — `ProviderFallbackLoopTest` (rate limit moves, residency skip without a call, tool ran, text shown, 401 and policy refusal, no chain, exhausted chain, cooling first, cooldown started), `ResponseGenerationHandlerTest::testARateLimitedTurnIsAnsweredByTheNextHop`, `EffectiveModelPolicyTest::testTheServiceHandsTheEffectiveChainToTheTurn`

### Task 4: Cooldown and the attempts on the run record
- **spec_ref**: `openspec/changes/models-several-models-per-turn/specs/tenant-model-policy/spec.md#requirement-every-attempt-is-on-the-run-record-req-mfail-005`
- **files**: `lib/Service/Llm/ProviderCooldown.php`, `lib/Service/Engine/RunTraceCollector.php`, `lib/Service/ScheduleService.php`, `src/views/Runs.vue`
- **acceptance_criteria**:
  - GIVEN a 429 with retry-after 30 WHEN a second turn in the same organisation runs within 30 seconds THEN the provider is recorded cooling and skipped
  - GIVEN a run that fell back WHEN a privacy officer opens it THEN both attempts and receivedBy are shown
- [x] Implement — `ProviderCooldown` (distributed cache `fallback:{organisation}:{provider}`, retry-after or 60 s, at most 600 s, rate limit and server errors only); a cooling first provider is recorded `cooling` and the turn starts at the next hop; `RunTraceCollector::recordProviderAttempt()` and `receivedBy` on the disclosure; `ScheduleService` writes `providerAttempts` on the run audit entry; `AnalyticsService` hands `providerAttempts` and `receivedBy` to the Runs page, which lists each attempt and "Received the prompt"
- [x] Test (PHPUnit with a fake cache and clock; Playwright under tests/e2e/spec-coverage/ on a seeded run record) — PHPUnit done (`ProviderCooldownTest` with a fake cache and clock, `ProviderFallbackLoopTest` cooling cases, `AnalyticsServiceTest::testARunRowShowsItsProviderAttempts`) and vitest `providerAttempts.spec.js`; the Playwright run needs a live instance and is left
  - 2026-11-21: `tests/e2e/spec-coverage/models-several-models-per-turn.spec.ts` written from the scenarios (the Runs page with a fallen-back run answered by page.route); not run here, it needs a running Nextcloud.
  - 2026-11-24: written, runs in nightly (UNBLOCK rule 3); not verified against a live instance here.

### Task 5: The fallback editor on Tenant operations
- **spec_ref**: `openspec/changes/models-several-models-per-turn/specs/tenant-model-policy/spec.md#requirement-a-model-policy-may-name-a-fallback-chain-req-mfail-001`
- **files**: `src/views/TenantOps.vue`, `src/api/modelPolicy.js`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN an organisation admin on the Model policy section WHEN they add, reorder and remove fallbacks THEN the order is saved and a refused entry shows the refusal text
- [x] Implement — a "Fallback models" block in the Model policy editor of `TenantOps.vue`: rows of provider and model, added (at most four), moved up and down, removed; the save sends `fallbackChain` in order, a refusal from the server ("This fallback is not allowed by the model policy.") is shown under the editor, and the policy summary names the chain. The list handling is in `src/utils/fallbackChain.js`; strings in `l10n/en.json` and `l10n/nl.json`, `.js` regenerated.
- [x] Test (Playwright under tests/e2e/spec-coverage/) — vitest done (`fallback-chain.spec.js`, the editor case in `tenant-ops-and-runs.spec.js`); the Playwright run needs a live instance and is left
  - 2026-11-21: `tests/e2e/spec-coverage/models-several-models-per-turn.spec.ts` written from the scenarios (add, reorder, remove and the refused fallback on Tenant operations, the policy endpoints answered by page.route); not run here, it needs a running Nextcloud.
  - 2026-11-24: vitest in; Playwright `models-several-models-per-turn.spec.ts` written, runs in nightly (UNBLOCK rule 3); not verified against a live instance here.

### Task 6: The ensemble setting on the agent
- **spec_ref**: `openspec/changes/models-several-models-per-turn/specs/model-ensemble/spec.md#requirement-an-agent-owner-can-switch-on-ensemble-answers-req-mens-001`
- **files**: `lib/Settings/hermiq_register.json` (Agent), `lib/Controller/AgentsController.php`, `src/modals/AgentFormModal.vue`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN an agent owner WHEN they pick two allowed reference models and save THEN the agent shows the ensemble and the cost line
  - GIVEN a reference model outside the policy WHEN saved THEN the save is refused
- [x] Implement — `Agent` 0.15.0 `ensemble` `{enabled, referenceModels}` (register 0.59.0); `ReferenceModelSettings::normalise()` refuses a reference model the effective policy does not allow ("This model is not allowed by your organisation's model policy."), more than three, fewer than two when enabled, or a malformed list. The agent form writes through OpenRegister's objects API, so the check runs in `AgentEnsembleGuardListener` on `ObjectCreatingEvent`/`ObjectUpdatingEvent` (the agent's organisation, else the writer's active one; an unchanged ensemble is not checked again), and in `AgentsController::create()`/`update()` for the direct API (400, agent unchanged). `EnsembleEditor.vue` on the agent form: the switch "Answer with several models", two or three provider/model rows from the policy's lists, and "Each answer costs up to four model calls."; the agent's Configuration card shows "Ensemble: 2 reference models" through the `agentEnsemble` formatter. Strings in `l10n/en.json` and `l10n/nl.json`, `.js` regenerated.
- [x] Test (PHPUnit on the save validation; Playwright under tests/e2e/spec-coverage/) — PHPUnit done (`ReferenceModelSettingsTest`, `AgentEnsembleGuardListenerTest`, `AgentsControllerEnsembleTest`) and vitest `agent-ensemble.spec.js`; the Playwright run needs a live instance and is left
  - 2026-11-21: `tests/e2e/spec-coverage/models-several-models-per-turn.spec.ts` written from the scenarios (the ensemble set up through the real save, and the refused reference model); not run here, it needs a running Nextcloud.
  - 2026-11-24: written, runs in nightly (UNBLOCK rule 3); not verified against a live instance here.

### Task 7: The ensemble turn and its budget
- **spec_ref**: `openspec/changes/models-several-models-per-turn/specs/model-ensemble/spec.md#requirement-reference-models-answer-without-tools-and-the-agents-own-model-merges-req-mens-002`
- **files**: `lib/Service/Engine/ResponseGenerationHandler.php`, `lib/Service/Engine/Engine.php`, `lib/Service/Llm/ProviderFactory.php` (return usage from callAnthropicChat and callFireworksChat), `lib/Service/ScheduleService.php`
- **acceptance_criteria**:
  - GIVEN two reference models WHEN a turn runs THEN each is called without tools, the agent's model merges with its tools, and usageByModel sums into usage
  - GIVEN a reference outside the residency or a budget at its cap WHEN the turn runs THEN that call is skipped and recorded
  - GIVEN an Anthropic, Fireworks or OpenAI call WHEN it returns THEN its input and output tokens are in the run's usage
- [x] Implement — `ReferenceAnswerRound` asks each reference model (at most three) one after the other through `createChatDriver()` with the reference as override, so model policy, residency and redaction refuse one before it is called (recorded `skipped` with its step); each call is made on a copy of the turn with no tools, no stream and no trace steps; a failing reference is recorded `failed` with its kind and left out; `BudgetService::isBlocked()` is checked before each call and stops the round ("Budget reached: answered from 1 model"); the steps say "Asked 2 models", and with no answer the agent's own model answers alone and says so. `ResponseGenerationHandler` then runs the agent's own turn with the answers in the system prompt as a labelled block (`TurnSystemPrompt`), with its tools and stream; `EnsembleUsage` makes `usage` the sum of every call and adds `usageByModel`; `RunTraceCollector` keeps the reference answers and `ScheduleService` writes them, redacted, as `referenceAnswers` on the run audit entry. Usage from the Anthropic, Fireworks and OpenAI calls was already returned on development (hermiq#985), so no `ProviderFactory` change was needed.
- [ ] Test (PHPUnit with stubbed drivers and BudgetService; one live chat turn on an ensemble agent) — PHPUnit done (`ReferenceAnswerRoundTest`, `EnsembleUsageTest`, `ResponseGenerationEnsembleTest`); the live chat turn needs a live instance and is left

## Verification
- [x] `openspec validate models-several-models-per-turn --type change --strict` passes
  - 2026-11-21: valid under `@fission-ai/openspec@1.2.0`, the version hydra's apply loop pins.
- [ ] PHPUnit and Playwright run once before push, exit codes read
- [ ] One live turn with the first provider's base URL pointed at an address that does not answer shows the fallback and both attempts on the run
