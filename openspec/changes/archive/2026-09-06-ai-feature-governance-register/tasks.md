# Tasks: ai-feature-governance-register

## 1. Declare the AiFeature schema + lifecycle (register patch)

- [x] 1.1 Add an `AiFeature` entry under `components.schemas` in `lib/Settings/hermiq_register.json` (namespaced `slug` `agentaifeature` — NOT plain `aifeature`, which collides with scholiq's `AiFeature` under OR's global `lower(slug)` resolution and silently no-ops the import; follows hermiq's `agentsession`/`agentskill` prefix convention — icon `RobotOutline`, `version` `0.1.0`, title, description, `type: object`, `required: ["slug","name"]`, `"x-openregister": { "publicRead": false, "publicWrite": false }`); bump the register JSON `info.version` so `loadConfiguration` re-imports on upgrade; use the Edit tool and re-parse the JSON after editing.
- [x] 1.2 Declare the properties `slug`, `name`, `description`, `riskCategory` (enum `minimal`|`limited`|`high`|`unacceptable`), `lifecycle` (enum `disabled`|`enabled`, default `disabled`), `tenantId`, `dpoAckBy`, `dpoAckAt` (date-time); keep the schema flat (no `if`/`then`/`allOf`).
- [x] 1.3 Add the `x-openregister-lifecycle` block: `property: lifecycle`, `initial: disabled`, transition `enable` (`disabled`→`enabled`, `requires: ["OCA\\Hermiq\\Lifecycle\\AiFeatureDpoAckGuard"]`) and transition `disable` (`enabled`→`disabled`, no guard).
- [x] 1.4 Re-validate `hermiq_register.json` as well-formed JSON and confirm every existing schema (`example`, `Schedule`, `Approval`, `TenantControl`, `Memory`, `Skill`, …) is unchanged (union import, no regression); import the register live via the repair step and confirm the `AiFeature` schema is created cleanly.

## 2. DPO-ack lifecycle guard (imperative business-rule seam, ADR-031)

- [x] 2.1 Create `lib/Lifecycle/AiFeatureDpoAckGuard.php` (new namespace `OCA\Hermiq\Lifecycle`, SPDX `@license`/`@copyright` docblock in hermiq house-style, inject `IAppConfig`): `check(array $transitionContext): bool` reads the object `slug` + tenant (`tenantId`, falling back to `tenant_id`), builds `IAppConfig` key `dpo_ack.<tenantId>.<slug>` (or legacy `dpo_ack.<slug>`), and returns `true` iff `getValueString('hermiq', key, '')` is non-empty; a missing slug returns `false` (fail-closed).

## 3. Controller + action-auth (ADR-023, mirror ApprovalController)

- [x] 3.1 Add the `aifeature.acknowledge` / `aifeature.enable` / `aifeature.disable` actions (default `["admin"]`) to `lib/actions.seed.json` (re-imported by `InitializeActions`).
- [x] 3.2 Create `lib/Controller/AiFeatureController.php` (SPDX docblock; `@NoAdminRequired` + `@NoCSRFRequired`; inject `IUserSession`, the AiFeature service, `ActionAuthService`, `LoggerInterface`; 401 when no user): `index()` lists the tenant's `AiFeature` objects via `ObjectService` (RBAC/tenancy-scoped, like `SkillController`).
- [x] 3.3 Implement `acknowledge(string $slug)`: `requireAction($user, 'aifeature.acknowledge')`, write `IAppConfig dpo_ack.<tenant>.<slug>` = `<uid>@<ISO-8601>`, load the matching `AiFeature` and stamp `dpoAckBy`/`dpoAckAt` via `ObjectService` (single write-path, ADR-004).
- [x] 3.4 Implement `enable(string $id)` / `disable(string $id)`: `requireAction($user, 'aifeature.enable'|'aifeature.disable')`, load the object RBAC-scoped (404 cross-tenant), drive the lifecycle transition through OpenRegister (the engine runs the guard on `enable`; surface the guard failure as 409/422).
- [x] 3.5 Register the routes in `appinfo/routes.php` with explicit auth (`aiFeature#index` GET `/api/ai-features`, `aiFeature#acknowledge` POST `/api/ai-features/{slug}/acknowledge`, `aiFeature#enable` POST `/api/ai-features/{id}/enable`, `aiFeature#disable` POST `/api/ai-features/{id}/disable`) — each route resolves to an existing method (route-auth + route-reachability gates PASS).

## 4. Frontend register view (ADR-004 frontend rules)

- [x] 4.1 Create `src/api/aiFeatures.js` (mirror `src/api/approvals.js`: stateless functions over `@nextcloud/axios` + `generateUrl`, no Pinia store): `listAiFeatures()`, `acknowledgeAiFeature(slug)`, `enableAiFeature(id)`, `disableAiFeature(id)`.
- [x] 4.2 Create `src/views/AiFeatureRegister.vue`: a table of features with a risk-category badge, lifecycle state, and per-row actions "Acknowledge (DPO)", "Enable" (disabled until acknowledged), "Disable"; all strings via `t()`, server data via `loadState` (no DOM reads), any `NcSelect` carries `inputLabel`, any modal in its own file under `src/modals/`.
- [x] 4.3 Register `AiFeatureRegister` in `src/registry.js` (`{ kind: 'page' }`) and add a menu entry + a `type:"custom"` page (component `AiFeatureRegister`, route `/ai-features`, with a `_note`) to `src/manifest.json` (no `manifest.d/` — edit the manifest directly).

## 5. Seed data (ADR-001/ADR-003, single write-path)

- [x] 5.1 Create a repair step `lib/Repair/SeedAiFeatures.php` that idempotently writes (skip when the `slug` already exists) the seed `AiFeature` objects via `ObjectService`: `autonomous-agent-run` (high), `skill-code-execution` (high), `chat-companion` (limited), each `lifecycle: disabled`; use nil UUID / `YOUR_*_HERE` placeholders where an id is otherwise required.

## 6. Tests + verification

- [x] 6.1 Add `tests/Unit/Lifecycle/AiFeatureDpoAckGuardTest.php` (namespace `OCA\Hermiq\Tests\Unit\Lifecycle`, extends `TestCase`, `IAppConfig` stub): acknowledged→`true`, not-acknowledged→`false`, missing-slug→`false`, and the tenant-scoped vs legacy key.
- [x] 6.2 Add `tests/Unit/Controller/AiFeatureControllerTest.php` (model on `ApprovalControllerTest`/`ActionAuthServiceTest`): assert each mutating method calls `requireAction(...)`, that a non-admin/non-DPO caller is refused (403), and that an unauthenticated caller gets 401; run PHPUnit the CI way (php:8.3-cli + OCP stubs).
- [x] 6.3 Verify live on NC + OpenRegister: enable is refused before acknowledgement (guard blocks), acknowledge records `dpo_ack.<tenant>.<slug>` + stamps `dpoAckBy`/`dpoAckAt`, enable then succeeds, disable is free, and a non-admin/non-DPO user is 403 on acknowledge/enable/disable.

## Acceptance criteria

- An `AiFeature` OpenRegister schema exists (`slug`, `name`, `description`, `riskCategory`, `lifecycle`, `tenantId`, `dpoAckBy`, `dpoAckAt`) with an `x-openregister-lifecycle` gate whose `enable` transition requires `AiFeatureDpoAckGuard`; existing schemas are unchanged after import.
- The register lists a tenant's features (risk-classified) via `ObjectService`; cross-tenant features are not shown.
- Enabling a high-risk feature is blocked until the DPO acknowledgement (`IAppConfig dpo_ack.<tenantId>.<slug>`) is recorded; after acknowledgement enable succeeds; disable is unguarded and never erases the acknowledgement.
- Acknowledge/enable/disable are gated via `ActionAuthService` (default admin-only, broadenable to a DPO group); a non-admin/non-DPO caller is refused (403), an unauthenticated caller 401.
- 2–3 realistic `AiFeature` objects are seeded idempotently through `ObjectService`; runtime governance (ADR-004) is unchanged.
- The DPO-ack guard and the controller auth guard are unit-tested, and the full acknowledge→enable→disable + auth flow is verified live.

## Quality reminders

- SPDX `@license`/`@copyright` tags inside each PHP file docblock; pass `composer check:strict`; add `@spec` docblock tags referencing this change's tasks/specs.
- Use the Edit tool (not sed/awk/scripts) to modify `hermiq_register.json`, `appinfo/routes.php`, `src/manifest.json`, and `src/registry.js`; re-parse JSON after each edit (a merge can silently dup-keys).
- Keep the schema flat — OpenRegister's importer rejects `if`/`then`/`allOf` conditionals; the activation rule is expressed by the lifecycle guard, not a schema conditional.
- Single write-path (ADR-004): all `AiFeature` object persistence goes through `ObjectService`; the guard writes no audit (the lifecycle engine auto-audits the transition), the ack write is `IAppConfig` + an object stamp.
- No stub bodies, no `var_dump`/`error_log`/`die`; do not edit shared OpenRegister stubs to pass tests (no mock-based fixes). Keep i18n keys in English source; nil UUID / `YOUR_*_HERE` for any placeholders.
