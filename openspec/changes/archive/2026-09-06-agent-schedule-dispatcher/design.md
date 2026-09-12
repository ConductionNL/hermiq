# Design: agent-schedule-dispatcher

## Context

`agent-schedule-schema` declares the declarative `Schedule` OpenRegister schema.
This change adds the runtime that fires those schedules. OpenRegister already ships
the agent engine (`AgentHandler`/`ChatService`, MCP, LLPhant, AuditTrail) — the only
gap is scheduling (ADR-001). OpenConnector has the canonical Nextcloud scheduler
pattern (`JobTask extends TimedJob` → `JobService`) but it is interval-only, with no
cron or one-shot support (ADR-002). This change copies that structure and adds cron +
one-shot semantics, plus at-most-once crash safety.

## Goals / Non-Goals

**Goals:**
- One `Hermiq\BackgroundJob\ScheduleTask extends TimedJob` that polls all due schedules per
  tick (NC registers one job; internal polling, not one job per row).
- `Hermiq\Service\ScheduleService::run()` that selects due+enabled schedules,
  commits run-state before the agent turn, impersonates the owner, delegates
  execution to OpenRegister, and manages delivery-hook / repeat / lifecycle.
- Cron next-run via `dragonmantank/cron-expression`; interval via arithmetic; once
  via `runAt`; all anchored to the owner's timezone.

**Non-Goals:**
- The delivery implementation (Talk/notification bodies) — that is the
  `talk-delivery` capability; here we only call a delivery hook.
- Any agent/LLM/tool/MCP engine — delegated to OpenRegister (ADR-001).
- The `Schedule` schema definition — owned upstream by `agent-schedule-schema`.
- Approval gate / kill-switch / redaction — separate ADR-004 changes.
- Frontend UI for schedules.

## Decisions

**Copy OpenConnector's wrapper pattern.** `ScheduleTask` is a thin `TimedJob` that
does nothing but delegate to `ScheduleService::run()` — mirroring
`OpenConnector/lib/Cron/JobTask.php` → `JobService`. The task sets
`setInterval(300)`, `setTimeSensitivity(IJob::TIME_SENSITIVE)`, and
`setAllowParallelRuns(false)`.

**At-most-once via commit-before-run.** For each due schedule the service computes
and persists the next `nextRun` and `lastStatus = running` through `ObjectService`
**before** invoking the (long) agent turn. A crash mid-turn therefore cannot re-fire
the same occurrence — the state is already advanced. This is the ADR-002 crash-safety
invariant.

**Owner impersonation, OpenRegister execution.** The service resolves
`Schedule.owner` (`IUserManager`/`IUserSession`), impersonates them, then calls
OpenRegister's `AgentHandler`/`ChatService` with the resolved `agentId` and `prompt`.
Every read/write goes through OpenRegister's single write-path so tenancy and audit
(ADR-004) are inherited.

**Next-run per kind, owner timezone.** cron → `dragonmantank/cron-expression`
(`CronExpression::factory($cronExpr)->getNextRunDate(...)` in the owner's tz);
interval → `now + intervalMinutes`; once → `runAt` (and the schedule is retired after
firing). `dragonmantank/cron-expression` is added to `composer.json`.

**Registration.** `ScheduleTask` is registered as a **single** `<background-jobs>`
entry in `appinfo/info.xml` — a second `<background-jobs>` block causes an NC34
upgrade 500, so only one block is used.

**Graceful per-schedule failure.** A failure on one schedule (missing agent, agent
error) is caught, recorded to `lastStatus`/`lastError`, and does not abort the rest
of the tick.

## Declarative-vs-imperative decision (ADR-031)

The `Schedule` schema is **declarative** — it lives in the register JSON and was
declared in the upstream `agent-schedule-schema` change; no imperative code touches
schedule *shape*.

The dispatcher, by contrast, is **legitimately imperative code**, and this is the
recognised ADR-031 exception. ADR-031 pushes derived fields, object lifecycle, and
aggregations into OpenRegister's declarative layer rather than app PHP. The dispatcher
is none of those: it is **scheduled bulk work that fires agents** — a genuine
scheduled-processing job (poll due rows on a `TimedJob` cadence, impersonate an owner,
invoke an external agent engine, deliver output, advance lifecycle). That is exactly
the class of work ADR-031 carves out as imperative: real scheduled processing, not a
derived value, a declarative lifecycle transition, or an aggregation that OpenRegister
could compute. There is no declarative construct in OpenRegister that "runs an agent
on a cron and delivers the result"; the imperative `ScheduleTask` + `ScheduleService`
is the correct home. It still honours the single write-path — every state mutation to
a `Schedule` object goes through `ObjectService`.

## Seed Data

The dispatcher operates on the `Schedule` seed objects defined in
`agent-schedule-schema`'s design (municipality daily permit briefing `0 8 * * *` →
Talk; consultancy weekly lead digest `0 9 * * 1` → notification; travel-agency nightly
booking summary `intervalMinutes=1440`, `repeat.times=30` → Talk). `agentId`
placeholders use the NIL UUID `00000000-0000-0000-0000-000000000000`. This change adds
no new seed objects; it consumes those.

## Risks / Trade-offs

- **Cron granularity capped by system-cron cadence.** Default ajax/system cron
  (~5 min) means sub-5-minute schedules are not minute-precise; webcron/systemd is
  required (documented in ADR-002). Operational, not a code fix.
- **Long agent turns inside a TimedJob.** A slow agent run holds the job; combined
  with commit-before-run this is safe but can delay later schedules in the same tick.
  Provisional decision: process sequentially per tick, accept the delay for MVP.
- **Owner-timezone resolution.** If the owner has no configured timezone, fall back to
  the instance default timezone and record the assumption. Provisional decision:
  instance-default fallback.
- **Delivery hook is a seam, not an implementation.** `deliver=talk/notification`
  calls a hook that the `talk-delivery` change fills in; until then the hook may be a
  no-op logging the intended delivery. Provisional decision: ship the hook seam with a
  logging no-op so the dispatcher is testable independently.
- **NC registers one job class.** All scheduling precision is bounded by the single
  polling task; acceptable and idiomatic (OpenConnector does the same).
