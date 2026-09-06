# Tasks: run-analytics

## 1. AnalyticsService

- [x] 1.1 Create `lib/Service/AnalyticsService.php` (SPDX): `computeAnalytics(?string $agentId=null)` loads the caller's schedules via `ObjectService` (RBAC on — tenant-scoped), collecting their UUIDs (optionally filtered to a given `agentId`).
- [x] 1.2 Read the `action='run'` `AuditTrail` entries via `AuditTrailMapper::findAll(filters:['action'=>'run'])` and keep only those whose `object_uuid` belongs to the caller's schedules (no cross-tenant leak — the caller's schedule set is the boundary).
- [x] 1.3 Aggregate into: `totalRuns`, `successRate` (`status='ok'` / total), `statusBreakdown` (count per status), `latency` (avg/min/max `durationMs`), and `perAgent` (runs + success per agentId). Return a plain metrics array. Note cost/tokens/tool-usage as unavailable (OR seam).

## 2. Controller + route

- [x] 2.1 Create `lib/Controller/AnalyticsController.php` (`@NoAdminRequired`, `@NoCSRFRequired`): `index()` reads optional `agentId` query param and returns `AnalyticsService::computeAnalytics()`; unauthenticated ⇒ 401.
- [x] 2.2 Register `GET /api/analytics` in `appinfo/routes.php`.

## 3. UI

- [x] 3.1 Add `src/api/analytics.js` wrapping the analytics endpoint (optional agentId).
- [x] 3.2 Add `src/views/RunAnalytics.vue`: metric cards (total runs, success rate, avg latency), a status breakdown, a per-agent table, and an agent filter (`NcSelect` with `inputLabel`); `NcEmptyContent`/loading states; a note that cost/token/tool-usage await OR recording.
- [x] 3.3 Register the Analytics page in `src/manifest.json` (`route: /analytics`, nav) + `src/registry.js` + `src/customComponents.js`.

## 4. Verify

- [x] 4.1 Unit-test `AnalyticsService` the CI way: aggregation (success rate, status breakdown, latency avg, per-agent) over a fixed set of run entries; org-scoping (entries outside the caller's schedules are excluded).
- [x] 4.2 Verify live on NC + OR: seed run entries (or reuse real ones), open the Analytics view, confirm the metrics compute from the `AuditTrail` (no separate store); Playwright-test the view (cards + per-agent + agent filter) with 0 console errors.

## Acceptance criteria

- Success rate, latency, status breakdown, and per-agent metrics are computed from OR `AuditTrail` run entries — no separate analytics store.
- The dashboard is available per-agent and aggregated per-tenant (org).
- Cross-tenant data leakage is not possible (only the caller's own schedules' runs are aggregated).
- Metrics render in a nav-reachable view consistent with the app.
- Cost/token/tool-usage are surfaced as "not recorded yet" (OR seam), not fabricated.

## Quality reminders

- SPDX in each PHP docblock; pass `composer phpcs` (lib scope) + PHPStan; run PHPUnit the CI way.
- Read-only surface (ADR-031 read service) — introduce no schema, no write path, no new store.
- No sed/awk/scripts on code — Edit tool only; `@spec` docblock tags; i18n keys in English.
