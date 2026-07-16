# Tasks: hermiq-github-store-skill-schema

<!-- Config-only change: declarative JSON + two version bumps. No PHP, no Vue, no new endpoints.
     Unindented `- [x]` count: 4 (well under the Hydra cap of 20). -->

## Implementation Tasks

### Task 1: Add GitHub publish-provenance fields to the Skill schema
- **spec_ref**: `openspec/changes/hermiq-github-store-skill-schema/specs/skills-catalog/spec.md#requirement-the-skill-schema-records-github-publish-provenance`
- **files**: `lib/Settings/hermiq_register.json`
- **acceptance_criteria**:
  - GIVEN the `Skill` schema (slug `agentskill`) WHEN it is edited THEN it gains `githubOwner` (string),
    `githubRepo` (string), and `publishedAt` (string, `format: date-time`), inserted after the existing
    `scanReport` property, copying the JSON shape from `AgentTemplate`'s three fields with the
    `description` reworded for skills / `SkillSerializer`.
  - GIVEN the three new fields WHEN the schema is validated THEN none of them appear in the schema's
    `required` array, so pre-existing `Skill` objects stay valid.
- [x] Implement
- [x] Test

### Task 2: Bump register and app versions so the version-gated re-import applies the fields
- **spec_ref**: `openspec/changes/hermiq-github-store-skill-schema/specs/skills-catalog/spec.md#requirement-the-skill-schema-records-github-publish-provenance`
- **files**: `lib/Settings/hermiq_register.json`, `appinfo/info.xml`
- **acceptance_criteria**:
  - GIVEN the register config WHEN `info.version` is edited THEN it reads `0.14.0` (was `0.13.0`).
  - GIVEN `appinfo/info.xml` WHEN `<version>` is bumped THEN the post-migration `InitializeSettings`
    repair step will run `loadConfiguration(force: false)` on upgrade and `importFromApp` re-imports the
    `agentskill` schema.
  - GIVEN an upgraded instance WHEN the `agentskill` schema is queried THEN the three optional
    provenance fields are present and existing `Skill` objects remain valid.
- [x] Implement
- [x] Test

## Quality checklist

- No PHPUnit/Newman/Playwright tests are added — this change ships no business logic, no endpoint, and
  no UI. Verification is the re-import check in Task 2 (query the `agentskill` schema after upgrade).
- `openspec validate` passes.
- No new user-facing strings, so no `nl_NL`/`en_US` additions are required (ADR-005).
- The three property `description` values are English schema metadata mirroring `AgentTemplate`.
- Confirm the diff touches only `lib/Settings/hermiq_register.json` and `appinfo/info.xml`.
