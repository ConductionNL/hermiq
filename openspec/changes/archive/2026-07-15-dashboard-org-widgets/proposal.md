---
kind: feature
---

# Proposal: dashboard-org-widgets

## Summary

Moves the per-organisation **Schedules** and **Agents-in-use** quota cards off the
`TenantOps` custom page and onto Hermiq's main Dashboard (`route:/`, `type:dashboard`) as
a new manifest-driven widget, `QuotaUsageWidget`, registered via `src/registry.js` (ADR-049
custom-fetch-widget escape hatch) and placed on the Dashboard's widget grid alongside the
three existing `stats-block` cards (Schedules, Skills, Approvals). No backend change: the
widget calls the existing `GET /api/tenant-ops/quota` endpoint
(`TenantOpsController::quota()` → `TenantOpsService::quotaStatus()`) that `TenantOps.vue`
already uses today.

## Motivation

Hermiq's nav/design realignment (2026-07-15) is moving every page toward manifest-driven,
grid-based conventions instead of bespoke `type:"custom"` markup (procest has 42
`type:"detail"` pages, pipelinq 24; Hermiq has 0). `TenantOps.vue` currently mixes a true
per-organisation quota display with several other sections (cost guardrails, model policy,
guardrail policy, access review, incidents, retention, audit export). Per the shared
realignment brief, **Tenant ops should keep only the true per-organisation operational
items**, and the org's schedule/agent quota usage — the piece users most want to check at a
glance — belongs on the main Dashboard where the app already shows three other `stats-block`
KPI cards. Hermiq's own `RunAnalytics` dashboard (`/analytics`) already established the
exact pattern needed here: a custom `kind:"widget"` component over a *computed* endpoint
(quota usage is not a plain OR-schema aggregate — it derives `atLimit` from a configured
limit — the same reason `analytics-kpis`/`analytics-breakdown` are custom widgets rather
than declarative `stats-block` `dataSource` entries).

## Affected Projects

- [ ] Project: `hermiq` — new `QuotaUsageWidget.vue`, `registry.js` entry, `manifest.json`
      Dashboard widget-grid entry, `TenantOps.vue` quota-section removal, `l10n/en.json` +
      `l10n/nl.json` additions.

## Scope

### In Scope

- A new `src/widgets/QuotaUsageWidget.vue` that fetches `GET /api/tenant-ops/quota` (via the
  existing `getQuota()` helper in `src/api/tenantOps.js`) and renders the Schedules and
  Agents-in-use count/limit cards with an at-limit warning, matching the current
  `TenantOps.vue` markup/behaviour.
- A `registry.js` entry (`kind: "widget"`) with `defaultSize`/`minSize`/`maxSize`/
  `allowedSlots`/`propsSchema` and an ADR-049 `_note` explaining why this is a custom widget
  and not a declarative `stats-block`.
- A widget-grid entry (`widgetKey`, `slot: "body"`, `gridX`/`gridY`/`gridWidth`/
  `gridHeight`) on the Dashboard page's top-level `widgets` array in `src/manifest.json`,
  positioned below the existing three `stats-block` cards.
- Removal of the "Quota usage" `<section>` from `TenantOps.vue`, plus the now-unused
  `quota` data property, `getQuota` import, and the `load()` method (the only thing that
  populated `quota`/`loading`/`error`) — the remaining `TenantOps.vue` sections
  (cost guardrails, model policy, guardrail policy, access review, incidents, retention,
  audit export) render unconditionally under the existing `canManage` gate, each already
  managing its own loading/error state.
- Preserving the existing visibility rule: quota is shown only to org owners / instance
  admins (`loadState('hermiq', 'can_manage_killswitch', false)`), exactly as it is today on
  `TenantOps.vue` — this initial state is already provided instance-wide by
  `DashboardController::provideKillSwitchCapability()` on every page load, not just
  `/tenant-ops`, so no backend change is needed to make it available on `/`.
- New/reused `l10n/en.json` + `l10n/nl.json` entries for any label text not already present.

### Out of Scope

- Any backend/API change — `TenantOpsController`/`TenantOpsService` are untouched; this is a
  pure frontend relocation of an existing, already-tenant-scoped read.
- The other `TenantOps.vue` sections (cost guardrails, model policy, guardrail policy,
  access review, incidents, retention, audit export) — these stay on Tenant ops as true
  per-organisation operational items and are not touched by this change.
- Moving MCP tools / Compliance / Guardrail policy into the in-app Settings section, and
  moving AI features to the NC admin settings — these are the sibling
  `inapp-settings-section` and `ai-features-to-admin` changes.
- Any change to the Dashboard's three existing `stats-block` widgets (Schedules, Skills,
  Approvals) or to the `RunAnalytics` dashboard.

## Approach

`QuotaUsageWidget.vue` follows the `AnalyticsKpiWidget.vue` pattern exactly: a `kind:"widget"`
Vue component that fetches a computed endpoint on `mounted()`, shows a loading spinner, an
error `NcNoteCard`, or the rendered cards. It additionally reads
`loadState('hermiq', 'can_manage_killswitch', false)` to preserve the current
org-owner/admin-only visibility (an `NcEmptyContent` or `null` render otherwise — see
Design for the exact choice). It is registered in `registry.js` next to `analytics-kpis` /
`analytics-breakdown`, and placed on the Dashboard's top-level `widgets` array — the same
grid mechanism `RunAnalytics` already uses for its two custom widgets — leaving the
Dashboard's `config.widgets`/`config.layout` declarative `stats-block` cards untouched.

## New Dependencies

None.

## Impact

- `src/manifest.json` — Dashboard page (`route:/`) widget-grid entry added; no schema/route
  change.
- `src/registry.js` — one new `kind:"widget"` entry.
- `src/widgets/QuotaUsageWidget.vue` — new file.
- `src/views/TenantOps.vue` — "Quota usage" section + its now-unused state/method/import
  removed.
- `l10n/en.json`, `l10n/nl.json` — label strings.
- No PHP/backend files change; no OpenRegister schema/register version bump needed (nav/UI
  relocation only, per the shared brief's rule 6).

## Cross-Project Dependencies

None outside hermiq. Coordinates in prose (not in code) with two sibling changes from the
same realignment brief: `inapp-settings-section` (moves Guardrail policy/MCP tools/
Compliance/non-organisation Tenant-ops items into the in-app Settings page) and
`ai-features-to-admin` (moves AI features to NC admin settings). Neither sibling touches the
quota cards or `QuotaUsageWidget`.

## Risks

### Risk 1: Widening or narrowing quota visibility unintentionally
**Severity:** Medium — **Mitigation:** the widget reuses the exact same
`can_manage_killswitch` loadState flag `TenantOps.vue` already gates on, and that flag is
already provided app-wide (not per-page) by `DashboardController`, so moving the display
does not change who can see it. Verified in `lib/Controller/DashboardController.php`
(`provideKillSwitchCapability()` runs on both `page()` and the catch-all).

### Risk 2: `TenantOps.vue`'s top-level `loading`/`error` were tied 1:1 to the quota fetch
**Severity:** Low — **Mitigation:** every other `TenantOps.vue` section already manages its
own loading/error state independently (`budgetsLoading`, `reviewLoading`,
`incidentsLoading`, `policyError`, `guardrailPolicyError`, `retentionError`); removing the
quota-only `loading`/`error`/`load()` and rendering the remaining sections directly under
the existing `canManage` gate changes no other section's behaviour.

## Rollback Strategy

Revert the four touched files (`manifest.json`, `registry.js`, `TenantOps.vue`) and delete
`QuotaUsageWidget.vue`; no data migration or backend state to unwind since no API or schema
changed.

## Open Questions

None — see `DEFERRED_DECISIONS.md` for judgment calls made without an explicit brief
instruction (widget grid placement/size, and the render-nothing-vs-empty-state choice for
non-admin viewers).
