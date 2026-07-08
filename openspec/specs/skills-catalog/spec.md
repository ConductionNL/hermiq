# Skills Catalog Specification

**Status**: active (management surface live-verified; run-loop consumption is an OR seam)

**Feature tier**: V1

**OpenSpec changes:** `skills-catalog` — DONE: `agentskill`/`agentskillsource` schemas; dependency-free `SkillSerializer` (byte-for-byte agentskills.io round-trip); `SkillService` (import/export/list/install-onto-agent); `SkillController` endpoints; `SkillsCatalog` UI (Playwright-verified: browse, import, install, export). Run-loop consumption of `installedOn` (skill available during a turn) is an OpenRegister seam.

## Purpose

Ports Hermes' self-improving skills system to OpenRegister objects, keeping full compatibility with
the agentskills.io skill format. Skills are stored with their frontmatter, body, and auxiliary files
as OR objects/files so an agent's capabilities can be browsed, installed, and evolved (active → stale
→ archived) without a separate filesystem-based skill store.
## Requirements
### Requirement: Skill objects hold agentskills.io frontmatter + body + files
The system MUST persist each skill as a `Skill` OpenRegister object carrying the agentskills.io
frontmatter fields, the skill body, and any auxiliary files as OR-managed files, plus a `state`
(active/stale/archived) and a `createdBy` field.

#### Scenario: A skill is imported from an agentskills.io-formatted package
- GIVEN a skill package with valid agentskills.io frontmatter, a body, and one auxiliary file
- WHEN the system imports it into a `Skill` object
- THEN the frontmatter fields, body, and auxiliary file MUST all be persisted on the `Skill` object
- AND the skill's `state` MUST default to `active` with `createdBy` set to the importing user

### Requirement: Bidirectional SkillSerializer round-trip fidelity
The system MUST provide a `SkillSerializer` that converts a `Skill` object to an agentskills.io file
package and back, and a round trip (serialize then deserialize) MUST reproduce the original
frontmatter and body byte-for-byte.

#### Scenario: A skill is exported then re-imported
- GIVEN an existing `Skill` object with frontmatter and body content
- WHEN `SkillSerializer` exports it to an agentskills.io package and then re-imports that package
- THEN the resulting `Skill` object's frontmatter and body MUST be identical to the original

### Requirement: Browse and install skills into an agent
The system MUST let a user browse available `Skill` objects (scoped to their tenant) and install a
chosen skill onto a specific agent, making it available to that agent's next run.

#### Scenario: A user installs a skill onto their agent
- GIVEN a `Skill` object in `active` state exists within the user's organisation
- WHEN the user installs that skill onto agent X
- THEN the system MUST associate the skill with agent X
- AND agent X's next run MUST have that skill available for use

### Requirement: Detach an installed skill from an agent
The system MUST let a user remove a previously-installed skill's association with a specific
agent, symmetrically undoing the install operation: the skill's `installedOn` list MUST no
longer contain the agent's uuid, and the agent's `skillInstalls` allowlist MUST no longer
contain the skill's uuid. Detaching MUST be idempotent — detaching a skill/agent pair that is
not currently associated MUST succeed as a no-op rather than error.

#### Scenario: A user detaches a skill from their agent
- GIVEN a `Skill` object installed on agent X (agent X's uuid is in the skill's `installedOn`)
- WHEN the user detaches that skill from agent X
- THEN the system MUST remove agent X's uuid from the skill's `installedOn`
- AND the system MUST remove the skill's uuid from agent X's `skillInstalls`
- AND agent X's next run MUST NOT have that skill available
@e2e exclude covered by SkillServiceTest::testUninstallFromAgentDesyncsBothDirections (both-sides removal via ObjectService); the DELETE /api/skills/{id}/install/{agentId} route + controller mirror the install endpoint. Newman/Playwright coverage deferred.

#### Scenario: Detaching an already-detached skill/agent pair is a no-op
- GIVEN a `Skill` object that is NOT installed on agent Y
- WHEN the user (or a repeated client request) detaches that skill from agent Y
- THEN the system MUST return success
- AND the skill's `installedOn` and agent Y's `skillInstalls` MUST remain unchanged
@e2e exclude idempotency is covered by SkillServiceTest::testUninstallFromAgentIsIdempotent (agent-side write skipped when already absent). Newman/Playwright coverage deferred.

## User Stories

- As an agent builder, I want to browse a catalog of skills so that I can extend my agent's capabilities without writing code.
- As an agent builder, I want to install an existing skill onto my agent so that it gains that capability immediately.
- As a skill author, I want my agentskills.io-formatted skill to import and export without losing fidelity so that I can share it outside Hermiq.
- As a tenant admin, I want skills scoped to my organisation so that I only see skills relevant to my tenant.

## Acceptance Criteria

- [ ] `Skill` and `SkillSource` schemas exist as OpenRegister objects
- [ ] Skill frontmatter, body, and auxiliary files are all persisted (files via OR file storage)
- [ ] `SkillSerializer` round-trips agentskills.io packages without loss
- [ ] Skills have a `state` field (active/stale/archived) and a `createdBy` field
- [ ] Users can browse tenant-scoped skills and install one onto a specific agent

## Notes

Depends on OpenRegister object/file storage and the `agent-memory` spec's per-tenant scoping model.
Related: ADR-003 (memory & skills as OR objects), ADR-001 (Option C+). The `skills-marketplace` (V2)
spec builds on this catalog for cross-org sharing and external hub publishing.
