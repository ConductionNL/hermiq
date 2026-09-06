# Proposal: schedules-onto-engine-triggers

## Summary

Hand the schedule clock to OpenRegister's flow engine. Every schedule whose
cadence a cron expression can carry gets a mirror flow: a schedule trigger
node with an explicit `runAs`, and one hermiq dispatch node that re-enters the
proven governed dispatch path. The app-local dispatcher stops polling those
schedules and keeps only what the engine cannot time yet. hermiq's scheduled
runs also become visible to the engine's oversight surface: a contributed
check lets the tenant kill switch stop them at every hop.

## Why

The fleet audit flagged hermiq's `ScheduleService` + `ScheduleTask` as a
duplicate scheduler. OpenRegister owns fleet scheduling (`TriggerScheduleNode`,
`FlowScheduleService`); hermiq owns agent execution. Today hermiq owns both:
a 5-minute `TimedJob` polls Schedule objects, computes cron/interval/once
due-ness itself, and fires. That clock is the duplicate. The domain around it
(kill switch, budget cap, approval gate, retry and dead-letter, delivery, the
run audit) is not a duplicate: no engine primitive carries it, and deleting it
would delete governance.

There is a second gap this closes. hermiq contributes `hermiq.agent-step` to
the engine, and the engine's oversight registry asks contributed checks before
every hop. hermiq never contributed one. So an agent step in a canvas-authored
flow runs even while a tenant's kill switch is engaged. For an AI-agent app
under EU AI Act Art. 14 that is a hole, not a nuance.

## What changes

- **The engine gets the clock.** A new `ScheduleFlowBridge` mirrors each
  eligible schedule as one OpenRegister flow:
  `openregister.trigger-schedule` (cron + `runAs`) into a new
  `hermiq.schedule-dispatch` node into `openregister.end`. The dispatch node
  calls `ScheduleService::runNow()`, so every gate, retry, delivery and audit
  behaviour is byte-for-byte the one a tick applied.
- **The dispatcher thins.** A mirrored schedule (marked by the new
  `engineFlowId` property) is no longer due by `nextRun` on the local tick.
  The tick keeps two jobs: arming (sync schedules to their mirror flows) and
  the shapes the engine cannot time yet (`once`, inexpressible intervals,
  pending retries).
- **Oversight reaches hermiq.** A contributed `IFlowOversightCheck` vetoes any
  `hermiq.*` hop for an organisation whose TenantControl kill switch is
  engaged.
- **In-flight schedules migrate.** An idempotent repair step mirrors existing
  eligible schedules on upgrade; `occ hermiq:schedules:rollback-flow-mirror`
  deletes the mirrors and returns the clock to the app-local dispatcher.

## What stays domain

Agent execution itself: impersonation, guardrails, budget accounting, the
approval gate, retry and dead-letter bookkeeping, delivery and the per-run
audit entry all stay in hermiq, reached through the dispatch node. The engine
times the run; hermiq runs it.

## Out of scope (staged in tasks.md)

- `once` schedules onto `FlowTimerService` one-shot timers.
- Intervals no 5-field cron can express (for example every 90 minutes).
- Retry re-arming through the engine (a pending retry still fires from the
  local tick).
- Deleting `ScheduleService`'s due-selection wholesale. The selection thins
  here and retires only when every shape has an engine home.
