# Delta: schedule-engine-delegation

## ADDED Requirements

### Requirement: Eligible schedules delegate their clock to the engine

Every enabled Schedule whose cadence a 5-field, timezone-safe cron expression
can carry SHALL be mirrored as one OpenRegister flow consisting of a schedule
trigger node, a `hermiq.schedule-dispatch` node and an end node. The mirrored
schedule SHALL carry the flow's uuid in `engineFlowId`, and the app-local
dispatcher SHALL NOT select it as due by `nextRun` while that marker is set.

The bridge SHALL publish the mirror flow's head when OpenRegister carries flow
versioning: the engine's version pin refuses every scheduled dispatch of an
unpublished flow, so an unpublished mirror is a clock that never ticks. The
marker SHALL be written only after a successful publish; a publish failure
SHALL remove the flow again and leave the schedule on its local clock. When a
refresh changes the mirrored definition, the bridge SHALL republish, because
the engine runs the pinned published version, not the flow row. A sync pass
SHALL publish the head of any mirrored, undrifted flow that lacks a published
version, so mirrors created before publishing existed heal without a manual
publish.

#### Scenario: A cron schedule is mirrored and leaves the local clock

- **GIVEN** an enabled `kind=cron` schedule with a 5-field cadence expression
- **WHEN** the bridge syncs
- **THEN** a flow exists with `trigger=schedule`, the schedule's cron and a
  trigger-node `runAs`, and the schedule stores that flow's uuid in
  `engineFlowId`
- **AND** the local dispatcher no longer selects the schedule as due by
  `nextRun`

#### Scenario: A pending retry still fires locally

- **GIVEN** a mirrored schedule with an open `retryState` whose
  `nextAttemptAt` has passed
- **WHEN** the local tick selects due schedules
- **THEN** the schedule is selected, so an open retry sequence is never
  dropped by delegation

#### Scenario: An inexpressible shape stays local

- **GIVEN** an enabled `kind=once` schedule, or an interval no 5-field cron
  expresses
- **WHEN** the bridge syncs
- **THEN** no flow is created and the schedule stays on the local dispatcher

#### Scenario: A mirrored flow is published so the engine will run it

- **GIVEN** an eligible, unmirrored schedule on an OpenRegister with flow
  versioning
- **WHEN** the bridge mirrors it
- **THEN** the mirror flow has a published version before `engineFlowId` is
  written, so the version pin accepts the first trigger tick
- **AND** if publishing fails, the flow is removed, no marker is written, and
  the schedule stays on the local dispatcher

#### Scenario: A changed cadence lands as a new published version

- **GIVEN** a mirrored schedule whose cron, `runAs` or enabled flag drifted
  from its flow
- **WHEN** the bridge refreshes the mirror
- **THEN** the flow's head is republished, so the engine fires the new
  definition instead of the old pinned version

@e2e exclude {backend delegation seam; requires a live OpenRegister flow
engine, which CI does not install. Verified by unit tests against
signature-matched stubs and live on a dual-app instance.}

### Requirement: A mirrored run declares and re-enters its governed identity

The bridge SHALL write the trigger node's `runAs` as the identity the run
already acts as: the bound agent's `actingUser` when it names a live user,
otherwise the schedule owner. A schedule whose resolved identity names no
live user SHALL NOT be mirrored. The dispatch node SHALL execute through
`ScheduleService::runNow()`, so the kill switch, budget cap, approval gate,
retry bookkeeping, delivery and run audit apply unchanged.

#### Scenario: runAs is the resolved acting identity

- **GIVEN** a schedule owned by `alice` bound to an agent whose `actingUser`
  is the live user `svc-runner`
- **WHEN** the bridge mirrors it
- **THEN** the trigger node's `runAs` is `svc-runner`

#### Scenario: A gated occurrence still gates

- **GIVEN** a mirrored schedule with `requiresApproval=true`
- **WHEN** the engine fires the dispatch node
- **THEN** the run does not execute and a single pending Approval is ensured,
  exactly as on a tick

#### Scenario: A stale mirror refuses to fire

- **GIVEN** a flow whose schedule no longer carries that flow's uuid in
  `engineFlowId`
- **WHEN** the dispatch node executes
- **THEN** the step fails loudly and does not run the agent

@e2e exclude {backend delegation seam; requires a live OpenRegister flow
engine, which CI does not install. Verified by unit tests against
signature-matched stubs and live on a dual-app instance.}

### Requirement: The tenant kill switch reaches every hermiq flow hop

hermiq SHALL register an oversight check with OpenRegister's
`FlowOversightRegistry` discovery event. The check SHALL veto any `hermiq.*`
node hop whose run belongs to an organisation with an engaged TenantControl
kill switch, SHALL veto a `hermiq.*` hop it cannot attribute while any kill
switch is engaged, and SHALL consent to all other hops.

#### Scenario: An engaged organisation's hop is vetoed

- **GIVEN** an organisation whose TenantControl kill switch is engaged
- **WHEN** the engine asks the check before a `hermiq.agent-step` hop of that
  organisation's run
- **THEN** the check vetoes with a reason naming the kill switch

#### Scenario: Other apps' hops are not hermiq's to veto

- **GIVEN** any engaged kill switch
- **WHEN** the engine asks the check before a non-hermiq node hop
- **THEN** the check consents

@e2e exclude {oversight veto seam inside OpenRegister's engine walk; CI does
not install the engine. Verified by unit tests against signature-matched
stubs and live on a dual-app instance.}

### Requirement: In-flight schedules migrate and roll back

An idempotent post-migration repair step SHALL mirror every already-eligible
schedule, skipping any schedule that carries `engineFlowId`. The command
`occ hermiq:schedules:rollback-flow-mirror` SHALL clear each schedule's
marker and then delete its mirror flow, returning the clock to the app-local
dispatcher.

#### Scenario: The repair step is idempotent

- **GIVEN** schedules already mirrored by a previous run
- **WHEN** the repair step runs again
- **THEN** no second flow is created for any of them

#### Scenario: Rollback restores the local clock

- **GIVEN** mirrored schedules
- **WHEN** the rollback command runs
- **THEN** every `engineFlowId` is cleared, every mirror flow is deleted, and
  the local dispatcher selects the schedules as due by `nextRun` again

@e2e exclude {upgrade-time repair step and occ command; no browser surface.
Verified by unit tests.}
