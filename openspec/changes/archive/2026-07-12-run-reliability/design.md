# Design: run-reliability

## Architecture Overview

Hermiq owns exactly one Nextcloud `TimedJob` (`ScheduleTask`, ADR-002): it polls
every 5 minutes and delegates everything to `ScheduleService::run()`, which
selects due, enabled `Schedule` objects and, per schedule, runs a
commit-before-run dispatch (`dispatch()` → `runDue()`). This change does not add
a second job or a per-item `IJobList` entry — it extends the **existing** poll's
due-selection and failure-handling so retries, dead-letter, and the circuit
breaker are all just more branches inside the same tick.

```
ScheduleTask (TimedJob, every 5 min)
  → ScheduleService::run()
      → findDueSchedules()          [EXTENDED: also selects retry-due schedules]
      → loadEngagedOrganisations()  [unchanged — GATE 1]
      → dispatch(schedule, ...)
          GATE 1 kill-switch        [unchanged]
          GATE 2 approval           [unchanged]
          → runDue(schedule, now)
              commit-before-run advance     [unchanged for a NORMAL occurrence;
                                              EXTENDED: once/finite-repeat auto-
                                              disable is deferred while a retry
                                              sequence is open]
              runAgentAsOwner() → deliver()
              on Throwable:
                retryEnabled=false → lastStatus='error'          [unchanged]
                retryEnabled=true  → NEW branch:
                  attempt < maxAttempts → schedule retry (backoff), lastStatus='retry_pending'
                  attempt >= maxAttempts → lastStatus='dead_letter',
                                            consecutiveDeadLetters++,
                                            DeliveryService::deliverFailureAlert()
                                            consecutiveDeadLetters >= threshold →
                                              enabled=false, lastStatus='paused_circuit_breaker',
                                              DeliveryService::deliverCircuitBreakerAlert()
              on success:
                retryState=null, consecutiveDeadLetters=0   [NEW — resets the streak]
              writeRunAudit()  [EXTENDED: + 'attempt' context field]
```

A **retry attempt** and the **dead-letter recovery run** (manual "Run now" on a
`dead_letter` schedule) are not special code paths — they are ordinary
occurrences that happen to be selected by the extended `findDueSchedules()` /
triggered by the existing `RunNowController`, so they pass through
`dispatch()`'s GATE 1 / GATE 2 exactly like any other fire. This is the
mechanism that satisfies "a retried run is a new governed dispatch" without
introducing a parallel enforcement path that could drift from the primary one.

## Nextcloud Integration

- **Controllers:** none new. The existing owner-guarded `RunNowController`
  (`POST /api/schedules/{id}/run`) is reused verbatim as the dead-letter
  "manual re-run" action; the existing `RunHistoryController`
  (`GET /api/schedules/{id}/runs`) is reused verbatim for dead-letter/retry
  visibility.
- **Services:**
  - `ScheduleService` (`lib/Service/ScheduleService.php`) — extended
    `findDueSchedules()`, a new failure branch inside `runDue()`, a new
    circuit-breaker check, `writeRunAudit()` gains an `attempt` context field.
  - `DeliveryService` (`lib/Service/DeliveryService.php`) — two new methods,
    `deliverFailureAlert(ObjectEntity $schedule, string $reason)` and
    `deliverCircuitBreakerAlert(ObjectEntity $schedule)`, mirroring the existing
    `deliverApprovalRequest()` shape (Talk Note-to-self best-effort +
    Nextcloud notification, deep-linked to the schedule, never throws).
  - `RunHistoryService` (`lib/Service/RunHistoryService.php`) — `toRunRecord()`
    passes through the new `attempt` context key exactly like the existing
    `durationMs`/`summary` keys (no new schema, `status` is already a free
    string so the new values need no mapping change).
- **Mappers/Entities:** none new — `AuditTrailMapper`/`ObjectEntity` are reused
  exactly as today.
- **Events/Hooks:** none new.

## Security Considerations

- No new authenticated surface is introduced — retries and dead-letter reuse
  `RunNowController`'s existing owner-guard (`@NoAdminRequired` + explicit
  `loadOwnedSchedule()` 404-for-non-owner) and `RunHistoryController`'s existing
  owner-scoped read. There is no new IDOR surface.
- **Governance invariant (EU AI Act Art. 14):** a retry attempt and the
  dead-letter recovery run MUST re-enter GATE 1 (kill-switch) and GATE 2
  (approval) exactly as any other dispatch — this is enforced structurally by
  routing every retry/re-run through the same `dispatch()` entry point rather
  than a bypass path, not by a separate check that could be forgotten.
- The owner failure-alert (dead-letter / circuit-breaker) reuses
  `DeliveryService`'s existing non-throwing contract — a Talk or Notification
  failure is logged and never affects the already-persisted run/schedule state,
  matching the `talk-delivery` invariant that delivery problems are never fatal.
- No new user input is trusted blindly: `retryMaxAttempts`,
  `retryBackoffBaseSeconds`, and `circuitBreakerThreshold` are bounded
  (min/max) at the schema level so a misconfigured schedule cannot produce an
  unbounded retry loop or a zero-backoff hot loop.

## NL Design System

Reuses existing patterns with no new components:
- `src/views/AgentDetail.vue`'s run-history table gains additional status
  values (`retry_pending`, `dead_letter`, `paused_circuit_breaker`) rendered
  through the same badge mechanism already used for `error`/`ok`
  (`agent-detail__badge--*` classes) — extended, not replaced.
- A "Re-run" `NcButton` on a `dead_letter` row calls the existing
  `runScheduleNow()` API helper (`src/api/agents.js`) — the same call the
  page-level "Run now" button already makes.
- `src/modals/ScheduleFormModal.vue` gains an optional "Retry policy" fieldset
  (an `NcCheckboxRadioSwitch` for `retryEnabled` gating three `NcInputField`
  number inputs), following the existing form's field-visibility pattern (e.g.
  `cronExpr` only shown when `kind=cron`).
- No new color/spacing tokens; badge colors reuse the existing
  success/error/warning CSS variables already present in the app (WCAG AA
  unaffected).

## File Structure

```
lib/
  Settings/
    hermiq_register.json        # MODIFIED — Schedule: + retryEnabled,
                                 #   retryMaxAttempts, retryBackoffBaseSeconds,
                                 #   circuitBreakerThreshold, retryState,
                                 #   consecutiveDeadLetters
  Service/
    ScheduleService.php          # MODIFIED — retry due-selection, failure
                                 #   branch, circuit breaker, audit context
    DeliveryService.php          # MODIFIED — + deliverFailureAlert(),
                                 #   deliverCircuitBreakerAlert()
    RunHistoryService.php        # MODIFIED — + attempt passthrough
src/
  modals/
    ScheduleFormModal.vue        # MODIFIED — retry-policy fieldset
  views/
    AgentDetail.vue              # MODIFIED — status vocabulary + re-run action
```

## Seed Data

Extends the existing `schedule` seed set (no new schema, so no new
`@self.schema` envelope) — three of the existing/planned seed `Schedule`
objects gain retry-policy values so the feature is exercisable on a fresh
install:

### Schema: `schedule` (existing — additive fields only)

| Field | Seed: "Daily briefing" | Seed: "Flaky API monitor" | Seed: "Weekly report" |
|---|---|---|---|
| `retryEnabled` | `false` (unchanged default) | `true` | `true` |
| `retryMaxAttempts` | — | `3` | `2` |
| `retryBackoffBaseSeconds` | — | `60` | `120` |
| `circuitBreakerThreshold` | — | `3` | `3` |
| `lastStatus` | `ok` | `dead_letter` (pre-seeded run history shows 1 fail + 2 retries) | `paused_circuit_breaker` (pre-seeded 3 consecutive dead-letters) |
| `consecutiveDeadLetters` | `0` | `1` | `3` |

**Related items per object:** none beyond the existing per-run `AuditTrail`
entries (`action='run'`) already seeded for run history — the two new seed
schedules additionally seed 3–4 audit entries each so a fresh install shows a
realistic retry sequence and a circuit-breaker trip in run history without any
manual setup.

## Trade-offs

- **Extending the single poll vs. a dedicated retry queue.** Chosen: reuse
  `ScheduleTask`'s existing 5-minute poll by widening `findDueSchedules()`'s due
  condition. *Alternative considered:* a separate `QueuedJob`-per-retry
  (mirroring `AgentRunRequestedJob`) — rejected because it would introduce a
  second scheduling mechanism for the same entity type Hermiq already polls,
  contradicting ADR-002 ("one TimedJob, poll internally"), and would require
  duplicating the kill-switch/approval gate check at a second call site instead
  of inheriting it for free from `dispatch()`.
- **Deferring once/finite-repeat auto-disable during an open retry sequence.**
  `runDue()` today disables a one-shot (or a finite repeat that just hit its
  limit) at commit-before-run time, before the agent ever runs. A retry-enabled
  schedule needs to stay `enabled=true` until its retry sequence resolves
  (success or dead-letter), or `findDueSchedules()`'s `enabled=true` filter
  would silently drop it before the next retry fires. *Alternative considered:*
  key the retry due-check off `retryState` alone regardless of `enabled` —
  rejected as a special-case escape hatch that would let a user-disabled
  schedule keep firing, which is exactly the invariant `agent-schedule`'s
  "Pause a schedule" requirement protects.
- **Circuit-breaker reset only on success, not on manual re-enable.** Chosen for
  simplicity: Hermiq is a thin client (schedule CRUD, including the `enabled`
  toggle, happens directly through the frontend's OpenRegister-backed Pinia
  store — there is no Hermiq controller in the enable/disable path to hook a
  reset into without adding new imperative code solely for that purpose).
  *Alternative considered:* a dedicated "resume schedule" endpoint that both
  re-enables and resets the counter — deferred (see proposal's Out of Scope);
  the conservative default (re-trip immediately on the next failure) is safer
  than silently forgetting a real streak of failures.
