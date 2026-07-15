# algoritmeregister-publication Specification

## Purpose
TBD - created by archiving change algoritmeregister-publication. Update Purpose after archive.
## Requirements
### Requirement: High-risk AI features carry Algoritmekader publication metadata

An `AiFeature` MUST be able to hold the Dutch **Algoritmekader** metadata required for a
national Algoritmeregister entry, as an optional field group that is only mandatory at
publish time: `doel` (purpose), `wettelijkeGrondslag` (legal basis), `impacttoetsen`
(the impact assessments performed — IAMA / DPIA / FRAIA — with references),
`dataBronnen` (data sources/categories), `menselijkeTussenkomst` (human-oversight
description), `verantwoordelijke` (responsible organisation + contact), and
`publicatiecategorie` (Algoritmekader category). The object MUST also carry the
publication-state fields `algoritmeregisterStatus`
(`niet-gepubliceerd` | `gepubliceerd` | `ingetrokken`, initial `niet-gepubliceerd`) and
`algoritmeregisterRef` (the external register id/URL, empty until published). Cataloguing
an `AiFeature` (design-time governance) MUST NOT require these fields; only publication
does.

#### Scenario: An unpublished high-risk feature can record Algoritmekader metadata

- **WHEN** a permitted caller sets the Algoritmekader fields on a `high`-risk `AiFeature`
- **THEN** the fields persist on the object through OpenRegister `ObjectService`
- **AND** `algoritmeregisterStatus` remains `niet-gepubliceerd` until an explicit publish

@e2e exclude metadata persistence covered by the controller/service unit tests + live verification; Playwright surface deferred.

### Requirement: Publication is gated to impactful, enabled, DPO-acknowledged, fully-described features

The system MUST refuse to publish an `AiFeature` to the Algoritmeregister unless ALL hold:
`riskCategory` is `high` (impactful — `minimal`/`limited` features MUST be refused as
out of scope for the national register), `lifecycle` is `enabled`, the DPO acknowledgement
is recorded (per the governance register's DPO gate), and every mandatory Algoritmekader
field is present. A publish attempt that fails any condition MUST be refused fail-closed
and MUST identify the failing condition(s) (e.g. the list of missing mandatory fields) —
never a partial/placeholder national-register entry.

#### Scenario: Publishing a feature with missing legal basis is refused

- **GIVEN** a `high`-risk, `enabled`, DPO-acknowledged `AiFeature` with no `wettelijkeGrondslag`
- **WHEN** a permitted caller invokes publish-to-Algoritmeregister
- **THEN** the system MUST refuse the publication (status stays `niet-gepubliceerd`)
- **AND** the response MUST name `wettelijkeGrondslag` (and any other missing mandatory field)

@e2e exclude publish-gate branches are unit-tested against the readiness matrix; no direct Playwright assertion.

#### Scenario: Publishing a limited-risk feature is refused

- **GIVEN** an `AiFeature` with `riskCategory = limited`
- **WHEN** publish-to-Algoritmeregister is invoked
- **THEN** the system MUST refuse it as out of scope for the national register (only `high`)

@e2e exclude publish-gate branches are unit-tested against the readiness matrix; no direct Playwright assertion.

### Requirement: Publication is delegated to the fleet publication path, not re-implemented

Publishing to the Algoritmeregister MUST map the `AiFeature` to an Algoritmekader-conformant
publication and hand it to OpenCatalogi's publication surface (the fleet publication leaf /
OpenRegister published-predicate), resolved at runtime through the integration seam — hermiq
MUST NOT hard-code a hermiq→OpenCatalogi dependency, MUST NOT open its own connection to
algoritmes.overheid.nl, and MUST NOT re-implement a publication/harvest engine. When
OpenCatalogi is not installed, the publish action MUST be unavailable and the feature MUST
remain fully governable internally (graceful degradation). A successful publish MUST set
`algoritmeregisterStatus = gepubliceerd` and store the returned `algoritmeregisterRef`;
withdrawal MUST set `algoritmeregisterStatus = ingetrokken` and request unpublication.

#### Scenario: Publish delegates and records the external reference

- **GIVEN** a publishable `high`-risk `AiFeature` and OpenCatalogi installed
- **WHEN** a permitted caller publishes it
- **THEN** the system MUST map it to the Algoritmekader shape and hand it to OpenCatalogi's
  publication path (no direct national-portal call from hermiq)
- **AND** on success set `algoritmeregisterStatus = gepubliceerd` and store `algoritmeregisterRef`

@e2e exclude delegation is unit-tested with the integration seam stubbed present/absent; live cross-app publication verified once OpenCatalogi co-install exists.

#### Scenario: Publish is unavailable without OpenCatalogi

- **GIVEN** OpenCatalogi is not installed
- **WHEN** the AI-feature register is opened
- **THEN** the publish-to-Algoritmeregister action MUST be unavailable (not an error)
- **AND** the feature MUST stay catalogued, risk-classified, and DPO-gated internally

@e2e exclude delegation + degradation are unit-tested with the integration seam stubbed present/absent; live cross-app publication verified once OpenCatalogi co-install exists.

### Requirement: The Algoritmeregister publication capability MUST be discoverable via a dedicated Settings page
The system MUST expose a dedicated in-app UI for the Algoritmeregister publication capability
(Settings → Algorithm register), independent of the AI-feature governance register's own
table. This UI MUST NOT re-implement any publish-readiness or delegation logic — it MUST call
the same, unmodified `AiFeatureController::publishToAlgoritmeregister()` /
`withdrawFromAlgoritmeregister()` endpoints the existing (relocating) AI-feature register
already calls, so the server-side `AlgoritmekaderMapper` readiness gate and `PublicationGateway`
delegation remain the single source of truth.

#### Scenario: The dedicated page reuses the existing publish endpoint, not a new one
- **GIVEN** the Settings → Algorithm register page
- **WHEN** a permitted caller publishes a feature from it
- **THEN** the system MUST call `POST /api/ai-features/{id}/publish` (the existing route) —
  no new backend endpoint MUST be introduced for this UI

#### Scenario: The page degrades gracefully without OpenCatalogi
- **GIVEN** OpenCatalogi is not installed
- **WHEN** a caller opens the Algorithm register tab
- **THEN** the list of high-risk `AiFeature` records MUST still render
- **AND** no Publish/Withdraw action MUST be offered (mirrors the existing
  `algoritmeregister-publication` graceful-degradation requirement)

@e2e exclude the underlying gate/delegation behavior is already unit-tested (AlgoritmekaderMapperTest, PublicationGatewayTest); this delta only adds a UI consumer of the unchanged endpoints, covered by test-plan.md TC-2/TC-3.

