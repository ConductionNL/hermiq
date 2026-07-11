# run-analytics (delta)

Bounds the AuditTrail read `AnalyticsService::computeAnalytics()` issues so it scans only
the calling tenant's own data, not every organisation's `action='run'` rows instance-wide.

## MODIFIED Requirements

### Requirement: Analytics computation MUST NOT read audit-trail rows outside the caller's tenant scope
The system's `AnalyticsService::computeAnalytics()` MUST issue an `AuditTrailMapper`
query bounded to the caller's own schedule set (via a scoped filter or an explicit limit
paired with a documented follow-up), and MUST NOT rely solely on post-query, in-PHP
filtering to enforce the tenant boundary.

#### Scenario: A tenant with few schedules requests analytics on a large multi-tenant instance
- **GIVEN** an OpenRegister instance with `action='run'` audit-trail rows belonging to
  many organisations
- **WHEN** a user from one organisation calls `GET` on the analytics endpoint
- **THEN** the underlying `AuditTrailMapper::findAll()` call MUST be bounded (a scoped
  filter and/or an explicit limit) rather than reading every organisation's rows
- **AND** the returned metrics MUST still only reflect that caller's own schedules

#### Scenario: The unit test guards the query shape, not just the aggregated result
- **GIVEN** `tests/Unit/Service/AnalyticsServiceTest.php`
- **WHEN** `computeAnalytics()` is invoked against a mocked `AuditTrailMapper`
- **THEN** the test MUST assert the arguments passed to `findAll()` include a bounding
  filter or limit, not merely assert on the mock's canned return value
