# Proposal: compliance-control-packs

## Summary
Rivals (IBM watsonx.governance, Credo AI, Holistic AI, Monitaur) sell "compliance packs": a
pre-built control catalogue for EU AI Act / ISO 42001 / NIST AI RMF / NYC LL144 / SR 11-7, evidence
auto-mapped to controls, a coverage dashboard + gap analysis, an auditor's export, and an
auto-generated "nutrition label" model card per AI system. Hermiq already has the raw governance
data these packs are built on top of — the hash-chained `AuditTrail`, the human-approval gate, the
per-org kill-switch + retention setting, the DPO-ack design-time AI-feature register, and per-org
model-policy enforcement — but nothing today maps that data to a named control catalogue, shows
coverage/gaps, or renders a per-agent lifecycle record. This change adds a seeded
`ControlFramework`/`Control` catalogue (EU AI Act, ISO/IEC 42001, NIST AI RMF), a service that
**computes** each control's status from Hermiq's own live data (never a hand-ticked checkbox), an
org-scoped compliance dashboard, an auditor's-pack export that extends the existing Art. 12 export,
and an AI factsheet (model card) per agent assembled from data Hermiq already governs.

Kind: **code**. Hermiq owns no database tables (thin-client, ADR-004); all new behaviour is two new
declarative OpenRegister schemas (seeded, read-mostly catalogue data) plus a computed-read service,
controller, and two Vue surfaces over data Hermiq already collects.

## Motivation
The Spectr competitive sweep (2026-07-12) classified 21 rival features across four AI-governance
platforms into this one gap cluster — the single largest gap cluster found for Hermiq. Every one of
those 21 features is a *packaging* problem, not a *data* problem: Hermiq already produces
art.12-grade audit trails, art.14 approval decisions, a stop mechanism, a DPO-ack gate, and
model-policy enforcement, but a compliance officer has no single place that says "here is EU AI Act
coverage, here is the gap list, here is the auditor's pack, here is what agent X actually does."
Building this now turns Hermiq's existing governance primitives into the auditable, dashboarded,
exportable "compliance pack" rivals charge enterprise prices for — without duplicating any of the
governance machinery those primitives already provide.

## Affected Projects
- [x] Project: `hermiq` — two new seeded schemas (`ControlFramework`, `Control`), a new
  `ComplianceService` that computes control status from existing services, a new
  `ComplianceController` (dashboard / export / factsheet endpoints), and two new Vue surfaces (a
  compliance dashboard page, an agent factsheet dialog).

## Scope

### In Scope
- **`ControlFramework` + `Control` OpenRegister schemas**, seeded with short, source-cited control
  text for: **EU AI Act** (Art. 12 record-keeping, Art. 14 human oversight, Art. 26 deployer
  duties), **ISO/IEC 42001** (a handful of AIMS clauses — risk assessment, AI system impact
  assessment, monitoring), **NIST AI RMF** (one control per GOVERN/MAP/MEASURE/MANAGE function).
  Each `Control` cites its source URL; text is short (a sentence or two), not a full legal
  reproduction.
- **Computed evidence mapping**: a `ComplianceService` that resolves each control's status
  (`satisfied` / `partial` / `unevidenced`) at read time from data Hermiq already owns — the
  Art. 12 audit export (`TenantOpsService::exportAuditTrail()`) for record-keeping controls,
  approval-gate decisions (`ApprovalService`) + the kill-switch (`TenantControlService`) for human
  oversight/stop-mechanism controls, per-org model policy (`ProviderFactory`/model-policy) for
  model-risk controls, and the access-review/capability data (`TenantOpsService::accessReviewList()`)
  for least-privilege controls. **Status is never hand-ticked** — there is no UI affordance to set
  it directly.
- **A compliance dashboard** (org-scoped, admin/DPO-gated like `TenantOps.vue`): per-framework
  coverage percentage and the list of `partial`/`unevidenced` controls (the gap list).
- **An auditor's-pack export**: extends the existing Art. 12 `exportAuditTrail()` JSON with the
  computed per-framework/control status and citations, reusing the exact download pattern
  `TenantOps.vue`'s `exportAudit()` already implements.
- **An AI factsheet / model card per agent**: a read-only, auto-generated lifecycle record (purpose,
  EU AI Act risk class + DPO-ack state from `AiFeatureService`, provider/model, tool/capability
  profile, owner/acting-user, approval-decision history, linked incident history, last access
  review) assembled entirely from existing `Agent`, `AiFeature`, `Approval`, and `Incident` data —
  no new fields are added to any of those schemas.

### Out of Scope
- **Real-time bias/drift/hallucination/toxicity model scoring** (IBM watsonx's headline capability).
  This needs an eval/monitoring model-inference pipeline Hermiq does not have; it is `agent-evals`
  territory now and a dedicated future model-monitoring change later — this change only surfaces
  what can be *evidenced* from governance data already collected, never a model-quality score.
- **The "regulatory knowledge graph"** (Credo AI) — a curated, continuously-updated legal-text corpus
  with cross-reference reasoning. This change ships a small, static, source-cited control list, not
  a knowledge graph or a regulatory-intelligence feed.
- **Third-party / vendor model assessment** (Credo AI) — assessing models Hermiq does not run itself
  is out of scope; the factsheet only describes agents configured in this Hermiq instance.
- **NYC LL144 and SR 11-7 packs** — cited by rivals as additional "Compliance Accelerators" but not
  requested here; the seeded catalogue covers EU AI Act / ISO 42001 / NIST AI RMF only. Adding more
  frameworks later is additive (new `Control` rows referencing a new `ControlFramework`).
- **Automated control-to-code CI gating** — this change reports status; it does not block deploys or
  runs on a control being `unevidenced`.

## Approach
Two new OpenRegister schemas hold the static catalogue (`ControlFramework`, `Control`); neither
carries a stored status field. A new `ComplianceService` computes each control's status on demand
by dispatching on a `evidenceSource` tag stored on the `Control` object to the one existing service
method that already proves that class of evidence (mirroring `Budget`'s "computed on read, never a
stored counter" precedent). `ComplianceController` exposes three read endpoints — dashboard
aggregation, export (wrapping `TenantOpsService::exportAuditTrail()`), and per-agent factsheet — all
gated through `ActionAuthService` (ADR-023) except the factsheet, which an agent's own
owner/acting-user may also view. Two Vue surfaces consume these: a new `ComplianceDashboard.vue`
custom page (modelled on `TenantOps.vue`) and a new `AgentFactsheetDialog.vue` (modelled on the
existing dialog pattern) opened from `AgentDetail.vue`.

## New Dependencies
None (no new PHP/npm packages).

## Impact
- **Config:** `lib/Settings/hermiq_register.json` gains `ControlFramework` and `Control` schema
  entries under `components.schemas` (union import, existing schemas untouched); `appinfo/info.xml`
  version bumps by one patch (register re-import is version-gated).
- **Backend:** new `lib/Service/ComplianceService.php`, new `lib/Controller/ComplianceController.php`
  + routes in `appinfo/routes.php`, a new repair step `lib/Repair/SeedComplianceControls.php`, new
  entries in `lib/actions.seed.json` (`compliance.view-dashboard`, `compliance.export-pack`,
  `compliance.view-factsheet`).
- **Frontend:** new `src/views/ComplianceDashboard.vue`, new `src/dialogs/AgentFactsheetDialog.vue`,
  new `src/api/compliance.js`, a menu/page entry in `src/manifest.json` + `src/registry.js`, a new
  "View compliance factsheet" action wired into `AgentDetail.vue`.
- **i18n:** new user-facing strings in `l10n/en.json` + `l10n/nl.json`.
- **Data:** OpenRegister creates magic tables for `ControlFramework`/`Control` on import; the repair
  step seeds ~3 frameworks and ~10 controls idempotently (by `slug`).

## Cross-Project Dependencies
None. All new endpoints are consumed only by Hermiq's own Vue frontend; the evidence sources read
are all already-internal Hermiq services (`TenantOpsService`, `ApprovalService`,
`TenantControlService`, `ProviderFactory`, `AiFeatureService`). No other apps-extra project reads or
writes this data.

## Risks

### Risk 1: A computed status could be read as a compliance guarantee it does not provide
**Severity:** Medium — **Mitigation:** every control's evidence description states exactly what
Hermiq evidence proves and does not prove; the dashboard and export both carry a standing disclaimer
that `satisfied` means "Hermiq can evidence this control from its own governance data," not "a
qualified auditor has certified compliance."

### Risk 2: Naming collision between the new `Control` schema and the existing `TenantControl`
kill-switch schema, which already uses the word "control" for an unrelated concept
**Severity:** Low — **Mitigation:** the new schema slugs are explicitly namespaced
(`agentcontrolframework` / `agentcompliancecontrol`, matching the existing `agentaifeature` /
`agentbudget` collision-avoidance convention) and never referred to as bare "Control" in UI copy
("compliance control" throughout); see design.md for the full rationale.

### Risk 3: Seeded control text could drift from the cited regulation without anyone noticing
**Severity:** Low — **Mitigation:** each `Control` carries its `sourceUrl`; the dashboard links out
to it so a reader can verify the source directly rather than trusting Hermiq's paraphrase.

## Rollback Strategy
Additive only. No existing schema, service, or endpoint is modified. Rollback removes the two new
schemas from `hermiq_register.json` (or leaves them unused), removes `ComplianceService`/
`ComplianceController`/the repair step, and removes the two new Vue surfaces — no other governance
feature (`ai-feature-governance-register`, `multi-tenant-ops`, `human-approval-gate-enforcement`,
`tenant-model-policy`) is touched.

## Open Questions
- Should the seeded catalogue be admin-editable (add/edit controls via UI) in this change, or
  strictly seed-only until a later change adds catalogue management? Provisional: seed-only here —
  editing a legal-citation catalogue is a distinct, lower-urgency concern; see DEFERRED_DECISIONS.
- Does the factsheet need a PDF/print view, or is the existing JSON-download pattern sufficient for
  a first version? Provisional: JSON only, matching the Art. 12 export precedent; PDF rendering is
  deferred.
