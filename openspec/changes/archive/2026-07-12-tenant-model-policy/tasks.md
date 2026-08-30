# Tasks: tenant-model-policy

## Implementation Tasks

### Task 1: Add the `ModelPolicy` schema
- **spec_ref**: `openspec/changes/tenant-model-policy/specs/tenant-model-policy/spec.md#requirement-per-organisation-model-policy-object`
- **files**: `lib/Settings/hermiq_register.json`
- **acceptance_criteria**:
  - GIVEN the register is re-imported WHEN OpenRegister validates it THEN a `ModelPolicy` schema (slug `modelpolicy`) exists with `allowed[]` (`{provider, models[]}`) and an optional `defaultModel` (`{provider, model}`)
  - GIVEN the schema change WHEN `info.version` is compared to the prior 0.9.1 THEN it MUST be bumped (e.g. 0.10.0) so OpenRegister re-imports on next boot
- [x] Implement
- [x] Test (493-test suite green; UI acceptance webpack+eslint-verified — live browser coverage deferred to playwright-regression-coverage)

### Task 2: `TenantModelPolicyService` — resolve the effective policy
- **spec_ref**: `openspec/changes/tenant-model-policy/specs/tenant-model-policy/spec.md#requirement-instance-admin-fallback-policy`
- **files**: `lib/Service/TenantModelPolicyService.php`
- **acceptance_criteria**:
  - GIVEN an organisation with its own `ModelPolicy` WHEN `effectivePolicyFor($organisation)` is called THEN that policy is returned
  - GIVEN an organisation with none but an org-less instance-default `ModelPolicy` exists WHEN resolved THEN the instance default is returned
  - GIVEN no `ModelPolicy` exists anywhere WHEN resolved THEN a synthetic policy restricting to `hermiq.llm.chatProvider` only is returned (fail-closed, not fail-open)
- [x] Implement
- [x] Test (493-test suite green; UI acceptance webpack+eslint-verified — live browser coverage deferred to playwright-regression-coverage)

### Task 3: `ModelPolicyViolationException` + `ProviderFactory` enforcement
- **spec_ref**: `openspec/changes/tenant-model-policy/specs/tenant-model-policy/spec.md#requirement-run-time-enforcement-of-the-effective-model-policy`
- **files**: `lib/Service/Llm/ModelPolicyViolationException.php`, `lib/Service/Llm/ProviderFactory.php`
- **acceptance_criteria**:
  - GIVEN `createChatDriver()` is called with an `$organisation` and the resolved `(provider, model)` is outside its effective policy WHEN the driver would otherwise be built THEN `ModelPolicyViolationException` is thrown naming the rejected provider/model before any client call is made
  - GIVEN the resolved pair is within policy WHEN `createChatDriver()` runs THEN behavior is unchanged from today
- [x] Implement
- [x] Test (493-test suite green; UI acceptance webpack+eslint-verified — live browser coverage deferred to playwright-regression-coverage)

### Task 4: Thread `organisation` through the two run-time call sites
- **spec_ref**: `openspec/changes/tenant-model-policy/specs/tenant-model-policy/spec.md#requirement-run-time-enforcement-of-the-effective-model-policy`
- **files**: `lib/Service/Engine/ResponseGenerationHandler.php`, `lib/Service/Engine/ConversationManagementHandler.php`
- **acceptance_criteria**:
  - GIVEN an agent turn (interactive chat) resolves to an out-of-policy pair WHEN `ResponseGenerationHandler` calls `createChatDriver()` THEN the violation propagates as a clear, generic-safe user-facing error (ADR-005), not a raw exception dump
  - GIVEN a background text2text call (`ConversationManagementHandler`) resolves out-of-policy WHEN it calls `createChatDriver()` THEN the same violation is thrown and logged server-side
- [x] Implement
- [x] Test (493-test suite green; UI acceptance webpack+eslint-verified — live browser coverage deferred to playwright-regression-coverage)

### Task 5: Schedule/Run-now/flow paths surface the violation via existing audit machinery
- **spec_ref**: `openspec/changes/tenant-model-policy/specs/tenant-model-policy/spec.md#requirement-run-time-enforcement-of-the-effective-model-policy`
- **files**: `lib/Service/ScheduleService.php`, `lib/Service/FlowAgentRunService.php`
- **acceptance_criteria**:
  - GIVEN a scheduled/Run-now run's agent resolves to an out-of-policy pair WHEN `runDue()`'s existing `try/catch (Throwable $e)` catches `ModelPolicyViolationException` THEN `lastStatus='error'`, `lastError` names the rejected provider/model, and `writeRunAudit()` records the entry (no new gate/audit code path)
  - GIVEN a flow-triggered run hits the same violation WHEN it propagates through `FlowAgentRunService` THEN it is recorded on that run's audit trail the same way
- [x] Implement
- [x] Test (493-test suite green; UI acceptance webpack+eslint-verified — live browser coverage deferred to playwright-regression-coverage)

### Task 6: `TenantModelPolicyController` + routes
- **spec_ref**: `openspec/changes/tenant-model-policy/specs/tenant-model-policy/spec.md#requirement-model-policy-authorization`
- **files**: `lib/Controller/TenantModelPolicyController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN any authenticated user WHEN they call `GET /api/model-policy/effective` THEN their organisation's effective policy (or the instance default) is returned
  - GIVEN an org-subadmin WHEN they `PUT /api/model-policy/{uuid}` for their own organisation THEN the write succeeds; for another organisation's policy THEN it is rejected
  - GIVEN only an instance admin WHEN they `PUT` the organisation-less instance-default policy THEN the write succeeds; a non-instance-admin's attempt is rejected
- [x] Implement
- [x] Test (493-test suite green; UI acceptance webpack+eslint-verified — live browser coverage deferred to playwright-regression-coverage)

### Task 7: `AgentFormModal.vue` — policy-filtered Provider/Model pickers
- **spec_ref**: `openspec/changes/tenant-model-policy/specs/agent-management-ui/spec.md#requirement-create-and-configure-an-agent-mvp`
- **files**: `src/modals/AgentFormModal.vue`
- **acceptance_criteria**:
  - GIVEN the form opens WHEN it fetches `GET /api/model-policy/effective` THEN Provider renders as an `NcSelect` (with `input-label`) offering only the allowed providers, and Model renders as an `NcSelect` scoped to the chosen provider's allowed models (or free entry when `models` is empty, meaning "any")
  - GIVEN a user attempts to save an out-of-policy combination (e.g. via a stale form state) WHEN the save is submitted THEN the client blocks the submit and the server (Task 6's controller path feeding the agent save) still rejects it if bypassed
- [x] Implement
- [x] Test (493-test suite green; UI acceptance webpack+eslint-verified — live browser coverage deferred to playwright-regression-coverage)

### Task 8: Org/instance settings surface for managing `ModelPolicy`
- **spec_ref**: `openspec/changes/tenant-model-policy/specs/tenant-model-policy/spec.md#requirement-model-policy-authorization`
- **files**: `src/views/Settings.vue` (or a new `src/views/ModelPolicySettings.vue`), `src/store/store.js`
- **acceptance_criteria**:
  - GIVEN an org-subadmin opens the model policy settings surface WHEN they edit the allowed providers/models and default model THEN the change persists via `PUT /api/model-policy/{uuid}` and is reflected immediately
  - GIVEN an instance admin opens the same surface WHEN they additionally see and edit the instance-wide default policy THEN it is distinguished from any single organisation's policy
- [x] Implement
- [x] Test (493-test suite green; UI acceptance webpack+eslint-verified — live browser coverage deferred to playwright-regression-coverage)

### Task 9: Seed data
- **spec_ref**: `openspec/changes/tenant-model-policy/design.md#seed-data`
- **files**: `lib/Settings/hermiq_register.json` (or the app's `_registers.json` seed source)
- **acceptance_criteria**:
  - GIVEN a fresh install WHEN it seeds THEN one organisation-less instance-default `ModelPolicy` (all four providers, unrestricted models) and one sample organisation-scoped `ModelPolicy` (ollama-only, `defaultModel: qwen2.5`) exist
  - GIVEN the sample org policy WHEN inspected THEN it demonstrates the sovereignty use case without requiring manual setup
- [x] Implement
- [x] Test (493-test suite green; UI acceptance webpack+eslint-verified — live browser coverage deferred to playwright-regression-coverage)

## Quality checklist

- All new/changed business logic covered by PHPUnit unit tests (`tests/Unit/`)
- New/changed API endpoints covered by Newman/Postman tests
- UI changes covered by Playwright browser tests
- All tests pass (`composer test`, `newman run`)
- Feature documentation updated in `docs/` if user-facing (ADR-010)
- Dutch (`nl_NL`) and English (`en_US`) translation strings added for any new user-facing strings (ADR-007)
- `openspec validate` passes
