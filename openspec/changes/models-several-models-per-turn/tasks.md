# Tasks: models-several-models-per-turn

Kind: code. Size L. Rows `hermiq:mo-failover`, `hermiq:dm-model-ensemble`, `integriq:gw-ai-proxy`.

## Implementation tasks

### Task 1: The fallback chain on the model policy
- **spec_ref**: `openspec/changes/models-several-models-per-turn/specs/tenant-model-policy/spec.md#requirement-a-model-policy-may-name-a-fallback-chain-req-mfail-001`
- **files**: `lib/Settings/hermiq_register.json` (ModelPolicy), `lib/Service/TenantModelPolicyService.php`, `lib/Controller/TenantModelPolicyController.php`
- **acceptance_criteria**:
  - GIVEN a policy allowing anthropic and ollama WHEN an organisation admin saves an ollama fallback THEN the effective policy returns it
  - GIVEN a policy allowing only ollama WHEN an openai fallback is saved THEN the save is refused and nothing changes
- [ ] Implement
- [ ] Test (PHPUnit on TenantModelPolicyService validation; Newman on PUT and GET /api/model-policy)

### Task 2: Typed provider failures
- **spec_ref**: `openspec/changes/models-several-models-per-turn/specs/tenant-model-policy/spec.md#requirement-a-refusal-or-a-configuration-error-never-triggers-a-fallback-req-mfail-003`
- **files**: `lib/Service/Llm/ProviderCallFailedException.php`, `lib/Service/Llm/ProviderFactory.php` (postToAnthropic, callFireworksChat), `lib/Service/Engine/ResponseGenerationHandler.php` (invokeChat)
- **acceptance_criteria**:
  - GIVEN a provider answer of 429, 500, 401 or 400 WHEN the call fails THEN the exception carries kind rate_limit, server, auth or bad_request, and retry-after when sent
- [ ] Implement
- [ ] Test (PHPUnit per status code on both direct-HTTP paths and the LLPhant wrapper)

### Task 3: The fallback loop with the same gates per hop
- **spec_ref**: `openspec/changes/models-several-models-per-turn/specs/tenant-model-policy/spec.md#requirement-a-temporary-provider-failure-moves-the-turn-to-the-next-allowed-hop-req-mfail-002`
- **files**: `lib/Service/Engine/ResponseGenerationHandler.php`, `lib/Service/Llm/ProviderFactory.php` (createChatDriver overrides), `lib/Service/Engine/StreamYieldChannel.php`
- **acceptance_criteria**:
  - GIVEN a 429 on the first hop and nothing shown yet WHEN the turn runs THEN the next allowed hop answers
  - GIVEN a hop outside the feature's residency WHEN the loop reaches it THEN it is skipped without a call
  - GIVEN a tool already ran WHEN the provider fails THEN no hop is tried
- [ ] Implement
- [ ] Test (PHPUnit with stubbed drivers for each branch)

### Task 4: Cooldown and the attempts on the run record
- **spec_ref**: `openspec/changes/models-several-models-per-turn/specs/tenant-model-policy/spec.md#requirement-every-attempt-is-on-the-run-record-req-mfail-005`
- **files**: `lib/Service/Llm/ProviderCooldown.php`, `lib/Service/Engine/RunTraceCollector.php`, `lib/Service/ScheduleService.php`, `src/views/Runs.vue`
- **acceptance_criteria**:
  - GIVEN a 429 with retry-after 30 WHEN a second turn in the same organisation runs within 30 seconds THEN the provider is recorded cooling and skipped
  - GIVEN a run that fell back WHEN a privacy officer opens it THEN both attempts and receivedBy are shown
- [ ] Implement
- [ ] Test (PHPUnit with a fake cache and clock; Playwright under tests/e2e/spec-coverage/ on a seeded run record)

### Task 5: The fallback editor on Tenant operations
- **spec_ref**: `openspec/changes/models-several-models-per-turn/specs/tenant-model-policy/spec.md#requirement-a-model-policy-may-name-a-fallback-chain-req-mfail-001`
- **files**: `src/views/TenantOps.vue`, `src/api/modelPolicy.js`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN an organisation admin on the Model policy section WHEN they add, reorder and remove fallbacks THEN the order is saved and a refused entry shows the refusal text
- [ ] Implement
- [ ] Test (Playwright under tests/e2e/spec-coverage/)

### Task 6: The ensemble setting on the agent
- **spec_ref**: `openspec/changes/models-several-models-per-turn/specs/model-ensemble/spec.md#requirement-an-agent-owner-can-switch-on-ensemble-answers-req-mens-001`
- **files**: `lib/Settings/hermiq_register.json` (Agent), `lib/Controller/AgentsController.php`, `src/modals/AgentFormModal.vue`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN an agent owner WHEN they pick two allowed reference models and save THEN the agent shows the ensemble and the cost line
  - GIVEN a reference model outside the policy WHEN saved THEN the save is refused
- [ ] Implement
- [ ] Test (PHPUnit on the save validation; Playwright under tests/e2e/spec-coverage/)

### Task 7: The ensemble turn and its budget
- **spec_ref**: `openspec/changes/models-several-models-per-turn/specs/model-ensemble/spec.md#requirement-reference-models-answer-without-tools-and-the-agents-own-model-merges-req-mens-002`
- **files**: `lib/Service/Engine/ResponseGenerationHandler.php`, `lib/Service/Engine/Engine.php`, `lib/Service/Llm/ProviderFactory.php` (return usage from callAnthropicChat and callFireworksChat), `lib/Service/ScheduleService.php`
- **acceptance_criteria**:
  - GIVEN two reference models WHEN a turn runs THEN each is called without tools, the agent's model merges with its tools, and usageByModel sums into usage
  - GIVEN a reference outside the residency or a budget at its cap WHEN the turn runs THEN that call is skipped and recorded
  - GIVEN an Anthropic, Fireworks or OpenAI call WHEN it returns THEN its input and output tokens are in the run's usage
- [ ] Implement
- [ ] Test (PHPUnit with stubbed drivers and BudgetService; one live chat turn on an ensemble agent)

## Verification
- [ ] `openspec validate models-several-models-per-turn --type change --strict` passes
- [ ] PHPUnit and Playwright run once before push, exit codes read
- [ ] One live turn with the first provider's base URL pointed at an address that does not answer shows the fallback and both attempts on the run
