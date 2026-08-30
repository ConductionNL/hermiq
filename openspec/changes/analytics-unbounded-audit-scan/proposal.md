---
kind: code
---

# Proposal: analytics-unbounded-audit-scan

# Why

`AnalyticsService::computeAnalytics()` (`lib/Service/AnalyticsService.php:117`) calls:

```php
$logs = $this->auditTrailMapper->findAll(filters: ['action' => self::RUN_ACTION]);
```

`AuditTrailMapper::findAll()` (see the standalone-CI stub at
`tests/Stubs/Db/AuditTrailMapper.php:66-73`, which mirrors the real OpenRegister mapper's
signature) declares `?int $limit = null` — no limit means no limit. This call passes only
an `action` filter, so it reads **every `action='run'` AuditTrail row in the entire
OpenRegister instance, across every tenant/organisation**, into PHP memory before the
method narrows the set down with `isset($scheduleUuidToAgent[$objectUuid])` (line ~120)
to the caller's own schedules. The docblock at `lib/Service/AnalyticsService.php:9-10`
even asserts "no cross-tenant run data leaks" — true for the *returned* data, but false
for what gets *read*: the query itself has no tenant boundary, only the in-PHP filter
does.

This is reachable on every request to `AnalyticsController::index()`
(`lib/Controller/AnalyticsController.php:91`, `#[NoAdminRequired]`, called from the
Analytics dashboard/page on normal user navigation) — not an admin-only or rare
maintenance path. As the install accumulates run history across all agents/orgs, this
becomes an unbounded full-table scan on every dashboard visit from any tenant, with two
compounding costs: (1) growing latency independent of the calling tenant's own data
volume, and (2) every tenant's row data (however briefly) enters the process handling
another tenant's request, which is a defense-in-depth concern even though the final
response is correctly scoped.

The sibling `RunHistoryService` (`lib/Service/RunHistoryService.php:100`) reads the same
mapper by `object_uuid` (already scoped to one object) — a materially smaller and safer
query shape. `AnalyticsService` is the only caller filtering by `action` alone with no
bound.

# What Changes

- `AnalyticsService::computeAnalytics()` MUST NOT call `AuditTrailMapper::findAll()`
  without a bound: either (a) pass `filters` that are tenant/schedule-scoped so the
  mapper only reads rows this caller could ever use (preferred — mirrors
  `RunHistoryService`'s per-object approach, applied per-schedule-uuid or via a
  batched `IN` filter if the mapper supports it), or (b) if a truly global scan is
  unavoidable given the mapper's current filter capabilities, add an explicit `limit`
  and pursue the scoped-filter fix as this change's primary deliverable, with the
  limit as a stopgap documented as such.
- Extend `tests/Unit/Service/AnalyticsServiceTest.php` to assert the query shape, not
  just the aggregated result: `tests/Unit/Service/AnalyticsServiceTest.php:93-94` mocks
  `AuditTrailMapper::findAll()` with a blanket `->method('findAll')->willReturn($runs)`
  that returns the fixture regardless of what arguments `computeAnalytics()` passes. This
  makes the existing green test a "phantom green" for this finding: it can't fail no
  matter how unbounded the real call is, because the mock ignores the call's arguments
  entirely. Add an expectation (`->with(...)` / a spy) that fails the test if
  `findAll()` is invoked without a bounding `filters` set or `limit`.
- Not BREAKING: output shape of `computeAnalytics()` is unchanged; this only changes
  the query issued to compute it.

# Impact

- Affected: `lib/Service/AnalyticsService.php`, `tests/Unit/Service/AnalyticsServiceTest.php`.
- Non-goal: changing `AuditTrailMapper`'s public signature (owned by OpenRegister, out of
  this app's scope per ADR-022 — apps consume, they don't reshape the abstraction).
