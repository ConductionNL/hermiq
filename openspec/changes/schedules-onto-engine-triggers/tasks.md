# Tasks: schedules-onto-engine-triggers

## Phase 1: the seam, the clock hand-off, oversight (this change)

- [x] 1.1 Declare `engineFlowId` on the Schedule schema (bump schema version)
      so the write-back survives OpenRegister's undeclared-property drop.
- [x] 1.2 `hermiq.schedule-dispatch` flow node: loads the schedule, refuses a
      stale or disabled delegation, re-enters `ScheduleService::runNow()`.
      Registered in `HermiqFlowNodeListener`.
- [x] 1.3 `ScheduleFlowBridge`: eligibility (5-field timezone-safe cron,
      expressible intervals, resolvable identity, organisation present),
      mirror/refresh/retire, `syncAll()`. Flow rows carry app, organisation,
      owner and `applicationSlug=hermiq-schedules`.
- [x] 1.4 `ScheduleService`: `markEngineDelegation()` / `clearEngineDelegation()`
      reusing the existing sanitised persist; `findDueSchedules()` skips
      `nextRun` due-ness for delegated schedules (retry due-ness kept).
- [x] 1.5 `ScheduleTask` arms on every tick: `syncAll()` before dispatch,
      failure-isolated so a bridge error never blocks the tick.
- [x] 1.6 Oversight: `TenantKillSwitchCheck` + registration listener on
      `RegisterFlowOversightEvent`, guarded on the class existing.
- [x] 1.7 Migration: `MirrorSchedulesToEngineFlows` repair step (idempotent)
      and `occ hermiq:schedules:rollback-flow-mirror` (with `--dry-run`),
      both registered in `appinfo/info.xml`.
- [x] 1.8 Stubs matched to the real OpenRegister signatures (`FlowMapper`
      delete/findByUuid, `RegisterFlowOversightEvent`, `IFlowOversightCheck`,
      `FlowRun`/`FlowRunMapper`) and unit tests for the bridge, the node, the
      check, the repair step and the rollback command.
- [x] 1.9 Publish the mirror flow's head (`FlowVersionService` via the
      container, guarded on the class existing). Found on the rig: the engine's
      `FlowRunVersionPin` refuses every scheduled dispatch of an unpublished
      flow, so a mirror that is only inserted never ticks. Create path: insert,
      publish, mark; a publish failure deletes the flow and the schedule keeps
      its local clock. Refresh path: draft, update, publish, because the engine
      runs the pinned published version, not the flow row; a crash in between
      leaves an unpublished head the next pass heals. The undrifted branch
      publishes any unpublished head, healing pre-publish mirrors. Stubs
      (`FlowVersion`, `FlowVersionService`) model the pin's contract so a
      bridge that skips publishing fails its tests.

## Phase 2: the shapes the engine cannot time yet (staged)

- [ ] 2.1 `kind=once` onto `FlowTimerService`: arm a one-shot timer at
      `runAt` whose expiry queues the mirror flow; cancel on schedule
      disable/delete. Needs a subject contract for ownerless timers.
- [ ] 2.2 Timezone-sensitive crons: either a `timezone` config key on
      `openregister.trigger-schedule` (OpenRegister change) or per-firing
      evaluation in the owner's timezone; until then they stay local.
- [ ] 2.3 Inexpressible intervals via timer re-arm chains.
- [ ] 2.4 Retry re-arming through the engine, so a delegated schedule's
      backoff no longer needs the local tick.

## Phase 3: retire the local clock (staged)

- [ ] 3.1 Sweep orphaned mirror flows from the flow side (schedule deleted in
      the UI) instead of waiting for the node's loud refusal.
- [ ] 3.2 When every shape has an engine home, drop `nextRun` due-selection
      from `findDueSchedules()` and shrink `ScheduleTask` to the sync pass.
- [ ] 3.3 Move `nextRun` display derivation to the engine's schedule state so
      the UI reads one clock.
