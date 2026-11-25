# agent-schedule Specification

**Status**: proposed
**Scope**: hermiq
**OpenSpec changes**:
- operations-parallel-run-workers

## Purpose

Scheduled agents run on several background workers at once, each occurrence at most once, within limits an administrator sets. Row `hermiq:op-scale`.

## MODIFIED Requirements

### Requirement: Fire due schedules and run the agent [MVP]
A single Nextcloud `TimedJob` MUST poll for due schedules and, for each, commit the occurrence and hand it to a queued background job that runs the bound agent through hermiq's agent engine under the schedule owner's identity. Several background workers MAY run these jobs at the same time.

#### Scenario: A due schedule fires exactly once
- GIVEN a schedule whose `nextRun` is now or in the past and `enabled=true`
- WHEN the dispatcher tick runs
- THEN the system MUST advance `nextRun` and mark the schedule `queued` **before** any worker invokes the agent (at-most-once / crash safety)
- AND the worker MUST claim the occurrence before it marks the schedule `running` and invokes the agent
- AND run the agent impersonating `Schedule.owner` so file search, tools, and delivery stay tenant-scoped
- AND record `lastStatus` and, for finite `repeat`, increment `repeat.completed` and delete the schedule when the limit is reached

## ADDED Requirements

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
