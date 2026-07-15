# Test Plan: dashboard-org-widgets

## Test Cases

### TC-1: Org owner sees quota usage on the Dashboard, under both quotas
- **spec_ref**: `openspec/changes/dashboard-org-widgets/specs/dashboard-org-widgets/spec.md#requirement-the-dashboard-must-show-organisation-quota-usage-to-org-owners-and-instance-admins`
- **type**: functional
- **persona**: N/A (org-owner role, not a named persona)
- **preconditions**: signed in as a user who is an organisation owner (or instance admin);
  the organisation has a known schedule count and agent-in-use count, both under their
  configured limits
- **steps**: open the Dashboard (`/`)
- **expected result**: a quota-usage widget renders below the Schedules/Skills/Approvals
  cards, showing "N / limit" for Schedules and "N / limit" for Agents in use, with no
  at-limit warning on either card
- **test command**: `/test-functional`

### TC-2: Schedule quota at limit shows a warning
- **spec_ref**: `openspec/changes/dashboard-org-widgets/specs/dashboard-org-widgets/spec.md#requirement-the-dashboard-must-show-organisation-quota-usage-to-org-owners-and-instance-admins`
- **type**: api
- **persona**: N/A
- **preconditions**: an organisation whose schedule count has reached the configured
  `scheduleQuota` app-config value
- **steps**: call `GET /apps/hermiq/api/tenant-ops/quota` as that organisation's owner
- **expected result**: response's `schedules.atLimit` is `true`; separately, opening the
  Dashboard renders the widget's Schedules card with the at-limit warning text
- **test command**: `/test-api`

### TC-3: Agent quota at limit shows a warning
- **spec_ref**: `openspec/changes/dashboard-org-widgets/specs/dashboard-org-widgets/spec.md#requirement-the-dashboard-must-show-organisation-quota-usage-to-org-owners-and-instance-admins`
- **type**: api
- **persona**: N/A
- **preconditions**: an organisation whose distinct in-use agent count has reached the
  configured `agentQuota` app-config value
- **steps**: call `GET /apps/hermiq/api/tenant-ops/quota` as that organisation's owner
- **expected result**: response's `agents.atLimit` is `true`; the Dashboard widget's
  Agents-in-use card shows the at-limit warning text
- **test command**: `/test-api`

### TC-4: Non-manager Dashboard visitor sees no quota widget
- **spec_ref**: `openspec/changes/dashboard-org-widgets/specs/dashboard-org-widgets/spec.md#requirement-the-quota-usage-widget-must-not-render-for-a-user-who-cannot-manage-organisation-operations`
- **type**: functional
- **persona**: N/A (plain organisation member, not owner/admin)
- **preconditions**: signed in as a user who is neither an organisation owner nor an
  instance admin (`can_manage_killswitch` is `false`)
- **steps**: open the Dashboard (`/`)
- **expected result**: the Schedules/Skills/Approvals stats-block cards render as before;
  no quota-usage widget, cards, or quota data appear anywhere on the page
- **test command**: `/test-functional`

### TC-5: Registry structural validation
- **spec_ref**: `openspec/changes/dashboard-org-widgets/specs/dashboard-org-widgets/spec.md#requirement-the-dashboard-quota-widget-must-be-registered-as-a-manifest-driven-custom-widget-not-bespoke-page-markup`
- **type**: regression
- **persona**: N/A
- **preconditions**: `src/registry.js` after this change
- **steps**: run `node tests/registry.spec.js` (or `npm run check:specs`)
- **expected result**: exits `0`; the quota widget entry carries `kind:"widget"` plus
  `defaultSize`/`minSize`/`maxSize`/`allowedSlots`/`propsSchema`
- **test command**: `/test-regression`

### TC-6: Manifest structural/schema validation
- **spec_ref**: `openspec/changes/dashboard-org-widgets/specs/dashboard-org-widgets/spec.md#requirement-the-dashboard-quota-widget-must-be-registered-as-a-manifest-driven-custom-widget-not-bespoke-page-markup`
- **type**: regression
- **persona**: N/A
- **preconditions**: `src/manifest.json` after this change
- **steps**: run `node tests/manifest-v2.spec.js` (or `npm run check:specs`)
- **expected result**: exits `0`; the Dashboard page's `widgets[]` array contains a valid
  grid entry for the quota widget
- **test command**: `/test-regression`

### TC-7: Tenant ops no longer shows Quota usage; other sections unaffected
- **spec_ref**: `openspec/changes/dashboard-org-widgets/specs/dashboard-org-widgets/spec.md#requirement-tenant-ops-must-no-longer-display-organisation-quota-usage`
- **type**: functional
- **persona**: N/A (org-owner role)
- **preconditions**: signed in as an organisation owner; the organisation has at least one
  budget, model policy, guardrail policy, agent (for access review), and incident
- **steps**: open Tenant ops (`/tenant-ops`)
- **expected result**: no "Quota usage" heading or Schedules/Agents-in-use cards appear;
  Cost guardrails, Model policy, Guardrail policy, Access review, Incidents, Retention,
  and the EU AI Act audit export button all still render and function as before
- **test command**: `/test-functional`

### TC-8: Regression — Dashboard's existing stats-block cards unaffected
- **spec_ref**: `openspec/changes/dashboard-org-widgets/specs/dashboard-org-widgets/spec.md#requirement-the-dashboard-must-show-organisation-quota-usage-to-org-owners-and-instance-admins`
- **type**: regression
- **persona**: N/A
- **preconditions**: any signed-in user
- **steps**: open the Dashboard (`/`)
- **expected result**: the Schedules, Skills, and Approvals `stats-block` cards (existing,
  untouched `config.widgets`/`config.layout`) render with correct counts, at their
  existing grid positions, regardless of `can_manage_killswitch`
- **test command**: `/test-regression`

## Coverage Summary

- REQ "Dashboard MUST show organisation quota usage to org owners and instance admins" —
  covered by TC-1 (functional, e2e-backed), TC-2/TC-3 (api, at-limit branches)
- REQ "Quota-usage widget MUST NOT render for a user who cannot manage organisation
  operations" — covered by TC-4 (functional)
- REQ "Dashboard quota widget MUST be registered as a manifest-driven custom widget" —
  covered by TC-5, TC-6 (regression/structural)
- REQ "Tenant ops MUST no longer display organisation quota usage" — covered by TC-7
  (functional)
- Regression: Dashboard's pre-existing `stats-block` cards — covered by TC-8

## Out of Scope

- Load/performance testing of the quota endpoint — unchanged backend, already covered by
  existing `TenantOpsServiceTest`/`TenantOpsControllerTest` PHPUnit suites.
- Any test of the sibling `inapp-settings-section` / `ai-features-to-admin` changes' nav
  moves — out of scope for this change entirely.
- Automated Playwright coverage of TC-4 (non-manager visibility) and TC-7 (Tenant ops
  regression) — no existing fixture logs in as a non-owner org member or navigates to
  `/tenant-ops`; deferred and flagged in `DEFERRED_DECISIONS.md`. TC-1 is the one scenario
  planned for real Playwright automation (Task 5 in `tasks.md`, extending
  `tests/e2e/dashboard-and-agents.spec.ts`).
