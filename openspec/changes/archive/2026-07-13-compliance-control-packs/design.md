# Design: compliance-control-packs

## Context

Hermiq's runtime governance is already extensive but scattered across single-purpose services:
`TenantOpsService::exportAuditTrail()` produces an Art. 12 record-keeping export, `ApprovalService`
records Art. 14 human-oversight decisions, `TenantControlService` holds the per-org kill-switch (the
Art. 14/26 stop mechanism) and the stated retention period, `AiFeatureService` +
`AlgoritmekaderMapper` gate high-risk features behind a DPO acknowledgement, and
`Llm\ProviderFactory` enforces the per-org model-provider allowlist (`TenantModelPolicyService`).
Each of these already IS a piece of evidence for a named regulatory control — but nothing maps them
to a control catalogue, shows coverage/gaps, or renders a consolidated per-agent lifecycle record.
Rivals package exactly this mapping as "compliance packs" (IBM watsonx Compliance Accelerators,
Credo AI policy packs, Holistic AI control mapping, Monitaur evidence-to-control mapping) and charge
enterprise prices for it. This change adds the mapping layer only — it introduces zero new
governance primitives, it reads the ones that already exist.

Established hermiq conventions this change follows (verified in the working tree at HEAD):
- **Schema house-style** (`lib/Settings/hermiq_register.json`): lowercase namespaced `slug`
  (`agentaifeature`, `agentbudget`, `tenantcontrol`), Material-Design `icon`, semantic `version`,
  `title`, `description`, `type: object`, `required`, flat properties (no `if`/`then`/`allOf` — the
  importer rejects conditionals), `"x-openregister": { "publicRead": false, "publicWrite": false }`.
- **Computed-on-read, never a stored counter**: `Budget`'s docblock states "current-period usage is
  computed on read from the existing run AuditTrail — never a stored counter." This change applies
  the identical principle to control status.
- **`ActionAuthService` (ADR-023)**: controllers declare a dot-separated action
  (`tenantops.create-incident`, `aifeature.enable`) and call `requireAction()`; the matrix seeds to
  `["admin"]` in `lib/actions.seed.json` (first-install safe; broadenable by an admin).
- **Custom Vue page + stateless API module**: `TenantOps.vue` / `AiFeatureRegister.vue` are
  `type:"custom"` pages registered in `src/registry.js` (`kind:'page'`) and `src/manifest.json`
  (menu entry + page entry), backed by a plain `src/api/*.js` module over `@nextcloud/axios` +
  `generateUrl` — no Pinia store, `loadState`/`IInitialState` for server-provided capability flags
  (never DOM reads), modals/dialogs in their own file under `src/dialogs/`.
- **Repair-step seeding** (ADR-001/ADR-003): `lib/Repair/SeedAiFeatures.php` is the model — an
  idempotent repair step (skip on existing `slug`) writing through `ObjectService` (single
  write-path, ADR-004).

## Goals / Non-Goals

**Goals:**
- Declare `ControlFramework` + `Control` OpenRegister schemas seeded with short, source-cited
  control text for EU AI Act, ISO/IEC 42001, and NIST AI RMF.
- Compute each control's status (`satisfied` / `partial` / `unevidenced`) at read time from the five
  existing evidence seams listed in Context — never a hand-set field.
- Provide an org-scoped compliance dashboard (per-framework coverage + gap list).
- Provide an auditor's-pack export that wraps the existing `exportAuditTrail()` output with the
  computed control statuses and citations.
- Provide a per-agent AI factsheet (model card) assembled from existing `Agent`, `AiFeature`,
  `Approval`, and `Incident` data.
- Gate the dashboard/export to admins/DPO via `ActionAuthService`; gate the factsheet to the agent's
  own owner/acting-user or an admin/DPO.

**Non-Goals:**
- No model-quality scoring (bias, drift, hallucination, toxicity, prompt-injection detection) — that
  needs an eval/inference pipeline Hermiq does not run; deferred to `agent-evals` +  a future
  model-monitoring change.
- No regulatory-knowledge-graph, no legal-text corpus beyond a short cited excerpt per control.
- No third-party/vendor model assessment.
- No catalogue-editing UI — the seeded catalogue is read-only in this change (Open Question in
  proposal.md).
- No change to any existing schema, service, or endpoint — this is purely additive.

## Decisions

**Schema slugs are namespaced to avoid the "control" collision.** `TenantControlService` /
`TenantControl` already use "control" for the per-org kill-switch — an unrelated, existing concept
(a boolean stop-switch), confirmed at HEAD (`lib/Service/TenantControlService.php`,
`tenantcontrol` schema). A bare `Control` or `ControlFramework` schema slug risks both a naming
collision in conversation and, per the `AiFeature`/scholiq precedent (a plain `aifeature` slug
silently collided with scholiq's global schema and imported nothing), a *global* slug collision with
any other Conduction app's schema named `control`. Following the same `agent`-prefix convention
hermiq already uses for collision-prone schemas (`agentsession`, `agentskill`, `agentaifeature`,
`agentbudget`), the two new schemas use slugs `agentcontrolframework` and `agentcompliancecontrol`.
UI copy never uses bare "Control" — always "compliance control" — to keep the two concepts visually
and verbally distinct. *Alternative considered:* reuse/extend `TenantControl` to also hold the
compliance catalogue — rejected, it conflates an imperative per-org stop-switch with a static,
cross-tenant reference catalogue; they have different lifecycles (one is per-organisation mutable
state, the other is seeded, instance-wide, read-mostly reference data).

**`ControlFramework` schema** (`agentcontrolframework`): a small reference row per framework —
`slug` (e.g. `eu-ai-act`), `name` (e.g. "EU AI Act"), `edition` (e.g. "Regulation (EU) 2024/1689"),
`sourceUrl`, `description`. No lifecycle, no tenant scope — instance-wide reference data, same as
how `Agent.tools` references a fixed MCP catalog rather than a per-tenant one.

**`Control` schema** (`agentcompliancecontrol`): one row per control —
`frameworkSlug` (`$ref: ControlFramework`), `controlId` (e.g. `"art.12"`, `"6.1.2"`,
`"GOVERN-1.1"`), `title`, `description` (a short, cited paraphrase — one or two sentences, not a
legal reproduction), `sourceUrl`, `evidenceSource` (enum — see below), `evidenceDescription` (a
human sentence explaining what "satisfied" means for this specific control, shown in the dashboard
and export). **No status field** — status is never persisted, only computed (Goals).
`evidenceSource` enum values, one per existing seam:
- `audit-trail-recordkeeping` → `TenantOpsService::exportAuditTrail()` (Art. 12 record-keeping).
- `approval-gate-oversight` → `ApprovalService` decision history (Art. 14 human oversight).
- `kill-switch-stop-mechanism` → `TenantControlService::getForOrganisation()` (Art. 14/26 stop
  mechanism / deployer duties).
- `model-policy-risk-control` → the org's `ModelPolicy` object, enforced at
  `ProviderFactory::createChatDriver()` (model-risk / provider-allowlist controls).
- `capability-review-least-privilege` → `TenantOpsService::accessReviewList()` (least-privilege /
  access-review controls).
- `dpo-ack-design-time-gate` → `AiFeatureService`/`AlgoritmekaderMapper` (design-time high-risk
  feature governance controls).

**`ComplianceService` computes status by dispatching on `evidenceSource` — never a generic
"has any data" check.** Each branch calls the ONE existing method that is the authoritative evidence
for that class of control, mirroring the `Budget` "computed on read" precedent:
```php
private function computeControlStatus(array $control, string $organisation): array
{
    return match ($control['evidenceSource']) {
        'audit-trail-recordkeeping'    => $this->evidenceFromAuditTrail($organisation),
        'approval-gate-oversight'      => $this->evidenceFromApprovals($organisation),
        'kill-switch-stop-mechanism'   => $this->evidenceFromKillSwitch($organisation),
        'model-policy-risk-control'    => $this->evidenceFromModelPolicy($organisation),
        'capability-review-least-privilege' => $this->evidenceFromAccessReview($organisation),
        'dpo-ack-design-time-gate'     => $this->evidenceFromAiFeatures($organisation),
        default                        => ['status' => 'unevidenced', 'detail' => 'No evidence source mapped.'],
    };
}
```
Concrete per-branch rules (each returns `status` + a short `detail` string surfaced in the
dashboard/export, never a raw boolean):
- **Audit trail**: `satisfied` when `exportAuditTrail()['recordCount'] > 0` for the org (runs have
  actually been logged); `unevidenced` when zero (nothing has run yet, so record-keeping is
  unproven, not "broken").
- **Approval gate**: `satisfied` when at least one `Approval` object for the org has
  `decidedBy`/`decidedAt` set (a human oversight decision has actually happened); `partial` when
  `Approval` objects exist but are all still `pending`; `unevidenced` when no approvals exist at all
  for the org.
- **Kill switch**: `satisfied` when `TenantControlService::getForOrganisation()` returns a
  `TenantControl` object for the org (the stop mechanism is provisioned, regardless of whether it is
  currently engaged — Art. 14/26 asks that the mechanism EXISTS, not that it is permanently on);
  `unevidenced` when no `TenantControl` object exists for the org yet.
- **Model policy**: `satisfied` when the org has its own `ModelPolicy` object with a non-empty
  `allowed` list; `partial` when only the instance-wide default policy applies (no org-specific
  allowlist); `unevidenced` when no policy exists anywhere (any provider/model is unrestricted).
- **Access review**: `satisfied` when every agent in `accessReviewList()` for the org has a
  non-null, non-stale `reviewedAt` (within the org's `retentionMonths` window from
  `TenantOpsService::getRetentionMonths()`); `partial` when some but not all agents are reviewed;
  `unevidenced` when the org has agents but none have ever been reviewed.
- **DPO-ack gate**: `satisfied` when at least one `AiFeature` for the org has `lifecycle=enabled`
  (which the existing `AiFeatureDpoAckGuard` already proves required a DPO ack to reach); `partial`
  when `AiFeature` rows exist but all are still `disabled`; `unevidenced` when the org has registered
  no `AiFeature` at all.

**Compliance dashboard aggregates, it does not re-derive.** `ComplianceService::dashboard($organisation)`
loads the seeded `Control` catalogue (all frameworks), computes status per control via the method
above, and groups by `frameworkSlug` into `{framework, coveragePercent, controls: [...]}`; the gap
list is simply every control whose computed status is not `satisfied`. No new aggregate is stored;
every dashboard load recomputes from live data (same "computed, not cached" posture as
`RunAnalytics`'s KPI widgets, ADR-049).

**Auditor's-pack export wraps, does not replace, the existing Art. 12 export.**
`ComplianceService::auditorPack($organisation)` calls the existing
`TenantOpsService::exportAuditTrail()` unmodified and nests its return value under an `auditTrail`
key alongside a new `complianceCoverage` key holding the same per-control status/citation data the
dashboard shows. The frontend reuses `TenantOps.vue`'s exact `downloadJson()` Blob-download pattern
(`new Blob([JSON.stringify(data, null, 2)])` → `URL.createObjectURL` → synthetic `<a download>`
click) — no new export mechanism is invented.

**AI factsheet assembles, it does not add fields.** `ComplianceService::factsheet($agentId)` loads
the `Agent` object (provider/model, tool allowlist, RAG/data-access scope, owner/actingUser), the
matching `AiFeature` (risk category, `lifecycle`, `dpoAckBy`/`dpoAckAt`) if one is registered for
that agent's slug, the agent's `Approval` decision history (via the same per-object AuditTrail read
`TenantOpsService::exportAuditTrail()` already performs per approval), any `Incident` rows whose
`linkedAgentId` matches, and the agent's `reviewedAt` from `accessReviewList()`. It returns a single
read-only JSON envelope — no new persisted "factsheet" object, so there is nothing to keep in sync.
*Alternative considered:* persist a generated factsheet snapshot as its own OpenRegister object —
rejected, it would immediately go stale relative to the live `Agent`/`AiFeature`/`Approval`/
`Incident` data it summarises, and Hermiq has no use case that requires a point-in-time frozen copy
yet (unlike the Art. 12 export, which IS meant to be a frozen artifact handed to an auditor).

**Authorization.** Dashboard and export are gated behind new `ActionAuthService` actions
(`compliance.view-dashboard`, `compliance.export-pack`; matrix seeds to `["admin"]`, mirroring
`tenantops.*`). The factsheet endpoint allows EITHER the requesting user being the agent's
`owner`/`actingUser` (self-service, like a user viewing their own agent's detail page) OR
`requireAction('compliance.view-factsheet')` for a DPO/admin viewing someone else's agent — an
explicit per-object IDOR guard mirroring `ApprovalController::isReviewer()`'s pattern, not a bare
`ActionAuthService` gate, because "is this my agent" is a data-ownership check OpenRegister RBAC
does not already express for a cross-schema read like this.

## API Design

### `GET /api/compliance/dashboard`
**Request:** none (organisation resolved from the authenticated user's active org).
**Response:**
```json
{
  "frameworks": [
    {
      "slug": "eu-ai-act",
      "name": "EU AI Act",
      "coveragePercent": 67,
      "controls": [
        {
          "controlId": "art.12",
          "title": "Record-keeping",
          "status": "satisfied",
          "detail": "142 audited run records found for this organisation.",
          "sourceUrl": "https://artificialintelligenceact.eu/article/12/"
        }
      ]
    }
  ],
  "gaps": [
    { "frameworkSlug": "iso-42001", "controlId": "9.1", "status": "unevidenced", "detail": "..." }
  ]
}
```

### `GET /api/compliance/export`
**Request:** none. **Response:** `{ "auditTrail": { ...exportAuditTrail() output... }, "complianceCoverage": { ...same shape as the dashboard response... }, "generatedAt": "<ISO8601>" }`.

### `GET /api/compliance/factsheet/{agentId}`
**Request:** none. **Response:**
```json
{
  "agent": { "id": "...", "name": "...", "provider": "...", "model": "...", "tools": ["..."], "owner": "...", "actingUser": "..." },
  "aiFeature": { "riskCategory": "high", "lifecycle": "enabled", "dpoAckBy": "...", "dpoAckAt": "..." },
  "approvals": [ { "decidedBy": "...", "decidedAt": "...", "status": "approved" } ],
  "incidents": [ { "description": "...", "impact": "...", "createdAt": "..." } ],
  "lastReviewedAt": "..."
}
```
**Errors:** `404` when the agent does not exist or the caller is neither its owner/actingUser nor
authorized via `compliance.view-factsheet` (matches the existing anti-probing 404-not-403 posture,
`run-audit-log`).

## Database Changes
None — Hermiq owns no Nextcloud database tables. Schema is declared in
`lib/Settings/hermiq_register.json` and applied through OpenRegister's existing register-import
repair step (version-gated by the `appinfo/info.xml` patch bump). See migration.md skip reason
below.

## Nextcloud Integration
- **Controllers:** `lib/Controller/ComplianceController.php` (`@NoAdminRequired` +
  `@NoCSRFRequired`, mirroring `TenantOpsController`/`ApprovalController`).
- **Services:** `lib/Service/ComplianceService.php`, depending on `ObjectService`,
  `TenantOpsService`, `ApprovalService`, `TenantControlService`, `AiFeatureService` (constructor DI,
  no new external dependency).
- **Repair:** `lib/Repair/SeedComplianceControls.php` (idempotent by `slug`, alongside
  `SeedAiFeatures`).
- **Events/Hooks:** none — purely a computed-read feature; no new event is emitted or consumed.

## Security Considerations
- All three endpoints are read-only (no state mutation) — no CSRF-sensitive write surface is added.
- Dashboard/export are `ActionAuthService`-gated (admin/DPO only) since they aggregate cross-agent,
  org-wide governance data.
- The factsheet endpoint requires an explicit per-object ownership check (agent owner/actingUser) OR
  the `compliance.view-factsheet` action, refusing with `404` (not `403`) to avoid confirming agent
  existence to an unauthorized caller — matching the existing `run-audit-log` anti-probing
  convention.
- No new secret- or PII-bearing field is introduced; the factsheet surfaces data already visible
  elsewhere (agent detail, AI-feature register, access-review list, incident list) in one read-only
  view — it does not widen any existing data's visibility.

## NL Design System
`ComplianceDashboard.vue` reuses `CnDataTable` (as `TenantOps.vue`/`AiFeatureRegister.vue` already
do) for the framework/control coverage table and NL-Design-System status badges (`satisfied` /
`partial` / `unevidenced`) using CSS variables only (no hardcoded colors, per project convention).
`AgentFactsheetDialog.vue` is an `NcDialog` in its own file under `src/dialogs/` (ADR-004 modal
isolation), reusing existing badge styling from `AiFeatureRegister.vue`'s risk-category badge.

## File Structure
```
lib/
  Controller/
    ComplianceController.php
  Service/
    ComplianceService.php
  Repair/
    SeedComplianceControls.php
  Settings/
    hermiq_register.json (ControlFramework, Control schemas added)
  actions.seed.json (compliance.view-dashboard, compliance.export-pack, compliance.view-factsheet)
src/
  api/
    compliance.js
  views/
    ComplianceDashboard.vue
  dialogs/
    AgentFactsheetDialog.vue
  manifest.json (menu + page entry)
  registry.js (page registration)
l10n/
  en.json
  nl.json
tests/Unit/
  Service/ComplianceServiceTest.php
  Controller/ComplianceControllerTest.php
```

## Seed Data

### Schema: `agentcontrolframework`
| Field | Object 1 | Object 2 | Object 3 |
|-------|----------|----------|----------|
| slug | `eu-ai-act` | `iso-42001` | `nist-ai-rmf` |
| name | EU AI Act | ISO/IEC 42001 | NIST AI RMF |
| edition | Regulation (EU) 2024/1689 | ISO/IEC 42001:2023 | AI RMF 1.0 (Jan 2023) |
| sourceUrl | https://artificialintelligenceact.eu/ | https://www.iso.org/standard/81230.html | https://www.nist.gov/itl/ai-risk-management-framework |
| description | EU regulation on AI, risk-tiered obligations | AI management system (AIMS) standard | Voluntary US risk-management framework |

### Schema: `agentcompliancecontrol` (representative rows; ~10 total across the three frameworks)
| Field | Row 1 | Row 2 | Row 3 | Row 4 |
|-------|-------|-------|-------|-------|
| frameworkSlug | eu-ai-act | eu-ai-act | eu-ai-act | iso-42001 |
| controlId | art.12 | art.14 | art.26 | 9.1 |
| title | Record-keeping | Human oversight | Deployer duties | Monitoring |
| evidenceSource | audit-trail-recordkeeping | approval-gate-oversight | kill-switch-stop-mechanism | capability-review-least-privilege |
| sourceUrl | .../article/12/ | .../article/14/ | .../article/26/ | ISO 42001 clause 9.1 |

(NIST rows follow the same shape: `GOVERN-1.1`→`dpo-ack-design-time-gate`, `MAP-1.1`→
`dpo-ack-design-time-gate`, `MEASURE-2.1`→`model-policy-risk-control`, `MANAGE-4.1`→
`kill-switch-stop-mechanism`; ISO rows also include `6.1.2` (risk assessment) →
`dpo-ack-design-time-gate` and `8.2` (AI system impact assessment) →
`capability-review-least-privilege`.)

**Related items per object:** none — these are pure reference/catalogue rows; no files, notes,
tasks, or contacts attach to them.

## Trade-offs
- **Computed-only status (no cache).** Every dashboard/export load recomputes from live data across
  up to five services — acceptable because org-scoped control counts are small (~10 controls) and
  each evidence method already exists and is already called elsewhere at similar frequency
  (`TenantOps.vue` calls the same methods on page load today). A cached/materialized view is a
  deferred optimization if dashboard load time becomes a problem.
- **Contract.md skipped**: no other apps-extra project consumes these endpoints — only Hermiq's own
  frontend. See DEFERRED_DECISIONS note in the final report if a cross-app consumer emerges later.
- **Discovery.md skipped**: no open feasibility question remained after verifying all five evidence
  seams directly at HEAD; this design straightforwardly extends existing, already-proven patterns
  (ADR-004 single write-path, ADR-023 action auth, the `Budget` computed-on-read precedent).
- **Migration.md skipped**: Hermiq owns no Nextcloud database tables; the two new schemas are
  declared in `hermiq_register.json` and applied via the existing version-gated register-import
  repair step, not an `OCP\Migration` class.
