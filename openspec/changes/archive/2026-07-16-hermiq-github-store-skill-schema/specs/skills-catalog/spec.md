# Skills Catalog Specification

**Status**: in-progress
**Scope**: hermiq
**OpenSpec changes**:
- `hermiq-github-store-skill-schema` — adds GitHub publish-provenance fields to the `Skill` schema

## Purpose
Extends the `Skill` schema (slug `agentskill`) so a skill can record where it was last published to
GitHub, mirroring the provenance fields already carried by `AgentTemplate`
(`agent-template-github-store`). This is the config head of the `hermiq-github-store` chain (ADR-032):
the fields are declared and version-gated into the running register here; the code that reads and
writes them ships in `hermiq-github-store`. No secrets are involved (ADR-003 — skills are OpenRegister
objects; provenance is non-secret).

## ADDED Requirements

### Requirement: The Skill schema records GitHub publish provenance
The system MUST declare, on the `Skill` schema (slug `agentskill`) in
`lib/Settings/hermiq_register.json`, three optional provenance properties mirroring `AgentTemplate`:
`githubOwner` (string), `githubRepo` (string), and `publishedAt` (string, `format: date-time`). None of
the three MUST be listed in the schema's `required` array, so every existing `Skill` object remains
valid after the fields are added. The fields are provenance only: they record the GitHub owner,
repository name, and timestamp of a skill's last publish, and MUST NOT be part of the agentskills.io
package that `SkillSerializer` round-trips (mirroring how `AgentTemplateSerializer::toPackage()` never
emits `githubOwner`) — the enforcement of that non-emission is owned by the `hermiq-github-store` code
change, not this schema change.

#### Scenario: The Skill schema exposes the three optional provenance fields after re-import
- GIVEN the register `info.version` has been bumped to `0.14.0` and the app `<version>` bumped
- WHEN the app is upgraded and the `InitializeSettings` repair step runs `loadConfiguration(force: false)`
- THEN `ConfigurationService::importFromApp()` MUST re-import the `agentskill` schema
- AND the imported `Skill` schema MUST expose `githubOwner`, `githubRepo`, and `publishedAt` as optional
  string properties (with `publishedAt` typed `date-time`)

#### Scenario: Existing skills stay valid and unchanged when the fields are added
- GIVEN a `Skill` object created before this change, with no `githubOwner`/`githubRepo`/`publishedAt`
- WHEN the schema is re-imported with the three new optional fields
- THEN the existing `Skill` object MUST remain valid without modification
- AND its three provenance fields MUST be absent/empty (no backfill, no data transformation)

## Non-Functional Requirements

- **Performance:** The change is a one-off version-gated schema re-import at upgrade time; no per-request
  cost is added.
- **Accessibility:** N/A — no UI is introduced by this change.
- **Internationalization:** N/A — no user-facing strings are introduced (field titles/descriptions are
  schema metadata, not translated UI strings). Dutch/English UI for the follow-up Store lands in
  `hermiq-github-store` (ADR-005).

## Acceptance Criteria

- [ ] The `Skill` schema in `lib/Settings/hermiq_register.json` declares `githubOwner`, `githubRepo`,
  and `publishedAt` with the same JSON shape as `AgentTemplate` (only descriptions reworded).
- [ ] None of the three fields appear in the `Skill` schema's `required` array.
- [ ] Register `info.version` is `0.14.0` and `appinfo/info.xml` `<version>` is bumped.
- [ ] After re-import, a query against the `agentskill` schema shows the three new optional fields and
  existing `Skill` objects remain valid.

## Notes
- Depends on OpenRegister's version-gated `ConfigurationService::importFromApp()` and the
  `InitializeSettings` post-migration repair step — both pre-existing.
- The write path for these fields (publish stamping) and the read path (Store card provenance) ship in
  `hermiq-github-store`.
