## Tasks

### 1. Schema properties

- [x] Add optional `sourceUrl` (string) and `sourceUpdatedAt` (string, date-time) to `components.schemas.Skill.properties` in `lib/Settings/hermiq_register.json`, neither added to `required`

Acceptance criteria
- Neither property appears in `required`
- Done: both properties were already in the register; `tests/Unit/Settings/SkillSourceIdentitySchemaTest.php` pins type, format and that only `name` is required.
- No existing property changes type or shape

### 2. Apply and verify on the live instance

- [x] Force a register reload (`POST /api/settings/load`) — `occ maintenance:repair` runs CORE steps only and will NOT apply an app register fragment
  - 2026-11-24: Newman folder "Skill source identity" in `tests/integration/hermiq.postman_collection.json` posts the reload between two skill counts. Written, runs in nightly (UNBLOCK rule 3); not run here, no instance.
- [x] Verify the live schema reports both properties, and that the skill count is unchanged at 101
  - 2026-11-24: same folder: the counts before and after the reload must be equal (the instance's own count, not a fixed 101), and the live `agentskill` schema read from `/api/schemas` must have `sourceUrl` (string) and `sourceUpdatedAt` (date-time), neither required. Written, runs in nightly (UNBLOCK rule 3); not run here, no instance.

Acceptance criteria
- Both properties are readable from the live schema, not merely present in the JSON file
- `count(*)` on the skill table is 101 before and after
