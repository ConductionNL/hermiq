# Agent Schedule Specification

**Status**: planned
**Standards**: cron (POSIX 5/6-field), OpenAPI
**Feature tier**: MVP

**OpenSpec changes:** _(none yet — run `/opsx-ff agent-schedule`)_

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
A single Nextcloud `TimedJob` MUST poll for due schedules and, for each, run the bound agent via OpenRegister's existing agent handler under the schedule owner's identity.

#### Scenario: A due schedule fires exactly once
- GIVEN a schedule whose `nextRun` is now or in the past and `enabled=true`
- WHEN the dispatcher tick runs
- THEN the system MUST advance `nextRun` and mark the schedule `running` **before** invoking the agent (at-most-once / crash safety)
- AND run the agent impersonating `Schedule.owner` so file search, tools, and delivery stay tenant-scoped
- AND record `lastStatus` and, for finite `repeat`, increment `repeat.completed` and delete the schedule when the limit is reached

### Requirement: Pause, resume, and delete schedules [MVP]
The system MUST let a user disable/enable or delete a schedule without touching the underlying agent.

#### Scenario: Pause a schedule
- GIVEN an enabled schedule
- WHEN the user disables it
- THEN the dispatcher MUST skip it on subsequent ticks until re-enabled

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
