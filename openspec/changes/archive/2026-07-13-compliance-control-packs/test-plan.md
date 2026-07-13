# Test Plan: compliance-control-packs

## Test Cases

### TC-1: Seeded catalogue is created idempotently
- **spec_ref**: `openspec/changes/compliance-control-packs/specs/compliance-control-packs/spec.md#requirement-a-seeded-source-cited-control-catalogue-spans-three-frameworks`
- **type**: functional
- **persona**: Noor (Municipal CISO / Functional Admin)
- **preconditions**: A fresh Hermiq install (or upgrade past this change's version) with the register repair step not yet run
- **steps**: Trigger the register repair step (install/upgrade); run it a second time
- **expected result**: `ControlFramework` objects for `eu-ai-act`/`iso-42001`/`nist-ai-rmf` and ~10 `Control` objects exist after the first run; the second run creates no duplicates
- **test command**: `/test-functional`

### TC-2: Control status recomputes from live data with no manual step
- **spec_ref**: `openspec/changes/compliance-control-packs/specs/compliance-control-packs/spec.md#requirement-control-status-is-computed-from-live-governance-data-never-hand-set`
- **type**: api
- **persona**: n/a
- **preconditions**: An organisation with zero audited runs
- **steps**: Call the dashboard endpoint (expect `unevidenced` for the audit-trail-recordkeeping control); run one agent to completion; call the dashboard endpoint again
- **expected result**: The control's status changes from `unevidenced` to `satisfied` with no intervening write to the `Control` object itself
- **test command**: `/test-api`

### TC-3: Each evidence source maps to its authoritative seam
- **spec_ref**: `openspec/changes/compliance-control-packs/specs/compliance-control-packs/spec.md#requirement-each-evidence-source-maps-to-exactly-one-existing-hermiq-seam`
- **type**: api
- **persona**: n/a
- **preconditions**: Fixture data covering each of the six `evidenceSource` values in each of `satisfied`/`partial`/`unevidenced` where applicable
- **steps**: Call `ComplianceService::computeControlStatus()` (via PHPUnit) for each fixture
- **expected result**: Each branch reads only the one named seam (`TenantOpsService`, `ApprovalService`, `TenantControlService`, `ModelPolicy`, `AiFeatureService`) and returns the expected status
- **test command**: `/test-api`

### TC-4: Admin views dashboard coverage and gap list
- **spec_ref**: `openspec/changes/compliance-control-packs/specs/compliance-control-packs/spec.md#requirement-a-compliance-dashboard-shows-per-framework-coverage-and-the-gap-list`
- **type**: functional
- **persona**: Noor (Municipal CISO / Functional Admin)
- **preconditions**: An organisation with a mix of satisfied/partial/unevidenced controls
- **steps**: Log in as an admin, open the compliance dashboard
- **expected result**: Per-framework coverage percentage and the gap list render, each gap showing its explanation and source link
- **test command**: `/test-persona-noor`

### TC-5: Non-authorized user cannot reach the dashboard
- **spec_ref**: `openspec/changes/compliance-control-packs/specs/compliance-control-packs/spec.md#requirement-dashboard-export-and-factsheet-access-are-restricted-by-role-and-ownership`
- **type**: security
- **persona**: n/a
- **preconditions**: A user without `compliance.view-dashboard`
- **steps**: Call the dashboard endpoint directly
- **expected result**: Refused (403); no coverage data returned
- **test command**: `/test-security`

### TC-6: Auditor's pack export wraps the existing Art. 12 export unmodified
- **spec_ref**: `openspec/changes/compliance-control-packs/specs/compliance-control-packs/spec.md#requirement-the-auditors-pack-export-extends-the-existing-art-12-export`
- **type**: regression
- **persona**: n/a
- **preconditions**: An organisation with existing audited runs and computed control statuses
- **steps**: Call the pre-existing Art. 12 export endpoint and the new compliance export endpoint; diff the audit-trail portion of the new export against the old endpoint's raw output
- **expected result**: The old endpoint's behavior is unchanged; the new export's `auditTrail` key equals the old endpoint's output exactly, with `complianceCoverage` added alongside
- **test command**: `/test-regression`

### TC-7: DPO views an agent's factsheet
- **spec_ref**: `openspec/changes/compliance-control-packs/specs/compliance-control-packs/spec.md#requirement-an-ai-factsheet-summarises-an-agents-governance-lifecycle`
- **type**: functional
- **persona**: Noor (Municipal CISO / Functional Admin)
- **preconditions**: An agent registered as a high-risk `AiFeature` with a DPO acknowledgement, one approved run, and one linked incident
- **steps**: Open the agent's detail page, click "View compliance factsheet"
- **expected result**: The dialog shows provider/model/tools, risk category + DPO-ack timestamp, the approval decision, and the linked incident, matching current data
- **test command**: `/test-persona-noor`

### TC-8: Non-owner cannot view another user's agent factsheet
- **spec_ref**: `openspec/changes/compliance-control-packs/specs/compliance-control-packs/spec.md#requirement-dashboard-export-and-factsheet-access-are-restricted-by-role-and-ownership`
- **type**: security
- **persona**: n/a
- **preconditions**: An agent owned by user A; user B is neither its owner/actingUser nor authorized via `compliance.view-factsheet`
- **steps**: User B requests user A's agent factsheet directly via the API
- **expected result**: HTTP 404 (not 403); no factsheet data returned
- **test command**: `/test-security`

### TC-9: Compliance dashboard and factsheet dialog are accessible
- **spec_ref**: `openspec/changes/compliance-control-packs/specs/compliance-control-packs/spec.md#requirement-a-compliance-dashboard-shows-per-framework-coverage-and-the-gap-list`
- **type**: accessibility
- **persona**: n/a
- **preconditions**: The dashboard and factsheet dialog are rendered
- **steps**: Run an accessibility audit against both surfaces
- **expected result**: WCAG AA compliance — CSS variables only, no hardcoded colors, keyboard-navigable table and dialog
- **test command**: `/test-accessibility`

## Coverage Summary

- Requirement "A seeded, source-cited control catalogue spans three frameworks" — covered (TC-1).
- Requirement "Control status is computed from live governance data, never hand-set" — covered (TC-2).
- Requirement "Each evidence source maps to exactly one existing Hermiq seam" — covered (TC-3).
- Requirement "A compliance dashboard shows per-framework coverage and the gap list" — covered (TC-4, TC-5, TC-9).
- Requirement "The auditor's-pack export extends the existing Art. 12 export" — covered (TC-6).
- Requirement "An AI factsheet summarises an agent's governance lifecycle" — covered (TC-7, TC-8, TC-9).
- Requirement "Dashboard, export, and factsheet access are restricted by role and ownership" — covered (TC-5, TC-8).

## Out of Scope

- Real-time bias/drift/hallucination/toxicity scoring, the regulatory-knowledge-graph, and
  third-party/vendor model assessment are out of scope for this change (see proposal.md) and have no
  test cases here.
- Catalogue-editing UI is not built in this change (seed-only), so no test case covers editing a
  `Control`/`ControlFramework` object.
