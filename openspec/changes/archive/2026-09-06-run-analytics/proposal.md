---
kind: code
---

# Proposal: run-analytics

# Why

The `run-analytics` capability spec (V2, status: idea) surfaces dashboards over agent run
data — success rate, latency, status breakdown, per-agent — computed directly from
OpenRegister's `AuditTrail` run records, with no separate analytics store or ETL (ADR-004
governance, ADR-001 Option C+). This change builds that read-only analytics surface on
Hermiq's existing run-audit entries.

# What Changes

- Add `lib/Service/AnalyticsService.php`: `computeAnalytics(?agentId)` loads the caller's
  **tenant-scoped** schedules (via `ObjectService`, RBAC on), then aggregates their
  `action='run'` `AuditTrail` entries into: total runs, success rate (`status='ok'`),
  status breakdown, latency (avg/min/max `durationMs`), and a per-agent breakdown — never
  including another organisation's runs (only the caller's own schedules are read).
- Add `lib/Controller/AnalyticsController.php` (`@NoAdminRequired`, `@NoCSRFRequired`):
  `GET /api/analytics` (org-aggregate) and `?agentId=` (per-agent), tenant-scoped.
- Register the route; add a **Analytics** nav page (`src/views/RunAnalytics.vue`,
  `src/api/analytics.js`): metric cards (total runs, success rate, avg latency), a status
  breakdown, and a per-agent table with an agent filter.

Cost/token and tool-usage metrics require OpenRegister to record them on the LLM call
(OR's `SearchTrail`/`ChatService` audit, not Hermiq's run entry) — that is an OR seam,
called out and surfaced as "not recorded yet", not fabricated.

# Impact

- Affected specs: `run-analytics` (idea → active).
- Affected code: `lib/Service/AnalyticsService.php`, `lib/Controller/AnalyticsController.php`,
  `appinfo/routes.php`, `src/manifest.json`, `src/registry.js`, `src/customComponents.js`,
  `src/views/RunAnalytics.vue`, `src/api/analytics.js`, `tests/Unit/Service/AnalyticsServiceTest.php`.
- OR seam (NOT implemented): cost/token/tool-usage metrics from OR's `SearchTrail`.
