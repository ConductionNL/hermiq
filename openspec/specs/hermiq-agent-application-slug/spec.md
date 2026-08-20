# hermiq-agent-application-slug Specification

## Purpose
hermiq's `Agent` schema carries an optional `applicationSlug`, mirroring
`Flow.applicationSlug` (hydra-flow-application-slug). It lets a consumer — such as
openbuild's export bundler — select exactly one external Application's agents (e.g.
`hydra-console`'s) without also matching hermiq's own unrelated agents. This
capability declares the field, sets it at creation time on the one seeded agent
(`SeedHydraTriageAgent`), and backfills the four Agent objects known to belong to
`hydra-console` as of this change — one seeded, three hand-created via the UI with
no seed script of their own.

## Requirements

### Requirement: hermiq's `Agent` schema declares an optional `applicationSlug`
The system MUST declare `applicationSlug` as an OPTIONAL string property on the
`agent` schema in `lib/Settings/hermiq_register.json`, with the schema's own
`version` and the register's top-level `info.version` both bumped in the same
commit — the import is gated on `info.version`, so a schema shipped without a
bump is inert on every existing install with no error anywhere
(hydra-flow-application-slug-backfill precedent).

#### Scenario: The declaration ships with a version bump
- GIVEN the register's `info.version` before this change
- WHEN `applicationSlug` is added to the `Agent` schema's `properties`
- THEN both `Agent.version` and `info.version` are strictly greater than their
  prior values
- AND `applicationSlug` is never added to the `Agent` schema's `required` list

### Requirement: The seeded Hydra Triage agent declares its owning application
The system MUST set `applicationSlug` to `hydra-console` on the "Hydra Triage"
Agent object when `SeedHydraTriageAgent` creates it on a clean install or upgrade.

#### Scenario: A clean install seeds the agent with its application slug
- GIVEN no agent named "Hydra Triage" exists yet
- WHEN `SeedHydraTriageAgent::run()` executes
- THEN the new Agent object's `applicationSlug` is `hydra-console`
@e2e exclude Backend-only repair-step write path with no UI surface; asserted by
`testTheSeededAgentDeclaresItsApplicationSlug()`.

### Requirement: The four hydra-console agents are backfilled with their application slug
The system MUST set `applicationSlug` to `hydra-console` on each of the four named
Agent objects (`Hydra Triage`, `Hydra Applier — Axel Pliér`, `Hydra Builder — Al
Gorithm`, `Hydra Author — Ada Wright`) found by exact name, only when the field is
currently absent or empty on that object.

#### Scenario: An already-existing agent with no application slug is backfilled
- GIVEN an Agent object named "Hydra Builder — Al Gorithm" already exists
- AND its `applicationSlug` is null or an empty string
- WHEN `BackfillAgentApplicationSlug::run()` executes
- THEN the object's `applicationSlug` is set to `hydra-console`
- AND every other field on the object (`name`, `prompt`, `tools`,
  `delegationAllowlist`, …) is unchanged
@e2e exclude Backend-only repair-step write path with no UI surface; asserted by
`testRunBackfillsApplicationSlugWhenEmpty()` and `testAllFourNamedAgentsAreBackfilled()`.
Live-verified: a hand-created agent seeded with no `applicationSlug` and a
`prompt` field was backfilled to `hydra-console` with `prompt` unchanged, on a
fresh isolated instance.

#### Scenario: Backfilling twice writes once
- GIVEN all four named agents already carry a non-empty `applicationSlug`
- WHEN `BackfillAgentApplicationSlug::run()` executes again
- THEN no write is issued for any of the four
@e2e exclude Backend-only repair-step write path with no UI surface; asserted by
`testBackfillingTwiceWritesOnce()`. Live-verified: re-running the repair step
against a fresh instance reported `0 agent(s) backfilled, 3 already present`.

### Requirement: A previously-set application slug is never overwritten
The system MUST leave `applicationSlug` untouched when a named agent already
carries a non-empty value for it.

#### Scenario: The agent already carries an application slug
- GIVEN an agent named "Hydra Triage" already exists
- AND its `applicationSlug` is already set to some non-empty value (an operator's
  own retag, or a value a prior run of this repair step already wrote)
- WHEN `BackfillAgentApplicationSlug::run()` executes
- THEN no write is issued for that object at all
@e2e exclude Backend-only repair-step write path with no UI surface; asserted by
`testRunDoesNotOverwriteAnExistingApplicationSlug()`. Live-verified: an agent
seeded with `applicationSlug: operator-retagged-value` kept that exact value
across a repair-step run.

### Requirement: The backfill write is PATCH-semantic, never a wholesale replace
The system MUST write the backfill through `ObjectService::patchObject()` (or an
equivalent merge-then-save path), never through `ObjectService::saveObject()` with
a partial payload — `saveObject()` is PUT-semantic and would silently null every
field absent from the payload.

#### Scenario: The patch payload carries exactly one key
- GIVEN an agent whose `applicationSlug` is empty
- WHEN the backfill writes it
- THEN the write call's data payload contains exactly the key `applicationSlug`
  and no other key
@e2e exclude Backend-only repair-step write path with no UI surface; asserted by
`testRunBackfillsApplicationSlugWhenEmpty()`, which pins the exact payload
`['applicationSlug' => 'hydra-console']`.
