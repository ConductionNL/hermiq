## ADDED Requirements

### Requirement: The register lists the tenant's high-risk AI features

The system MUST provide a design-time register of the AI features the platform offers,
persisted as `AiFeature` OpenRegister objects (Schema.org type `schema:SoftwareApplication`),
each carrying a machine-readable `slug`, a human `name`, a plain-language `description`,
an EU AI Act `riskCategory` (`minimal`|`limited`|`high`|`unacceptable`), a `lifecycle`
state (`disabled`|`enabled`, initial `disabled`), the owning `tenantId`, and the DPO-ack
audit fields `dpoAckBy`/`dpoAckAt`. Reads MUST go through OpenRegister `ObjectService`
(OCP: `IUserSession` resolves the caller) so OpenRegister's native RBAC + multi-tenancy
scope the list to the caller's tenant; a caller MUST NOT see another tenant's features.

#### Scenario: The register returns the tenant's features risk-classified

- **WHEN** an authenticated user opens the AI-feature register
- **THEN** the system MUST return that tenant's `AiFeature` objects, each with its
  `slug`, `name`, `riskCategory`, `lifecycle` state, and any recorded `dpoAckBy`/`dpoAckAt`
- **AND** features owned by other tenants MUST NOT appear in the list

@e2e exclude backend-list read covered by the controller unit test + live verification; Playwright UI coverage deferred to a follow-up.

### Requirement: Enabling a high-risk feature is blocked until the DPO acknowledges it

The system MUST NOT allow the `enable` lifecycle transition (`disabled`→`enabled`) on an
`AiFeature` until the tenant's Data Protection Officer acknowledgement has been recorded.
The gate MUST be the declarative `x-openregister-lifecycle` `enable` transition whose
`requires` names the PHP guard `OCA\Hermiq\Lifecycle\AiFeatureDpoAckGuard`; the guard
MUST resolve the acknowledgement from `IAppConfig` key `dpo_ack.<tenantId>.<slug>` (or
legacy `dpo_ack.<slug>` when no tenant) for app `hermiq` and MUST return `false` (blocking
the transition) when that value is empty or the object has no `slug` (fail-closed).

#### Scenario: Enable is refused for a feature with no DPO acknowledgement

- **GIVEN** an `AiFeature` in `lifecycle=disabled` with no `dpo_ack.<tenantId>.<slug>` value set
- **WHEN** a permitted caller invokes the enable action for that feature
- **THEN** the lifecycle engine MUST run `AiFeatureDpoAckGuard`, the guard MUST return `false`
- **AND** the transition MUST be refused (feature stays `disabled`) with the guard identified in the response

@e2e exclude lifecycle-guard behaviour is unit-tested (not-acknowledged→false) + verified live; no Playwright surface asserts the guard directly.

### Requirement: Recording the DPO acknowledgement unblocks enablement

The system MUST let a permitted caller record the DPO acknowledgement for a feature. On
acknowledgement the system MUST write the `IAppConfig` value `dpo_ack.<tenantId>.<slug>`
(app `hermiq`) to a non-empty audit string (the acknowledging uid + timestamp) and MUST
stamp `dpoAckBy` (the uid) and `dpoAckAt` (the timestamp) onto the `AiFeature` object via
`ObjectService` (single write-path, ADR-004). After acknowledgement, the `enable`
transition MUST succeed for that feature and tenant.

#### Scenario: After acknowledgement the feature can be enabled

- **GIVEN** a `disabled` high-risk `AiFeature` whose enable was previously refused
- **WHEN** a permitted caller records the DPO acknowledgement for it
- **THEN** `IAppConfig dpo_ack.<tenantId>.<slug>` MUST be non-empty and the object MUST carry `dpoAckBy`/`dpoAckAt`
- **AND** a subsequent enable action MUST transition the feature to `lifecycle=enabled`

@e2e exclude acknowledge→enable happy path is covered by the guard unit test (acknowledged→true) + live verification; Playwright coverage deferred.

### Requirement: Disabling an enabled feature is unrestricted

The system MUST allow the `disable` lifecycle transition (`enabled`→`disabled`) on an
`AiFeature` with no DPO-ack requirement — the `x-openregister-lifecycle` `disable`
transition carries no guard, so a permitted caller can always turn a feature off. The
recorded acknowledgement MUST NOT be erased by disabling (re-enabling later does not
require re-acknowledging within the same tenant/slug).

#### Scenario: An enabled feature is disabled without a guard check

- **GIVEN** an `AiFeature` in `lifecycle=enabled`
- **WHEN** a permitted caller invokes the disable action
- **THEN** the feature MUST transition to `lifecycle=disabled` with no guard evaluated
- **AND** the existing `dpo_ack.<tenantId>.<slug>` value MUST remain intact

@e2e exclude disable transition (no guard) is covered by the controller unit test + live verification; no dedicated Playwright surface.

### Requirement: Acknowledge, enable, and disable are restricted to admins or the DPO role

The acknowledge, enable, and disable actions MUST NOT be invocable by any authenticated
user. Each mutating controller method (`@NoAdminRequired` per NC middleware) MUST gate its
body through hermiq's action authorization (OCP: `IGroupManager` via `ActionAuthService`,
ADR-023) with the action names `aifeature.acknowledge` / `aifeature.enable` /
`aifeature.disable`, which seed to `["admin"]` (admin-only) and MAY be broadened by an
admin to a DPO group. A caller whose groups do not intersect the action's allowed set
MUST be refused (403 `OCSForbiddenException`); an unauthenticated caller MUST get 401.

#### Scenario: A non-admin, non-DPO user cannot acknowledge or enable

- **GIVEN** an authenticated user who is neither an instance admin nor a member of any group mapped to the `aifeature.*` actions
- **WHEN** they call the acknowledge, enable, or disable endpoint
- **THEN** the system MUST refuse with 403 and MUST NOT record the acknowledgement or perform the transition

#### Scenario: An admin (or a broadened DPO group member) can acknowledge and enable

- **GIVEN** an instance admin, or a user in a group an admin has mapped to `aifeature.acknowledge`/`aifeature.enable`
- **WHEN** they record the acknowledgement and then enable the feature
- **THEN** the acknowledgement MUST be recorded and the enable transition MUST succeed

@e2e exclude the action-authorization guard is asserted by the controller unit test (requireAction invoked; non-admin→403) against OCP stubs; no live Playwright login-matrix test.
