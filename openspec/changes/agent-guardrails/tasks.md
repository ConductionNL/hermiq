# Tasks: agent-guardrails

## Implementation Tasks

### Task 1: GuardrailPolicy schema + Approval schema extension
- **spec_ref**: `openspec/changes/agent-guardrails/specs/agent-guardrails/spec.md#requirement-per-organisation-guardrail-policy-with-a-fully-open-fallback`
- **files**: `lib/Settings/hermiq_register.json`, `appinfo/info.xml`
- **acceptance_criteria**:
  - GIVEN the register re-import runs after this change WHEN `occ` upgrade/repair executes THEN a new `GuardrailPolicy` schema (`inputFilters`, `outputFilters`, `toolPolicy`, `enabled`) is present
  - GIVEN the same re-import WHEN it completes THEN the `Approval` schema's `sourceType` enum includes `toolcall` and gains `toolId`/`toolArguments`/`consumedAt` fields, all optional
  - GIVEN `appinfo/info.xml` WHEN diffed against its prior committed value THEN the patch version is incremented by exactly one
- [ ] Implement
- [ ] Test

### Task 2: GuardrailPolicyService — CRUD, resolution, and content filters
- **spec_ref**: `openspec/changes/agent-guardrails/specs/agent-guardrails/spec.md#requirement-input-is-filtered-before-every-llm-turn`
- **files**: `lib/Service/GuardrailPolicyService.php`
- **acceptance_criteria**:
  - GIVEN no `GuardrailPolicy` exists for an organisation or instance-wide WHEN `effectivePolicyFor()` is called THEN it returns the fully-open fallback (all filters off, `toolPolicy` empty)
  - GIVEN an organisation's own policy and an instance-default policy both exist WHEN `effectivePolicyFor()` is called for that organisation THEN the organisation's own policy is returned
  - GIVEN `piiAction: redact` and text containing a secret pattern WHEN `filterInput()`/`filterOutput()` run THEN the returned text is `RedactionService::redact()`'s masked output, with no new PII/secret pattern set introduced
  - GIVEN `piiAction: block` or `promptInjectionAction: block` and a matching input WHEN the filter runs THEN it reports a block outcome (not just a redacted string) so the caller can refuse the turn
- [ ] Implement
- [ ] Test

### Task 3: Wire input/output filters into Engine::processMessage()
- **spec_ref**: `openspec/changes/agent-guardrails/specs/agent-guardrails/spec.md#requirement-output-is-filtered-before-persistence-and-before-delivery`
- **files**: `lib/Service/Engine/Engine.php`
- **acceptance_criteria**:
  - GIVEN `inputFilters.promptInjectionAction: block` and a matching `$userMessage` WHEN `processMessage()` runs THEN the LLM is never called and no user/assistant `Message` is persisted for this attempt
  - GIVEN `inputFilters.piiAction: redact` WHEN `processMessage()` runs THEN both the persisted user `Message` and the text sent to the LLM are the redacted text
  - GIVEN `outputFilters.piiAction: redact` WHEN `processMessage()` runs THEN both the persisted assistant `Message` and the returned envelope's `message` field are the redacted text
- [ ] Implement
- [ ] Test

### Task 4: Wire input/output filters into ScheduleService::runAgentAsOwner()
- **spec_ref**: `openspec/changes/agent-guardrails/specs/agent-guardrails/spec.md#requirement-output-is-filtered-before-persistence-and-before-delivery`
- **files**: `lib/Service/ScheduleService.php`
- **acceptance_criteria**:
  - GIVEN the in-app engine feature flag is off (legacy `ChatService` branch) and the schedule's `prompt` matches a block rule WHEN `runAgentAsOwner()` runs THEN the legacy `ChatService` call never happens and the run fails with a guardrail-block reason inheriting existing retry/dead-letter handling
  - GIVEN either engine-flag state WHEN `runAgentAsOwner()` returns THEN the returned output string has already passed the output filter, so `runDue()`'s call to `DeliveryService::deliver()` never receives a raw blocked value
  - GIVEN a webhook- or flow-triggered run (which also call `runAgentAsOwner()`) WHEN the output filter blocks the result THEN their own persistence (resultField / audit summary) receives the filtered value with no changes needed in `FlowAgentRunService`/`WebhookAgentRunService` themselves
- [ ] Implement
- [ ] Test

### Task 5: Tool classification (auto/deny) enforced in FacadeToolInvoker
- **spec_ref**: `openspec/changes/agent-guardrails/specs/agent-guardrails/spec.md#requirement-per-tool-risk-classification-enforced-before-invocation`
- **files**: `lib/Service/Engine/ToolLoop.php`, `lib/Service/Engine/FacadeToolInvoker.php`, `lib/Service/Engine/ResponseGenerationHandler.php`
- **acceptance_criteria**:
  - GIVEN `ResponseGenerationHandler` already resolves the agent's `organisation` for tenant-model-policy WHEN it calls `ToolLoop::buildFunctionInfos()` THEN the same organisation is threaded through to resolve the effective `GuardrailPolicy` exactly once for the turn
  - GIVEN a tool with no `toolPolicy` entry WHEN it is called THEN `FacadeToolInvoker` invokes it unchanged (`auto`, zero regression)
  - GIVEN a tool classified `deny` WHEN it is called THEN `ToolRegistryFacade::invokeTool()` is never reached, a refusal tool-result is returned to the LLM, and a `tool` trace step with a denied outcome is recorded
- [ ] Implement
- [ ] Test

### Task 6: ApprovalService sourceType=toolcall + DeliveryService notification
- **spec_ref**: `openspec/changes/agent-guardrails/specs/agent-guardrails/spec.md#requirement-a-confirm-classified-tool-call-reuses-the-existing-human-approval-gate`
- **files**: `lib/Service/ApprovalService.php`, `lib/Service/DeliveryService.php`
- **acceptance_criteria**:
  - GIVEN a `confirm` tool call with no existing Approval WHEN `ensurePendingApprovalForToolCall()` is called THEN exactly one pending `Approval` (`sourceType: toolcall`) is created, carrying `toolId`/`toolArguments`/`correlationId`, and the resolved reviewer is notified
  - GIVEN a pending `toolcall` Approval already exists for the same correlationId WHEN `ensurePendingApprovalForToolCall()` is called again THEN no duplicate is created (idempotent, mirroring the schedule/flow/webhook cases)
  - GIVEN a `toolcall` Approval is approved WHEN `ApprovalService::approve()` runs THEN `resumeGatedRun()`'s `toolcall` branch returns `ran: false` and dispatches nothing
- [ ] Implement
- [ ] Test

### Task 7: Confirm-tool retry-and-consume flow in FacadeToolInvoker
- **spec_ref**: `openspec/changes/agent-guardrails/specs/agent-guardrails/spec.md#requirement-a-confirm-classified-tool-call-reuses-the-existing-human-approval-gate`
- **files**: `lib/Service/Engine/FacadeToolInvoker.php`, `lib/Service/ApprovalService.php`
- **acceptance_criteria**:
  - GIVEN a `confirm` tool called for the first time WHEN `FacadeToolInvoker::__call()` runs THEN it refuses the call, triggers `ensurePendingApprovalForToolCall()`, and returns an "awaiting approval" tool-result
  - GIVEN an approved, unconsumed `toolcall` Approval matching the exact agent/tool/arguments within the validity window WHEN the identical tool call is retried THEN the underlying tool is invoked exactly once and the Approval is marked consumed
  - GIVEN that same Approval has already been consumed WHEN the identical tool call is attempted again THEN it is treated as a brand-new, unapproved attempt (a fresh pending Approval, not a second free invocation)
- [ ] Implement
- [ ] Test

### Task 8: GuardrailPolicy admin API + Vue client
- **spec_ref**: `openspec/changes/agent-guardrails/specs/agent-guardrails/spec.md#requirement-guardrail-policy-administration-is-authorization-guarded`
- **files**: `lib/Controller/GuardrailPolicyController.php`, `appinfo/routes.php`, `src/api/guardrailPolicy.js`
- **acceptance_criteria**:
  - GIVEN an instance admin WHEN they request or upsert any organisation's policy (including the instance default) THEN the request succeeds
  - GIVEN an organisation owner WHEN they request or upsert another organisation's policy THEN the request is rejected
  - GIVEN the Vue API client WHEN it calls the effective-policy endpoint THEN it returns the shape documented in design.md's API Design section
- [ ] Implement
- [ ] Test

### Task 9: l10n strings
- **spec_ref**: `openspec/changes/agent-guardrails/specs/agent-guardrails/spec.md#requirement-every-guardrail-action-is-visible-in-run-history`
- **files**: `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN the new blocked-content chat error, the tool-confirm approval notification subject/summary, and the policy admin form labels WHEN `l10n/en.json` is inspected THEN every new key is present with an English value
  - GIVEN the same keys WHEN `l10n/nl.json` is inspected THEN every key has a Dutch value
- [ ] Implement
- [ ] Test

## Quality checklist

- All new/changed business logic covered by PHPUnit unit tests (`tests/Unit/`) — GuardrailPolicyService resolution/filters, FacadeToolInvoker classification branches, ApprovalService toolcall generalisation
- New/changed API endpoints (GuardrailPolicyController) covered by Newman/Postman tests
- UI changes (policy admin form) covered by Playwright browser tests
- All tests pass (`composer test`, `newman run`)
- Feature documentation updated in `docs/` (guardrail policy admin surface)
- Dutch (`nl_NL`) and English (`en_US`) translation strings added for every new user-facing string (ADR-007)
- `openspec validate --strict` passes
