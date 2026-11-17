# skills-marketplace (delta)

Implements the Hermiq-ownable surface of `skills-marketplace`: quarantine on external
install with a review gate, an age-based Curator that never hard-deletes, and hub publish
via SkillSerializer + OpenConnector. The content security scan + a live hub are documented
seams.

## ADDED Requirements

### Requirement: Quarantine on install from another org or hub
The system MUST place any skill installed from another organisation or an external hub into
a `quarantined` state, and the skill MUST NOT become usable (`active`) until it passes the
review gate (the content security scan — an OpenRegister seam — realised today as an
explicit approval).

#### Scenario: An org installs a skill from an external source
- **GIVEN** a skill package sourced from another tenant or a hub
- **WHEN** the system installs it
- **THEN** the resulting `Skill` MUST be `state='quarantined'` with its `source` recorded
- **AND** it MUST NOT be `active` until `approveQuarantined` (the review gate) runs

### Requirement: Curator manages lifecycle without hard-delete
The system MUST run a background Curator that transitions skills `active`→`stale`→`archived`
by threshold and MUST NOT hard-delete a skill (object or files) at any point.

#### Scenario: A skill ages past the staleness threshold
- **GIVEN** an `active` `Skill` older than the configured staleness threshold
- **WHEN** the Curator job runs
- **THEN** the skill's state MUST become `stale` (then `archived` past the archival
  threshold)
- **AND** the object and its files MUST NOT be deleted

### Requirement: Publish to an external hub via OpenConnector
The system MUST publish a skill by serialising it via `SkillSerializer` and routing the
outbound submission through OpenConnector's `CallService` — MUST NOT open a direct HTTP
client — and MUST return a structured error when no hub connector is available.

#### Scenario: A user publishes a skill with no hub configured
- **WHEN** publish is requested and no OpenConnector hub connector is configured
- **THEN** the system MUST return a structured error (no direct HTTP), not throw
