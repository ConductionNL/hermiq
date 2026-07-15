# Design: dashboard-org-widgets

## Architecture Overview

Hermiq's Dashboard page (`src/manifest.json`, `route:"/"`, `type:"dashboard"`) currently
combines two widget mechanisms on the same grid:

1. **Declarative `stats-block` cards** — `config.widgets[]` (each with a `dataSource`
   pointing at an OR register/schema `count` aggregate) laid out via `config.layout[]`
   (`widgetId`, `gridX`, `gridY`, `gridWidth`, `gridHeight`). Today: Schedules, Skills,
   Approvals.
2. **Custom `kind:"widget"` registry components** — a top-level `widgets[]` array (each
   entry: `widgetKey`, `slot`, `gridX`, `gridY`, `gridWidth`, `gridHeight`), resolved
   through `src/registry.js`. Today this array is empty on the Dashboard page; Hermiq's
   `RunAnalytics` dashboard (`/analytics`) uses this exact mechanism for its two widgets,
   `analytics-kpis` and `analytics-breakdown`.

Quota usage cannot be a declarative `stats-block` `dataSource` because it is not a plain OR
object-count aggregate — it derives `atLimit` from a *configured limit*
(`IAppConfig::scheduleQuota`/`agentQuota`) compared against a *derived* agent count (distinct
`agentId`s across the org's schedules, not a schema-level count), computed by
`TenantOpsService::quotaStatus()`. This is the identical justification `analytics-kpis`
already carries (ADR-049): a computed endpoint, not a bindable object query. So
`QuotaUsageWidget` joins the Dashboard's top-level `widgets[]` array, positioned below the
three existing `stats-block` cards.

```
Dashboard (route:/, type:dashboard)
├─ config.widgets[] + config.layout[]   (declarative stats-block: Schedules/Skills/Approvals)
└─ widgets[]                            (custom registry widgets)
   └─ { widgetKey: "quota-usage", slot: "body", gridX:0, gridY:1, gridWidth:12, gridHeight:1 }
      → registry.js "quota-usage" → QuotaUsageWidget.vue → GET /api/tenant-ops/quota
```

## Goals / Non-Goals

**Goals**
- Relocate the Schedules/Agents-in-use quota display from `TenantOps.vue` to the main
  Dashboard, as a manifest-driven `kind:"widget"` entry (not more bespoke `type:"custom"`
  markup).
- Preserve the existing org-owner/admin-only visibility rule exactly.
- Leave the `/api/tenant-ops/quota` endpoint, `TenantOpsService::quotaStatus()`, and every
  other `TenantOps.vue` section untouched.

**Non-Goals**
- Redesigning the quota data shape or adding new quota dimensions (e.g. token/budget quotas
  are a separate, already-existing "Cost guardrails" section that stays on Tenant ops).
- Changing the Dashboard's three existing `stats-block` cards or the `RunAnalytics`
  dashboard.
- Any Settings-page nav work (`inapp-settings-section`) or AI-features admin move
  (`ai-features-to-admin`) — those are sibling changes.

## Decisions

### Decision 1: Custom `kind:"widget"` (registry) over declarative `stats-block`
**Chosen:** custom widget, mirroring `analytics-kpis`/`analytics-breakdown`.
**Alternative considered:** two `stats-block` `dataSource` cards (one per quota) directly in
`config.widgets[]`, matching the existing Schedules/Skills/Approvals cards.
**Why rejected:** `stats-block`'s `dataSource` binds an OR register/schema `count`
aggregate; it cannot express "count vs. a configured limit" or "count of distinct
`agentId`s referenced by the org's schedules" — both require `TenantOpsService`'s
computed logic. Forcing this into `dataSource` would need a new declarative aggregate type
(e.g. `aggregate: "quota"`) invented solely for this one case — far more machinery than
reusing the already-established custom-widget escape hatch that `analytics-kpis` proved out.

### Decision 2: One widget, two cards (not two separate widget entries)
**Chosen:** a single `QuotaUsageWidget.vue` rendering both the Schedules and
Agents-in-use cards from one `GET /api/tenant-ops/quota` response.
**Alternative considered:** two registry entries (`quota-schedules`, `quota-agents`), each
its own tiny widget.
**Why rejected:** both values come from the exact same endpoint/response; splitting into two
widgets would mean two independent fetches of the same payload for no placement benefit
(the brief describes "a new dashboard widget... showing the caller's org schedule + agent
quota usage" — singular). Matches `analytics-kpis`, which also renders multiple KPI cards
from one widget/one fetch.

### Decision 3: Preserve the org-owner/admin-only visibility gate
**Chosen:** `QuotaUsageWidget` reads `loadState('hermiq', 'can_manage_killswitch', false)`
(and `managed_organisations` if needed for a future org picker) exactly as `TenantOps.vue`
does today, and renders nothing (returns `null` from its root — see Decision 4) for a
non-manager viewer.
**Alternative considered:** show the widget to every Dashboard visitor, since
`quotaStatus()` only ever reads the caller's own tenant-scoped data (no cross-tenant leak
risk) and the Dashboard's other three `stats-block` cards are not capability-gated.
**Why rejected (for now):** `TenantOpsController`'s docblock is explicit that the UI
additionally gates visibility to org owners/admins as a deliberate product decision, not
just a data-leak precaution — plain org members are not meant to see quota-vs-limit
management data. Relocating the surface should not silently widen who sees it. This is
flagged as a judgment call in `DEFERRED_DECISIONS.md` since the brief did not explicitly
say to keep or drop the gate.

### Decision 4: Non-manager render = nothing, not an empty state
**Chosen:** the widget's `render()`/template returns nothing (no card, no message, no
placeholder) when `canManage` is `false`, so the Dashboard grid cell is simply blank for
that viewer rather than showing an explanatory `NcEmptyContent`.
**Alternative considered:** an `NcEmptyContent` telling the viewer quota is admin-only
(matching how `TenantOps.vue` itself explains the gate on its own page).
**Why rejected:** on a dedicated Tenant-ops page, an explanatory empty state makes sense
(the user navigated there for ops content). On a shared Dashboard grid position, an
"Organisation admins only" card would be visual noise for the majority of everyday users;
the widget quietly not rendering (comparable to how nav items are simply absent for users
without a capability) is the better default. Flagged in `DEFERRED_DECISIONS.md`.

## Risks / Trade-offs

- [Risk] A non-manager Dashboard visitor sees an empty grid cell where the widget would be,
  which could look like a layout bug rather than an intentional gate → [Mitigation] this
  mirrors existing Hermiq nav-item gating (missing, not "locked"); the widget's own code
  comment documents the intentional no-render, and this is called out for the user in
  `DEFERRED_DECISIONS.md`.
- [Risk] `TenantOps.vue`'s top-level `loading`/`v-if`/`v-else` wrapper currently gates ALL
  its remaining sections, not just quota → [Mitigation] collapsing that wrapper to render
  sections directly is a pure refactor: every remaining section already owns its own
  loading/error state (verified at HEAD), so no section's behaviour changes.
- [Risk] Removing `getQuota` from `src/api/tenantOps.js` imports in `TenantOps.vue` while
  `QuotaUsageWidget.vue` newly imports it → [Mitigation] `getQuota()` itself is not removed
  from `src/api/tenantOps.js` (it is still exported and used by the new widget); only the
  `TenantOps.vue` import/usage is removed.

## Migration Plan

Not applicable — no database/schema change, no versioned migration. Deploy is: ship the four
touched files + new widget file in one PR; `npm run check:specs` (registry.spec +
manifest-v2.spec) and `npm run lint` must stay green. Rollback is a plain file revert (see
Rollback Strategy in `proposal.md`).

## Nextcloud Integration

- Controllers: none new — reuses `TenantOpsController::quota()` (`#[NoAdminRequired]`,
  already tenant-scoped, unchanged).
- Services: none new — reuses `TenantOpsService::quotaStatus()`, unchanged.
- Frontend: `src/api/tenantOps.js#getQuota()` (existing, reused), `@nextcloud/initial-state`
  `loadState()` (existing pattern, ADR-004 — never a DOM data-attribute read).

## Security Considerations

No new attack surface: the widget calls an existing, already-authenticated
(`#[NoAdminRequired]` + `IUserSession` check), already tenant-scoped endpoint. The only
behavioural nuance is UI-level visibility (Decision 3/4 above), which preserves — not
loosens — the current admin-only display rule. No new user input is accepted; the widget is
read-only.

## NL Design System

`QuotaUsageWidget.vue` reuses the same NL Design System tokens/components already used by
`AnalyticsKpiWidget.vue` (`NcLoadingIcon`, `NcNoteCard`, `var(--color-*)` tokens, no
hardcoded colors) so the two custom dashboard widgets look visually consistent.

## File Structure

```
src/
  widgets/
    QuotaUsageWidget.vue        (new)
  registry.js                    (+1 entry: "quota-usage")
  manifest.json                  (Dashboard page: +1 widgets[] entry)
  views/
    TenantOps.vue                (Quota usage section + quota/loading/error/load() removed)
l10n/
  en.json                        (+ any new label keys)
  nl.json                        (+ same keys, Dutch)
```

## Trade-offs

See Decisions above. The overall trade-off of this change is: one more small custom widget
(vs. a hypothetical new declarative `stats-block` aggregate type) in exchange for zero new
manifest-schema surface and full reuse of the already-proven `analytics-kpis` pattern.
