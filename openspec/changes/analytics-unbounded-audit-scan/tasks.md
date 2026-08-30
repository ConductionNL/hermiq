# Tasks: analytics-unbounded-audit-scan

## 1. Backend: bound the audit-trail read

- [ ] 1.1 In `lib/Service/AnalyticsService.php::computeAnalytics()` (currently
      `lib/Service/AnalyticsService.php:117`), replace the unbounded
      `$this->auditTrailMapper->findAll(filters: ['action' => self::RUN_ACTION])` with a
      call that bounds the read to the caller's own tenant scope — pass the caller's
      schedule UUIDs (already computed in `$scheduleUuidToAgent`, line ~105) as part of
      `filters`, or batch them if the mapper's filter grammar supports an `IN`-style
      match on `object_uuid`.
- [ ] 1.2 If the mapper cannot filter by a UUID set in one call (verify against the real
      `OCA\OpenRegister\Db\AuditTrailMapper::findAll()` signature, not just the test
      stub), add an explicit `limit` as a stopgap and document why in a code comment,
      plus file a follow-up noting the scoped-filter fix is deferred to OpenRegister.
- [ ] 1.3 Confirm no other caller in `lib/` relies on the old unscoped call shape.

## 2. Tests: make the phantom-green mock assert the query shape

- [ ] 2.1 In `tests/Unit/Service/AnalyticsServiceTest.php`, change the blanket
      `$mapper->method('findAll')->willReturn($runs)` (line ~94) to an expectation that
      also asserts the arguments `computeAnalytics()` passes — e.g.
      `$mapper->expects($this->once())->method('findAll')->with($this->callback(fn ($filters) => ...))`
      verifying a bounding filter/limit is present.
- [ ] 2.2 Add a case where the caller's schedule set changes between two calls and
      assert the mapper call's filter argument changes accordingly (proves the bound is
      derived from the caller's own data, not a static value).
- [ ] 2.3 Re-run `composer phpunit` (unit suite) to confirm the tightened mock still
      passes against the fixed implementation and would fail against the current
      (unbounded) one — flip task 1.1 back out temporarily to confirm the red/green
      delta before finalizing.

## 3. Verify

- [ ] 3.1 `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan).
- [ ] 3.2 Manually call `GET /apps/hermiq/api/analytics` (or the routed equivalent) on a
      dev instance with multiple orgs seeded and confirm the response is unchanged in
      shape and correctness for the calling tenant.

## Acceptance criteria

- `AnalyticsService::computeAnalytics()` never issues an `AuditTrailMapper::findAll()`
  call without a filter or limit bounding it to the caller's own tenant scope.
- `AnalyticsServiceTest` fails if that invariant regresses (i.e. the mock actually
  asserts on the call, not just the return value).

## Quality reminders

- No sed/awk/scripts on code — Edit tool only.
- SPDX unaffected (existing files only).
