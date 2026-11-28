# Agent Schedule Specification

**Status**: active (shipped to `main` v0.1.10; cron/interval/once dispatcher with commit-before-run at-most-once, live-verified)
**Standards**: cron (POSIX 5/6-field), OpenAPI
**Feature tier**: MVP

**OpenSpec changes:**
- `openspec/changes/agent-schedule-schema/` — declarative `Schedule` schema (kind: config)
- `openspec/changes/agent-schedule-dispatcher/` — `ScheduleTask` + `ScheduleService` (kind: code, depends_on schema)

## Purpose

Let a user attach a schedule to an OpenRegister agent so it runs unattended — on a cron
expression, a fixed interval, or once at a future time. This is the one capability
OpenRegister does not yet have (its `Agent` entity has no schedule fields and there is no
agent-firing background job) and is the core of Hermiq's MVP: a schedulable agent.

## Data Model

A new OpenRegister schema **`Schedule`** in the `hermiq` register. Key properties:

| property | type | required | notes |
|---|---|---|---|
| `name` | string | yes | human label |
| `agentId` | string (uuid) | yes | reference to an OpenRegister `Agent` |
| `kind` | enum `once`\|`interval`\|`cron` | yes | schedule type |
| `cronExpr` | string | when kind=cron | 5/6-field cron, validated |
| `intervalMinutes` | integer | when kind=interval | ≥1 |
| `runAt` | datetime | when kind=once | future timestamp |
| `prompt` | string | no | task text passed to the agent run |
| `deliver` | enum `talk`\|`notification`\|`none` | yes | where output goes (see `talk-delivery`) |
| `enabled` | boolean | yes | paused schedules are skipped |
| `repeat` | object `{times, completed}` | no | null = forever; one-shots auto-1 |
| `nextRun` | datetime | derived | next fire time |
| `lastStatus` / `lastError` | string | derived | last run outcome |
| `owner` / `organisation` | string | yes | tenant scoping (from `ObjectEntity`) |

Timezone anchored to the schedule owner's configured timezone, not server-local.
## Requirements
### Requirement: Define a schedule for an agent [MVP]
The system MUST let a user create a `Schedule` bound to an existing agent with a cron, interval, or one-shot trigger, validating the cron expression before saving.

#### Scenario: Create a daily cron schedule
- GIVEN an agent "Morning briefing" exists in OpenRegister
- WHEN the user creates a schedule with `kind=cron`, `cronExpr="0 8 * * *"`, `deliver=talk`
- THEN the system MUST persist a `Schedule` object with a computed `nextRun` at the next 08:00 in the owner's timezone
- AND reject the save with a clear error if the cron expression is invalid

### Requirement: Fire due schedules and run the agent [MVP]
A single Nextcloud `TimedJob` MUST poll for due schedules and, for each, commit the occurrence and hand it to a queued background job that runs the bound agent through hermiq's agent engine under the schedule owner's identity. Several background workers MAY run these jobs at the same time.

#### Scenario: A due schedule fires exactly once
- GIVEN a schedule whose `nextRun` is now or in the past and `enabled=true`
- WHEN the dispatcher tick runs
- THEN the system MUST advance `nextRun` and mark the schedule `queued` **before** any worker invokes the agent (at-most-once / crash safety)
- AND the worker MUST claim the occurrence before it marks the schedule `running` and invokes the agent
- AND run the agent impersonating `Schedule.owner` so file search, tools, and delivery stay tenant-scoped
- AND record `lastStatus` and, for finite `repeat`, increment `repeat.completed` and delete the schedule when the limit is reached

### Requirement: Pause, resume, and delete schedules [MVP]
The system MUST let a user disable/enable or delete a schedule without touching the underlying agent.

#### Scenario: Pause a schedule
- GIVEN an enabled schedule
- WHEN the user disables it
- THEN the dispatcher MUST skip it on subsequent ticks until re-enabled

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

### Requirement: A person can give an agent a standing goal with a check (REQ-AGGOAL-001)

The system MUST let a person set one active goal on a session with an agent they may use, with a statement, a check, an interval of 15 to 1440 minutes and a turn limit of 1 to 50. The check MUST be either an object count with a target, evaluated as the goal owner, or a judge question answered through the model provider chokepoint.

#### Scenario: A permit officer sets a reminder goal
- GIVEN a permit officer in a session with the agent "Permit reminder"
- WHEN they choose "Set a goal", enter "Every overdue permit application has had a reminder", pick the check "Count of overdue applications without a reminder is 0", every 60 minutes, at most 10 turns, and save
- THEN the session header shows the goal, "turn 0 of 10" and "Stop goal"

### Requirement: Goal turns continue the same session through the scheduled-run gates (REQ-AGGOAL-002)

The system MUST run each goal turn in the goal's session, through the kill switch, budget and approval gates of a scheduled run. After each turn it MUST run the check and store the result. It MUST set the goal to reached when the check passes, to exhausted when the turn limit is used, and MUST notify the goal owner in both cases.

#### Scenario: The goal is reached after three turns
- GIVEN an active goal whose check counts 4, 1 and then 0 overdue applications after turns one to three
- WHEN the third turn's check runs
- THEN the goal is reached, no fourth turn runs, and the officer gets a notification "Goal reached: Every overdue permit application has had a reminder"
- @e2e exclude background job, covered by PHPUnit on ScheduleService and GoalCheckService

#### Scenario: The kill switch holds a goal
- GIVEN an active goal whose organisation's kill switch is engaged
- WHEN its next turn is due
- THEN no turn runs, the goal shows "blocked" for that turn, and it retries at the next interval
- @e2e exclude background job, covered by PHPUnit

### Requirement: A goal can be stopped by its owner or the agent owner (REQ-AGGOAL-003)

The system MUST let the goal owner and the agent owner stop an active goal. A stopped goal MUST run no further turns. Any other user MUST get HTTP 404 for the goal.

#### Scenario: The officer stops the goal
- GIVEN an active goal in its session
- WHEN the officer chooses "Stop goal"
- THEN the header shows "Goal stopped" and no further turn runs

### Requirement: A schedule can repeat on an interval or run once (REQ-SPCOV-003)

Besides a cron rule, a person SHALL be able to schedule an agent every given number of
minutes, or once at a set date and time. An interval schedule SHALL fire again that many
minutes after its last due time. A one-time schedule SHALL fire once and SHALL NOT fire
again.

Rows: `sc-interval`, `sc-once`. Board: `hermiq/HmPlanning`.

#### Scenario: An agent runs every thirty minutes
- **GIVEN** a schedule of kind "Interval" with 30 minutes, last due at 09:00
- **WHEN** the dispatcher runs at 09:30
- **THEN** the agent runs, and the next run reads 10:00
@e2e exclude The dispatcher runs on Nextcloud's background-job clock, which a browser test cannot advance; the next run per kind is covered by ScheduleServiceTest.

#### Scenario: An agent runs once
- **GIVEN** a schedule of kind "Once" set to 14 November 2026 at 16:00
- **WHEN** that time has passed and the dispatcher has run
- **THEN** the agent has run once, and the schedule shows no next run
@e2e exclude The dispatcher runs on Nextcloud's background-job clock, which a browser test cannot advance; a one-time schedule clearing its next run is covered by ScheduleServiceTest.

### Requirement: A schedule keeps its local time across daylight saving changes (REQ-SPCOV-004)

The system SHALL compute a schedule's next run in the time zone of the schedule owner, as
set in their Nextcloud profile, falling back to the instance time zone and then UTC. A
schedule set for 08:00 SHALL keep firing at 08:00 local time when daylight saving time
starts or ends.

Rows: `sc-timezone`. No screen: computed on the server at every run.

#### Scenario: Summer time ends
- **GIVEN** an owner in `Europe/Amsterdam` with the cron rule `0 8 * * *`
- **WHEN** the next run is computed on Saturday 24 October 2026, the day before summer time ends
- **THEN** Sunday's run is at 08:00 Amsterdam time, which is 07:00 UTC
@e2e exclude Computed on the server at every run, with no screen; the cron next run in the owner's Europe/Amsterdam zone is covered by ScheduleServiceTest.

#### Scenario: The owner has no time zone set
- **GIVEN** an owner without a time zone and an instance default of `Europe/Amsterdam`
- **WHEN** the next run is computed
- **THEN** it is computed in `Europe/Amsterdam`
@e2e exclude Computed on the server at every run, with no screen; the zone resolution is covered by ScheduleServiceTest.

### Requirement: A schedule does not start a new run while its last run is still going (REQ-SPCOV-014)

The dispatcher SHALL run due schedules one tick at a time and SHALL NOT start a tick while the
previous tick is still running. A schedule whose run is still going SHALL therefore not start a
second run, and its next due run SHALL start on the first tick after the running one has ended.

Row: `sc-overlap`. Board: `hermiq/HmPlanning`.

#### Scenario: A long run is not doubled
- **GIVEN** a schedule that runs every five minutes, and a run that started at 08:00 and takes twelve minutes
- **WHEN** the dispatcher's next ticks come due at 08:05 and 08:10
- **THEN** no second run of that schedule starts before 08:12
- **AND** run history shows one run starting at 08:00 and the next one after 08:12
@e2e exclude Needs a run that outlasts two background-job ticks, which a browser test cannot drive; covered by ScheduleTaskTest (parallel runs off).

### Requirement: Every schedule a person may see is on one page (REQ-SCHED-001)

The system MUST offer a Schedules page listing the schedules the user owns or of agents the user may use, and every schedule of the organisation to an organisation admin, with agent, when, next run, last result and enabled, filterable by agent, enabled and failed last result.

#### Scenario: A functional admin checks which schedules failed last night
- GIVEN a functional admin with twelve schedules across five agents, two of which failed
- WHEN they open Schedules and filter on "Last result: failed"
- THEN two rows show, each with its agent, next run and the error on hover

#### Scenario: A colleague does not see another person's private agent's schedule
- GIVEN a schedule of a private agent the user may not use
- WHEN the user calls `GET /api/schedules`
- THEN that schedule is not in the answer
- @e2e exclude authorization contract, covered by PHPUnit and Newman

### Requirement: An agent's page offers one choice of how it starts (REQ-SCHED-002)

The system MUST show on the agent page how the agent starts: by hand, on a schedule and on an event, each with whether it is active. A user who may use the agent MUST be able to run it by hand with a prompt without a schedule, through the same governance gates as a scheduled run. "Add event" MUST create a draft flow with a trigger and this agent's step for the person to finish on the canvas.

#### Scenario: A case handler runs an agent by hand without a schedule
- GIVEN an agent with no schedule
- WHEN a case handler enters "Summarise the open objections" under "By hand" and chooses "Run now"
- THEN the run starts, appears in run history with trigger "manual", and no schedule was created

#### Scenario: An owner adds an event start
- GIVEN the owner on the agent page
- WHEN they choose "Add event" and "When an object is created or changed"
- THEN a draft flow opens on the canvas with a trigger node and this agent's step, inactive until they activate it

### Requirement: A schedule can be set in plain words (REQ-SCHED-003)

The system MUST read a plain English or Dutch description of when a schedule runs, turn it into a rule, and show a readback and the next three runs before saving. It MUST store the rule, not the words. Text it cannot read MUST be refused with an example.

#### Scenario: A team lead schedules a weekday digest
- GIVEN a team lead creating a schedule
- WHEN they type "every weekday at eight" in "When should it run?"
- THEN the form shows "Every weekday at 08:00, Europe/Amsterdam" and the next three weekday dates, and saving stores the cron rule `0 8 * * 1-5`

#### Scenario: A Dutch phrase is read too
- GIVEN the same form in Dutch
- WHEN the team lead types "elke maandag om negen uur"
- THEN the readback is "Elke maandag om 09:00" and the rule is `0 9 * * 1`
- @e2e exclude parser table, covered by PHPUnit on ScheduleWhenParser

### Requirement: Each occurrence is claimed once, whatever the number of workers (REQ-PRUN-001)

Hermiq MUST let a worker run a scheduled occurrence only after it has claimed that occurrence, and exactly one claim per occurrence MUST succeed. A worker that loses the claim MUST exit without running. The gates for the kill switch, the budget and approval MUST be checked again by the worker before the turn.

#### Scenario: Four workers, one occurrence
- GIVEN four background workers and one queued occurrence of the schedule "Nachtelijke zaakcontrole", added twice to the queue by accident
- WHEN the workers pick up both jobs at the same moment
- THEN exactly one run of that occurrence appears on the Runs page, and the other job exits without a run
- @e2e exclude needs parallel worker processes; covered by a race test on the claim and a live check with two workers

#### Scenario: The kill switch engaged after queueing still stops the run
- GIVEN an occurrence queued at 02:00
- WHEN an administrator engages the organisation's kill switch at 02:01, before a worker picks it up
- THEN the worker records the occurrence as skipped by the kill switch and does not run the agent
- @e2e exclude timing across a background job; covered by PHPUnit on the worker

### Requirement: A lost worker is recorded as a failed run (REQ-PRUN-002)

Hermiq MUST mark an occurrence whose claim outlived its time-to-live without an outcome as failed with the message "The worker stopped before the run finished.", and MUST then apply the schedule's retry policy. Hermiq MUST NOT run that occurrence again under the same key.

#### Scenario: A worker is killed mid-run
- GIVEN a running occurrence whose worker process is stopped
- WHEN the claim's time-to-live passes and the next tick sweeps
- THEN the schedule shows `failed` with "The worker stopped before the run finished.", and a retry, if the schedule has one, runs under a new attempt key
- @e2e exclude killing a worker process; covered by PHPUnit on the sweep

### Requirement: An administrator limits how many runs go at once (REQ-PRUN-003)

Hermiq MUST let only a Nextcloud administrator set a maximum of parallel runs for the instance and per organisation. A worker that would exceed either limit MUST put the occurrence back in the queue and MUST NOT drop it. The limits MUST apply to scheduled runs.

#### Scenario: One organisation cannot take every worker
- GIVEN a per-organisation limit of 2 and 40 nightly schedules of Gemeente Tilburg due at 02:00, with four workers
- WHEN the workers run
- THEN at most two Tilburg runs are running at any moment, and a run of another organisation due at 02:00 starts without waiting for all 40
- @e2e exclude needs parallel workers and two organisations; covered by PHPUnit on the limit check and a live check

## User Stories

- As an agent builder, I want to run an agent every morning so that I get a daily briefing without manual triggering.
- As an admin, I want to pause a misbehaving schedule so that it stops firing while I investigate.
- As a tenant user, I want my scheduled runs to execute as me so that they only touch my data.

## Acceptance Criteria

- [ ] A `Schedule` OpenRegister schema exists in the `hermiq` register with the properties above.
- [ ] Creating a schedule validates the cron/interval/once input and computes `nextRun`.
- [ ] A `TimedJob` dispatcher polls due schedules and fires the bound agent via OpenRegister.
- [ ] Firing advances `nextRun` / bumps `repeat.completed` **before** the agent turn (no double-fire on crash).
- [ ] Each run executes under the schedule owner's identity (tenant isolation).
- [ ] Disabling or deleting a schedule stops future fires.

## Notes

- Uses `dragonmantank/cron-expression` for next-run computation; copies OpenConnector's
  `JobService`/`JobTask` scheduler pattern (which is interval-only today).
- NC registers ONE `TimedJob` class → the dispatcher must poll all schedules internally;
  sub-5-minute precision needs webcron/systemd (documented in ADR-002).
- Delegates execution to OpenRegister's `AgentHandler`/`ChatService` — Hermiq owns no LLM/tool logic (ADR-001).
- Related: **ADR-001** (agent boundary), **ADR-002** (scheduling via TimedJob), `talk-delivery`, `run-audit-log`.
