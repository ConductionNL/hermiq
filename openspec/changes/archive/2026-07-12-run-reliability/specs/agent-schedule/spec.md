## ADDED Requirements

### Requirement: Per-schedule opt-in bounded retry with exponential backoff [MVP]

A `Schedule` MUST support an opt-in retry policy — `retryEnabled` (boolean,
default `false`), `retryMaxAttempts` (integer 1–10, default 3), and
`retryBackoffBaseSeconds` (integer ≥1, default 60) — persisted on the schedule.
When `retryEnabled` is `false` (the default), an agent-turn failure MUST behave
exactly as before this change: `lastStatus` is set to `error` and no retry is
scheduled. When `retryEnabled` is `true` and a due occurrence's agent turn
fails, the system MUST schedule a retry attempt no earlier than
`retryBackoffBaseSeconds * 2^(attempt-1)` seconds later, for up to
`retryMaxAttempts` attempts of that occurrence, via the dispatcher's existing
poll (no new background job or per-item scheduled task).

#### Scenario: A transient failure is retried with growing backoff

- GIVEN a schedule with `retryEnabled=true`, `retryMaxAttempts=3`,
  `retryBackoffBaseSeconds=60`
- WHEN its agent run throws on the first attempt
- THEN the system MUST record `lastStatus='retry_pending'` and schedule the
  next retry attempt no earlier than 60 seconds later
- AND WHEN that retry also fails, the following attempt MUST be scheduled no
  earlier than 120 seconds after that
- AND a third consecutive failure MUST exhaust the retry budget for that
  occurrence (the attempt count reaches `retryMaxAttempts`)

#### Scenario: Retry disabled is unaffected (backward compatibility)

- GIVEN a schedule with `retryEnabled=false` (or the field absent, its default)
- WHEN its agent run throws
- THEN the system MUST record `lastStatus='error'` exactly as before this
  change, with no retry scheduled

### Requirement: Dead-letter state after retries are exhausted, with manual re-run [MVP]

The system MUST record `lastStatus='dead_letter'` for a schedule once a
retry-enabled occurrence exhausts its retry budget (`retryMaxAttempts`
consecutive failures) without a successful run. The system MUST keep the
schedule `enabled`, deferring any one-shot or finite-repeat-limit
auto-disable that would otherwise have been applied at commit-before-run time
until the retry sequence resolves — either by success or by exhausting the
budget. The system MUST make the dead-lettered run visible through the
existing run-history read surface. The system MUST let the owner manually
re-run it through the existing owner-guarded run-now action.

#### Scenario: An exhausted retry sequence is recorded as dead-letter and reappears in run history

- GIVEN a schedule with `retryEnabled=true` whose `retryMaxAttempts` consecutive
  attempts for one occurrence all fail
- WHEN the last retry attempt fails
- THEN the system MUST write a run-history entry with `status='dead_letter'`
- AND the schedule's `lastStatus` MUST read `dead_letter`

#### Scenario: A one-shot schedule stays runnable while retries are pending

- GIVEN a `kind='once'` schedule with `retryEnabled=true` whose first attempt
  fails
- WHEN the dispatcher would normally have disabled the one-shot at
  commit-before-run time
- THEN the schedule MUST remain `enabled=true` until its retry sequence
  resolves, so the next scheduled retry attempt is not silently skipped

#### Scenario: Owner re-runs a dead-lettered schedule

- GIVEN a schedule in `lastStatus='dead_letter'`
- WHEN the owner invokes the existing run-now action on it
- THEN the system MUST run the bound agent again as a fresh, fully governed
  dispatch (the organisation kill-switch and the schedule's approval gate are
  re-checked exactly as for any other fire)
- AND on success, the system MUST clear the dead-letter state and reset the
  schedule's consecutive-dead-letter counter to zero

### Requirement: Consecutive-dead-letter circuit breaker auto-pauses a schedule [MVP]

The system MUST track a `consecutiveDeadLetters` counter (derived, default 0)
on each schedule, incrementing it each time an occurrence is marked
`dead_letter` and resetting it to 0 whenever a run completes successfully
(whether or not retries were used). Once `consecutiveDeadLetters` reaches the
schedule's configured `circuitBreakerThreshold` (integer ≥1, default 3), the
system MUST auto-pause the schedule (`enabled=false`), record
`lastStatus='paused_circuit_breaker'`, and notify the owner of the auto-pause
and its reason.

#### Scenario: Three consecutive dead-letters trip the breaker

- GIVEN a schedule with `circuitBreakerThreshold=3` and
  `consecutiveDeadLetters=2` already recorded from two prior fully-exhausted
  occurrences
- WHEN a third occurrence also exhausts its retry budget and is marked
  `dead_letter`
- THEN the system MUST set the schedule's `enabled=false` and
  `lastStatus='paused_circuit_breaker'`
- AND the dispatcher MUST skip the schedule on every subsequent tick until an
  owner re-enables it
- AND the owner MUST receive a failure notification identifying the schedule
  and the auto-pause reason

#### Scenario: A success resets the consecutive-dead-letter streak

- GIVEN a schedule with `consecutiveDeadLetters=2`
- WHEN its next occurrence completes successfully (with or without retries
  having been used along the way)
- THEN `consecutiveDeadLetters` MUST reset to 0, so an unrelated future failure
  does not immediately trip the breaker

### Requirement: A retried run is a new governed dispatch [MVP]

Every retry attempt, and the eventual dead-letter recovery run, MUST re-enter
the same organisation kill-switch check and the same schedule approval gate
that any other dispatch goes through. A retry attempt or a manual dead-letter
re-run MUST NOT bypass either gate.

#### Scenario: An engaged kill-switch halts a pending retry

- GIVEN a schedule with a pending retry attempt due to fire
- WHEN the tick that would fire the retry finds the schedule's organisation
  kill-switch engaged
- THEN the retry MUST NOT invoke the agent — it is halted exactly like any
  other kill-switch-gated occurrence

#### Scenario: An approval-gated schedule's retry still requires approval

- GIVEN a schedule with `requiresApproval=true` and a pending retry attempt
- WHEN the retry attempt becomes due
- THEN the system MUST NOT invoke the agent directly — it MUST follow the
  existing approval-gate path (ensure a pending `Approval`, notify the
  reviewer) rather than running the retry unattended
