# Tasks: operations-parallel-run-workers

Kind: code. Size M. Row `hermiq:op-scale`.

## Implementation tasks

### Task 1: The occurrence claim, proven under a race
- **spec_ref**: `openspec/changes/operations-parallel-run-workers/specs/agent-schedule/spec.md#requirement-each-occurrence-is-claimed-once-whatever-the-number-of-workers-req-prun-001`
- **files**: `lib/Service/Run/OccurrenceClaim.php`, `lib/Settings/hermiq_register.json`
- **acceptance_criteria**:
  - GIVEN two processes locking one schedule at once through `ObjectService::lockObject()` WHEN both try THEN exactly one holds the lock; if both do, the change stops and the lane raises an atomic lock with OpenRegister
  - GIVEN an expired lock and an unchanged `lastStatus` older than the time-to-live WHEN the sweep asks THEN the occurrence is reported as lost
- [x] Implement
- [ ] Test (a race test with two PHP processes against the dev instance; PHPUnit on the stale check)
  - Built 17 Nov: `OccurrenceClaim` claims through OpenRegister main's ADVISORY lock (`lockObject(LockRequest, advisory: true)`), not the object lock. Reason: the object lock lets the holder's own user extend it, and every worker impersonates the schedule owner, so two workers would both hold it; the advisory lock is taken by compare-and-swap and refuses any second holder. `Schedule.pendingOccurrence` and `pendingSince` added (register 0.65.0, Schedule 0.5.0). PHPUnit on the claim and the stale check: `OccurrenceClaimTest`. Left: the two-process race against a live instance.

### Task 2: The tick enqueues instead of running
- **spec_ref**: `openspec/changes/operations-parallel-run-workers/specs/agent-schedule/spec.md#requirement-fire-due-schedules-and-run-the-agent-mvp`
- **files**: `lib/Service/ScheduleService.php`, `lib/BackgroundJob/ScheduleRunJob.php`
- **acceptance_criteria**:
  - GIVEN a due schedule WHEN the tick runs THEN `nextRun` is advanced, `lastStatus` is `queued` and one `ScheduleRunJob` is in the job list
  - GIVEN the same tick run twice WHEN the second runs THEN no second job is added for the occurrence
- [x] Implement
- [x] Test (PHPUnit on `run()` with a fake job list)
  - `ScheduleServiceParallelRunsTest::testTheTickQueuesADueOccurrenceInsteadOfRunningIt`, `testASecondTickQueuesNothingForAnOccurrenceInFlight`. The tick queues only when the installed OpenRegister offers the advisory lock; otherwise it runs schedules itself as before.

### Task 3: The worker claims, re-checks the gates and runs
- **spec_ref**: `openspec/changes/operations-parallel-run-workers/specs/agent-schedule/spec.md#requirement-each-occurrence-is-claimed-once-whatever-the-number-of-workers-req-prun-001`
- **files**: `lib/BackgroundJob/ScheduleRunJob.php`, `lib/Service/ScheduleService.php`
- **acceptance_criteria**:
  - GIVEN a lock held by another worker WHEN the job runs THEN it exits and nothing is recorded
  - GIVEN a kill switch engaged after queueing WHEN the job runs THEN the occurrence is recorded as skipped
  - GIVEN `runNow()` from an engine-fired schedule WHEN it runs THEN it takes the same lock on the schedule
- [x] Implement
- [x] Test (PHPUnit on the job and on `runNow()`)
  - `ScheduleRunJobTest`; `ScheduleServiceParallelRunsTest` (stale, lost claim, kill switch after queueing, the claiming worker, `runNow()` under the same claim).

### Task 4: The sweep for lost workers
- **spec_ref**: `openspec/changes/operations-parallel-run-workers/specs/agent-schedule/spec.md#requirement-a-lost-worker-is-recorded-as-a-failed-run-req-prun-002`
- **files**: `lib/Service/ScheduleService.php`
- **acceptance_criteria**:
  - GIVEN an expired lock with no outcome WHEN the tick sweeps THEN the schedule is `failed` with the lost-worker message and the retry policy is applied under a new key
- [x] Implement
- [x] Test (PHPUnit on the sweep)
  - `ScheduleServiceParallelRunsTest::testTheSweepFailsALostOccurrenceAndRetriesUnderANewKey`, `testTheSweepLeavesALiveWorkerAlone`.

### Task 5: Concurrency limits for scheduled runs
- **spec_ref**: `openspec/changes/operations-parallel-run-workers/specs/agent-schedule/spec.md#requirement-an-administrator-limits-how-many-runs-go-at-once-req-prun-003`
- **files**: `lib/Controller/Settings/RunConcurrencySettingsController.php`, `appinfo/routes.php`, `lib/BackgroundJob/ScheduleRunJob.php`, `src/views/AdminRoot.vue`
- **acceptance_criteria**:
  - GIVEN a per-organisation limit of 2 and 2 runs with a live lock WHEN a third job of that organisation starts THEN it re-queues itself and exits
  - GIVEN a non-admin WHEN they call the settings route THEN the answer is 403
- [x] Implement
- [x] Test (PHPUnit on the limit check and the auth)
  - `RunConcurrencySettingsTest`, `RunConcurrencySettingsControllerTest`, `ScheduleServiceParallelRunsTest::testAWorkerOverTheOrganisationLimitRequeuesTheOccurrence`. A running run counts while it is within the claim's time-to-live.

### Task 6: Operator documentation and a live check
- **spec_ref**: `openspec/changes/operations-parallel-run-workers/specs/agent-schedule/spec.md#requirement-an-administrator-limits-how-many-runs-go-at-once-req-prun-003`
- **files**: `docs/`
- **acceptance_criteria**:
  - GIVEN the docs WHEN an operator follows "Running agents in parallel" THEN two workers run two due schedules at the same time on the dev instance
- [x] Implement
- [ ] Test (a live check with two `occ background-job:worker` processes, run times written into the PR body)
  - `docs/Installation/parallel-runs.md`. Left: the live check.

## Verification
- [ ] `openspec validate operations-parallel-run-workers --type change --strict` passes
- [ ] PHPUnit runs, exit code read, including the race test
  - The unit suite runs green (5767 tests); the race test needs a live instance.
- [ ] A live check on the dev instance with two workers: ten due schedules finish in about half the single-worker time, each run once
