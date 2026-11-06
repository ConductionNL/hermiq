---
kind: code
depends_on: [schedules-onto-engine-triggers]
---

# Proposal: operations-parallel-run-workers

## Summary

An organisation with many scheduled agents no longer waits for them one after the other. The schedule tick stops running agents itself: it decides which schedules are due, commits each occurrence as it does today, and hands it to a queued background job. Several Nextcloud background workers then run those jobs at the same time. Each occurrence still runs at most once: a worker claims it before it starts, and a lost worker shows up as a failed run, never as a second one. An administrator sets how many scheduled runs may go at once, for the instance and per organisation.

## Why

One row of hermiq's capability matrix, decided in the OpenSpec pass of 2026-09-27.

| row | own rating | decision |
|---|---|---|
| `hermiq:op-scale` | no | build: five competitors rate yes |

Competitor cells rated yes, quoted from the matrix:

- Hermes agent: "hermes_cli/kanban_swarm.py:1-8 parallel specialist workers on the kanban dispatcher (plugins/kanban/systemd/hermes-kanban-dispatcher.service); cron/jobs.py:2652-2653 at-most-once claims across N replicas".
- Copilot Studio: "https://learn.microsoft.com/en-us/microsoft-copilot-studio/requirements-quotas managed cloud service; quotas of thousands of requests per minute per Dataverse environment (for example 8,000 RPM for actions with a paid plan) and adjustable on request."
- Dify: "docker/docker-compose.yaml:227,306,353,660 separate api, Celery worker, beat and agent_backend services; docker/.env.example:57,66-67 SERVER_WORKER_AMOUNT, CELERY_WORKER_AMOUNT, CELERY_AUTO_SCALE".
- n8n: "queue mode spreads executions over worker processes (packages/@n8n/config/src/configs/executions.config.ts:90-91 EXECUTIONS_MODE, packages/cli/src/commands/worker.ts, packages/cli/src/scaling/job-processor.ts)".
- Open WebUI: "models/automations.py:308 FOR UPDATE SKIP LOCKED so several instances share scheduled runs; utils/subagents.py:298 max_concurrent sub-agents".

## What hermiq already has

- One `ScheduleTask` `TimedJob`, every 5 minutes, "time-sensitive, never in parallel" (`lib/BackgroundJob/ScheduleTask.php:48-80`, `setAllowParallelRuns(false)` at `:80`), calling `ScheduleService::run()` (`:100-116`).
- `ScheduleService::run()` loads the due schedules and dispatches them one after the other in one PHP process (`lib/Service/ScheduleService.php:338-362`). `dispatch()` applies the kill switch, budget and approval gates (`:937-1000`), then commits the occurrence before the agent turn: `nextRun` and `repeat` advanced and `lastStatus: running` written (`startOccurrence()` at `:1491-1500`, `startRetryAttempt()` and `startFreshOccurrence()` at `:1515-1577`). That commit is today's at-most-once guarantee (hermiq ADR-002).
- Runs started by a flow, a webhook or a Talk message are already `QueuedJob`s (`lib/BackgroundJob/AgentRunRequestedJob.php:43`, `WebhookAgentRunJob.php:44`, `TalkTurnJob.php:45`), so Nextcloud can run them on several workers at once. Only scheduled runs are sequential.
- The open `schedules-onto-engine-triggers` change moves the clock of most schedules onto OpenRegister's flow engine, which calls `ScheduleService::runNow()` from a dispatch node; the local tick keeps `once` schedules, intervals a cron expression cannot carry, and pending retries.
- The open `instant-talk` change specifies an insert-only claim store with a time-to-live for Talk turns, so a turn runs exactly once whichever path picks it up. It is not built.
- OpenRegister's `ObjectService::lockObject()` locks an object for a holder and a duration, and its lock handler documents that it throws `LockedException` when the object is already locked (openregister `lib/Service/ObjectService.php:4532-4545`, `lib/Service/Object/LockHandler.php:267`, development at c53dd0685c).
- Nextcloud removes a `QueuedJob` from the job list before running it (`lib/public/BackgroundJob/QueuedJob.php:26-30`) and offers `occ background-job:worker` for extra worker processes (`core/Command/Background/JobWorker.php:41`), read in the Nextcloud server tree at 175c7e6be47.

## What this change builds

1. The tick commits and enqueues: for each due schedule it runs the gates and the commit-before-run as today, writes `lastStatus: queued`, and adds a `ScheduleRunJob` for that occurrence.
2. `ScheduleRunJob` claims the occurrence before the turn by taking OpenRegister's object lock on the schedule, with the occurrence key as the lock holder and a time-to-live as its duration, runs it with the existing governed path, and exits without running when the lock is held.
3. `pendingOccurrence` on `Schedule`, so the tick, the worker and the sweep agree on which occurrence is in flight.
4. Concurrency limits set by an administrator for scheduled runs: runs at once for the instance (default 4) and per organisation (default 2). A job over the limit puts itself back in the queue.
5. A sweep in the tick that marks an occurrence stuck in `queued` or `running` past its time-to-live as failed with "The worker stopped before the run finished", and hands it to the schedule's retry policy.
6. Operator documentation: how many `background-job:worker` processes to run, and what the limits do.

## Out of scope

- Parallel steps inside one run, or sub-agents in parallel. One run stays one turn loop.
- Scaling OpenRegister's flow engine workers. Mirrored schedules run on the engine; its workers are OpenRegister's.
- A shared limit for flow- and webhook-triggered runs. They already run as queued jobs and have no schedule to count; a limit for them is a later change.
- A queue or worker daemon of hermiq's own. Nextcloud's job list and workers are the queue (hermiq ADR-002, hydra ADR-069).
