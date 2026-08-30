# Proposal: run-reliability

## Summary

Give a Hermiq schedule an opt-in, bounded automatic-retry policy with exponential
backoff, a durable **dead-letter** state for an occurrence whose retries are
exhausted (visible in run history, one-click manual re-run), an owner-facing
failure alert via the existing Talk/Notification delivery chain, and a
consecutive-dead-letter **circuit breaker** that auto-pauses a chronically failing
schedule and tells its owner why. Today a failed scheduled run is recorded
(`lastStatus='error'`) and silently left for the owner to notice — there is no
retry, no distinct "permanently failed" state, and no proactive alert. This is
the #1 cited adoption blocker in market research for autonomous-agent platforms:
users need to trust that a failure is retried when it might be transient, and
loudly surfaced when it is not.

## Motivation

`ScheduleService::runDue()` already catches every agent-turn `Throwable` (see
`lib/Service/ScheduleService.php:591-600`): it sets `lastStatus='error'`,
`lastError=$e->getMessage()`, writes a redacted `AuditTrail` entry, and returns.
Nothing else happens — no retry, no notification (the schedule's own `deliver`
setting only fires on a *successful* run's output, see `talk-delivery`), and no
distinguishing signal between "failed once, will fire again next cycle" and
"has been failing for weeks". Evidence from the Spectr research DB
(`app_id='hermiq'`, journeys 262/264) backs this directly:

- `aa-bounded-retry` (must): "I want an agent to retry transient failures
  automatically with a bounded limit, so that flaky sources self-heal without an
  auto-loop quietly burning 30x the tokens."
- `agentic-workflow-retry-backoff` (must): "I want automatic retries with
  exponential backoff and jitter, so that transient rate-limit/API failures
  recover without manual re-runs."
- `agentic-workflow-dead-letter-queue` (should): "I want runs that fail after all
  retries to land in a dead-letter queue, so that a human is alerted to permanent
  errors needing intervention."
- `agentic-workflow-error-alert` (must): "I want a proactive alert (Talk/
  notification) when a run fails, so that automations do not fail silently."
- `aa-reliable-timezone-schedule` (must): "...so that my 7am briefing actually
  arrives and I know when one is skipped."

The 262 market-insight synthesis names reliability the #1 adoption blocker
against every competitor surveyed (n8n, LangGraph, Copilot Studio) — all of
which ship retry/backoff and dead-letter as table stakes.

## Affected Projects

- [x] Project: `hermiq` — Schedule schema gains retry/circuit-breaker fields;
      `ScheduleService` gains retry scheduling, dead-letter transition, and
      circuit-breaker logic; `DeliveryService` gains an owner failure-alert path;
      run-history gains attempt/dead-letter visibility; the schedule form and run
      history Vue views gain the new fields/states.

No other `apps-extra` project is touched: retries reuse Hermiq's existing single
`ScheduleTask` poll (ADR-002) and existing `DeliveryService`/`RunHistoryService` —
nothing here talks to another app.

## Scope

### In Scope

- Per-schedule opt-in retry policy: `retryEnabled` (bool, default `false`),
  `retryMaxAttempts` (1–10, default 3), `retryBackoffBaseSeconds` (≥1, default 60)
  persisted on the `Schedule` object.
- Exponential backoff between attempts (`backoffBaseSeconds * 2^(attempt-1)`),
  scheduled through the existing 5-minute dispatcher poll — no new background
  job, no per-item `IJobList` entry (ADR-002).
- A `dead_letter` run/schedule status once the retry budget is exhausted without
  success, visible in the existing run-history read surface, with manual re-run
  via the **existing** owner-guarded `RunNowController` (no new endpoint).
- A failure alert to the schedule owner — via the existing `DeliveryService`
  Talk → Note-to-self → Notification fallback chain (`talk-delivery` dialect) —
  fired on dead-letter and, distinctly, on a circuit-breaker auto-pause,
  regardless of the schedule's own `deliver` output setting.
- A `consecutiveDeadLetters` counter and `circuitBreakerThreshold` (default 3):
  reaching the threshold auto-pauses the schedule (`enabled=false`,
  `lastStatus='paused_circuit_breaker'`) and notifies the owner. The counter
  resets to 0 on any run that completes successfully.
- Every retry attempt, and the eventual dead-letter recovery run, is a **new**
  fully governed dispatch: the organisation kill-switch and the schedule's
  approval gate are re-checked exactly as for any other fire — a retry MUST NOT
  bypass either.

### Out of Scope

- Per-agent or per-organisation retry defaults / policy templates — this ships
  per-schedule fields only; a future change may add an org-level default.
- Jitter on the backoff calculation (cited in one story) — pure exponential
  backoff only, to keep the dispatcher's due-selection deterministic and
  testable; jitter can be layered on later without a schema change.
- A dedicated "dead-letter queue" browsing UI separate from run history — reuses
  the existing run-history list/detail surface, filtered by status.
- Any new backend endpoint for manual re-run — reuses the existing
  `POST /api/schedules/{id}/run` (`RunNowController`).
- Circuit-breaker reset on manual re-enable — the counter only resets on a
  successful run (see Risks); adding a dedicated "resume" action that also
  resets the counter is deferred.
- Cross-app retry/alerting (e.g. OpenConnector flows) — out of scope; no
  cross-app RPC is introduced (per apps-extra gate-27).

## Approach

Extend the existing single-poll dispatcher (`ScheduleTask` → `ScheduleService`)
rather than adding new scheduling infrastructure: `findDueSchedules()` also
selects a schedule whose `retryState.nextAttemptAt` has arrived, even if its
regular `nextRun` has not. On an agent-turn failure, `runDue()` branches on
`retryEnabled`: disabled means today's unchanged `error` behavior; enabled means
a bounded number of backed-off retries before the occurrence is marked
`dead_letter`. The kill-switch and approval gate stay upstream of the agent
invocation exactly as today, so every retry attempt re-enters those same gates.
Dead-letter and circuit-breaker alerts reuse `DeliveryService`'s Talk → Note-to-
self → Notification fallback chain (adding one or two new alert methods,
mirroring `deliverApprovalRequest()`), never failing the run. Manual re-run of a
dead-lettered occurrence reuses `RunNowController`/`ScheduleService::runNow()`
unchanged. Run history requires only a small addition (an `attempt` field in the
audit context) — its status field is already a free string, so the new
`retry_pending` / `dead_letter` / `paused_circuit_breaker` values need no schema
change there.

## New Dependencies

None.

## Impact

- **Schema:** `lib/Settings/hermiq_register.json` — `Schedule` gains
  `retryEnabled`, `retryMaxAttempts`, `retryBackoffBaseSeconds`,
  `circuitBreakerThreshold` (user-configured) and `retryState`,
  `consecutiveDeadLetters` (derived, dispatcher-written), all optional with
  backward-compatible defaults.
- **Code:** `lib/Service/ScheduleService.php` (due-selection, failure branch,
  circuit breaker), `lib/Service/DeliveryService.php` (owner failure-alert
  methods), `lib/Service/RunHistoryService.php` (attempt field passthrough).
- **Frontend:** `src/modals/ScheduleFormModal.vue` (retry-policy fields),
  `src/views/AgentDetail.vue` (run-history status vocabulary + re-run action).
- **No new routes, no new controllers, no new database migration.**

## Cross-Project Dependencies

None. Self-contained within `hermiq`; no OpenRegister core change is required
(the new fields are declared entirely within Hermiq's own register/schema).

## Risks

### Risk 1: Retries must never bypass EU AI Act Art. 14 oversight
**Severity:** High — **Mitigation:** retries and the dead-letter recovery run
flow through the exact same `dispatch()` gate order (kill-switch, then approval)
as any other fire; no retry-specific shortcut is introduced. Covered by explicit
spec scenarios and unit tests asserting a gated/halted retry never invokes the
agent.

### Risk 2: Backoff shorter than the dispatcher's 5-minute poll cannot fire on time
**Severity:** Medium — **Mitigation:** document that retry timing is rounded up
to the next poll tick, consistent with the existing sub-5-minute precision
caveat already noted in `agent-schedule`'s Notes; `retryBackoffBaseSeconds`
defaults to 60s but effective latency is poll-bound.

### Risk 3: Manual re-run of a dead-lettered occurrence double-advances `nextRun`/`repeat.completed`
**Severity:** Low — **Mitigation:** this is pre-existing `runNow()` semantics
(a manual run is already indistinguishable from a tick, per `agent-schedule-
dispatcher`'s design) — not a regression introduced here. Documented explicitly
so operators understand a dead-letter re-run consumes one occurrence of a
finite repeat.

### Risk 4: Circuit breaker does not reset on manual re-enable
**Severity:** Low — **Mitigation:** `consecutiveDeadLetters` resets only on a
successful run (see Out of Scope); a schedule re-enabled after auto-pause that
fails again re-trips immediately. This is the deliberately conservative choice
(fail toward more caution) and avoids adding a dedicated resume endpoint just to
reset a counter.

## Rollback Strategy

All new `Schedule` properties are optional with backward-compatible defaults
(`retryEnabled=false` reproduces today's behavior exactly). Reverting is a plain
code revert plus removing the new schema properties from
`hermiq_register.json` — no data migration or cleanup of existing `Schedule`/
`AuditTrail` objects is required, since OpenRegister tolerates additive optional
fields and unknown-on-read is a no-op.

## Open Questions

None outstanding — the roadmap-only items (per-org retry defaults, backoff
jitter, a dedicated dead-letter UI) are deliberately deferred, not blocking.
