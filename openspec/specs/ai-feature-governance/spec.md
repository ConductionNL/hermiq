# AI Feature Governance Specification

**Status**: active

**Feature tier**: V1

**OpenSpec changes:** `ai-feature-governance-register` — DONE (17 of 17 tasks): the `AiFeature`
register with its EU AI Act risk category and lifecycle state, the declarative
`AiFeatureDpoAckGuard` that fails closed until a Data Protection Officer acknowledgement is
recorded, the acknowledgement write path, and admin-or-DPO restriction on acknowledge, enable and
disable.

## Purpose

A design-time register of the AI features this instance offers, and a gate that keeps a high-risk
one switched off until the tenant's Data Protection Officer has signed for it.

The gate is declarative, on the lifecycle transition itself, rather than a check in a controller.
An imperative check protects the one call site that remembers to make it. This one protects the
transition, so every path to `enabled` meets it.

## Requirements

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

### Requirement: An administrator chooses the provider of one AI feature (REQ-AIBIND-001)

The AI-feature register MUST offer "Change provider" on every feature. The dialog MUST offer only the providers and models the organisation's model policy allows, and a residency. Saving MUST call `PUT /api/ai-features/{id}/binding`; the register MUST then show the provider, model and residency the server stored. A refusal from the server MUST be shown in the dialog in the server's words and MUST leave the row unchanged. "Use the organisation default" MUST clear the binding.

#### Scenario: An administrator puts summaries on a local model
- GIVEN an administrator on the AI-feature register, and a model policy that allows ollama and openai
- WHEN they choose "Change provider" on "Summarise a case", pick ollama with the model llama3 and save
- THEN the register shows "Summarise a case" running on ollama, llama3, on the instance

#### Scenario: A binding the policy does not allow
- GIVEN the policy changed after the dialog opened and no longer allows openai
- WHEN the administrator saves openai for "Translate a message"
- THEN the dialog shows the server's refusal naming the policy and the register still shows the old provider

#### Scenario: Back to the default
- GIVEN a feature bound to ollama
- WHEN the administrator chooses "Use the organisation default" and saves
- THEN the register shows the feature running on the policy default

### Requirement: An AI feature MAY bind its own provider and model

The system MUST let an `AiFeature` carry an optional `provider` and `model`. When
both are set, runs of that feature MUST use them. When they are unset, the feature
MUST fall back to the organisation's effective `ModelPolicy` default, which is the
behaviour today.

A binding MUST NOT be writable when the organisation's effective `ModelPolicy` does
not permit the pair, and the refusal MUST name the policy that forbids it.

Candidate C-integrations-38 (`integrations.tsv:34`), relevance **`must`**, driven
passer zammad: AI provider and features at `config/routes/ai_provider_connection.rb`,
`ai_agent.rb`, `ai_text_tool.rb` and `ai_vector_index.rb`, admin areas `AI::Provider`
and `AI::Assistance`, permissions `admin.ai_provider` and
`admin.ai_assistance_ticket_summary`.

#### Scenario: Two features, two providers

- **GIVEN** an organisation whose `ModelPolicy` permits both `ollama` and `openai`
- **WHEN** one feature is bound to `ollama` and another to `openai`
- **THEN** each feature's runs MUST use its own provider

#### Scenario: A binding outside the policy is refused at write time

- **GIVEN** an organisation whose `ModelPolicy` permits only `ollama`
- **WHEN** a feature is bound to `openai`
- **THEN** the write MUST be refused, naming the policy

#### Scenario: An unbound feature keeps today's behaviour

- **GIVEN** a feature with no provider or model set
- **WHEN** it runs
- **THEN** the effective `ModelPolicy` default MUST be used

### Requirement: The feature binding narrows the policy and is re-checked on every turn

The system MUST resolve a run's `(provider, model)` pair as: the feature's binding
when set, otherwise the effective `ModelPolicy` default, and MUST then check the
resolved pair against the effective `ModelPolicy` on **every** turn, whatever
triggered the run.

A policy narrowed after a binding was written MUST take effect without the feature
being revisited. A feature binding MUST NOT be able to widen what a policy permits.

#### Scenario: Narrowing the policy disables a stale binding

- **GIVEN** a feature bound to `openai` under a policy that permitted it
- **WHEN** the organisation's policy is narrowed to `ollama` only
- **THEN** the next run of that feature MUST be refused, naming the policy, with no
  edit to the feature

#### Scenario: One enforcement point, whatever the trigger

- **GIVEN** a feature whose binding is outside the current policy
- **WHEN** it is run from a schedule tick, a manual run and an interactive turn
- **THEN** all three MUST be refused identically

### Requirement: A configured provider MUST declare where it runs

The system MUST let each configured provider carry a `residency` of `on-premise`,
`eu` or `outside-eu`, plus a free-text `location`. The residency MUST be administered
by whoever configures the provider and MUST NOT be inferred from an endpoint hostname
or address.

A `.eu` hostname resolving outside the EU, and a local reverse proxy in front of a
hosted model, are both ordinary. A guessed residency is one no data protection officer
can rely on, and reliance is the whole purpose of the field.

#### Scenario: Residency is a statement, not a guess

- **WHEN** a provider is configured with an endpoint whose hostname suggests one
  region
- **THEN** the system MUST NOT set a residency from that hostname, and MUST require
  the administrator to state it

#### Scenario: The detail an enum cannot carry is kept

- **GIVEN** a provider declared `eu`
- **WHEN** its location is read
- **THEN** the free-text location the administrator typed MUST be returned with it

### Requirement: A feature MAY require a residency, and a run outside it is refused before the call

The system MUST let an `AiFeature` declare a `requiredResidency`. When it is set, a
run whose resolved provider carries a different residency MUST be refused **before**
any request reaches the provider. The refusal MUST name the feature, the required
residency and the provider's actual residency.

The check MUST run in the same place as the model-policy check, in the order: resolve
the feature binding, narrow by policy, check residency, then call. Each step MUST name
itself when it refuses.

A run refused after the text has been sent records a breach rather than preventing
one, which is why the ordering is specified rather than left to the implementation.

#### Scenario: Case text never leaves for a forbidden region

- **GIVEN** a feature requiring `on-premise` and a provider carrying `outside-eu`
- **WHEN** the feature runs
- **THEN** no request MUST reach the provider, and the refusal MUST name both
  residencies

#### Scenario: The refusing step names itself

- **GIVEN** a run refused on residency and another refused on model policy
- **WHEN** each refusal is read
- **THEN** each MUST say which check refused it

#### Scenario: No required residency refuses nothing

- **GIVEN** a feature with no `requiredResidency`
- **WHEN** it runs against any permitted provider
- **THEN** no residency refusal MUST occur

### Requirement: Every run MUST record the feature, the provider and the residency in force

The system MUST record, on each run entry written to the audit trail, the AI feature
the run belongs to, the provider and model actually used, and the provider's residency
and location **as they stood at the time of the run**.

The residency MUST be copied onto the run rather than referenced, so relabelling a
provider later MUST NOT change what earlier runs say.

#### Scenario: Which model saw this case, and where, is a read

- **GIVEN** a completed run
- **WHEN** its audit entry is read
- **THEN** it MUST carry the feature, the provider, the model, the residency and the
  location

#### Scenario: Relabelling a provider does not rewrite history

- **GIVEN** runs recorded while a provider was labelled `eu`
- **WHEN** the provider is relabelled `outside-eu`
- **THEN** those earlier runs MUST still read `eu`

### Requirement: A split deployment MUST be expressible as configuration

An instance running on premise MUST be able to bind a feature to a provider whose
residency is `eu` or `outside-eu`, with no change to where its objects are stored. The
residency label MUST make visible exactly which features send data off the premises.

Candidate C-configuration-75 (`configuration.tsv:111`), relevance `could`. **No
driven passer.** The evidence is decos-join's documented `/zaaksysteem/joni-plus`
page, admitted under decision D21 and labelled documented here.

#### Scenario: The case system stays where it is

- **GIVEN** an on-premise instance with one feature bound to a hosted provider
- **WHEN** that feature runs
- **THEN** only that feature's text MUST reach the hosted provider, and no object
  storage MUST move

#### Scenario: What leaves the building is readable in one place

- **WHEN** an administrator reads the AI feature register
- **THEN** each feature MUST show the residency of the provider it will use

### Requirement: The register records whether a feature's outputs are labelled as AI-made

The `AiFeature` schema SHALL carry `outputsLabelled` (boolean, default `false`) and `outputLabelling` (text) so a DPO can see, per feature, whether the people who read its outputs are told AI made them (EU AI Act Art. 50) and how. The `message-translation` feature SHALL be seeded with `outputsLabelled: true` and an `outputLabelling` text naming the provenance fields and the disclosure sentence. The seed step SHALL back-fill both fields on an existing `message-translation` row that lacks them, and SHALL leave a row that already has them untouched. The entity is a schema.org `SoftwareApplication`; `outputsLabelled` maps to a `PropertyValue`.

#### Scenario: A fresh install records that translations are labelled

- GIVEN no `message-translation` feature exists
- WHEN the `SeedMessageTranslationFeature` repair step runs
- THEN the created row MUST contain `outputsLabelled: true`
- AND a non-empty `outputLabelling` text

#### Scenario: A row seeded before this change is back-filled once

- GIVEN a `message-translation` row exists without `outputsLabelled`
- WHEN the repair step runs
- THEN the row MUST be saved with `outputsLabelled: true` and the `outputLabelling` text
- AND every other field of the row MUST keep its value, including `lifecycle`

#### Scenario: A row that already records labelling is left alone

- GIVEN a `message-translation` row with `outputsLabelled: true`
- WHEN the repair step runs
- THEN no save MUST happen

#### Scenario: A feature that says nothing about labelling reads as not labelled

- GIVEN an `AiFeature` row created without `outputsLabelled`
- WHEN the register is read
- THEN `outputsLabelled` MUST default to `false`
