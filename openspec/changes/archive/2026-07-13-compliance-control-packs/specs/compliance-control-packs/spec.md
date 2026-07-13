## ADDED Requirements

### Requirement: A seeded, source-cited control catalogue spans three frameworks

The system MUST provide a seeded `ControlFramework` + `Control` catalogue covering EU AI Act
(Art. 12, Art. 14, Art. 26), ISO/IEC 42001 (a representative set of AIMS clauses), and NIST AI RMF
(one control per GOVERN/MAP/MEASURE/MANAGE function), persisted as OpenRegister objects. Each
`Control` MUST carry a short, source-cited description and a `sourceUrl` pointing at the original
regulation/standard text, and MUST reference exactly one `ControlFramework` via `frameworkSlug`.

#### Scenario: The seeded catalogue is present after install/upgrade

- **GIVEN** a fresh Hermiq install or an upgrade past this change's version
- **WHEN** the register-import repair step runs
- **THEN** the system MUST have created `ControlFramework` objects for `eu-ai-act`, `iso-42001`,
  and `nist-ai-rmf`, and `Control` objects for each framework's seeded controls
- **AND** re-running the repair step MUST NOT duplicate any existing `Control`/`ControlFramework`
  (idempotent by `slug`)

@e2e exclude seeding is covered by a PHPUnit repair-step test asserting idempotency; no Playwright
surface asserts catalogue seeding directly.

### Requirement: Control status is computed from live governance data, never hand-set

The system MUST compute each control's status (`satisfied` / `partial` / `unevidenced`) at read
time from Hermiq's own existing governance data, dispatched by the control's `evidenceSource`, and
MUST NOT provide any UI or API affordance to set a control's status directly. The `Control` schema
MUST NOT carry a persisted status field.

#### Scenario: A control's status changes when the underlying evidence changes, with no manual step

- **GIVEN** a `Control` with `evidenceSource=audit-trail-recordkeeping` and an organisation with zero
  audited runs
- **WHEN** the compliance dashboard is loaded for that organisation
- **THEN** the system MUST report that control as `unevidenced`
- **WHEN** an agent for that organisation subsequently completes a run (writing at least one
  `AuditTrail` entry) and the dashboard is reloaded
- **THEN** the system MUST report that same control as `satisfied`, with no manual status update by
  any user

@e2e exclude covered by a PHPUnit test on `ComplianceService::computeControlStatus()` stubbing the
audit-trail count before/after; live-verified against a real organisation during archive.

### Requirement: Each evidence source maps to exactly one existing Hermiq seam

The system MUST compute status for each `evidenceSource` value using the one Hermiq service method
that is authoritative for that evidence, and MUST NOT introduce a new, parallel data store for any
evidence class already produced elsewhere: `audit-trail-recordkeeping` MUST read
`TenantOpsService::exportAuditTrail()`; `approval-gate-oversight` MUST read `ApprovalService`
decision records; `kill-switch-stop-mechanism` MUST read
`TenantControlService::getForOrganisation()`; `model-policy-risk-control` MUST read the
organisation's `ModelPolicy` object; `capability-review-least-privilege` MUST read
`TenantOpsService::accessReviewList()`; `dpo-ack-design-time-gate` MUST read `AiFeatureService`
data.

#### Scenario: Human-oversight control reflects recorded approval decisions

- **GIVEN** an organisation with one `Approval` object whose `decidedBy`/`decidedAt` are set
  (approved)
- **WHEN** the dashboard computes the `approval-gate-oversight` control for that organisation
- **THEN** the system MUST report `satisfied`, citing the decision as the evidence
- **AND** an organisation whose approvals are all still `pending` MUST instead be reported `partial`

@e2e exclude covered by a PHPUnit test on `ComplianceService::evidenceFromApprovals()` against
fixture `Approval` objects in each state.

### Requirement: A compliance dashboard shows per-framework coverage and the gap list

An authorized user MUST be able to view, per organisation, each framework's coverage percentage
(the share of its controls computed `satisfied`) and the full list of controls not `satisfied`
(`partial` or `unevidenced`), each showing its `detail` explanation and `sourceUrl`.

#### Scenario: An admin views coverage and gaps for their organisation

- **GIVEN** an organisation with a mix of `satisfied`, `partial`, and `unevidenced` controls across
  all three frameworks
- **WHEN** an admin opens the compliance dashboard
- **THEN** the system MUST show each framework's coverage percentage
- **AND** MUST list every non-`satisfied` control with its explanation and source link
- **AND** a user without the `compliance.view-dashboard` action MUST be refused access to the
  dashboard endpoint

@e2e exclude dashboard aggregation covered by PHPUnit; the `ActionAuthService` gate covered by the
controller auth-guard test; Playwright coverage of the rendered dashboard deferred to a follow-up.

### Requirement: The auditor's-pack export extends the existing Art. 12 export

The system MUST provide an export that includes the existing, unmodified
`TenantOpsService::exportAuditTrail()` output alongside the same per-control coverage data the
dashboard shows, downloadable via the same JSON-Blob pattern the existing Art. 12 export button
already uses.

#### Scenario: An admin downloads the auditor's pack

- **GIVEN** an organisation with computed control statuses across all three frameworks
- **WHEN** an admin requests the compliance export
- **THEN** the system MUST return a single JSON payload containing the unmodified audit-trail export
  plus the per-control coverage data
- **AND** the existing Art. 12-only export endpoint MUST continue to return exactly what it did
  before this change (no regression)

@e2e exclude covered by a PHPUnit test asserting the export payload shape and that the pre-existing
`exportAuditTrail()` return value is passed through unmodified.

### Requirement: An AI factsheet summarises an agent's governance lifecycle

The system MUST provide a per-agent, read-only factsheet assembled from existing data: the agent's
purpose/provider/model/tool allowlist, its linked `AiFeature` risk classification and DPO-ack state
(if registered), its approval-decision history, any linked incidents, and its last access-review
timestamp. The system MUST NOT persist a separate factsheet object — the response is always
assembled live.

#### Scenario: A DPO views an agent's factsheet

- **GIVEN** an agent registered as a high-risk `AiFeature` with a recorded DPO acknowledgement, one
  approved run, and one linked incident
- **WHEN** an authorized DPO requests that agent's factsheet
- **THEN** the system MUST return the agent's provider/model/tools, the `AiFeature` risk category and
  DPO-ack timestamp, the approval decision, and the linked incident, all reflecting current data
- **AND** the response MUST NOT include any field not derivable from the `Agent`, `AiFeature`,
  `Approval`, or `Incident` objects

@e2e exclude covered by a PHPUnit test on `ComplianceService::factsheet()` against fixture objects;
Playwright coverage of the rendered dialog deferred to a follow-up.

### Requirement: Dashboard, export, and factsheet access are restricted by role and ownership

The dashboard and export endpoints MUST refuse a caller who lacks the `compliance.view-dashboard` /
`compliance.export-pack` action respectively. The factsheet endpoint MUST refuse a caller who is
neither the target agent's `owner`/`actingUser` nor authorized via `compliance.view-factsheet`, and
MUST refuse with HTTP 404 (not 403) to avoid confirming the agent's existence to an unauthorized
caller.

#### Scenario: A non-owner, non-authorized user cannot view another user's agent factsheet

- **GIVEN** an agent owned by user A, and user B who is neither its owner/actingUser nor holds
  `compliance.view-factsheet`
- **WHEN** user B requests that agent's factsheet
- **THEN** the system MUST refuse with HTTP 404
- **AND** no factsheet data MUST be returned to user B

@e2e exclude covered by a PHPUnit controller test asserting the 404-not-403 posture for both the
missing-agent and unauthorized-caller cases.

## Non-Functional Requirements

- **Performance:** the dashboard and export endpoints MUST complete within the same order of
  magnitude as `TenantOps.vue`'s existing page load (all evidence reads are bounded by an
  organisation's own object counts, no unbounded cross-tenant scan).
- **Accessibility:** the compliance dashboard and factsheet dialog MUST meet WCAG AA (NL Design
  System components, CSS variables only, no hardcoded colors).
- **Internationalization:** Dutch and English MUST be supported for every new user-facing string
  (ADR-005/ADR-007).

## Acceptance Criteria

- [ ] `ControlFramework`/`Control` schemas exist in `lib/Settings/hermiq_register.json` and seed
  idempotently for EU AI Act, ISO/IEC 42001, and NIST AI RMF.
- [ ] `Control` objects carry no persisted status field; status is always computed at read time.
- [ ] Each `evidenceSource` maps to exactly the one existing service method named in this spec.
- [ ] The compliance dashboard shows per-framework coverage and the gap list, gated by
  `compliance.view-dashboard`.
- [ ] The auditor's-pack export wraps the unmodified `exportAuditTrail()` output with the coverage
  data, gated by `compliance.export-pack`.
- [ ] The agent factsheet assembles `Agent`/`AiFeature`/`Approval`/`Incident` data live, gated by
  ownership or `compliance.view-factsheet`, refusing with 404 otherwise.

## Notes

- Builds on, and does not modify, `run-audit-log`, `human-approval-gate-enforcement`,
  `multi-tenant-ops`, `ai-feature-governance-register`, and `tenant-model-policy`.
- Real-time bias/drift/hallucination/toxicity scoring, the regulatory-knowledge-graph pattern, and
  third-party/vendor model assessment are explicitly out of scope (see proposal.md) — future
  `agent-evals`/model-monitoring work may extend `evidenceSource` with new mapped values later.
- Related ADRs: ADR-004 (governance via OpenRegister AuditTrail, single write-path), ADR-023
  (action authorization).
