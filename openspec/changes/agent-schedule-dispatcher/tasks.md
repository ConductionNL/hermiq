# Tasks: agent-schedule-dispatcher

## 1. Dependency and registration

- [x] 1.1 Add `dragonmantank/cron-expression` to `composer.json` and update `composer.lock`.
- [x] 1.2 Register `Hermiq\BackgroundJob\ScheduleTask` as a single `<background-jobs>` entry in `appinfo/info.xml` (one block only — avoid the double-block upgrade crash).

## 2. ScheduleTask (TimedJob wrapper)

- [x] 2.1 Create `lib/BackgroundJob/ScheduleTask.php` extending `OCP\BackgroundJob\TimedJob`, with SPDX docblock.
- [x] 2.2 In the constructor call `setInterval(300)`, `setTimeSensitivity(IJob::TIME_SENSITIVE)`, `setAllowParallelRuns(false)`; delegate `run()` to `ScheduleService::run()`.

## 3. ScheduleService (dispatch logic)

- [x] 3.1 Create `lib/Service/ScheduleService.php` (SPDX docblock) that selects due schedules (`nextRun <= now` AND `enabled`) via OpenRegister `ObjectService`.
- [x] 3.2 Compute next `nextRun` per `kind` (cron via `dragonmantank/cron-expression`, interval via arithmetic, once via `runAt`) anchored to the owner's timezone.
- [x] 3.3 Persist next `nextRun` and `lastStatus="running"` via `ObjectService` BEFORE invoking the agent (at-most-once / crash safety).
- [x] 3.4 Impersonate `Schedule.owner` (`IUserSession`/`IUserManager`) and invoke OpenRegister `AgentHandler`/`ChatService` with the resolved `agentId` and `prompt`.
- [x] 3.5 Capture output and call the delivery hook based on `deliver` (`talk`/`notification`/`none`); `talk-delivery` implementation is out of scope (ship a logging no-op seam).
- [x] 3.6 Write `lastStatus`/`lastError`, increment `repeat.completed`, and delete the schedule via `ObjectService` when `repeat.times` is reached.
- [x] 3.7 Catch per-schedule failures (missing agent, agent error) into `lastStatus`/`lastError` without aborting the rest of the tick.

## 4. Verify

- [x] 4.1 Verified live on NC 34 + OpenRegister 0.2.17: seeded cron/interval/once schedules (nextRun in the past), executed `ScheduleTask` — each fired and `nextRun` advanced correctly: cron `*/5` → next slot; interval 60 → ~now+60m; once → `nextRun=null` + `enabled=false` (self-disabled). All under the owner's timezone (instance-default fallback).
- [x] 4.2 Verified live: `nextRun` stays advanced even when the agent invocation throws (nil agent → OR "expected one result but found none") — the failure path no longer reverts the commit (bug 4 fixed). A second immediate tick re-fired nothing (advanced schedules are future-dated / one-shots disabled). Finite-repeat delete-at-limit is unit-tested (`testFiniteRepeatIsPreservedOnSave` + limit path); a live LLM-successful run (`lastStatus=ok`) is gated on a real OpenRegister Agent + Ollama and left as follow-up — the full dispatch-and-invoke wiring is proven (execution reaches OR `ChatService`/`AgentMapper`).

## Acceptance criteria

- One `TimedJob` polls due+enabled schedules and fires the bound OpenRegister agent under the owner's identity.
- `nextRun`/`repeat.completed` are advanced BEFORE the agent turn (no double-fire on crash).
- Cron next-run uses `dragonmantank/cron-expression` and honours the owner timezone.
- Disabling or deleting a schedule stops future fires; finite `repeat` schedules self-delete at their limit.
- Hermiq adds no LLM/tool engine; all execution and persistence go through OpenRegister (single write-path).

## Quality reminders

- SPDX `@license`/`@copyright` tags inside each PHP file docblock; pass `composer check:strict`.
- No stub `run()` bodies, no `var_dump`/`error_log`; the delivery no-op must log its intent, not be empty.
- Do not use sed/awk/scripts to edit PHP — use the Edit tool.
- i18n keys in English source; add `@spec` docblock tags referencing this change's tasks.
- Run PHPUnit the CI way (php:8.3-cli + OCP stubs, no live NC/OR).
