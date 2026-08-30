# Test Plan: run-reliability

## Test Cases

### TC-1: Retry disabled — unchanged failure behavior
- **spec_ref**: `openspec/changes/run-reliability/specs/agent-schedule/spec.md#requirement-per-schedule-opt-in-bounded-retry-with-exponential-backoff-mvp`
- **type**: functional
- **persona**: n/a
- **preconditions**: a schedule with `retryEnabled=false` (default) bound to an agent that will throw
- **steps**: let the schedule fire on its normal `nextRun`
- **expected result**: `lastStatus='error'`, no `retryState` set, no retry fires on the next tick before the schedule's real next occurrence
- **test command**: `/test-functional`

### TC-2: Retry with growing exponential backoff
- **spec_ref**: `openspec/changes/run-reliability/specs/agent-schedule/spec.md#requirement-per-schedule-opt-in-bounded-retry-with-exponential-backoff-mvp`
- **type**: functional
- **persona**: n/a
- **preconditions**: a schedule with `retryEnabled=true`, `retryMaxAttempts=3`, `retryBackoffBaseSeconds=60`, bound to an agent that throws on its first two invocations and succeeds on the third
- **steps**: let the schedule fire; observe the two retry attempts and the eventual success
- **expected result**: attempt 1 fails → `lastStatus='retry_pending'`, `retryState.nextAttemptAt` ≈ now+60s; attempt 2 fails → `retryState.nextAttemptAt` ≈ now+120s; attempt 3 succeeds → `lastStatus='ok'`, `retryState=null`, `consecutiveDeadLetters=0`
- **test command**: `/test-functional`

### TC-3: Retry budget exhausted → dead-letter, visible in run history
- **spec_ref**: `openspec/changes/run-reliability/specs/agent-schedule/spec.md#requirement-dead-letter-state-after-retries-are-exhausted-with-manual-re-run-mvp`
- **type**: functional
- **persona**: n/a
- **preconditions**: a schedule with `retryEnabled=true`, `retryMaxAttempts=2`, bound to an agent that always throws
- **steps**: let the schedule fire and exhaust both attempts; open the agent's run history
- **expected result**: the final attempt records `lastStatus='dead_letter'`; run history lists both attempts newest-first, the last showing `status='dead_letter'` with an `attempt` number
- **test command**: `/test-functional`

### TC-4: One-shot schedule stays enabled through a retry sequence
- **spec_ref**: `openspec/changes/run-reliability/specs/agent-schedule/spec.md#requirement-dead-letter-state-after-retries-are-exhausted-with-manual-re-run-mvp`
- **type**: regression
- **persona**: n/a
- **preconditions**: a `kind='once'` schedule with `retryEnabled=true` whose agent throws on the first attempt
- **steps**: observe the schedule's `enabled` field immediately after the first failed attempt, before the retry fires
- **expected result**: `enabled` remains `true` (NOT auto-disabled as a plain one-shot would be) until the retry sequence resolves
- **test command**: `/test-regression`

### TC-5: Owner manually re-runs a dead-lettered schedule
- **spec_ref**: `openspec/changes/run-reliability/specs/agent-schedule/spec.md#requirement-dead-letter-state-after-retries-are-exhausted-with-manual-re-run-mvp`
- **type**: functional
- **persona**: Priya (ZZP Developer / Integrator)
- **preconditions**: a schedule in `lastStatus='dead_letter'`, owned by the logged-in user
- **steps**: click "Re-run" on the dead-lettered row in run history
- **expected result**: the existing owner-guarded run-now action fires; on success `lastStatus='ok'`, `retryState=null`, `consecutiveDeadLetters` resets to 0
- **test command**: `/test-functional`

### TC-6: Circuit breaker trips after N consecutive dead-letters and auto-pauses
- **spec_ref**: `openspec/changes/run-reliability/specs/agent-schedule/spec.md#requirement-consecutive-dead-letter-circuit-breaker-auto-pauses-a-schedule-mvp`
- **type**: functional
- **persona**: n/a
- **preconditions**: a schedule with `circuitBreakerThreshold=3`, `consecutiveDeadLetters=2` already recorded from two prior dead-lettered occurrences
- **steps**: let a third occurrence exhaust its retries into `dead_letter`
- **expected result**: `enabled=false`, `lastStatus='paused_circuit_breaker'`; the dispatcher skips it on the next tick; the owner receives an alert
- **test command**: `/test-functional`

### TC-7: A success resets the consecutive-dead-letter streak
- **spec_ref**: `openspec/changes/run-reliability/specs/agent-schedule/spec.md#requirement-consecutive-dead-letter-circuit-breaker-auto-pauses-a-schedule-mvp`
- **type**: regression
- **persona**: n/a
- **preconditions**: a schedule with `consecutiveDeadLetters=2`
- **steps**: let its next occurrence complete successfully
- **expected result**: `consecutiveDeadLetters` resets to `0`
- **test command**: `/test-regression`

### TC-8: Kill-switch halts a pending retry (governance not bypassed)
- **spec_ref**: `openspec/changes/run-reliability/specs/agent-schedule/spec.md#requirement-a-retried-run-is-a-new-governed-dispatch-mvp`
- **type**: security
- **persona**: Noor (Municipal CISO / Functional Admin)
- **preconditions**: a schedule with a pending retry attempt due; its organisation's `TenantControl` is engaged (kill-switch on) before the retry tick
- **steps**: let the tick run
- **expected result**: the agent is NOT invoked for the retry; the occurrence is halted exactly like any other kill-switch-gated fire (no bypass)
- **test command**: `/test-security`

### TC-9: Approval-gated schedule's retry still requires approval
- **spec_ref**: `openspec/changes/run-reliability/specs/agent-schedule/spec.md#requirement-a-retried-run-is-a-new-governed-dispatch-mvp`
- **type**: security
- **persona**: Noor (Municipal CISO / Functional Admin)
- **preconditions**: a schedule with `requiresApproval=true` and `retryEnabled=true`, a pending retry attempt due
- **steps**: let the tick run
- **expected result**: the agent is NOT invoked directly — a pending `Approval` is ensured (or reused, idempotent) and the reviewer notified, exactly as for a normal gated occurrence
- **test command**: `/test-security`

### TC-10: Dead-letter alert reaches the owner even when deliver=none
- **spec_ref**: `openspec/changes/run-reliability/specs/talk-delivery/spec.md#requirement-deliver-a-failure-alert-to-the-schedule-owner-mvp`
- **type**: functional
- **persona**: Jan-Willem (Small Business Owner)
- **preconditions**: a schedule with `deliver='none'`, `retryEnabled=true`, whose retries exhaust
- **steps**: let the occurrence reach `dead_letter`
- **expected result**: the owner receives a Talk message (or Notification fallback) describing the failure, distinct from the (absent) normal run-output delivery
- **test command**: `/test-functional`

### TC-11: A failed alert never reverts recorded state
- **spec_ref**: `openspec/changes/run-reliability/specs/talk-delivery/spec.md#requirement-deliver-a-failure-alert-to-the-schedule-owner-mvp`
- **type**: regression
- **persona**: n/a
- **preconditions**: Talk and Notifications both simulated to throw
- **steps**: trigger a dead-letter and a circuit-breaker trip
- **expected result**: `lastStatus` still reads `dead_letter`/`paused_circuit_breaker` as recorded; only a warning is logged; the tick does not fail
- **test command**: `/test-regression`

### TC-12: Retry-policy fields on the schedule form
- **spec_ref**: `openspec/changes/run-reliability/specs/agent-schedule/spec.md#requirement-per-schedule-opt-in-bounded-retry-with-exponential-backoff-mvp`
- **type**: functional
- **persona**: Mark (MKB Software Vendor)
- **preconditions**: the schedule create/edit modal is open
- **steps**: toggle "Retry enabled" on; set max attempts, backoff base, circuit-breaker threshold; save
- **expected result**: the new fields appear only when the toggle is on and are persisted on save; with the toggle off, the save payload is unchanged from today
- **test command**: `/test-functional`

### TC-13: Run-history status badges + accessibility of the new states
- **spec_ref**: `openspec/changes/run-reliability/specs/run-audit-log/spec.md#requirement-run-history-surfaces-retry-attempts-and-dead-lettercircuit-breaker-outcomes-mvp`
- **type**: accessibility
- **persona**: Henk (Elderly Citizen)
- **preconditions**: an agent's run history contains `retry_pending`, `dead_letter`, and `paused_circuit_breaker` rows
- **steps**: open Agent Detail's run history with a screen reader / keyboard-only navigation
- **expected result**: each new status is visually and programmatically distinguishable (not color-only), and the "Re-run" action is keyboard-operable with a clear accessible name
- **test command**: `/test-accessibility`

## Coverage Summary

- Per-schedule opt-in retry + exponential backoff — TC-1, TC-2 (covered)
- Dead-letter state + manual re-run — TC-3, TC-4, TC-5 (covered)
- Circuit breaker auto-pause + reset — TC-6, TC-7 (covered)
- Retries respect kill-switch + approval gate — TC-8, TC-9 (covered)
- Owner failure alert (dead-letter + circuit-breaker, never fatal) — TC-10, TC-11 (covered)
- Frontend: schedule form + run-history UI — TC-12, TC-13 (covered)

## Out of Scope

- Backoff jitter — not implemented in this change (proposal's Out of Scope), so no test case.
- Org-level default retry policy — no test case; deferred to a future change.
- A dedicated dead-letter-queue browsing UI separate from run history — not built; TC-3/TC-13 exercise the existing run-history surface instead.
