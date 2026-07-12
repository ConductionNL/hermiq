# Tasks: run-reliability

<!-- HYDRA CAP: the supervisor rejects specs with more than 20 lines matching `^- \[ \]`
     (unindented checkboxes). Count before writing: each `- [ ] Implement` / `- [ ] Test`
     costs 1. Acceptance criteria are plain text bullets, not checkboxes. -->

## Implementation Tasks

### Task 1: Declare retry/circuit-breaker Schedule properties
- **spec_ref**: `openspec/changes/run-reliability/specs/agent-schedule/spec.md#requirement-per-schedule-opt-in-bounded-retry-with-exponential-backoff-mvp`
- **files**: `lib/Settings/hermiq_register.json`
- **acceptance_criteria**:
  - GIVEN the register is (re-)imported via `ConfigurationService::importFromApp()` WHEN it runs THEN the `Schedule` schema gains `retryEnabled` (boolean, default `false`), `retryMaxAttempts` (integer, min 1, max 10, default 3), `retryBackoffBaseSeconds` (integer, min 1, default 60), `circuitBreakerThreshold` (integer, min 1, default 3), `retryState` (nullable object `{attempt:int, nextAttemptAt:date-time}`), and `consecutiveDeadLetters` (integer, default 0), without altering existing properties
  - GIVEN a `Schedule` created without any of the new fields WHEN it is saved THEN it persists with the documented defaults (backward-compatible)
- [ ] Implement
- [ ] Test

### Task 2: Extend due-selection to include retry-due schedules
- **spec_ref**: `openspec/changes/run-reliability/specs/agent-schedule/spec.md#requirement-per-schedule-opt-in-bounded-retry-with-exponential-backoff-mvp`
- **files**: `lib/Service/ScheduleService.php` (`findDueSchedules()`)
- **acceptance_criteria**:
  - GIVEN an enabled schedule whose `nextRun` is in the future but whose `retryState.nextAttemptAt` is due WHEN a tick runs THEN the schedule is selected for dispatch
  - GIVEN a schedule with no `retryState` and a future `nextRun` WHEN a tick runs THEN it is NOT selected (unchanged behavior)
- [ ] Implement
- [ ] Test

### Task 3: Failure branch — schedule retry with exponential backoff, or dead-letter on exhaustion
- **spec_ref**: `openspec/changes/run-reliability/specs/agent-schedule/spec.md#requirement-per-schedule-opt-in-bounded-retry-with-exponential-backoff-mvp`
- **files**: `lib/Service/ScheduleService.php` (`runDue()`)
- **acceptance_criteria**:
  - GIVEN `retryEnabled=false` WHEN the agent turn throws THEN `lastStatus='error'` is recorded exactly as before this change
  - GIVEN `retryEnabled=true` and attempt < `retryMaxAttempts` WHEN the agent turn throws THEN `lastStatus='retry_pending'` is recorded and `retryState.nextAttemptAt` is set to `now + retryBackoffBaseSeconds * 2^(attempt-1)`
  - GIVEN `retryEnabled=true` and the `retryMaxAttempts`-th attempt throws WHEN it fails THEN `lastStatus='dead_letter'` is recorded, `retryState` is cleared, and the deferred one-shot/finite-repeat auto-disable (Task 4) is applied
- [ ] Implement
- [ ] Test

### Task 4: Defer once/finite-repeat auto-disable during an open retry sequence; reset counters on success
- **spec_ref**: `openspec/changes/run-reliability/specs/agent-schedule/spec.md#requirement-dead-letter-state-after-retries-are-exhausted-with-manual-re-run-mvp`
- **files**: `lib/Service/ScheduleService.php` (`runDue()`)
- **acceptance_criteria**:
  - GIVEN a `kind='once'` schedule with `retryEnabled=true` whose first attempt fails WHEN retries are still pending THEN the schedule stays `enabled=true`
  - GIVEN any schedule's occurrence completes successfully (first attempt or a later retry) WHEN it succeeds THEN `retryState` is cleared, `consecutiveDeadLetters` resets to 0, and the deferred once/finite-repeat disable/delete is applied on success as normal
- [ ] Implement
- [ ] Test

### Task 5: Circuit breaker — auto-pause after N consecutive dead-letters
- **spec_ref**: `openspec/changes/run-reliability/specs/agent-schedule/spec.md#requirement-consecutive-dead-letter-circuit-breaker-auto-pauses-a-schedule-mvp`
- **files**: `lib/Service/ScheduleService.php`
- **acceptance_criteria**:
  - GIVEN `consecutiveDeadLetters` reaches `circuitBreakerThreshold` after a dead-letter WHEN it is recorded THEN the schedule is set `enabled=false`, `lastStatus='paused_circuit_breaker'`, and `DeliveryService::deliverCircuitBreakerAlert()` is called
  - GIVEN a kill-switch-halted or approval-gated retry attempt WHEN it is skipped THEN it MUST NOT count toward `consecutiveDeadLetters` (only an exhausted agent-turn failure counts)
- [ ] Implement
- [ ] Test

### Task 6: Owner failure alerts via DeliveryService (dead-letter + circuit-breaker)
- **spec_ref**: `openspec/changes/run-reliability/specs/talk-delivery/spec.md#requirement-deliver-a-failure-alert-to-the-schedule-owner-mvp`
- **files**: `lib/Service/DeliveryService.php`
- **acceptance_criteria**:
  - GIVEN a schedule with `deliver='none'` WHEN it is marked `dead_letter` THEN the owner still receives a Talk-or-Notification-fallback alert (mirrors `deliverApprovalRequest()`'s never-throws contract)
  - GIVEN a schedule is auto-paused by the circuit breaker WHEN the pause is recorded THEN the owner receives a distinct alert naming the auto-pause reason
  - GIVEN both Talk and Notification throw WHEN an alert attempt is made THEN the already-recorded `dead_letter`/`paused_circuit_breaker` state is unchanged and only a warning is logged
- [ ] Implement
- [ ] Test

### Task 7: Audit context + run-history attempt visibility
- **spec_ref**: `openspec/changes/run-reliability/specs/run-audit-log/spec.md#requirement-run-history-surfaces-retry-attempts-and-dead-lettercircuit-breaker-outcomes-mvp`
- **files**: `lib/Service/ScheduleService.php` (`writeRunAudit()`), `lib/Service/RunHistoryService.php` (`toRunRecord()`)
- **acceptance_criteria**:
  - GIVEN an occurrence that failed, retried twice, and was marked `dead_letter` WHEN the owner reads run history THEN each attempt appears newest-first with its own `attempt` number, `status`, and duration, and the last entry shows `status='dead_letter'`
- [ ] Implement
- [ ] Test

### Task 8: Frontend — retry-policy fields on the schedule form
- **spec_ref**: `openspec/changes/run-reliability/specs/agent-schedule/spec.md#requirement-per-schedule-opt-in-bounded-retry-with-exponential-backoff-mvp`
- **files**: `src/modals/ScheduleFormModal.vue`
- **acceptance_criteria**:
  - GIVEN the schedule form WHEN the user enables `retryEnabled` THEN `retryMaxAttempts`, `retryBackoffBaseSeconds`, and `circuitBreakerThreshold` inputs become visible and are included in the save payload
  - GIVEN `retryEnabled` is off (default) WHEN the form is saved THEN the payload is unchanged from today (backward compatible)
- [ ] Implement
- [ ] Test

### Task 9: Frontend — run-history status vocabulary + dead-letter re-run action
- **spec_ref**: `openspec/changes/run-reliability/specs/agent-schedule/spec.md#requirement-dead-letter-state-after-retries-are-exhausted-with-manual-re-run-mvp`
- **files**: `src/views/AgentDetail.vue`
- **acceptance_criteria**:
  - GIVEN a run row with `status` in (`retry_pending`, `dead_letter`, `paused_circuit_breaker`) WHEN the run-history table renders THEN it shows a distinct badge for each (extending the existing `agent-detail__badge--*` classes, not just the current error/ok binary)
  - GIVEN a run row with `status='dead_letter'` WHEN the owner clicks the new "Re-run" action THEN it calls the existing `runScheduleNow()` helper (no new API call)
- [ ] Implement
- [ ] Test

## Quality checklist

- All tasks checked off; `openspec validate --changes run-reliability --strict` passes; manual testing against acceptance criteria; code review against spec requirements
- All new/changed business logic covered by PHPUnit unit tests (`tests/Unit/`), run the CI way (php:8.3-cli + OCP stubs, no live NC/OR)
- SPDX `@license`/`@copyright` docblock tags on every touched PHP file; `@spec` tags referencing this change's requirements
- No stub bodies, no `var_dump`/`error_log`/`die`; no sed/awk/scripts on PHP or Vue files — use Edit
- New/changed API surface: none (no new routes) — no Newman/Postman collection changes needed
- UI changes (Tasks 8–9) covered by a Playwright browser test exercising retry-policy fields + dead-letter re-run
- Dutch (`nl_NL`) and English (`en_US`) translation strings added for the new status labels and retry-policy field labels (ADR-007)
- Verify LIVE on NC + OpenRegister before archiving: a retry-enabled schedule that fails, retries with growing backoff, exhausts its budget into `dead_letter`, notifies the owner, and (after repeating) trips the circuit breaker — plus confirm a kill-switch/approval-gated retry is halted exactly like a normal occurrence
