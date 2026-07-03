## ADDED Requirements

### Requirement: A single TimedJob polls due schedules

The system MUST register exactly one `Hermiq\Cron\ScheduleTask` extending
`OCP\BackgroundJob\TimedJob` (OCP interface: `OCP\BackgroundJob\TimedJob`,
`OCP\BackgroundJob\IJob`). On each tick it MUST delegate to
`Hermiq\Service\ScheduleService::run()`, which finds all `Schedule` objects that are
due — `nextRun <= now` AND `enabled = true` — via OpenRegister `ObjectService`. The
task MUST call `setAllowParallelRuns(false)` and run time-sensitive. Disabled
schedules MUST be skipped.

#### Scenario: Only enabled, due schedules are selected

- **WHEN** the dispatcher tick runs
- **THEN** `ScheduleService::run()` MUST select every `Schedule` with `nextRun` at or
  before the current time and `enabled = true`
- **AND** MUST NOT select schedules that are disabled or whose `nextRun` is in the
  future

#### Scenario: Parallel runs are prevented

- **WHEN** a dispatcher tick is already running and the next tick is triggered
- **THEN** the second tick MUST NOT run concurrently (`setAllowParallelRuns(false)`)

### Requirement: Run-state is committed before the agent turn

For each due schedule, the system MUST compute and persist the next `nextRun` and set
`lastStatus = running` via OpenRegister `ObjectService` **before** invoking the agent,
so a crash during the (long) agent turn cannot re-fire the schedule (at-most-once).

#### Scenario: A due schedule fires exactly once even on crash

- **GIVEN** a due, enabled schedule
- **WHEN** the dispatcher processes it
- **THEN** the system MUST advance `nextRun` and set `lastStatus = running` before the
  agent is invoked
- **AND** if the process crashes during the agent turn, the next tick MUST NOT re-fire
  the same occurrence, because `nextRun` was already advanced

### Requirement: Next-run computation per kind and owner timezone

The system MUST compute `nextRun` by `kind`: for `cron`, using the
`dragonmantank/cron-expression` package against `cronExpr`; for `interval`, by adding
`intervalMinutes`; for `once`, from the stored `runAt`. All computation MUST be
anchored to the schedule owner's configured timezone, not server-local time. The
`dragonmantank/cron-expression` dependency MUST be added to `composer.json`.

#### Scenario: Cron next-run honours the owner timezone

- **GIVEN** a schedule with `kind=cron`, `cronExpr="0 8 * * *"`, owned by a user whose
  timezone is Europe/Amsterdam
- **WHEN** the dispatcher computes the next run
- **THEN** `nextRun` MUST be the next 08:00 in Europe/Amsterdam, not server-local 08:00

#### Scenario: One-shot fires from runAt

- **GIVEN** a schedule with `kind=once` and a past `runAt`
- **WHEN** the dispatcher processes it
- **THEN** the agent MUST fire once and the schedule MUST NOT be re-selected afterwards

### Requirement: Fire the bound agent under the owner's identity

For each due schedule, the system MUST impersonate `Schedule.owner`
(OCP interface: `OCP\IUserSession` / `OCP\IUserManager`) and invoke OpenRegister's
existing agent handler (`OCA\OpenRegister\Service\Handler\AgentHandler` /
`ChatService`) with the bound agent (`agentId`) and the schedule `prompt`. Hermiq MUST
NOT implement its own agent/LLM/tool engine (ADR-001). Execution MUST stay
tenant-scoped so file search, tools, and delivery act as the owner.

#### Scenario: Agent runs as the schedule owner

- **WHEN** a due schedule is dispatched
- **THEN** the system MUST impersonate `Schedule.owner` before invoking the agent
- **AND** MUST call OpenRegister's `AgentHandler`/`ChatService` with the resolved
  agent and `prompt`

#### Scenario: Missing agent is recorded, not fatal

- **GIVEN** a due schedule whose `agentId` resolves to no live agent
- **WHEN** the dispatcher processes it
- **THEN** the run MUST fail gracefully with `lastStatus` reflecting the failure and
  `lastError` set, without aborting the rest of the tick

### Requirement: Delivery hook, run-state, and repeat accounting

After the agent turn the system MUST capture the output and delegate delivery via a
delivery hook based on `deliver` (`talk` / `notification` / `none`) — the delivery
implementation itself is the separate `talk-delivery` capability and is out of scope
beyond calling the hook. The system MUST then write `lastStatus` and, on failure,
`lastError`, increment `repeat.completed` for finite `repeat`, and delete the schedule
via `ObjectService` when `repeat.completed` reaches `repeat.times`.

#### Scenario: Finite repeat is deleted at its limit

- **GIVEN** a schedule with `repeat = { times: 3, completed: 2 }`
- **WHEN** the dispatcher completes this run
- **THEN** `repeat.completed` MUST become 3
- **AND** the schedule MUST be deleted via `ObjectService` because the limit is reached

#### Scenario: Output is delivered per the deliver setting

- **GIVEN** a schedule with `deliver = talk`
- **WHEN** the agent run completes with output
- **THEN** the system MUST invoke the delivery hook targeting Talk with the captured
  output
- **AND** a schedule with `deliver = none` MUST skip the delivery hook
