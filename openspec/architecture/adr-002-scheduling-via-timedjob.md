# ADR-002: Scheduling via a Nextcloud TimedJob that polls OpenRegister Schedule objects

**Status**: proposed

**Date**: 2026-07-03

## Context

Hermiq's core capability is running an OpenRegister agent on a schedule. OpenRegister's
`Agent` entity has no schedule fields and `openregister/lib/Cron/` has no agent-firing job.
OpenConnector already has the canonical Nextcloud scheduler pattern (`JobService` +
`JobTask extends TimedJob`), but it stores only a fixed `interval` and polls — it has **no
cron-expression support**. Hermes (the app we port) runs an in-process 60s ticker with
cross-process file locks and, optionally, an external "Chronos" NAS that arms one one-shot
per job. Neither maps cleanly onto a Nextcloud app.

## Decision

Model each schedule as an OpenRegister **`Schedule`** object and register **one**
`Hermiq\BackgroundJob\ScheduleTask extends TimedJob` that, on each tick, **polls all due schedules
and dispatches them internally**. Compute next-run times with `dragonmantank/cron-expression`
(cron), simple arithmetic (interval), or the stored timestamp (once). Delegate execution to
OpenRegister's `AgentHandler`/`ChatService` (Hermiq runs no agent engine). Copy OpenConnector's
`JobService`/`JobTask` structure but add cron + one-shot semantics.

Firing is made at-most-once by advancing `nextRun` / bumping `repeat.completed` and writing a
`running` status via `ObjectService` **before** invoking the (long) agent turn, with
`setAllowParallelRuns(false)` on the task. Each dispatch impersonates `Schedule.owner` before
calling the agent. The Hermes Chronos NAS webhook and file-locked JSON store are **dropped**.

## Consequences

**Positive:**
- One well-understood Nextcloud primitive; no daemon, no external scheduler.
- Reuses OpenRegister for execution and storage; stays within ADR-001's thin-app boundary.
- Crash-safe: a mid-run failure cannot re-fire a one-shot, because state is committed first.

**Negative / trade-offs:**
- Nextcloud registers ONE `TimedJob` class and polls it on the system cron cadence; **cron
  granularity is capped by the poll interval**. Default ajax/cron (~5 min) means sub-5-minute
  schedules need webcron or systemd-cron and still aren't minute-precise. This must be
  documented for operators.
- Long agent turns run inside a `TimedJob`; OpenRegister object-write latency and job runtime
  must be watched.

## Alternatives Considered

| Option | Reason not chosen |
|--------|------------------|
| Add schedule fields to OpenRegister's `Agent` + a job in OpenRegister | Scheduling is Hermiq's reason to exist; putting it in OR blurs the ADR-001 boundary and couples OR to a cron engine. |
| Per-schedule `IJobList` entries (one job per schedule) | Nextcloud's `IJobList` is not designed for thousands of per-row cron jobs; a single polling task is the idiomatic pattern (as OpenConnector does). |
| Port Hermes' in-process ticker / Chronos NAS webhook | An always-on ticker and an external NAS callback don't fit a Nextcloud app's lifecycle; scale-to-zero is a hosted-VPS concern, not an MVP need. |
