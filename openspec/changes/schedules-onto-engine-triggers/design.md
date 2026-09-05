# Design: schedules-onto-engine-triggers

## The shape map

hermiq's Schedule schema has three timing kinds. Each maps, or deliberately
does not, onto an engine primitive:

| Schedule shape | Engine primitive | Phase |
|---|---|---|
| `kind=cron`, 5-field expression, timezone-safe | `openregister.trigger-schedule` (`cron`, `runAs`) | 1 (this change) |
| `kind=interval`, minutes divide 60, or whole hours dividing 24 | `openregister.trigger-schedule` with the derived cron (`*/N * * * *` or `0 */H * * *`) | 1 (this change) |
| `kind=once` (`runAt`) | `FlowTimerService` one-shot timer: a deadline, which is exactly the timer service's primitive | staged |
| `kind=cron`, 6-field or timezone-sensitive | none yet; the engine evaluates cron in the server timezone and takes 5 fields only | staged |
| `kind=interval`, inexpressible (for example 90 minutes) | none; would need timer re-arm chains | staged |
| A pending retry attempt (`retryState.nextAttemptAt`) | none; retry backoff is domain bookkeeping, not a cadence | stays local |

A schedule that maps stays governed by everything it had, because the mirror
flow's work node is not the agent step. It is a new, thinner node:

```
openregister.trigger-schedule ──> hermiq.schedule-dispatch ──> openregister.end
        (cron, runAs)                  (scheduleId)
```

`hermiq.schedule-dispatch` loads the Schedule object and calls
`ScheduleService::runNow()`. That is the same private dispatch path a tick
used: kill switch, budget cap, approval gate, commit-before-run, retry
bookkeeping, delivery, audit. Nothing is reimplemented and nothing is lost.
The alternative (trigger straight into `hermiq.agent-step`) was rejected: it
would drop the approval gate, the budget hard cap, delivery and retry for
exactly the runs that had them.

### Timezone rule

hermiq evaluates cron in the schedule owner's timezone;
`FlowScheduleService::isDue()` evaluates in the server default timezone. A
cron is mirrored only when that difference cannot change its meaning:

- the owner's resolved timezone equals the server default timezone, or
- the expression is a pure cadence: minute field `*` or `*/n`, every other
  field `*`.

Anything else (a "09:00 daily" owned by someone in another timezone) stays on
the local tick until the trigger node learns timezones. Shifting hour fields
by an offset was rejected: DST makes the rewrite wrong twice a year, silently.

### Interval alignment

An interval schedule fired rolling ("N minutes after the last run"). Its
mirror fires aligned ("every N minutes on the wall clock"). The cadence is
preserved; the phase may shift once at migration. This is documented and
accepted: rolling semantics were an artefact of the poller, not a promise the
schema made.

## Whose identity a mirrored run uses (runAs)

`TriggerScheduleNode` refuses a flow whose trigger names nobody (ADR-099).
The bridge writes `runAs` as **the identity the run already acts as today**:
the bound agent's `actingUser` when it names a live user, otherwise the
schedule's owner. That is `ScheduleService::resolveActingUser()`'s exact
resolution, computed at mirror time.

This is not a widening. The owner authored the schedule as a standing
instruction to run unattended; mirroring reproduces that consent, it does not
create it. Two safety properties hold on top:

- OpenRegister re-resolves `runAs` at every firing and fails closed when the
  user is gone or disabled, matching hermiq's own offboarding pause.
- The dispatch node re-enters `runNow()`, which re-resolves the acting user
  itself. If `actingUser` changed after mirroring, the run still acts as the
  correct current identity; the bridge refreshes the trigger's `runAs` on its
  next sync pass.

A schedule whose resolved identity names no live user is not mirrored.

## Arming and the two-way sync

"Creation delegates to the engine" happens on the tick: `ScheduleTask` calls
`ScheduleFlowBridge::syncAll()` before dispatching. For every enabled
schedule the bridge:

- mirrors an eligible, unmirrored schedule (insert flow, write `engineFlowId`
  back onto the schedule);
- refreshes a mirrored schedule whose cron, `runAs` or enabled flag drifted;
- retires the mirror (delete flow, clear `engineFlowId`) when the schedule
  was edited into a shape the engine cannot time.

The write-back property `engineFlowId` is declared in the Schedule schema
first, because OpenRegister silently drops undeclared properties on save.

A schedule deleted from the UI leaves its mirror orphaned until the node next
fires; the node then finds no delegated schedule, disables the flow and fails
the step loudly. Sweeping from the flow side is staged.

### Publishing and the version pin

OpenRegister versions flow definitions, and its `FlowRunVersionPin` refuses
every scheduled dispatch of a flow with no published version. A mirror that is
only inserted is therefore a clock that never ticks: the schedule leaves the
local dispatcher and gains nothing in return. Proven on the rig, where the
whole dual-app chain worked after one manual publish.

The bridge publishes server-side through `FlowVersionService`, the same class
the publish endpoint uses, resolved lazily from the container and guarded on
the class existing (an OpenRegister without versioning has no pin and nothing
to publish). `publishedBy` stays null: no person pressed publish, the bridge
did, as the standing consequence of the owner's schedule.

The chosen semantics per path:

- **Create: insert, publish, mark.** A publish failure deletes the flow and
  never writes the marker, so the schedule keeps its local clock. A crash
  between publish and mark leaves a published flow the dispatch node refuses
  (the schedule does not name it): a loud dead flow, never a dead clock.
- **Refresh: draft, update, publish.** The engine runs the pinned published
  version, not the flow row, so an update without a republish would fire the
  old cadence forever. Drafting first flips the head off `published`, which
  makes a crash between the update and the publish detectable; the old
  published version keeps serving until the new one lands.
- **Heal: an undrifted mirror without a published version is published.**
  This catches mirrors created before the bridge published, and any crash the
  refresh path left behind, on the next sync pass instead of waiting for a
  manual publish.

### Double-fire safety

At-most-once holds because the clock has exactly one owner per schedule:

- `engineFlowId` set: `findDueSchedules()` ignores `nextRun` for it; only the
  engine fires the occurrence. A pending retry is the one exception; it
  stays local so an open retry sequence is never silently dropped.
- `engineFlowId` empty: the local tick owns it, as before.

The rollback command restores the second state atomically per schedule:
clear the marker, then delete the flow. A crash between the two leaves a
mirror whose schedule is no longer delegated; the node's delegation check
(schedule's `engineFlowId` must equal the firing flow) refuses to run it, so
the failure mode is a loud dead flow, never a double fire.

## Oversight registration

hermiq contributes `hermiq.tenant-killswitch` through
`RegisterFlowOversightEvent`, guarded on the event class existing. The check:

- consents to any hop that is not a `hermiq.*` node type (other apps' flows
  are not hermiq's to veto);
- consents when no TenantControl kill switch is engaged (the cheap common
  path, one read);
- vetoes a `hermiq.*` hop whose run belongs to an engaged organisation (the
  run's organisation, read from the `FlowRun` by `runUuid`);
- vetoes a `hermiq.*` hop whose organisation it cannot establish while any
  kill switch is engaged. A kill switch that cannot attribute fails closed.

A TenantControl read error consents (logged), mirroring the dispatcher's
documented choice: a transient read failure must not halt every tenant. The
registry itself already fails closed on a check that throws.

This gives the kill switch three layers for a scheduled run: the engine-side
check on every hop, `runNow()`'s own gate 1, and OpenRegister's instance-wide
`KillSwitchCheck`. The redundancy is deliberate; each layer covers a path the
others do not (canvas-authored agent steps, legacy tick runs, instance halt).

## Migration and rollback

- `MirrorSchedulesToEngineFlows` (post-migration repair step): calls
  `syncAll()`. Idempotent by construction: the `engineFlowId` guard makes a
  second run a no-op, the `MirrorPendingApprovalsToTasks` pattern. Never
  throws; failures are counted and logged.
- `occ hermiq:schedules:rollback-flow-mirror`: for every schedule carrying
  `engineFlowId`, clear the marker, then delete the flow. `--dry-run`
  supported. After rollback the local tick owns every clock again.

## Risks

- **hermiq CI has no OpenRegister.** Flow classes resolve to
  `tests/Stubs`; the stubs mirror the real signatures (`FlowMapper`
  `findByUuid`/`insert`/`update`/`delete`, `Flow` magic accessors) and the
  standing rule applies: verified live where both apps are installed.
- **Organisation-less schedules.** A flow row without an organisation is
  invisible to every tenant forever (hermiq#140). The bridge refuses to
  mirror a schedule without one; it stays on the local tick.
- **Engine cadence.** `FlowScheduleService` fires from its own sweep; a
  mirrored schedule's effective granularity is the engine's, not the tick's
  5 minutes. Both are coarse clocks; the schema never promised sub-5-minute
  precision.
