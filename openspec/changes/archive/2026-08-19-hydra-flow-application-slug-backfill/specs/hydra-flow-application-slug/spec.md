# Hydra Flow Application Slug Specification

**Status**: planned
**Scope**: hermiq
**OpenSpec changes**:
- hydra-flow-application-slug-backfill

## Purpose

hermiq's `SeedHydraTriageFlow` repair step seeds the "Hydra Triage" agentflow into
OpenRegister's native flow store (ADR-065). OpenRegister's `Flow` entity now carries an
optional `applicationSlug` field, letting a consumer distinguish "this app's flows that
belong to a specific application" from "this app's flows" in general — `?app=hermiq` alone
also matches hermiq's own unrelated demo/test flows. This capability makes hermiq's one
hydra-pipeline flow discoverable by hydra-console's real OpenBuild application slug, on
both new installs and instances where the flow was already seeded before this field
existed.

## ADDED Requirements

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

## Non-Functional Requirements

- **Performance:** No measurable impact — one additional field read/write on a repair step
  that already runs at most once per app upgrade, on at most one row.
- **Accessibility:** N/A — no user-facing surface.
- **Internationalization:** N/A — no new user-facing strings.

## Acceptance Criteria

- [ ] A clean install seeds "Hydra Triage" with `applicationSlug: 'hydra-console'`.
- [ ] An upgrade of an instance where "Hydra Triage" already exists with an empty
      `applicationSlug` backfills it to `'hydra-console'` and touches no other field.
- [ ] An upgrade of an instance where "Hydra Triage" already carries a non-empty
      `applicationSlug` writes nothing.
- [ ] `GET /apps/openregister/api/flows?app=hermiq&applicationSlug=hydra-console` returns
      the "Hydra Triage" flow and excludes hermiq's unrelated demo/test flows (verified once
      the companion openregister filter change is live; not exercised by hermiq's own unit
      tests, which stub `FlowMapper` and cannot observe the live filter's SQL).

## Notes

- The other 11 hydra-pipeline flows visible at `?app=hermiq` are NOT in scope: they are
  seeded from `hydra/flows/*.flow.json` in the separate `hydra` repository, independently of
  any hermiq repair step, by explicit design (`hydra-flows-first-port` decision D3 — "the
  flow decides what runs next, and that decision is hydra's, not a leaf app's"). Applying
  `applicationSlug` to those is a `hydra`-repo change.
- Depends on OpenRegister's `Flow.applicationSlug` field and `?applicationSlug=` filter,
  landed by a companion openregister change (STRING(255), nullable, `or_flow_app_slug_idx`
  on `(applicationSlug, id)`, AND-composes with `?app=`).
- `hydra-console`'s slug is verified against two independent live/source sources — see
  `design.md` — not invented.
