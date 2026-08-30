# dashboard-org-widgets Specification

## Purpose
TBD - created by archiving change dashboard-org-widgets. Update Purpose after archive.
## Requirements
### Requirement: The Dashboard MUST show organisation quota usage to org owners and instance admins
The system MUST render a Dashboard widget showing the caller's organisation Schedules and
Agents-in-use quota usage (count vs. configured limit, with an at-limit warning) to a user
for whom `can_manage_killswitch` is `true`, sourced from the existing
`GET /api/tenant-ops/quota` endpoint (`TenantOpsController::quota()` →
`TenantOpsService::quotaStatus()`, unchanged by this capability).

#### Scenario: An org owner opens the Dashboard and is under both quotas
@e2e tests/e2e/dashboard-and-agents.spec.ts
- GIVEN a user is an organisation owner (`can_manage_killswitch` is `true`) whose
  organisation has 3 schedules (limit 100) and 2 distinct agents in use across those
  schedules (limit 50)
- WHEN they open the Dashboard (`/`)
- THEN the system MUST render a quota-usage widget showing "3 / 100" for Schedules and
  "2 / 50" for Agents in use
- AND neither card MUST display an at-limit warning

#### Scenario: An organisation's schedule quota is reached
@e2e exclude the count-vs-limit/`atLimit` computation is unchanged backend logic already
covered by `tests/Unit/Service/TenantOpsServiceTest.php` (schedule-quota-reached case); this
change only relocates where the already-computed payload is rendered. No dedicated
at-limit Playwright fixture exists.
- GIVEN an organisation owner's organisation has reached its configured schedule quota
  (schedule count >= limit)
- WHEN they open the Dashboard
- THEN the quota-usage widget's Schedules card MUST display an at-limit warning

#### Scenario: An organisation's agent quota is reached
@e2e exclude same rationale as the schedule-quota scenario above — covered by
`tests/Unit/Service/TenantOpsServiceTest.php` (agent-quota-reached case); presentation-only
relocation.
- GIVEN an organisation owner's organisation has reached its configured agent quota
  (distinct agent count >= limit)
- WHEN they open the Dashboard
- THEN the quota-usage widget's Agents-in-use card MUST display an at-limit warning

### Requirement: The quota-usage widget MUST NOT render for a user who cannot manage organisation operations
The system MUST NOT render the quota-usage widget's cards or any quota data to a Dashboard
visitor for whom `can_manage_killswitch` is `false`, preserving the same visibility boundary
the quota display had on Tenant ops before this change.

#### Scenario: A plain organisation member opens the Dashboard
@e2e exclude no existing Playwright fixture authenticates as a non-owner organisation
member (only the admin/owner login flow is scripted); deferred, flagged in
DEFERRED_DECISIONS.md.
- GIVEN a signed-in user who is neither an organisation owner nor an instance admin
  (`can_manage_killswitch` is `false`)
- WHEN they open the Dashboard
- THEN the system MUST NOT render the quota-usage widget's Schedules/Agents-in-use cards,
  or any quota count/limit data, to that user

### Requirement: The Dashboard quota widget MUST be registered as a manifest-driven custom widget, not bespoke page markup
The system MUST expose the quota-usage widget via a `src/registry.js` entry of
`kind:"widget"` carrying `defaultSize`, `minSize`, `maxSize`, `allowedSlots`, and
`propsSchema`, referenced from the Dashboard page's top-level `widgets[]` array in
`src/manifest.json` by `widgetKey`, matching the `analytics-kpis`/`analytics-breakdown`
custom-widget convention (ADR-049) rather than adding new `type:"custom"` page markup.

#### Scenario: The registry structural test validates the new widget entry
@e2e exclude verified by `tests/registry.spec.js`, a Node structural validator (not a
Playwright UI flow); run via `npm run check:specs`.
- GIVEN `tests/registry.spec.js`, the structural validator for `src/registry.js`
- WHEN it parses the registry after this change
- THEN the quota-usage widget entry MUST declare `kind:"widget"` plus `defaultSize`,
  `minSize`, `maxSize`, `allowedSlots`, and `propsSchema`
- AND `npm run check:specs` MUST exit `0`

#### Scenario: The manifest structural/schema test validates the Dashboard widget-grid entry
@e2e exclude verified by `tests/manifest-v2.spec.js`, a Node structural/Ajv validator (not a
Playwright UI flow); run via `npm run check:specs`.
- GIVEN `tests/manifest-v2.spec.js`, the validator for `src/manifest.json`
- WHEN it validates the manifest after this change
- THEN the Dashboard page's `widgets[]` array MUST contain a valid
  `{ widgetKey, slot, gridX, gridY, gridWidth, gridHeight }` entry for the quota widget
- AND validation MUST exit `0`

### Requirement: Tenant ops MUST no longer display organisation quota usage
The system MUST remove the "Quota usage" section — including its dedicated `quota` state
and the `load()` fetch that only ever populated it — from `TenantOps.vue`, while every other
Tenant-ops section (cost guardrails, model policy, guardrail policy, access review,
incidents, retention, EU AI Act audit export) MUST continue to render exactly as before.

#### Scenario: An org owner opens Tenant ops after the change
@e2e exclude no existing Playwright fixture navigates to `/tenant-ops`; regression coverage
for the unaffected sections is the pre-existing `tests/Unit/Controller/TenantOpsControllerTest.php`
+ `tests/Unit/Service/TenantOpsServiceTest.php` suites (unchanged by this frontend-only
change). Flagged in DEFERRED_DECISIONS.md as a candidate for future Playwright coverage.
- GIVEN an organisation owner opens Tenant ops (`/tenant-ops`)
- WHEN the page renders
- THEN the system MUST NOT display a "Quota usage" heading or Schedules/Agents-in-use cards
  on that page
- AND the Cost guardrails, Model policy, Guardrail policy, Access review, Incidents,
  Retention, and EU AI Act audit export sections MUST still render as before

