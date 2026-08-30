# Tasks: run-trace-observability

## Implementation Tasks

### Task 1: `RunTraceCollector` — ordered, in-memory step recorder
- **spec_ref**: `openspec/changes/run-trace-observability/specs/run-audit-log/spec.md#requirement-every-run-and-tool-call-is-audited-mvp`
- **files**: `lib/Service/Engine/RunTraceCollector.php`, `tests/Unit/Service/Engine/RunTraceCollectorTest.php`
- **acceptance_criteria**:
  - GIVEN a fresh collector WHEN `startStep('tool', 'openregister.searchObjects')` is called, then
    `endStep($token, 'ok')` THEN `toArray()` returns one step with `seq=0`, the given `type`/`name`,
    ISO-8601 `startedAt`/`endedAt`, a computed `durationMs`, and `outcome='ok'`
  - GIVEN three steps started/ended in sequence WHEN `toArray()` is called THEN the steps are
    returned in the exact order they were started, with `seq` 0..2
  - GIVEN `endStep()` is called with an unknown token WHEN this happens THEN it MUST NOT throw and
    MUST NOT corrupt already-recorded steps (defensive — a caller bug must never break a run)
- [x] Implement
- [x] Test

### Task 2: Thread the collector through `Engine`/`ToolLoop`/`FacadeToolInvoker`
- **spec_ref**: `openspec/changes/run-trace-observability/specs/run-audit-log/spec.md#requirement-every-run-and-tool-call-is-audited-mvp`
- **files**: `lib/Service/Engine/Engine.php`, `lib/Service/Engine/ToolLoop.php`, `lib/Service/Engine/FacadeToolInvoker.php`, `tests/Unit/Service/Engine/EngineTest.php`, `tests/Unit/Service/Engine/FacadeToolInvokerTest.php`
- **acceptance_criteria**:
  - GIVEN `Engine::processMessage()` is called with an optional `RunTraceCollector` WHEN the turn
    calls two tools THEN the returned envelope's `steps` key contains one `context`, one `history`,
    one `llm`, and two `tool` step entries (name/duration/outcome), in call order
  - GIVEN `FacadeToolInvoker::__call()` invokes a tool that raises an `isError` result WHEN the
    collector records that call THEN its step outcome is `error`, never `ok`
  - GIVEN `Engine::processMessage()` is called with no collector (existing callers unaffected) WHEN
    the turn completes THEN behavior is unchanged and the returned `steps` key is an empty array,
    never a fatal error
- [x] Implement
- [x] Test

### Task 3: `ScheduleService` captures steps and includes them in the run audit write
- **spec_ref**: `openspec/changes/run-trace-observability/specs/run-audit-log/spec.md#requirement-every-run-and-tool-call-is-audited-mvp`
- **files**: `lib/Service/ScheduleService.php`, `tests/Unit/Service/ScheduleServiceTest.php`
- **acceptance_criteria**:
  - GIVEN a run via the in-app Engine path (`engine.enabled=true`) that calls one tool WHEN the run
    finalises THEN the persisted `action='run'` entry's `changed.steps` includes context/history/llm/
    tool/delivery steps and `changed.toolStepsAvailable=true`
  - GIVEN a run via the default OpenRegister `ChatService` path WHEN the run finalises THEN
    `changed.steps` includes context/history/llm/delivery steps derived from the existing `timings`
    return value, `changed.toolStepsAvailable=false`, and no tool-type step is fabricated
  - GIVEN the `deliver()` call succeeds or fails WHEN the run finalises THEN exactly one `delivery`
    step is recorded with the correct `outcome` (`ok`/`error`) and the delivery failure MUST NOT
    abort the audit write (mirrors the existing non-fatal delivery contract)
  - GIVEN `$result['timings']` is absent or malformed on either path WHEN steps are assembled THEN
    the coarse steps for that run are simply omitted — never a fabricated duration
- [x] Implement
- [x] Test

### Task 4: `RunHistoryService::getRunTrace()` — trace read + gate-wait reconstruction
- **spec_ref**: `openspec/changes/run-trace-observability/specs/run-audit-log/spec.md#requirement-downloadable-redacted-run-trace-mvp`
- **files**: `lib/Service/RunHistoryService.php`, `tests/Unit/Service/RunHistoryServiceTest.php`
- **acceptance_criteria**:
  - GIVEN a completed run's `AuditTrail` entry WHEN `getRunTrace(scheduleUuid, runId)` is called
    THEN it returns that entry's `changed.steps` (and other run fields) in the shape documented in
    design.md's API section
  - GIVEN a run immediately preceded by one or more unbroken `awaiting_approval` entries for the
    same schedule WHEN its trace is built THEN a leading `gate_wait` step is synthesised spanning
    from the first such entry's `created` to this run's `startedAt`
  - GIVEN a run with NO adjacent gate-skip entry immediately before it WHEN its trace is built THEN
    no `gate_wait` step is added — never guessed across a gap or a different schedule's entries
  - GIVEN a `runId` that does not belong to the given `scheduleUuid` WHEN `getRunTrace()` is called
    THEN it returns null/not-found rather than another schedule's run
- [x] Implement
- [x] Test

### Task 5: `RunHistoryController::trace()` + route
- **spec_ref**: `openspec/changes/run-trace-observability/specs/run-audit-log/spec.md#requirement-downloadable-redacted-run-trace-mvp`
- **files**: `lib/Controller/RunHistoryController.php`, `appinfo/routes.php`, `tests/Unit/Controller/RunHistoryControllerTest.php`
- **acceptance_criteria**:
  - GIVEN the schedule owner WHEN they call `GET /api/schedules/{scheduleId}/runs/{runId}/trace`
    THEN they receive 200 with the run's full step timeline
  - GIVEN a non-owner authenticated user WHEN they call the same endpoint for another user's
    schedule THEN they receive 404 (not 403), identical to `index()`'s existing anti-probing
    convention, with zero step data in the response
  - GIVEN an unauthenticated caller WHEN they call the endpoint THEN they receive 401
- [x] Implement
- [x] Test

### Task 6: Frontend — run-trace API + step-timeline expand view
- **spec_ref**: `openspec/changes/run-trace-observability/specs/agent-management-ui/spec.md#requirement-run-history-view-mvp`
- **files**: `src/api/agents.js`, `src/views/AgentDetail.vue`
- **acceptance_criteria**:
  - GIVEN a user expands a completed run in the Run history table WHEN the expand action fires
    THEN `getRunTrace(scheduleId, runId)` is called and the ordered steps render with type/name/
    duration/outcome
  - GIVEN a run with `toolStepsAvailable=false` WHEN its timeline renders THEN the UI plainly states
    tool-level detail is unavailable for that run, rather than implying no tools ran
  - GIVEN an agent with no runs (existing empty-state scenario) WHEN the section renders THEN
    behavior is unchanged (no regression to the existing empty-state hint)
- [x] Implement
- [x] Test — compile-level verified (eslint 0 errors on the touched files); live browser coverage
  deferred to the playwright-regression-coverage change.

### Task 7: Frontend — "Download trace (JSON)" action
- **spec_ref**: `openspec/changes/run-trace-observability/specs/agent-management-ui/spec.md#requirement-run-history-view-mvp`
- **files**: `src/views/AgentDetail.vue`
- **acceptance_criteria**:
  - GIVEN an expanded run's timeline WHEN the user clicks "Download trace (JSON)" THEN the browser
    saves a `.json` file whose content matches the rendered timeline data exactly (no client-side
    re-redaction or transformation beyond formatting)
- [x] Implement
- [x] Test — compile-level verified (eslint 0 errors); live browser coverage deferred to the
  playwright-regression-coverage change.

## Quality checklist

- All new/changed business logic covered by PHPUnit unit tests (`tests/Unit/`)
- New/changed API endpoints covered by Newman/Postman tests
- UI changes covered by Playwright browser tests
- All tests pass (`composer test`, `newman run`)
- Feature documentation updated in `docs/` if user-facing (ADR-010)
- Dutch (`nl_NL`) and English (`en_US`) translation strings added for any new user-facing strings (ADR-007)
- `openspec validate` passes
