# Tasks: analytics-unbounded-audit-scan

## 1. Backend: bound the audit-trail read

> **Re-measured 2026-11-17 (build round 2): blocked on a design decision, no code changed.**
> The read moved to `AnalyticsService::visibleRuns()` and its tenant boundary is no longer the schedule
> set: it is `changed.agentId` checked against the caller's visible agents (`loadVisibleAgents()`), because
> the run entries hang on different objects per writer: `run` on a schedule (`ScheduleService`), a skill
> draft (`SkillConsolidationService`) or a schedule again (`SkillLearningsCaptureService`), and `agent-run`
> on the flow's triggering object (`FlowAgentRunService`) or the agent (`WebhookAgentRunService`). There is
> no `$scheduleUuidToAgent` any more. So:
> - 1.1 as written (`object_uuid IN <schedule uuids>`) would drop every `agent-run` and skill run from the
>   caller's own metrics: a wrong total, not a bound.
> - 1.2's stopgap `limit` would make every metric a silent floor (the method's own comment says why it is
>   deliberately unlimited), and a limit before the per-row tenant check pages over rows the caller cannot see.
> - A correct bound needs OpenRegister's `AuditTrailMapper::findAll()` to filter on `changed.agentId`
>   (a JSON path, per database dialect), or the writers to record the agent in a queryable column. That is
>   an OpenRegister change with MySQL, PostgreSQL and SQLite JSON tests, and a decision on which of the two.
> Until one is chosen, 1.1 to 2.3 cannot be built without changing what a tenant sees.
>
> **Decided 2026-11-24 (build round 3): bound the read per visible agent through the mapper's existing
> `search` term**, a LIKE on `changed`, so OpenRegister only returns entries whose context names one of the
> caller's agents. No OpenRegister change, no limit, the per-row `changed.agentId` check stays (the LIKE is
> a superset) and entries are de-duplicated. One query per visible agent; no visible agent, no query.


- [x] 1.1 In `lib/Service/AnalyticsService.php::computeAnalytics()` (currently
      `lib/Service/AnalyticsService.php:117`), replace the unbounded
      `$this->auditTrailMapper->findAll(filters: ['action' => self::RUN_ACTION])` with a
      call that bounds the read to the caller's own tenant scope — pass the caller's
      schedule UUIDs (already computed in `$scheduleUuidToAgent`, line ~105) as part of
      `filters`, or batch them if the mapper's filter grammar supports an `IN`-style
      match on `object_uuid`.
- [x] 1.2 If the mapper cannot filter by a UUID set in one call (verify against the real
      `OCA\OpenRegister\Db\AuditTrailMapper::findAll()` signature, not just the test
      stub), add an explicit `limit` as a stopgap and document why in a code comment,
      plus file a follow-up noting the scoped-filter fix is deferred to OpenRegister.
- [x] 1.3 Confirm no other caller in `lib/` relies on the old unscoped call shape.
      `visibleRuns()` is private; computeAnalytics() and listRuns() are its only callers. BudgetService and
      DepartmentEvidence run their own unscoped `action` reads: follow-ups, outside this change.

## 2. Tests: make the phantom-green mock assert the query shape

- [x] 2.1 In `tests/Unit/Service/AnalyticsServiceTest.php`, change the blanket
      `$mapper->method('findAll')->willReturn($runs)` (line ~94) to an expectation that
      also asserts the arguments `computeAnalytics()` passes — e.g.
      `$mapper->expects($this->once())->method('findAll')->with($this->callback(fn ($filters) => ...))`
      verifying a bounding filter/limit is present.
- [x] 2.2 Add a case where the caller's schedule set changes between two calls and
      assert the mapper call's filter argument changes accordingly (proves the bound is
      derived from the caller's own data, not a static value).
- [x] 2.3 Re-run `composer phpunit` (unit suite) to confirm the tightened mock still
      passes against the fixed implementation and would fail against the current
      (unbounded) one — flip task 1.1 back out temporarily to confirm the red/green
      delta before finalizing.

## 3. Verify

- [x] 3.1 `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan).
      2026-11-24 (#1930): PHPCS, PHPMD, PHPStan and the PHPUnit failing set identical to development; Psalm 7
      not runnable in the cloud sandbox (PHP 8.3.6, needs >= 8.3.16).
- [x] 3.2 Manually call `GET /apps/hermiq/api/analytics` (or the routed equivalent) on a
      dev instance with multiple orgs seeded and confirm the response is unchanged in
      shape and correctness for the calling tenant.
      2026-11-24: written as the Newman folder "Run analytics" in
      `tests/integration/hermiq.postman_collection.json`: the organisation response keeps its
      shape, per-agent rows and the status breakdown add up to the totals, `/api/runs` counts the
      same runs, and the one-agent scope counts exactly that agent's row. Written, runs in
      nightly (UNBLOCK rule 3); not run here, no instance.

## Acceptance criteria

- `AnalyticsService::computeAnalytics()` never issues an `AuditTrailMapper::findAll()`
  call without a filter or limit bounding it to the caller's own tenant scope.
- `AnalyticsServiceTest` fails if that invariant regresses (i.e. the mock actually
  asserts on the call, not just the return value).

## Quality reminders

- No sed/awk/scripts on code — Edit tool only.
- SPDX unaffected (existing files only).
