# Design: cost-guardrails

## Architecture Overview
Cost guardrails add one new OR object type (`Budget`), one new service (`BudgetService`), and one new
gate in the two places Hermiq already synchronously gates a run before it executes:
`ScheduleService::dispatch()` (scheduled + Run-now) and `FlowAgentRunService` (webhook/event-triggered
runs, `flow-agent-listener`). Both already share a single kill-switch data source
(`ScheduleService::isOrganisationEngaged()`); `BudgetService` is added as a third, independently
injected collaborator so neither existing service needs to know about the other's data.

```
                     ┌────────────────────────────┐
tick / runNow /  ──▶ │ ScheduleService::dispatch() │
flow webhook         │  GATE 1  kill-switch        │──▶ skipped_killswitch
                     │  GATE 2  budget hard-cap ◀──┼── BudgetService::isBlocked()
                     │  GATE 3  human approval     │──▶ skipped_budget / awaiting_approval
                     │  runDue() → agent turn      │
                     └────────────────────────────┘
                                   │
                                   ▼
                     AuditTrail action='run' entry (usage, status)
                                   │
                     ┌─────────────┴─────────────┐
                     ▼                           ▼
          AnalyticsService                BudgetService
       (existing aggregation)      (windows the SAME entries to the
                                     budget's current period; also
                                     backs the pre-run estimate)
```

`FlowAgentRunService` gains the identical GATE 2 check (it already mirrors GATE 1 and GATE 3 for the
flow-triggered path per its own docblock), so a budget-exhausted organisation/agent is blocked
regardless of which trigger fired the run.

**Gate ordering rationale**: budget sits between the kill-switch and the approval gate, not after it.
An organisation/agent that has exhausted its budget should not even accumulate a fresh pending
Approval (which could never legitimately run) — the same reasoning that puts the kill-switch first.
Budget therefore behaves like the kill-switch (blocks unconditionally, including an already-authorised
`bypassApprovalGate=true` occurrence) rather than like the approval gate (which only applies to
un-authorised occurrences).

## API Design

### `GET /api/budgets?organisation={organisation}`
Lists all budgets (organisation-scope and agent-scope) for an organisation. Admin-gated (see Security).
**Response:**
```json
{ "budgets": [ { "id": "uuid", "scope": "organisation", "agentId": null, "period": "monthly",
  "tokenLimit": 2000000, "eurLimit": null, "softThresholdPercent": 80, "enabled": true } ] }
```

### `POST /api/budgets`
Creates a budget. Admin-gated.
**Request:**
```json
{ "organisation": "org-uuid", "scope": "agent", "agentId": "agent-uuid", "period": "daily",
  "tokenLimit": 50000, "eurLimit": null, "softThresholdPercent": 80 }
```
**Response:** the created budget (same shape as list).

### `PUT /api/budgets/{budgetId}` / `DELETE /api/budgets/{budgetId}`
Update/delete a budget. Admin-gated (mirrors `TenantControlController::mayAdminister`).

### `GET /api/budgets/status?organisation={organisation}&agentId={agentId}`
Current-period usage vs. limit for one scope. Open to any authenticated user; tenancy is the guard
(mirrors `AnalyticsController`/`TenantOpsController`'s `@NoAdminRequired` + tenant-scoped read).
**Response:**
```json
{ "scope": "agent", "agentId": "agent-uuid", "period": "monthly", "periodKey": "2026-07",
  "tokens": { "used": 410000, "limit": 500000, "percent": 82.0 },
  "eur": { "available": false },
  "softThresholdReached": true, "hardCapReached": false }
```

### `GET /api/agents/{agentId}/budget-estimate`
Pre-run rough cost estimate for one agent, sourced from `AnalyticsService`'s existing per-agent
aggregation. Open to any authenticated user (tenant-scoped, mirrors `AnalyticsController`).
**Response:**
```json
{ "agentId": "agent-uuid", "available": true, "sampleSize": 12,
  "avgPromptTokens": 1800, "avgCompletionTokens": 620, "avgTotalTokens": 2420,
  "avgCostEur": null, "label": "estimate — trailing average over last 12 runs" }
```
`available:false` (with a null average) when the agent has no recorded runs yet — never a fabricated
zero, matching `AnalyticsService`'s existing `tokens.available` convention.

## Database Changes
No Nextcloud DB migration. `Budget` is declared exactly like the existing `TenantControl`/`Memory`/
`Session` types: an OpenAPI schema entry in `lib/Settings/hermiq_register.json`, imported into
OpenRegister via the existing Repair step. No `lib/Migration/` class is introduced — Hermiq owns no
database tables of its own (ADR-004/thin-client architecture).

### Schema: `Budget` (slug `budget`)
| Field | Type | Notes |
|---|---|---|
| `scope` | enum `organisation`\|`agent` | required |
| `agentId` | uuid, `$ref Agent` | required when `scope=agent`; empty for `scope=organisation` |
| `period` | enum `daily`\|`weekly`\|`monthly` | required |
| `tokenLimit` | integer, min 1 | optional; at least one of `tokenLimit`/`eurLimit` required (service-level validation — OR JSON Schema has no cross-field `oneOf` on this project's tooling) |
| `eurLimit` | number, min 0 | optional; only usable when the instance-wide EUR rate (below) is configured |
| `softThresholdPercent` | integer, default 80, 1-100 | when current usage crosses this fraction of the limit, one warning notification fires per period |
| `enabled` | boolean, default true | a disabled budget is not read by the dispatch gate (kept, not deleted, for audit continuity) |
| `warnedPeriodKey` | string | derived — the period key (`YYYY-MM-DD`/`YYYY-Www`/`YYYY-MM`) the soft-threshold warning was last sent for; empty until first warn in a period |
| `lastHardBlockAt` | date-time | derived — when a run was last blocked by this budget's hard cap, for admin visibility |

Organisation scope comes from `ObjectEntity.organisation` (set at creation, exactly as `TenantControl`
already does) — there is no separate `organisationId` property.

Current-period **usage** is never stored on the `Budget` object: `BudgetService` computes it on read
by windowing the same `action='run'` AuditTrail entries `AnalyticsService` already aggregates to the
budget's period (so usage and the existing run-analytics dashboard can never disagree). This mirrors
`TenantOpsService::quotaStatus()`, which also computes usage on read rather than maintaining a counter.

### Instance-wide EUR conversion rate
A new `IAppConfig` key `budget.eurPer1kTokens` (app `hermiq`), read the same way
`TenantOpsService` reads `scheduleQuota`/`agentQuota`. Unset by default — EUR budgets/estimates are
unavailable (`eur.available:false`) until an instance admin sets a rate; no per-provider pricing table
is introduced (see proposal Risk 1).

## Nextcloud Integration
- **Controllers**: `BudgetController` (new) — CRUD + status; adds `budgetEstimate()` to
  `AnalyticsController` OR a new lightweight endpoint on `BudgetController` (chosen: `BudgetController`
  keeps `AnalyticsController` unchanged, since the estimate is a budget-facing concern that happens to
  read analytics data, not an analytics-facing concern).
- **Services**: `BudgetService` (new) — reads `Budget` objects via `ObjectService`, computes
  status/estimate, exposes `isBlocked(organisation, ?agentId): bool` for the dispatch gate and
  `recordWarningIfDue(...)` for the soft-threshold notification. Depends on `AnalyticsService`
  (estimate), `AuditTrailMapper` (period-window usage, same as `TenantOpsService`/`AnalyticsService`),
  `IAppConfig` (EUR rate), `OrganisationMapper` (resolve org owner for the warning recipient).
- **Mappers/Entities**: reuses `ObjectEntity`/`ObjectService`/`AuditTrailMapper`/`OrganisationMapper` —
  no new mapper classes.
- **Events/Hooks**: none new. `DeliveryService` gains one new method,
  `deliverBudgetWarning(ObjectEntity $budget, array $recipientUids): DeliveryResult`, mirroring the
  existing `deliverApprovalRequestForFlowRun()` shape (Talk primary, Notification fallback, same
  dialect as talk-delivery) rather than a bespoke notification path.

## Security Considerations
- **Read** endpoints (`GET /api/budgets`, `.../status`, `.../budget-estimate`) are
  `@NoAdminRequired` + tenant-scoped via `ObjectService` (RBAC/multitenancy ON), exactly like
  `AnalyticsController`/`TenantOpsController` — the method body never trusts a caller-supplied
  organisation without ObjectService's own tenant filtering also applying.
- **Write** endpoints (`POST`/`PUT`/`DELETE /api/budgets*`) require instance-admin OR
  organisation-owner, reusing `TenantControlController::mayAdminister()`'s exact check
  (`IGroupManager::isAdmin` OR `OrganisationMapper::findByUuid($organisation)->getOwner() === caller`).
  A non-admin, non-owner caller gets 403 on write and 404 (not 403) on a `show`-style read of another
  org's budget, matching the existing kill-switch controller's anti-probing convention.
- The dispatch-path budget read (`loadEngagedBudgets()`) runs with `_rbac: false, _multitenancy: false`
  exactly like `loadEngagedOrganisations()` — it is a system tick, not a user request, and a read
  failure fails OPEN (logs, treats as "nothing blocked") so a transient OR read error cannot
  instance-wide-halt every tenant. This mirrors the kill-switch's own documented fail-open contract for
  the *read*, while the block itself (once computed) remains a hard, synchronous stop.
- No new input surface beyond standard numeric/enum validation on budget CRUD fields; no secrets, no
  file uploads, no cross-app calls (gate-27 — this stays entirely inside Hermiq's own register).

## NL Design System
Budget cards on `TenantOps.vue`/`AgentDetail.vue` reuse the existing `tenant-ops__card` /
`tenant-ops__card--warn` pattern (CSS variables, no hardcoded colors) already used for the
schedule/agent quota cards — a budget nearing/at its cap gets the same warn treatment. The new
`BudgetFormModal.vue` uses standard `NcDialog` + `NcSelect` (with `inputLabel`, per ADR-004) +
`NcInputField` components, no bespoke form widgets.

## File Structure
```
lib/
  Controller/
    BudgetController.php          (new)
  Service/
    BudgetService.php              (new)
    ScheduleService.php             (+ inject BudgetService, + GATE 2 in dispatch())
    FlowAgentRunService.php         (+ inject BudgetService, + equivalent gate)
    DeliveryService.php             (+ deliverBudgetWarning())
  Settings/
    hermiq_register.json            (+ Budget schema entry)
appinfo/
  routes.php                        (+ budget routes)
src/
  api/
    budgets.js                      (new, mirrors tenantOps.js)
  modals/
    BudgetFormModal.vue              (new)
  views/
    TenantOps.vue                    (+ org-level budget cards + manage-budgets entry point)
    AgentDetail.vue                  (+ agent-level budget card + cost-estimate line on Run now)
  modals/
    ScheduleFormModal.vue            (+ cost-estimate line at schedule creation)
```

## Seed Data

### Schema: `budget`
| Field | Object 1 | Object 2 | Object 3 |
|---|---|---|---|
| slug (`@self`) | `budget-org-monthly-default` | `budget-agent-daily-briefing` | `budget-agent-heavy-tool` |
| scope | `organisation` | `agent` | `agent` |
| agentId | _(none)_ | seeded "Daily Briefing" agent | seeded "Heavy Tool Runner" agent |
| period | `monthly` | `daily` | `daily` |
| tokenLimit | 2,000,000 | 50,000 | 200,000 |
| eurLimit | _(none — rate unset)_ | _(none)_ | _(none)_ |
| softThresholdPercent | 80 | 80 | 90 |
| enabled | true | true | true |

**Related items per object:** none (Budget carries no file/note/task/contact relations — it is a pure
config + derived-status object, like `TenantControl`).

## Trade-offs
- **Live-computed usage vs. a running counter**: computing current-period usage from AuditTrail on
  every read (like `TenantOpsService`) costs a scan per status/gate check, but guarantees the budget
  dashboard and the run-analytics dashboard never disagree and needs no new write path. A running
  counter would be faster but introduces a second source of truth that can drift from the audit trail
  — rejected for the same reason `AnalyticsService`'s docblock gives for not introducing a separate
  analytics store.
- **Independent `BudgetService` vs. folding budget logic into `ScheduleService`**: `ScheduleService` is
  already 1,286 lines with an existing `PHPMD.ExcessiveClassLength` suppression; adding budget logic
  as a fourth responsibility there would make that suppression's rationale ("removed wholesale by
  or-chat-proxy-deprecation") false. An independent, injected `BudgetService` (mirroring
  `TenantOpsService`) keeps `ScheduleService`'s dispatch-path change to the smallest possible diff (one
  gate, one injected collaborator).
- **Token-based budgets as the primary mechanism vs. EUR-first**: tokens are always available (every
  run's AuditTrail entry already carries usage when OR reports it); EUR requires an extra,
  admin-supplied conversion rate. Making tokens the default keeps the guardrail usable on day one even
  when no EUR rate is configured.

## Open Questions
(carried from proposal.md — resolved provisionally there; no new ones identified during design)
