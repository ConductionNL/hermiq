# Migration: skill-source-identity-schema

## Current State

`Skill` (register `hermiq`, schema `agentskill`, table `oc_openregister_table_2428_4349`)
carries 101 objects and has no property recording where a skill came from.
`githubOwner`/`githubRepo` exist but are written only by the publish path.

## Target State

The same schema with two additional OPTIONAL properties, `sourceUrl` and
`sourceUpdatedAt`. All 101 existing objects remain valid and unmodified.

## Migration Class

```
None required.
OpenRegister schemas are declarative: the register JSON is the source of truth and
is applied by the settings loader, not by a Nextcloud migration class. No table
column is added — OR objects store properties in the object payload.
```

## Migration Steps

1. Add `sourceUrl` and `sourceUpdatedAt` to `components.schemas.Skill.properties`
   in `lib/Settings/hermiq_register.json`. Neither is added to `required`.
2. Reload the register configuration so the new schema version is applied
   (`POST /api/settings/load` with force — `maintenance:repair` runs CORE steps only
   and will NOT apply an app register fragment).
3. Verify the live schema reports both properties before the dependent change ships.

## Data Impact

- **101 objects affected: zero.** Both properties are optional and nothing writes
  them in this change, so every existing object stays byte-identical.
- No transformation, no data loss, safe to run on live data.
- **No backfill.** Existing skills keep an absent `sourceUrl`, which the dependent
  change treats as "identity not yet known" and resolves on first re-install.

## Rollback Procedure

Remove both properties from the register JSON and force a reload. Because nothing
writes them in this change, no object holds a value that would be orphaned. Once
`skill-install-idempotency` has shipped, rolling back this change alone would strip
identity from skills that have it — so the two must be rolled back together, newest
first.

## Verification

- Live schema shows both properties after the forced reload
- `select count(*) from oc_openregister_table_2428_4349` is unchanged at 101
