# Design: operations-parallel-run-workers

Kind: code. Size M. `ScheduleTask`, `ScheduleService::run()` and `runNow()`, a new queued job, the use of OpenRegister's object lock, two admin settings and operator documentation. One optional `Schedule` property, incidental to the code, so the change is `kind: code` under hydra ADR-032.

## Context at development db6b74dc

- `lib/BackgroundJob/ScheduleTask.php:48-80` the one `TimedJob`, 300 seconds, time-sensitive, `setAllowParallelRuns(false)`; `:100-116` `run()` syncing mirror flows then calling `ScheduleService::run()`.
- `lib/Service/ScheduleService.php:338-362` `run()`: `findDueSchedules()` (`:840`), `loadEngagedOrganisations()`, then a sequential `foreach` with per-schedule isolation; `:937` `dispatch()` with the kill switch, budget and approval gates; `:1491-1500` `startOccurrence()`, `:1515-1532` `startRetryAttempt()`, `:1550-1577` `startFreshOccurrence()` committing `nextRun`, `repeat` and `lastStatus: running` before the turn.
- `lib/BackgroundJob/AgentRunRequestedJob.php:43`, `WebhookAgentRunJob.php:44`, `TalkTurnJob.php:45` existing `QueuedJob` run paths.
- `appinfo/info.xml:51-58` the single `<background-jobs>` block.
- `openspec/specs/agent-schedule/spec.md:49-57` "Fire due schedules and run the agent", with its at-most-once scenario.
- `openspec/changes/schedules-onto-engine-triggers/proposal.md`, "The dispatcher thins"; `openspec/changes/instant-talk/design.md:32-40`, the insert-only claim store with a time-to-live.
- `lib/Settings/hermiq_register.json:170-174` `Schedule.lastStatus`, a free string written by the dispatcher; `:240` `retryState`.
- openregister development at c53dd0685c (read only): `lib/Service/ObjectService.php:4532-4545` `lockObject(identifier, process, duration)`, `:4566` `unlockObject()`; `lib/Service/Object/LockHandler.php:260-267` "@throws LockedException If object is already locked."
- hermiq ADR-002 (one polling `TimedJob`, commit before run, at most once), hydra ADR-069 (jobs in `lib/BackgroundJob/`, `QueuedJob` for dynamic work, runtime registration only for dynamic jobs).
- Nextcloud server at 175c7e6be47 (read only): `lib/public/BackgroundJob/QueuedJob.php:26-30`, `core/Command/Background/JobWorker.php:41`.

## D1. The tick decides and commits; workers run

`ScheduleService::run()` keeps its loop, its gates and its commit. What changes is the last step of `dispatch()`: instead of running the turn in the tick's process, it writes `lastStatus: queued`, stores the occurrence key in the new optional `Schedule.pendingOccurrence`, and adds `ScheduleRunJob` with `{ scheduleId, occurrenceKey }` to Nextcloud's job list. The tick stays `setAllowParallelRuns(false)`, so the decision "this occurrence is due and committed" is still made by one process at a time, which is what makes it at most once today.

The occurrence key is the schedule uuid plus the `nextRun` value that was due, for example `3f2c...:2026-09-28T02:00:00Z`. A retry attempt appends its attempt number.

Rejected: letting several ticks run in parallel and race for due schedules. The commit-before-run write goes through `ObjectService`, which has no compare-and-set, so two ticks could both advance the same schedule. Keeping one decider and many runners avoids that race altogether.

Rejected: one `IJobList` entry per schedule, re-added each time it is due. ADR-002 rejected it for thousands of per-row cron jobs; here only a due occurrence becomes a job, and it disappears when it runs.

## D2. A worker claims before it runs

`ScheduleRunJob::run()` checks that `Schedule.pendingOccurrence` still equals its `occurrenceKey`, then claims the occurrence by locking the schedule object through `ObjectService::lockObject()`, with the occurrence key as the lock's holder and the time-to-live as its duration. A schedule has at most one occurrence in flight, so a lock on the schedule is a lock on the occurrence. The winner writes `lastStatus: running`, runs the turn through the same governed path the tick used (the gates are checked again, because time passed between queue and run and a kill switch may have been engaged in between), records the outcome, clears `pendingOccurrence` and unlocks. A worker that meets `LockedException`, or finds a different `pendingOccurrence`, exits without doing anything.

Rejected: a claim table of hermiq's own. hermiq keeps its state in OpenRegister (hermiq ADR-001 and ADR-003), and OpenRegister already has a lock with a holder and a duration.

`instant-talk` specifies its own insert-only claim store for Talk turns. Two claim mechanisms in one app are two things to get right, so the lane should decide whether Talk turns can use the same OpenRegister lock on the turn's session, or this change should use the Talk claim store once it exists. Until then this change needs nothing from `instant-talk`.

## D3. A lost worker becomes a failed run, never a second run

Nextcloud removes a `QueuedJob` from the list before running it, so a worker that dies mid-turn leaves no job behind and nothing re-runs the occurrence. The schedule is left in `queued` or `running` and its lock expires. Each tick sweeps schedules whose `pendingOccurrence` is set, whose lock has expired or was never taken, and whose `lastStatus` changed longer ago than the time-to-live (default 30 minutes, longer than the longest allowed turn), and marks them `lastStatus: failed` with `lastError` "The worker stopped before the run finished." The existing retry policy then decides whether a new attempt is queued, as a new occurrence key. At most once holds: an occurrence runs once or is recorded as failed.

## D4. Limits an administrator sets

`IAppConfig` `run_concurrency`: `{ maxParallel: 4, maxParallelPerOrganisation: 2 }`, set in admin settings (`#[AuthorizedAdminSetting]`). Before claiming, a worker counts schedules with `lastStatus: running` and a live lock, instance-wide and in its organisation; over either limit it adds a fresh `ScheduleRunJob` for the same occurrence and exits, so the occurrence waits in the queue rather than being dropped. The limits count scheduled runs. Flow- and webhook-triggered runs (`AgentRunRequestedJob`, `WebhookAgentRunJob`) have no schedule to lock and keep running as Nextcloud picks up their jobs; a shared limit for them needs a counter that is not tied to a schedule, and is left to a later change.

The per-organisation limit keeps one organisation's hundred nightly agents from holding every worker while another organisation's agents wait.

## D5. Schedules on the engine

A schedule mirrored onto OpenRegister's flow engine (`schedules-onto-engine-triggers`) is fired by the engine, which calls `ScheduleService::runNow()`. `runNow()` takes the same lock on the schedule, so a schedule that fires both from the engine and from a local retry cannot run twice at once. Parallelism of engine-fired runs is the engine's workers', not this change's.

## D6. Operators run more workers

The admin documentation gets a section "Running agents in parallel": run two to four `occ background-job:worker` processes (systemd units), set the limits of D4 to at most the number of PHP workers the instance can spare, and watch the Runs page for `queued` runs that wait long. With the default single cron process nothing breaks: jobs run one after another, as today.

## Declarative versus imperative

`Schedule.pendingOccurrence` is declared in `lib/Settings/hermiq_register.json` with a register version bump. `lastStatus` is a free string and gains the value `queued` and a clearer `failed` message. Claiming and queueing are runtime coordination, which hydra ADR-031 leaves imperative.

## Risks

- OpenRegister's lock is not atomic under two workers: its docblock promises `LockedException`, but a read-then-write lock would let two workers through. Mitigation: Task 1 races two processes on one schedule before anything else is built; if both get the lock, an atomic lock is raised with OpenRegister and this change waits.
- A long turn outlives the time-to-live and the sweep marks a live run failed. Mitigation: the time-to-live is longer than the maximum turn time an agent's `maxTokens` and the tool loop allow, and the sweep also requires `lastStatus` unchanged for that long.
- More parallel runs hit the LLM provider's rate limit. Mitigation: the per-organisation and instance limits, and the provider's 429 is recorded as a run failure that the retry policy handles.
