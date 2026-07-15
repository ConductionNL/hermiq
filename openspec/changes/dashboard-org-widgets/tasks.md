# Tasks: dashboard-org-widgets

<!-- HYDRA CAP: The supervisor rejects specs with more than 20 lines matching `^- \[ \]`
     (unindented checkboxes). This change has 5 tasks × 2 checkboxes = 10. -->

## Implementation Tasks

### Task 1: Create the QuotaUsageWidget component
- **spec_ref**: `openspec/changes/dashboard-org-widgets/specs/dashboard-org-widgets/spec.md#requirement-the-dashboard-must-show-organisation-quota-usage-to-org-owners-and-instance-admins`
- **files**: `src/widgets/QuotaUsageWidget.vue`, `src/api/tenantOps.js` (reused, no change)
- **acceptance_criteria**:
  - GIVEN the widget mounts WHEN `can_manage_killswitch` (loadState) is `true` THEN it
    calls `getQuota()` and renders Schedules + Agents-in-use cards (count/limit) with an
    at-limit warning per card when `atLimit` is `true`
  - GIVEN the widget mounts WHEN `can_manage_killswitch` is `false` THEN it renders
    nothing (no cards, no error, no empty-state card)
  - GIVEN the fetch fails WHEN `getQuota()` rejects THEN the widget shows an `NcNoteCard`
    error, matching `AnalyticsKpiWidget.vue`'s error pattern
- [ ] Implement
- [ ] Test

### Task 2: Register the widget and place it on the Dashboard grid
- **spec_ref**: `openspec/changes/dashboard-org-widgets/specs/dashboard-org-widgets/spec.md#requirement-the-dashboard-quota-widget-must-be-registered-as-a-manifest-driven-custom-widget-not-bespoke-page-markup`
- **files**: `src/registry.js`, `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN `src/registry.js` WHEN parsed by `tests/registry.spec.js` THEN it contains a
    `kind:"widget"` entry (e.g. `"quota-usage"`) with `defaultSize`, `minSize`,
    `maxSize`, `allowedSlots: ["body"]`, `propsSchema`, and an ADR-049 `_note`
  - GIVEN `src/manifest.json`'s Dashboard page (`route:"/"`) WHEN validated by
    `tests/manifest-v2.spec.js` THEN its top-level `widgets[]` array contains a
    `{ widgetKey: "quota-usage", slot: "body", gridX, gridY, gridWidth, gridHeight }`
    entry positioned below the three existing `stats-block` cards
  - GIVEN both structural tests THEN `npm run check:specs` exits `0`
- [ ] Implement
- [ ] Test

### Task 3: Remove the Quota usage section from TenantOps
- **spec_ref**: `openspec/changes/dashboard-org-widgets/specs/dashboard-org-widgets/spec.md#requirement-tenant-ops-must-no-longer-display-organisation-quota-usage`
- **files**: `src/views/TenantOps.vue`
- **acceptance_criteria**:
  - GIVEN `TenantOps.vue` WHEN this task is done THEN the "Quota usage" `<section>`,
    the `quota` data property, the `load()` method, and the `getQuota` import are all
    removed
  - GIVEN the removal THEN the top-level `loading`/`error` data properties and the
    `v-if="loading" / v-else` wrapper (which only ever gated on the quota fetch) are
    removed too, and the remaining sections (cost guardrails, model policy, guardrail
    policy, access review, incidents, retention, audit export) render directly under the
    existing `canManage` gate, unchanged in behaviour
  - GIVEN `npm run lint` THEN it passes with no unused-import/unused-data warnings
- [ ] Implement
- [ ] Test

### Task 4: Add l10n entries for the widget's label strings
- **spec_ref**: `openspec/changes/dashboard-org-widgets/specs/dashboard-org-widgets/spec.md#non-functional-requirements`
- **files**: `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN every `t('hermiq', '...')` string introduced in `QuotaUsageWidget.vue` THEN a
    matching English key exists in `l10n/en.json` and a Dutch translation exists in
    `l10n/nl.json`
- [ ] Implement
- [ ] Test

### Task 5: Extend Playwright coverage for the Dashboard quota widget
- **spec_ref**: `openspec/changes/dashboard-org-widgets/specs/dashboard-org-widgets/spec.md#requirement-the-dashboard-must-show-organisation-quota-usage-to-org-owners-and-instance-admins`
- **files**: `tests/e2e/dashboard-and-agents.spec.ts`
- **acceptance_criteria**:
  - GIVEN the existing admin-login Dashboard flow in `dashboard-and-agents.spec.ts`
    WHEN it navigates to `/` THEN it additionally asserts the quota-usage widget renders
    with Schedules/Agents-in-use cards whose values match a direct
    `GET /api/tenant-ops/quota` call in the same test
- [ ] Implement
- [ ] Test

## Quality checklist

<!-- These are reminders for the builder, not tracked checkboxes.
     Keeping them as plain text avoids inflating the Hydra cap count. -->

- No PHPUnit/Newman changes needed — no backend/API surface changed by this capability
- `npm run check:specs` (registry.spec.js + manifest-v2.spec.js) passes
- `npm run lint` passes with zero orphan imports after the `TenantOps.vue` removal
- Dutch (`nl_NL`) and English (`en_US`) translation strings added for every new
  user-facing string (ADR-005/ADR-007)
- Manually verify in a dev instance: an org-owner login shows the widget on `/`, a
  non-owner org-member login does not
- `openspec validate dashboard-org-widgets --type change --strict` passes
