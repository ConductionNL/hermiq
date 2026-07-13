# Tasks: run-replay-and-dry-run

## Implementation Tasks

### Task 1: `ToolClassificationService` — fail-safe-closed per-tool classification
- **spec_ref**: `openspec/changes/run-replay-and-dry-run/specs/run-replay-and-dry-run/spec.md#requirement-dry-run-neutralises-side-effecting-tool-calls`
- **files**: `lib/Service/ToolClassificationService.php`, `tests/Unit/Service/ToolClassificationServiceTest.php`
- **acceptance_criteria**:
  - GIVEN a registry id explicitly listed as read-only (e.g. `openregister.searchObjects`) WHEN
    `isSideEffecting($id)` is called THEN it returns `false`
  - GIVEN a registry id not present in the classification map WHEN `isSideEffecting($id)` is called
    THEN it returns `true` (fail-safe closed — the default MUST be side-effecting)
  - GIVEN an empty or malformed registry id WHEN `isSideEffecting($id)` is called THEN it returns
    `true` rather than throwing
- [ ] Implement
- [ ] Test

### Task 2: `FacadeToolInvoker` dry-run neutralisation with redacted `would-have-called` steps
- **spec_ref**: `openspec/changes/run-replay-and-dry-run/specs/run-replay-and-dry-run/spec.md#requirement-dry-run-neutralises-side-effecting-tool-calls`
- **files**: `lib/Service/Engine/FacadeToolInvoker.php`, `tests/Unit/Service/Engine/FacadeToolInvokerTest.php`
- **acceptance_criteria**:
  - GIVEN `dryRun=true` and a side-effecting tool WHEN `__call()` dispatches it THEN
    `ToolRegistryFacade::invokeTool()` is NEVER called, a trace step with
    `outcome='would-have-called'` and redacted arguments is recorded, and a synthetic result is
    returned to the LLM
  - GIVEN `dryRun=true` and a read-only tool WHEN `__call()` dispatches it THEN
    `ToolRegistryFacade::invokeTool()` IS called for real and the step outcome is `ok`/`error`
  - GIVEN `dryRun=false` (default, existing callers) WHEN `__call()` dispatches any tool THEN
    behavior is byte-for-byte unchanged from before this change
  - GIVEN a `would-have-called` step's arguments contain a secret-shaped value WHEN it is recorded
    THEN `RedactionService::redact()` has masked it before it reaches the trace
- [ ] Implement
- [ ] Test

### Task 3: Thread `dryRun` through `ToolLoop`, `Engine`, and `ResponseGenerationHandler`
- **spec_ref**: `openspec/changes/run-replay-and-dry-run/specs/run-replay-and-dry-run/spec.md#requirement-dry-run-neutralises-side-effecting-tool-calls`
- **files**: `lib/Service/Engine/ToolLoop.php`, `lib/Service/Engine/Engine.php`, `lib/Service/Engine/ResponseGenerationHandler.php`, `tests/Unit/Service/Engine/EngineTest.php`
- **acceptance_criteria**:
  - GIVEN `Engine::processMessage(..., dryRun: true)` WHEN the turn calls a tool THEN the flag
    reaches `FacadeToolInvoker` via `ResponseGenerationHandler`/`ToolLoop::buildFunctionInfos()`
  - GIVEN `Engine::processMessage()` is called with no `dryRun` argument (existing callers) WHEN the
    turn completes THEN behavior is unchanged (defaults to `false`)
- [ ] Implement
- [ ] Test

### Task 4: `ScheduleService::evaluateGates()` — extracted, non-mutating gate check
- **spec_ref**: `openspec/changes/run-replay-and-dry-run/specs/run-replay-and-dry-run/spec.md#requirement-dry-run-and-replay-respect-existing-governance-gates-without-mutating-schedule-state`
- **files**: `lib/Service/ScheduleService.php`, `tests/Unit/Service/ScheduleServiceTest.php`
- **acceptance_criteria**:
  - GIVEN an organisation with an engaged kill-switch WHEN `evaluateGates()` is called for one of
    its schedules THEN it returns `skipped_killswitch` and `dispatch()`'s own behavior is unchanged
  - GIVEN a budget-exhausted organisation/agent WHEN `evaluateGates()` is called THEN it returns
    `skipped_budget`
  - GIVEN a schedule with `requiresApproval=true` and no bypass WHEN `evaluateGates()` is called
    THEN it returns `awaiting_approval` WITHOUT creating a new pending `Approval` object
  - GIVEN none of the above WHEN `evaluateGates()` is called THEN it returns `null` (pass)
- [ ] Implement
- [ ] Test

### Task 5: `ScheduleService::dryRunNow()` — preview entry point + scratch-conversation cleanup
- **spec_ref**: `openspec/changes/run-replay-and-dry-run/specs/run-replay-and-dry-run/spec.md#requirement-dry-run-and-replay-require-the-in-app-agent-engine`
- **files**: `lib/Service/ScheduleService.php`, `tests/Unit/Service/ScheduleServiceTest.php`
- **acceptance_criteria**:
  - GIVEN `hermiq.engine.enabled=false` WHEN `dryRunNow()` is called THEN it throws a clear,
    actionable error naming the required feature flag, and no agent runs
  - GIVEN `hermiq.engine.enabled=true` and gates pass WHEN `dryRunNow()` is called THEN it runs the
    agent as a dry-run, writes an `action='run'` audit entry marked `dryRun: true` with the exact
    prompt used, and the schedule's `nextRun`/`repeat`/`enabled` are unchanged afterward
  - GIVEN the dry-run turn completes (success or failure) WHEN cleanup runs THEN the scratch
    `Conversation` and its `Message` objects are deleted
- [ ] Implement
- [ ] Test

### Task 6: `ScheduleService::replayRun()` — replay from recorded prompt + step diff
- **spec_ref**: `openspec/changes/run-replay-and-dry-run/specs/run-replay-and-dry-run/spec.md#requirement-replay-re-executes-a-runs-exact-recorded-prompt-as-a-dry-run-and-diffs-the-outcome`
- **files**: `lib/Service/ScheduleService.php`, `tests/Unit/Service/ScheduleServiceTest.php`
- **acceptance_criteria**:
  - GIVEN a completed run whose recorded prompt differs from the schedule's current `prompt` WHEN
    `replayRun()` is called THEN the replay uses the run's originally recorded prompt text
  - GIVEN a run recorded before this change shipped (no persisted prompt) WHEN `replayRun()` is
    called THEN it refuses cleanly with a "not available for replay" error, never a crash
  - GIVEN the original run called tools `[A, B]` and the replay calls `[A, C]` WHEN the diff is
    computed THEN it reports `toolSequenceMatches: false` with position 0 matching and position 1 not
  - GIVEN the replay's final output text differs from the original's WHEN the diff is computed THEN
    `outputChanged: true` is reported
- [ ] Implement
- [ ] Test

### Task 7: `RunHistoryService` — surface `prompt`/`dryRun`/`replayOf`; `AnalyticsService` excludes dry-runs
- **spec_ref**: `openspec/changes/run-replay-and-dry-run/specs/run-audit-log/spec.md#requirement-every-run-and-tool-call-is-audited-mvp`
- **files**: `lib/Service/RunHistoryService.php`, `lib/Service/AnalyticsService.php`, `tests/Unit/Service/RunHistoryServiceTest.php`, `tests/Unit/Service/AnalyticsServiceTest.php`
- **acceptance_criteria**:
  - GIVEN a dry-run's audit entry WHEN `getRunTrace()` reads it THEN the returned record includes
    `dryRun: true` and, for a replay, `replayOf: <original runId>`
  - GIVEN a mix of real and dry-run entries for an agent WHEN
    `AnalyticsService::computeAnalytics()` runs THEN dry-run entries are excluded from the
    status/success-rate breakdown
  - GIVEN a dry-run entry recorded LLM token usage WHEN `BudgetService`'s spend total is computed
    THEN that usage IS still counted (unchanged — no filter added there)
- [ ] Implement
- [ ] Test

### Task 8: `RunNowController::dryRun()` + `RunHistoryController::replay()` + routes
- **spec_ref**: `openspec/changes/run-replay-and-dry-run/specs/run-replay-and-dry-run/spec.md#requirement-dry-run-and-replay-require-the-in-app-agent-engine`
- **files**: `lib/Controller/RunNowController.php`, `lib/Controller/RunHistoryController.php`, `appinfo/routes.php`, `tests/Unit/Controller/RunNowControllerTest.php`, `tests/Unit/Controller/RunHistoryControllerTest.php`
- **acceptance_criteria**:
  - GIVEN the schedule owner WHEN they call `POST /api/schedules/{scheduleId}/dry-run` THEN they
    receive 200 with the dry-run's step timeline
  - GIVEN the schedule owner WHEN they call `POST /api/schedules/{scheduleId}/runs/{runId}/replay`
    THEN they receive 200 with the original/replay/diff payload
  - GIVEN a non-owner authenticated user WHEN they call either endpoint for another user's schedule
    THEN they receive 404 (not 403), identical to the existing anti-probing convention
  - GIVEN a gated/blocked schedule or the engine flag off WHEN either endpoint is called THEN the
    response carries the specific error (409 gate status or 422 feature-flag-required) from
    design.md's API section
- [ ] Implement
- [ ] Test

### Task 9: Frontend — "Dry-run" button, "Replay" row action, diff render
- **spec_ref**: `openspec/changes/run-replay-and-dry-run/specs/agent-management-ui/spec.md#requirement-attach-a-schedule-and-run-now-mvp`
- **files**: `src/api/agents.js`, `src/views/AgentDetail.vue`
- **acceptance_criteria**:
  - GIVEN a schedule attached WHEN the user clicks "Dry-run" THEN `dryRunSchedule(scheduleId)` is
    called and the resulting step timeline renders labelled as a dry-run
  - GIVEN the engine flag is off WHEN "Dry-run" is clicked THEN the feature-flag-required message
    from the API response is shown, not a generic error
  - GIVEN a completed run in the Run history table WHEN the user clicks "Replay" for it THEN
    `replayRun(scheduleId, runId)` is called and the original/replay/diff renders per-position
    tool-name comparisons
- [ ] Implement
- [ ] Test — compile-level verified (eslint 0 errors on the touched files); live browser coverage
  deferred to the playwright-regression-coverage change.

### Task 10: i18n strings
- **spec_ref**: `openspec/changes/run-replay-and-dry-run/specs/agent-management-ui/spec.md#requirement-run-history-view-mvp`
- **files**: `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN every new user-facing string introduced in Tasks 8-9 (button labels, gate-refusal
    messages, `would-have-called` badge text, diff labels) WHEN the l10n files are checked THEN each
    has an English key and a Dutch translation, keys in English per project convention
- [ ] Implement
- [ ] Test

## Quality checklist

- All new/changed business logic covered by PHPUnit unit tests (`tests/Unit/`)
- New/changed API endpoints covered by Newman/Postman tests
- UI changes covered by Playwright browser tests
- All tests pass (`composer test`, `newman run`)
- Feature documentation updated in `docs/` if user-facing (ADR-010)
- Dutch (`nl_NL`) and English (`en_US`) translation strings added for any new user-facing strings (ADR-007)
- `openspec validate` passes
