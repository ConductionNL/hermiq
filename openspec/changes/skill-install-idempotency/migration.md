# Migration: skill-install-idempotency

## Current State

101 skill objects in `oc_openregister_table_2428_4349` (register `hermiq`, schema
`agentskill`). None carries `sourceUrl` or `sourceUpdatedAt`. Two names are
duplicated by the non-idempotent install:

| name | rows |
|---|---|
| Meeting Summariser | `active` 07-05 14:21 + `quarantined` 07-26 16:02 |
| Nightly Backup Helper | `active` 07-05 14:16 + `active` 07-05 14:21 |

## Target State

Every skill installed from a bundle carries a canonical `sourceUrl` and a
`sourceUpdatedAt`. The two duplicate pairs are reduced to one row each.

## Migration Class

```
None. Identity is stamped LAZILY by the installer on first re-install, not by a
migration: the correct sourceUrl is only knowable from the bundle being installed,
so a migration would have to guess it. Guessing identity is how the duplicates
appeared in the first place.
```

## Migration Steps

1. Ship the installer. On the next install of any bundle, matched skills are stamped
   with `sourceUrl` via the one-time name fallback.
2. **Duplicate cleanup, manually and with eyes on it:**
   a. SELECT both rows of each pair with their content and inspect them — an
      identical NAME is not an identical SKILL, and deleting on name alone is how a
      real skill gets lost.
   b. Keep the `active` *Meeting Summariser* (07-05); delete the `quarantined`
      07-26 shadow.
   c. Keep the earlier `active` *Nightly Backup Helper* (14:16); delete the 14:21
      duplicate.
   d. Re-count: 101 → 99.

## Data Impact

- **Lazy stamping**: touches only skills present in a bundle being installed. A
  locally authored skill is never modified.
- **Deletion: 2 rows**, after inspection, on a shared-by-agents instance.
- **Irreversible.** The deleted rows are exported to a file first so the content is
  recoverable even though the objects are not.
- Safe on live data: no bulk update, no schema rewrite.

## Rollback Procedure

- The installer change is revertible; already-stamped `sourceUrl` values remain and
  are simply unread, which is harmless.
- The two deletions are NOT revertible by reverting code. Recovery is re-importing
  from the pre-deletion export.

## Verification

- Re-installing `buildiq-hydra` leaves the skill count at 99, not 193
- Every skill in the bundle carries a `sourceUrl` afterwards
- `select name, count(*) … having count(*) > 1` returns zero rows
