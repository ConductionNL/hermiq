# Design: ai-feature-governance-register

## Context

Hermiq's governance today is **runtime**: `ScheduleService` dispatches agents, the
`Approval`/`TenantControl` schemas + `human-approval-gate-enforcement` give the Art. 14
human gate and per-org kill-switch, and everything is audited through OpenRegister's
hash-chained `AuditTrail` via the single `ObjectService` write-path (ADR-004). There is
no **design-time** artifact answering "which high-risk AI features does this platform
provide, how are they risk-classified under the EU AI Act, and who signed off before
each was switched on?" — the AI Act's registration/oversight obligations expect exactly
that inventory.

Scholiq already built this shape: an `AiFeature` OpenRegister schema (`slug`, `name`,
`description`, `riskCategory`, `lifecycle`) with an `x-openregister-lifecycle` block
whose `enable` transition `requires` a PHP guard (`AiFeatureDpoAckGuard`) that blocks
activation until the DPO has acknowledged the feature via an `IAppConfig` key. This
change **ports that to hermiq**, adapts it to hermiq's conventions (lowercase schema
slug, `ActionAuthService` action-auth, `IUserSession`/`ObjectService` controllers, the
v2 manifest/registry Vue shell), adds hermiq multi-tenancy (`tenantId`) and DPO-ack
audit fields, and positions hermiq as the **fleet home** for AI-feature governance so
scholiq's `AiFeature` later delegates here.

Established hermiq conventions this change follows (verified in the working tree):
- **Schema house-style** (`lib/Settings/hermiq_register.json`): each schema has a
  lowercase `slug` (`approval`, `tenantcontrol`, `agentskill`, `agentsession`), a
  Material-Design `icon`, `version` `0.1.0`, `title`, `description`, `type: object`,
  a `required` array, and `"x-openregister": { "publicRead": false, "publicWrite": false }`.
  No schema yet carries `x-openregister-lifecycle` — this change introduces the first.
- **Controller auth pattern**: `ApprovalController`/`SkillController` use the
  `@NoAdminRequired` + `@NoCSRFRequired` docblock posture (not PHP attributes), inject
  `IUserSession` + a service + `LoggerInterface`, 401 on no user, and gate mutations
  explicitly in the body — either a per-object IDOR guard (ApprovalController's
  `ensureDecidableApproval` / `isReviewer`) or `ActionAuthService::requireAction()`
  (ADR-023). `ActionAuthService` + `lib/actions.seed.json` already exist.
- **View/API pattern**: a `type:"custom"` page component under `src/views/` registered
  in `src/registry.js` (kind `page`), a menu + page entry in `src/manifest.json` (there
  is **no** `manifest.d/` — the manifest is edited directly), and a plain
  stateless `src/api/*.js` module using `@nextcloud/axios` + `generateUrl` (no custom
  Pinia store).
- **Seed / register mechanism**: the register JSON is imported by `InitializeSettings`
  (a repair step) via `ConfigurationService::importFromApp()`. Repair steps
  (`InitializeSettings`, `InitializeActions`) are the hermiq idiom for install/upgrade
  bootstrapping; no seeded objects exist in the tree yet.
- **Tests**: `tests/Unit/{Service,Controller}/*Test.php`, namespace
  `OCA\Hermiq\Tests\Unit\…`, extending `PHPUnit\Framework\TestCase`
  (`ActionAuthServiceTest`, `ApprovalControllerTest` are the models).

## Goals / Non-Goals

**Goals:**
- Declare an `AiFeature` OpenRegister schema mirroring scholiq's five props plus hermiq
  multi-tenancy (`tenantId`) and DPO-ack audit fields (`dpoAckBy`, `dpoAckAt`), with a
  declarative `x-openregister-lifecycle` gate on the `enable` transition.
- Port the DPO-ack lifecycle guard to hermiq (`OCA\Hermiq\Lifecycle\AiFeatureDpoAckGuard`,
  `IAppConfig` app `hermiq`, key `dpo_ack.<tenantId>.<slug>`).
- Provide a thin controller (list / acknowledge / enable / disable) whose mutating
  methods are gated to admins / the DPO role via `ActionAuthService` (ADR-023).
- Provide a custom Vue view + API module to browse the register and drive the actions.
- Seed 2–3 realistic `AiFeature` objects through `ObjectService` (single write-path).
- Unit-test the guard and the controller auth guard.

**Non-Goals:**
- No change to runtime governance (`Approval`, `TenantControl`, dispatcher, AuditTrail) —
  ADR-004 is unchanged.
- No cross-app delegation wiring in this change (scholiq→hermiq delegation is future
  work; this change only establishes the fleet home and its schema/API).
- No new external dependency and no bespoke audit store — the OpenRegister lifecycle
  engine auto-audits the transition; the ack-write is recorded via `ObjectService`.
- No editing of shared OpenRegister stubs to pass tests (no mock-based fixes).

## Decisions

**Schema shape + slug.** The `AiFeature` schema lands in
`lib/Settings/hermiq_register.json` under `components.schemas` with the namespaced slug
`agentaifeature`. OpenRegister resolves schemas by GLOBAL `lower(slug)`, and scholiq
already ships an `AiFeature` schema (`lower(slug) = aifeature`) — a plain `aifeature`
slug silently collides with it (the register import no-ops, the schema is never created).
hermiq already namespaces its own collision-prone schemas with an `agent` prefix
(`agentsession`, `agentsessionturn`, `agentskill`, `agentskillsource`), so `AiFeature`
follows the same rule as `agentaifeature`. (Verified live: a plain `aifeature` import
resolved to scholiq's schema 27 and created nothing; the namespaced slug creates a
distinct hermiq schema cleanly.) Icon `RobotOutline`, version `0.1.0`,
`required: ["slug", "name"]`, and
`"x-openregister": { "publicRead": false, "publicWrite": false }`. Properties:
`slug` (string; the machine id + `IAppConfig` key suffix), `name` (string),
`description` (string), `riskCategory` (enum `minimal`|`limited`|`high`|`unacceptable`),
`lifecycle` (enum `disabled`|`enabled`, default `disabled`; managed by the engine, not
set directly), `tenantId` (string; the hermiq multi-tenancy scope used to key the
DPO-ack), `dpoAckBy` (string uid — who acknowledged), `dpoAckAt` (date-time — when). The
schema is kept flat (no `if`/`then`/`allOf` — the OpenRegister importer rejects
conditionals). Tenant scope also comes from `ObjectEntity.organisation` at the data
layer; `tenantId` is the explicit key the guard reads.

**Declarative lifecycle (ADR-031 declarative side).** The activation gate is a
declarative `x-openregister-lifecycle` block on the schema — not imperative controller
logic:

```json
"x-openregister-lifecycle": {
  "property": "lifecycle",
  "initial": "disabled",
  "transitions": {
    "enable":  { "from": "disabled", "to": "enabled",
                 "requires": ["OCA\\Hermiq\\Lifecycle\\AiFeatureDpoAckGuard"] },
    "disable": { "from": "enabled",  "to": "disabled" }
  }
}
```

OpenRegister's lifecycle engine resolves `requires` by FQCN via DI and calls the guard's
`check()` before executing the `enable` transition. No `Application.php` registration is
needed for the guard itself (DI autoloads it). `disable` is free.

**DPO-ack guard = imperative business-rule seam (ADR-031 justified exception).** The
"has the DPO acknowledged this feature?" check cannot be expressed declaratively — it is
an external-config lookup — so it is a PHP guard, exactly the seam ADR-031 permits.
`AiFeatureDpoAckGuard::check($transitionContext)` reads the object's `slug` and tenant
(`tenantId`, falling back to scholiq's `tenant_id`), builds the `IAppConfig` key
`dpo_ack.<tenantId>.<slug>` (or legacy `dpo_ack.<slug>` when tenant is empty), and
returns `true` iff `IAppConfig->getValueString('hermiq', key, '')` is non-empty; a
missing slug returns `false` (fail-closed). No `AuditTrail::record()` in the guard — the
lifecycle engine emits the transition audit entry automatically. *Alternative
considered:* encode the ack as a boolean property on the object and gate on it
declaratively — rejected, the acknowledgement is a separately-authorised act by a
different party (the DPO) and belongs in `IAppConfig` (an admin/DPO-only config write),
not in the tenant-writable object body.

**Controller auth model = ActionAuthService (ADR-023), mirroring ApprovalController's
posture.** `AiFeatureController` copies `ApprovalController`'s shape: `@NoAdminRequired` +
`@NoCSRFRequired`, injected `IUserSession` + service + `LoggerInterface`, 401 when no
user. Because acknowledge/enable/disable are **governance actions that must be restricted
to admins or the DPO role — not any authenticated user** (OWASP A01 IDOR / ADR-005
Rule 3), each mutating method body calls
`$this->actionAuth->requireAction($user, 'aifeature.<action>')` before doing work.
Per ADR-023 the actions seed to `["admin"]` (first-install safe: admin-only), and an
admin can broaden `aifeature.acknowledge` to a `dpo` group via the action matrix — this
is why acknowledge is an ADR-023 *action* (delegable to a DPO role) rather than a plain
`#[AuthorizedAdminSetting]` even though it writes `IAppConfig`. `index()` lists the
tenant's own features and relies on OpenRegister RBAC + tenancy (like `SkillController`);
it may additionally require `aifeature.view` (default admin, broadenable). Actions:

```json
// lib/actions.seed.json (added to the "actions" object)
"aifeature.acknowledge": ["admin"],
"aifeature.enable":      ["admin"],
"aifeature.disable":     ["admin"]
```

**Acknowledge write-path.** `acknowledge(slug)` (1) resolves the caller + tenant,
(2) `requireAction('aifeature.acknowledge')`, (3) writes `IAppConfig`
`dpo_ack.<tenant>.<slug>` = `"<uid>@<ISO-8601 timestamp>"` (a non-empty audit string the
guard treats as "acknowledged"), and (4) loads the matching `AiFeature` object via
`ObjectService` and stamps `dpoAckBy`/`dpoAckAt`, saving through `ObjectService` (single
write-path, ADR-004 — the stamp is auto-audited). The `IAppConfig` write is the
authoritative ack the guard reads; the object stamp is the human-readable mirror shown
in the UI.

**Enable/disable drive the declarative transition.** `enable(id)`/`disable(id)` load the
`AiFeature` by id via `ObjectService` (RBAC-scoped, 404 for cross-tenant),
`requireAction('aifeature.enable'|'aifeature.disable')`, then invoke the lifecycle
transition through OpenRegister (the engine runs the guard on `enable`; a not-yet-acked
feature returns the guard failure, surfaced as HTTP 409/422 with the guard name).
`disable` has no guard and always succeeds from `enabled`.

**Frontend = custom page + stateless API module.** `src/views/AiFeatureRegister.vue`
(registered in `src/registry.js` as `{ kind: 'page' }`, referenced by a `type:"custom"`
page + a menu entry in `src/manifest.json`; there is no `manifest.d/` so the manifest is
edited directly) renders a table of features: name, a risk-category badge
(`minimal`/`limited`/`high`/`unacceptable`), the lifecycle state, and per-row actions
"Acknowledge (DPO)", "Enable" (disabled until `dpoAckAt`/ack present), "Disable". It
follows ADR-004 frontend rules: modals in their own files under `src/modals/`, all
strings via `t()`, server data via `loadState`/`IInitialState` (never DOM reads), and
`inputLabel` on any `NcSelect`. `src/api/aiFeatures.js` mirrors `src/api/approvals.js`:
plain exported functions over `@nextcloud/axios` + `generateUrl` (`listAiFeatures`,
`acknowledgeAiFeature(slug)`, `enableAiFeature(id)`, `disableAiFeature(id)`), no
`defineStore`.

**Seed data (ADR-001/ADR-003) via a repair step through ObjectService.** A repair step
`lib/Repair/SeedAiFeatures.php` (alongside `InitializeSettings`/`InitializeActions`)
idempotently writes 2–3 realistic `AiFeature` objects through `ObjectService` (single
write-path, ADR-004 — never a bespoke insert), skipping any whose `slug` already exists:
- `autonomous-agent-run` — "Autonomous agent run", `riskCategory: high`, `lifecycle: disabled`.
- `skill-code-execution` — "Skill code execution", `riskCategory: high`, `lifecycle: disabled`.
- `chat-companion` — "Chat companion", `riskCategory: limited`, `lifecycle: disabled`.
Placeholders use the nil UUID / `YOUR_*_HERE` where an id is otherwise required. *Alternative
considered:* an `objects[]` fragment inside `hermiq_register.json` imported by
`ConfigurationService` — viable (OpenRegister imports fragment objects live on repair)
but hermiq has never used it; the repair-step-through-`ObjectService` route matches the
existing `Initialize*` idiom and keeps the single write-path explicit.

**Declarative-vs-imperative summary (ADR-031).** The activation *state machine* is
declarative (`x-openregister-lifecycle`). The DPO-ack *check* is an imperative PHP guard
— a justified business-rule seam (external-config lookup that must run before the
transition). The ack *write* is an imperative controller action — a justified
external-config write (`IAppConfig`) plus an object stamp via the single `ObjectService`
write-path. Nothing bypasses `ObjectService` for object persistence, so tenancy + the
hash-chained `AuditTrail` are inherited (ADR-004).

## Risks / Trade-offs

- **First schema with `x-openregister-lifecycle` in hermiq.** [The lifecycle engine +
  FQCN guard resolution are unexercised in this register] → Verify the `enable`/`disable`
  transitions and guard resolution live against the deployed OpenRegister before
  archiving; unit-test the guard in isolation against `IAppConfig` stubs (CI has no live
  OR).
- **Tenant-key mismatch between object and guard.** [If the object's `tenantId` differs
  from the value used at ack time, `enable` never unblocks] → `acknowledge` and the guard
  MUST derive the tenant identically (the object's `tenantId`); the guard falls back to
  the legacy unscoped key for single-tenant/no-tenant objects.
- **Acknowledge writes IAppConfig — an admin-only surface per ADR-023 Rule 3.** [Treating
  it as a delegable action rather than `#[AuthorizedAdminSetting]` widens who can write
  config] → It seeds to `["admin"]` (admin-only until an admin explicitly broadens to a
  DPO group), so the safe first-install posture is preserved and the broadening is an
  auditable admin choice.
- **Seed idempotency.** [A repair step re-running on upgrade could duplicate features] →
  The step checks for an existing object by `slug` (per tenant) and no-ops when present.
- **Enable is a real state change, not read-only.** [`enable` mutates governance state] →
  It is `ActionAuthService`-gated, RBAC-scoped (404 cross-tenant), and the transition +
  stamp are audited via `ObjectService`; the guard fails closed when the ack is absent.
