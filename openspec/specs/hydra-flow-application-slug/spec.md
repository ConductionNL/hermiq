# hydra-flow-application-slug Specification

## Purpose
hermiq's `SeedHydraTriageFlow` repair step seeds the "Hydra Triage" agentflow into
OpenRegister's native flow store (ADR-065). OpenRegister's `Flow` entity now carries an
optional `applicationSlug` field, letting a consumer distinguish "this app's flows that
belong to a specific application" from "this app's flows" in general — `?app=hermiq` alone
also matches hermiq's own unrelated demo/test flows. This capability makes hermiq's one
hydra-pipeline flow discoverable by hydra-console's real OpenBuild application slug, on
both new installs and instances where the flow was already seeded before this field
existed.

## Requirements

### Requirement: The seeded Hydra Triage flow declares its owning application (REQ-001)
The system MUST set `applicationSlug` to `hydra-console` on the "Hydra Triage" agentflow
object when `SeedHydraTriageFlow` creates it on a clean install.

#### Scenario: A clean install seeds the flow with its application slug
- GIVEN no flow named "Hydra Triage" exists yet for app `hermiq`
- WHEN `SeedHydraTriageFlow::run()` executes and a default organisation resolves
- THEN a new `Flow` row is inserted with `applicationSlug` set to `hydra-console`
- AND every other field (`nodes`, `edges`, `enabled`, `owner`, `organisation`) is unchanged
  from the flow's existing seeded shape
@e2e exclude Backend-only repair-step write path with no UI surface; asserted by
`testRunInsertsTheFlowWithItsApplicationSlug()` and `testFlowObjectDeclaresTheApplicationSlug()`.

### Requirement: An already-seeded flow with no application slug is backfilled (REQ-002)
The system MUST set `applicationSlug` on an existing "Hydra Triage" flow, found by name,
when that field is currently empty — without modifying any other field on the row.

#### Scenario: The flow was seeded before applicationSlug existed
- GIVEN a flow named "Hydra Triage" already exists for app `hermiq`
- AND its `applicationSlug` is null or an empty string
- WHEN `SeedHydraTriageFlow::run()` executes
- THEN the existing row's `applicationSlug` is set to `hydra-console` and persisted via an
  update
- AND `nodes`, `edges`, `enabled`, `owner` and every other field on the row are left exactly
  as they were, including any prior operator edits
@e2e exclude Backend-only repair-step write path with no UI surface; asserted by
`testRunBackfillsApplicationSlugWhenEmpty()`.

### Requirement: A previously-set application slug is never overwritten (REQ-003)
The system MUST leave `applicationSlug` untouched when an existing "Hydra Triage" flow
already carries a non-empty value for it.

#### Scenario: The flow already carries an application slug
- GIVEN a flow named "Hydra Triage" already exists for app `hermiq`
- AND its `applicationSlug` is already set to some non-empty value (an operator's own
  retag, or a value a prior run of this repair step already wrote)
- WHEN `SeedHydraTriageFlow::run()` executes
- THEN no write is issued for that row at all
- AND the repair step reports `present`, exactly as it does today for an existing flow
@e2e exclude Backend-only repair-step write path with no UI surface; asserted by
`testRunDoesNotInsertWhenTheFlowIsAlreadyPresent()`.
