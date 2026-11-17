---
kind: code
depends_on: [agent-schedule-schema]
---

# Proposal: agent-schedule-dispatcher

## Why

Declaring the `Schedule` schema (upstream `agent-schedule-schema`) lets users persist
schedules, but nothing fires them. This change adds the runtime that makes a
schedule actually run its agent: a single Nextcloud `TimedJob` that polls due
`Schedule` objects and, for each, invokes the bound OpenRegister agent under the
schedule owner's identity. This is Hermiq's core capability and the one engine gap
OpenRegister does not fill (ADR-001, ADR-002).

## What Changes

- Add `Hermiq\BackgroundJob\ScheduleTask extends OCP\BackgroundJob\TimedJob`: one polling
  task, `setInterval(~300s)`, `setAllowParallelRuns(false)`, time-sensitive. It
  delegates all logic to `ScheduleService` (copying OpenConnector's `JobTask`
  wrapper pattern).
- Add `Hermiq\Service\ScheduleService::run()`: finds due schedules (`nextRun <= now`
  AND `enabled = true`) via OpenRegister `ObjectService`, and for each:
  1. computes and writes the next `nextRun` and sets `lastStatus = running`
     **before** the agent turn (at-most-once / crash safety);
  2. impersonates `Schedule.owner`;
  3. invokes OpenRegister's existing agent handler
     (`OCA\OpenRegister\Service\Handler\AgentHandler` / `ChatService`) with the
     bound agent + `prompt`;
  4. captures output and calls a delivery hook (`talk` / `notification` — the
     delivery mechanism itself is the separate `talk-delivery` spec, out of scope
     here beyond the hook call);
  5. writes `lastStatus` / `lastError`, bumps `repeat.completed`, and deletes the
     schedule when `repeat.times` is reached.
- Next-run computation: cron via the `dragonmantank/cron-expression` composer
  package (added to `composer.json`), interval via arithmetic, once via the stored
  `runAt`. All anchored to the schedule owner's timezone.
- Register `ScheduleTask` as a `<background-jobs>` entry in `appinfo/info.xml` (single
  block — avoid the double-block upgrade crash) or via a repair step.

This is the **second change in the ADR-032 chain** and `depends_on`
`agent-schedule-schema` — it consumes the `Schedule` schema that change declares.

## Capabilities

### New Capabilities
- `agent-schedule-dispatcher`: the `TimedJob` + `ScheduleService` runtime that polls
  due schedules, fires the bound OpenRegister agent under the owner's identity with
  at-most-once crash safety, and manages run-state / repeat / delivery-hook.

### Modified Capabilities
<!-- None — the Schedule schema fields are declared in agent-schedule-schema, not modified here. -->

## Impact

- **Code (net-new):** `lib/BackgroundJob/ScheduleTask.php`, `lib/Service/ScheduleService.php`.
- **Dependency:** `composer.json` gains `dragonmantank/cron-expression`.
- **Config:** `appinfo/info.xml` registers the background job (single
  `<background-jobs>` block).
- **Runtime dependency on OpenRegister:** delegates execution to
  `AgentHandler`/`ChatService` and all persistence to `ObjectService` (single
  write-path, ADR-001 / ADR-004). Hermiq owns no LLM/tool logic.
- **Operational:** cron granularity is capped by the system-cron poll cadence;
  sub-5-minute schedules need webcron/systemd (documented in ADR-002).
- **Upstream dependency:** requires `agent-schedule-schema` to have landed.
