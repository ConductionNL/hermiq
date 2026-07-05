# skills-catalog (delta)

Implements the Hermiq-owned surface of the `skills-catalog` capability: schemas, a lossless
agentskills.io serializer, a tenant-scoped catalog, install-onto-agent, and the browse/
import/install UI. The run-loop consumption of `installedOn` remains an OR seam.

## ADDED Requirements

### Requirement: Skill objects hold agentskills.io frontmatter + body + files
The system MUST persist each skill as a `Skill` OpenRegister object carrying the
agentskills.io frontmatter, the skill body, and any auxiliary files, plus a `state`
(`active|stale|archived`) and a `createdBy` field.

#### Scenario: A skill is imported from an agentskills.io-formatted package
- **GIVEN** a skill package with valid agentskills.io frontmatter and a body
- **WHEN** the system imports it into a `Skill` object
- **THEN** the frontmatter and body MUST be persisted on the `Skill` object
- **AND** the skill's `state` MUST default to `active` with `createdBy` set to the importer

### Requirement: Bidirectional SkillSerializer round-trip fidelity
The system MUST provide a `SkillSerializer` that converts a `Skill` to an agentskills.io
package and back, and a round trip (serialize then deserialize) MUST reproduce the original
frontmatter and body byte-for-byte.

#### Scenario: A skill is exported then re-imported
- **GIVEN** an existing `Skill` with frontmatter and body content
- **WHEN** `SkillSerializer` exports it to a package and then re-imports that package
- **THEN** the resulting frontmatter and body MUST be identical to the original

### Requirement: Browse and install skills into an agent
The system MUST let a user browse tenant-scoped `Skill` objects and install a chosen skill
onto a specific agent, recording the association on the skill's `installedOn`.

#### Scenario: A user installs a skill onto their agent
- **GIVEN** an `active` `Skill` within the user's organisation
- **WHEN** the user installs that skill onto agent X
- **THEN** the system MUST record agent X on the skill's `installedOn`
- **AND** the association MUST be idempotent (no duplicate on re-install)

### Requirement: Skills catalog view
The system MUST provide a nav-reachable Skills view that browses tenant-scoped skills,
imports an agentskills.io package, and installs a skill onto an agent — consuming the skill
endpoints only, with an `inputLabel` on every `NcSelect` (ADR-004).

#### Scenario: An operator browses and imports a skill
- **WHEN** the operator opens the Skills view, pastes an agentskills.io package, and imports
- **THEN** the new skill MUST appear in the list with its name, state, and installed count,
  without console errors
