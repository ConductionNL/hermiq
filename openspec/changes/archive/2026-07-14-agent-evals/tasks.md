# Tasks: agent-evals

## Implementation Tasks

### Task 1: EvalDataset + EvalRun schemas
- **spec_ref**: `openspec/changes/agent-evals/specs/agent-evals/spec.md#requirement-evaldataset-crud-as-a-plain-openregister-object`
- **files**: `lib/Settings/hermiq_register.json`, `appinfo/info.xml`
- **acceptance_criteria**:
  - GIVEN the register re-import runs WHEN the app version is bumped THEN `evaldataset` and `evalrun` schemas exist with `publicRead:false, publicWrite:false`
  - GIVEN the `EvalDataset` schema WHEN a case's `expectationType` is `contains`/`notContains`/`jsonPathEquals`/`rubric` THEN the matching deterministic/rubric fields are present on the embedded `cases` array item schema
- [x] Implement
- [x] Test

### Task 2: ScheduleService run-usage/run-steps getters
- **spec_ref**: `openspec/changes/agent-evals/specs/agent-evals/spec.md#requirement-an-evalrun-executes-each-case-through-the-agents-real-engine-path`
- **files**: `lib/Service/ScheduleService.php`
- **acceptance_criteria**:
  - GIVEN `runAgentAsOwner()` just completed WHEN a caller reads `getLastRunUsage()`/`getLastRunSteps()` THEN it sees the SAME data `writeRunAudit()` already records internally
  - GIVEN no existing caller of `ScheduleService` reads these getters WHEN this task ships THEN every existing test/behaviour is unchanged (additive only)
- [x] Implement
- [x] Test

### Task 3: BudgetService scope-union widening for EvalRun
- **spec_ref**: `openspec/changes/agent-evals/specs/agent-evals/spec.md#requirement-eval-run-token-usage-counts-toward-the-same-per-orgper-agent-budget-a-scheduled-run-does`
- **files**: `lib/Service/BudgetService.php`
- **acceptance_criteria**:
  - GIVEN a completed EvalRun with a recorded `action='run'` AuditTrail entry WHEN `BudgetService::isBlocked()`/`statusForScope()` is next evaluated for the same organisation/agent THEN the EvalRun's usage is included in `currentUsageTokens()`
  - GIVEN only Schedule-driven usage existed before this task WHEN this task ships THEN Schedule-only budget behaviour is unchanged (union is additive)
- [x] Implement
- [x] Test

### Task 4: ProviderFactory::generateText() optional organisation param
- **spec_ref**: `openspec/changes/agent-evals/specs/agent-evals/spec.md#requirement-llm-as-judge-scoring-goes-through-the-existing-providerfactory-chokepoint`
- **files**: `lib/Service/Llm/ProviderFactory.php`
- **acceptance_criteria**:
  - GIVEN an organisation whose ModelPolicy excludes the configured provider/model WHEN `generateText(prompt, userId, allowNextcloud, organisation: $org)` is called THEN it throws `ModelPolicyViolationException`
  - GIVEN an existing caller (`AbstractTextProvider`) that never passes `$organisation` WHEN this task ships THEN its behaviour is unchanged (default `null`, no enforcement)
- [x] Implement
- [x] Test

### Task 5: EvalScoringService (deterministic + LLM-judge)
- **spec_ref**: `openspec/changes/agent-evals/specs/agent-evals/spec.md#requirement-deterministic-scoring`
- **files**: `lib/Service/EvalScoringService.php`
- **acceptance_criteria**:
  - GIVEN a `contains`/`notContains` case WHEN scored against an actual output THEN `passed` reflects the substring check
  - GIVEN a `jsonPathEquals` case with malformed actual-output JSON WHEN scored THEN `passed=false` with a non-fatal `errorMessage` (never throws)
  - GIVEN a `rubric` case WHEN scored via `ProviderFactory::generateText()` THEN the returned score is compared against `rubricPassThreshold` to set `passed`
- [x] Implement
- [x] Test

### Task 6: EvalRunService orchestration
- **spec_ref**: `openspec/changes/agent-evals/specs/agent-evals/spec.md#requirement-kill-switch-and-budget-hard-cap-gate-an-eval-run-exactly-as-they-gate-a-schedule-tick`
- **files**: `lib/Service/EvalRunService.php`
- **acceptance_criteria**:
  - GIVEN an engaged kill-switch or exhausted budget hard cap for the target organisation WHEN an EvalRun is triggered THEN no case executes and the run is recorded `blocked_killswitch`/`blocked_budget`
  - GIVEN all gates pass WHEN each case executes THEN it goes through `ScheduleService::runAgentAsOwner()` and `DeliveryService` is never called
  - GIVEN the run completes WHEN its `passRate` is compared against the previous completed EvalRun for the same dataset+agent THEN `regressionGateResult` (`passed`/`failed`/`not_applicable`) is recorded per the configured threshold
  - GIVEN the run completes (success or failure) WHEN persistence finishes THEN one redacted `action='run'` AuditTrail entry is written via `AuditTrailMapper`
- [x] Implement
- [x] Test

### Task 7: EvalRunController + route
- **spec_ref**: `openspec/changes/agent-evals/specs/agent-evals/spec.md#requirement-run-trigger-endpoint-is-owner-guarded-idor`
- **files**: `lib/Controller/EvalRunController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN a dataset/agent owned by another user WHEN a non-owner calls `POST /api/evals/{datasetId}/run` THEN the response is `404`
  - GIVEN a valid owned dataset+agent WHEN the endpoint is called THEN it returns `evalRunId`, `status`, `passRate`, `regressionGateResult`, `previousPassRate`
- [x] Implement
- [x] Test

### Task 8: Frontend data layer (store + api + manifest)
- **spec_ref**: `openspec/changes/agent-evals/specs/agent-evals/spec.md#requirement-evaldataset-crud-as-a-plain-openregister-object`
- **files**: `src/store/store.js`, `src/api/evalRuns.js`, `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN `useEvalDatasetStore`/`useEvalRunStore` WHEN a component calls their CRUD methods THEN they hit `/apps/openregister/api/objects/hermiq/{evaldataset|evalrun}` like `useScheduleStore`
  - GIVEN the manifest WHEN the app loads THEN "Eval datasets" and "Eval runs" pages/menu entries are registered
- [x] Implement
- [x] Test

### Task 9: EvalDatasetFormModal + EvalDatasets.vue
- **spec_ref**: `openspec/changes/agent-evals/specs/agent-evals/spec.md#requirement-evaldataset-crud-as-a-plain-openregister-object`
- **files**: `src/modals/EvalDatasetFormModal.vue`, `src/views/EvalDatasets.vue`
- **acceptance_criteria**:
  - GIVEN the dataset list page WHEN rendered THEN it uses `CnDataTable` and an "Add case" control that appends a row to the embedded `cases` array
  - GIVEN a case row WHEN `expectationType` changes THEN only the matching fields for that type are shown/required
- [x] Implement
- [x] Test

### Task 10: EvalRuns.vue + l10n strings
- **spec_ref**: `openspec/changes/agent-evals/specs/agent-evals/spec.md#requirement-ui-surfaces-per-case-results-and-pass-rate-trend`
- **files**: `src/views/EvalRuns.vue`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN a dataset+agent selection WHEN "Run eval" is clicked THEN the run's per-case results render with failing cases visually distinguished
  - GIVEN a dataset+agent's run history WHEN the page loads THEN a pass-rate trend across past runs is shown
- [x] Implement
- [x] Test

## Quality checklist

- All new/changed business logic covered by PHPUnit unit tests (`tests/Unit/`)
- New/changed API endpoints covered by Newman/Postman tests
- UI changes covered by Playwright browser tests
- All tests pass (`composer test`, `newman run`)
- Feature documentation updated in `docs/` if user-facing (ADR-010)
- Dutch (`nl_NL`) and English (`en_US`) translation strings added for any new user-facing strings (ADR-007)
- `openspec validate` passes
