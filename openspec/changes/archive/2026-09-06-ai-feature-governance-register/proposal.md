---
kind: code
---

# Proposal: ai-feature-governance-register

## Why

Hermiq already has **runtime** governance for autonomous agents (ADR-004): every
run, LLM call, tool invocation, and approval decision is written to OpenRegister's
tamper-evident `AuditTrail`, a per-schedule human-approval gate (`Approval`) enforces
EU AI Act Art. 14 oversight, and a per-organisation `TenantControl` kill-switch can
halt a tenant. What it lacks is the **design-time** layer the AI Act also demands: a
durable, tenant-scoped **inventory of the high-risk AI features the platform provides**,
each risk-classified (EU AI Act) and gated so a high-risk feature cannot be *enabled*
until the tenant's **Data Protection Officer (DPO) has acknowledged it in writing**.
Scholiq already proved this shape with its `AiFeature` schema + DPO-ack lifecycle guard;
this change **ports it to hermiq and generalises it** so any Conduction app's AI
features can be catalogued centrally — hermiq becomes the fleet home for AI-feature
governance (scholiq's `AiFeature` later delegates here).

## What Changes

- Add a new declarative OpenRegister schema **`AiFeature`** to
  `lib/Settings/hermiq_register.json`: `slug`, `name`, `description`, `riskCategory`
  (enum `minimal`|`limited`|`high`|`unacceptable`), `lifecycle` (enum
  `disabled`|`enabled`, default `disabled`), plus hermiq multi-tenancy (`tenantId`) and
  DPO-ack audit fields (`dpoAckBy`, `dpoAckAt`). The schema carries an
  `x-openregister-lifecycle` block: the `enable` transition (`disabled`→`enabled`)
  `requires` the hermiq DPO-ack guard; the `disable` transition (`enabled`→`disabled`)
  is free. This is the declarative side (ADR-031).
- Add a PHP lifecycle guard **`lib/Lifecycle/AiFeatureDpoAckGuard.php`** (a new `lib/`
  namespace hermiq does not have yet) — ported from scholiq's guard. It reads the
  `IAppConfig` key `dpo_ack.<tenantId>.<slug>` (or legacy `dpo_ack.<slug>`) and blocks
  the `enable` transition unless that value is non-empty (ADR-031 legitimate
  business-rule seam; OpenRegister resolves it by FQCN from the schema's `requires`).
- Add **`lib/Controller/AiFeatureController.php`** (modelled on `ApprovalController`):
  `index()` (list the tenant's `AiFeature` objects), `acknowledge(slug)` (record the
  DPO acknowledgement: write `IAppConfig dpo_ack.<tenant>.<slug>` and stamp
  `dpoAckBy`/`dpoAckAt` on the object), `enable(id)` / `disable(id)` (drive the
  lifecycle transition). Every mutating method is gated through hermiq's
  `ActionAuthService` (ADR-023) so acknowledge/enable/disable are **admin/DPO-only**,
  not any authenticated user (OWASP A01 / ADR-005 Rule 3). Routes in
  `appinfo/routes.php`.
- Add a custom frontend view **`src/views/AiFeatureRegister.vue`** + API module
  **`src/api/aiFeatures.js`** (modelled on `ApprovalInbox.vue` / `src/api/approvals.js`):
  a table of AI features with a risk-category badge and lifecycle state, and the
  actions "Acknowledge (DPO)", "Enable" (disabled until acknowledged), "Disable".
  Registered in `src/registry.js` and wired via a `type:"custom"` page + menu entry in
  `src/manifest.json`.
- **Seed 2–3 realistic `AiFeature` objects** (ADR-001/ADR-003) via a repair step
  writing through `ObjectService` (single write-path, ADR-004) — e.g. "Autonomous agent
  run" (high), "Skill code execution" (high), "Chat companion" (limited).
- Add PHPUnit tests: the DPO-ack guard (acknowledged→true, not-acknowledged→false,
  missing-slug→false, tenant-scoped key) and the controller auth guard.

**Runtime governance (ADR-004) is unchanged** — this change adds the *design-time*
register that complements it; every acknowledge/enable/disable is itself a governance
action recorded through the same `ObjectService` single write-path and auto-audited.

## Capabilities

### New Capabilities
- `ai-feature-governance`: the design-time high-risk AI-feature register — the
  `AiFeature` schema + DPO-ack lifecycle gate. Covers listing the tenant's features
  risk-classified per the EU AI Act, the rule that a high-risk feature cannot be
  enabled until its DPO acknowledgement is recorded, that acknowledgement unblocks
  enablement, that disabling is free, and that acknowledge/enable/disable are restricted
  to admins / the DPO role (never any authenticated user).

### Modified Capabilities
<!-- None. Runtime governance (human-approval-gate, multi-tenant-ops) is unchanged;
     this is a new design-time concern with its own capability. -->
- <!-- none -->

## Impact

- **Config:** `lib/Settings/hermiq_register.json` gains an `AiFeature` entry under
  `components.schemas` with an `x-openregister-lifecycle` block (union import, existing
  schemas untouched — no regression).
- **Code:** new `lib/Lifecycle/AiFeatureDpoAckGuard.php`, `lib/Controller/AiFeatureController.php`,
  a `lib/Service/AiFeatureService.php` (or the existing setup service — see design),
  a seed repair step, `src/views/AiFeatureRegister.vue`, `src/api/aiFeatures.js`, and
  new routes in `appinfo/routes.php`.
- **Config store:** `IAppConfig` gains `dpo_ack.<tenantId>.<slug>` keys (one per DPO
  acknowledgement); `ActionAuthService` gains `aifeature.*` actions (default `["admin"]`).
- **Dependencies:** OpenRegister (existing) for the lifecycle engine that resolves the
  guard FQCN; no new external dependency. Scholiq's `AiFeature` later delegates here.
- **Data:** OpenRegister creates a magic table for `AiFeature` in the `hermiq` register
  on import; the seed step writes 2–3 objects idempotently.
