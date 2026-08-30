# Tasks: compliance-control-packs

## Implementation Tasks

### Task 1: Declare the ControlFramework + Control schemas (register patch)
- **spec_ref**: `openspec/changes/compliance-control-packs/specs/compliance-control-packs/spec.md#requirement-a-seeded-source-cited-control-catalogue-spans-three-frameworks`
- **files**: `lib/Settings/hermiq_register.json`, `appinfo/info.xml`
- **acceptance_criteria**:
  - GIVEN the register JSON WHEN `ControlFramework` (`agentcontrolframework`) and `Control`
    (`agentcompliancecontrol`) are added under `components.schemas` THEN every existing schema
    remains unchanged (union import, no regression)
  - GIVEN the `Control` schema WHEN inspected THEN it carries `frameworkSlug` ($ref
    `ControlFramework`), `controlId`, `title`, `description`, `sourceUrl`, `evidenceSource` (enum
    of the six mapped seams), `evidenceDescription`, and NO status field
  - GIVEN `appinfo/info.xml` WHEN this change lands THEN `<version>` is bumped by one patch so the
    register re-import is triggered
- [x] Implement
- [x] Test — `npm run check:json-strict`/`check:register` pass (union import, no
  regression); `Control` schema carries no status field (verified directly in the JSON).

### Task 2: Seed the catalogue idempotently
- **spec_ref**: `openspec/changes/compliance-control-packs/specs/compliance-control-packs/spec.md#requirement-a-seeded-source-cited-control-catalogue-spans-three-frameworks`
- **files**: `lib/Repair/SeedComplianceControls.php`
- **acceptance_criteria**:
  - GIVEN a fresh install WHEN the repair step runs THEN `ControlFramework` objects for
    `eu-ai-act`/`iso-42001`/`nist-ai-rmf` and ~10 `Control` objects across them are created via
    `ObjectService` (single write-path, ADR-004)
  - GIVEN the repair step already ran WHEN it runs again THEN no `Control`/`ControlFramework` is
    duplicated (skip on existing `slug`)
- [x] Implement
- [x] Test — `tests/Unit/Repair/SeedComplianceControlsTest.php` (fresh-install seeds
  3 frameworks + 10 controls; re-run is idempotent, matched by slug/frameworkSlug+controlId)

### Task 3: ComplianceService — computed evidence mapping
- **spec_ref**: `openspec/changes/compliance-control-packs/specs/compliance-control-packs/spec.md#requirement-control-status-is-computed-from-live-governance-data-never-hand-set`
- **files**: `lib/Service/ComplianceService.php`
- **acceptance_criteria**:
  - GIVEN a `Control` with each of the six `evidenceSource` values WHEN
    `computeControlStatus($control, $organisation)` runs THEN it dispatches to the one matching
    seam (`TenantOpsService::exportAuditTrail()`, `ApprovalService` decisions,
    `TenantControlService::getForOrganisation()`, the org's `ModelPolicy`,
    `TenantOpsService::accessReviewList()`, or `AiFeatureService` data) and returns
    `satisfied`/`partial`/`unevidenced` plus a human `detail` string — never a stored value
  - GIVEN an organisation whose evidence changes (e.g. a run completes) WHEN status is recomputed
    THEN the returned status reflects the new data with no manual update
- [x] Implement
- [x] Test — `tests/Unit/Service/ComplianceServiceTest.php` covers all six evidenceSource
  branches (each state transition: satisfied/partial/unevidenced) via fixture ObjectServices
  and mocked seam services

### Task 4: ComplianceService — dashboard, export, and factsheet aggregation
- **spec_ref**: `openspec/changes/compliance-control-packs/specs/compliance-control-packs/spec.md#requirement-a-compliance-dashboard-shows-per-framework-coverage-and-the-gap-list`
- **files**: `lib/Service/ComplianceService.php`
- **acceptance_criteria**:
  - GIVEN an organisation's computed control statuses WHEN `dashboard($organisation)` runs THEN it
    returns per-framework coverage percentage and the gap list (every non-`satisfied` control)
  - GIVEN the existing `TenantOpsService::exportAuditTrail()` output WHEN `auditorPack($organisation)`
    runs THEN it returns that output unmodified nested alongside the same coverage data, and the
    pre-existing Art. 12-only export endpoint is unaffected
  - GIVEN an agent with a linked `AiFeature`, approval history, and incidents WHEN
    `factsheet($agentId)` runs THEN it assembles a single read-only envelope from `Agent`,
    `AiFeature`, `Approval`, and `Incident` data with no new persisted object
- [x] Implement
- [x] Test — `tests/Unit/Service/ComplianceServiceTest.php` covers dashboard aggregation
  (coverage % + gap list), auditorPack() passing exportAuditTrail() through unmodified, and
  factsheet() assembling all four sources (+ null for a missing agent)

### Task 5: ComplianceController — routes + action-auth gating
- **spec_ref**: `openspec/changes/compliance-control-packs/specs/compliance-control-packs/spec.md#requirement-dashboard-export-and-factsheet-access-are-restricted-by-role-and-ownership`
- **files**: `lib/Controller/ComplianceController.php`, `lib/actions.seed.json`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN `lib/actions.seed.json` WHEN `compliance.view-dashboard`, `compliance.export-pack`, and
    `compliance.view-factsheet` are added (default `["admin"]`) THEN a caller lacking the action is
    refused on the dashboard/export endpoints
  - GIVEN a factsheet request WHEN the caller is neither the agent's owner/actingUser nor holds
    `compliance.view-factsheet` THEN the system refuses with HTTP 404 (not 403)
  - GIVEN `appinfo/routes.php` WHEN the three routes are registered THEN each resolves to an
    existing `ComplianceController` method (route-auth + route-reachability gates pass)
- [x] Implement
- [x] Test — `tests/Unit/Controller/ComplianceControllerTest.php` covers the 401/403 gates
  on dashboard/export and the factsheet's 404-not-403 ownership-or-action-auth IDOR guard
  (owner, non-owner-non-authorized, and authorized-non-owner cases)

### Task 6: Frontend — compliance dashboard page
- **spec_ref**: `openspec/changes/compliance-control-packs/specs/compliance-control-packs/spec.md#requirement-a-compliance-dashboard-shows-per-framework-coverage-and-the-gap-list`
- **files**: `src/api/compliance.js`, `src/views/ComplianceDashboard.vue`, `src/manifest.json`, `src/registry.js`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN an admin opens the compliance dashboard WHEN it loads THEN it renders per-framework
    coverage + the gap list via `CnDataTable`, and the export button reuses the existing
    `downloadJson()` Blob pattern from `TenantOps.vue`
  - GIVEN `src/manifest.json`/`src/registry.js` WHEN wired THEN `ComplianceDashboard` appears as a
    `type:"custom"` page with a menu entry, `kind:'page'` in the registry
  - GIVEN every new user-facing string WHEN added THEN both `l10n/en.json` and `l10n/nl.json` carry
    English-keyed entries
- [x] Implement
- [x] Test (compile-verified; live coverage deferred to the playwright-regression-coverage
  change) — `npm run lint` (0 errors) and `npm run check:specs` (manifest-v2/registry pass)
  verify the page/menu/registry wiring compiles and validates; no live browser render performed

### Task 7: Frontend — agent factsheet dialog
- **spec_ref**: `openspec/changes/compliance-control-packs/specs/compliance-control-packs/spec.md#requirement-an-ai-factsheet-summarises-an-agents-governance-lifecycle`
- **files**: `src/dialogs/AgentFactsheetDialog.vue`, `src/views/AgentDetail.vue`, `src/api/compliance.js`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN `AgentDetail.vue` WHEN a "View compliance factsheet" action is added THEN it opens
    `AgentFactsheetDialog.vue` (`NcDialog`, its own file under `src/dialogs/`, ADR-004 modal
    isolation) showing the assembled factsheet
  - GIVEN a user without access WHEN the dialog's underlying request is refused (404) THEN the
    dialog shows a clear "not available" state, not a raw error
- [x] Implement
- [x] Test (compile-verified; live coverage deferred to the playwright-regression-coverage
  change) — `npm run lint` (0 errors) verifies the dialog/button wiring compiles; no live
  browser render performed

## Quality checklist

- All new/changed business logic covered by PHPUnit unit tests (`tests/Unit/`)
- New/changed API endpoints covered by Newman/Postman tests
- UI changes covered by Playwright browser tests
- All tests pass (`composer test`, `newman run`)
- Feature documentation updated in `docs/` if user-facing (ADR-010)
- Dutch (`nl_NL`) and English (`en_US`) translation strings added for any new user-facing strings (ADR-007)
- `openspec validate` passes
