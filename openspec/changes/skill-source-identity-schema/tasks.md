## Tasks

### 1. Schema properties

- [ ] Add optional `sourceUrl` (string) and `sourceUpdatedAt` (string, date-time) to `components.schemas.Skill.properties` in `lib/Settings/hermiq_register.json`, neither added to `required`

Acceptance criteria
- Neither property appears in `required`
- No existing property changes type or shape

### 2. Apply and verify on the live instance

- [ ] Force a register reload (`POST /api/settings/load`) — `occ maintenance:repair` runs CORE steps only and will NOT apply an app register fragment
- [ ] Verify the live schema reports both properties, and that the skill count is unchanged at 101

Acceptance criteria
- Both properties are readable from the live schema, not merely present in the JSON file
- `count(*)` on the skill table is 101 before and after
