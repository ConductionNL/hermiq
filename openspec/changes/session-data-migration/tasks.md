# Tasks: session-data-migration

## 1. Establish the baseline before touching anything

- [x] 1.1 Record exact counts: total `conversation` objects (282 at time of writing), how many are archived (soft-deleted), and messages per conversation. These are the numbers the verification compares against; derived after the migration they prove nothing.
- [x] 1.2 Confirm the archived count can actually be READ back. OpenRegister's default reads exclude soft-deleted rows, and on 2026-08-13 `_includeDeleted=true` was measured moving a `total` from 22 to 106 while the result set stayed at 22 live rows — the flag reached the count query only. If archived conversations cannot be listed, the migration needs a different read path and this is the moment to find out, not after 282 objects have moved.
- [x] 1.3 Snapshot the source objects to a file so a botched run can be compared against ground truth rather than against memory.

Acceptance criteria
- Live, archived and message counts are written down before any write.
- The archived-read path is proven to return rows, not just a promising total.

## 2. Write the migration

- [x] 2.1 Construct each `session` object in FULL. `saveObject` is PUT-semantic — a partial write silently drops every unlisted field.
- [x] 2.2 Preserve `_uuid`, `_owner`, `_organisation`, `_created`, `_updated` and the archive marker (`metadata.deletedAt` / `metadata.deletedBy`) exactly. Uuid preservation is what keeps messages attached; changing it orphans every message.
- [x] 2.3 Set trigger-origin to `human` on every migrated object.
- [x] 2.4 Make the migration idempotent — upsert by uuid — so a partial failure can be re-run instead of half-migrating and needing manual repair.
- [x] 2.5 Detect a uuid collision with a soft-deleted tombstone and REPORT it; do not auto-purge. The `_uuid` unique index has no `WHERE _deleted IS NULL`, so a tombstone holds its uuid forever; a collision means something unexpected exists and a human should look at it.
- [x] 2.6 Do NOT delete or modify the source `conversation` objects. Copy semantics are what make rollback a delete of the new copies.

Acceptance criteria
- Re-running the migration twice produces the same result as running it once.
- No source object is modified.

## 3. Verify field-by-field — and prove the verifier can fail

- [x] 3.1 Write a verifier comparing every property of every source object against its migrated counterpart, reporting a count of matches and an explicit list of mismatches.
- [x] 3.2 **Run the positive control FIRST**: deliberately corrupt one migrated object and confirm the verifier reports exactly that object. A verifier that has never failed is not evidence of anything.
- [x] 3.3 Assert message counts per session match the task 1.1 baseline — this is the orphaned-message check.
- [x] 3.4 Assert the archived sessions are present and still archived. The Archive tab emptying itself is the visible symptom of missing this.

Acceptance criteria
- The verifier demonstrably FAILS on a corrupted object before it is trusted to pass.
- Counts match the pre-migration baseline exactly: 282 sessions, archived count preserved, message counts per session unchanged.

## 4. Run it

- [x] 4.1 Run against a copy of the data first and verify there.
- [x] 4.2 Run against the live instance, then re-run the verifier.
- [x] 4.3 Record the final counts in the PR — the numbers, not "migration completed successfully".

Acceptance criteria
- The PR states the counts before and after.

## 5. Quality

- [x] 5.1 `composer check:strict` clean; fix any pre-existing issues touched.
- [x] 5.2 Confirm rollback works: delete the migrated sessions and check the conversations are intact and the app still functions.

Acceptance criteria
- Strict gates pass and the rollback path is exercised, not just described.

## Results, measured 2026-09-07

This ran on a dev instance, NOT the 282-object one the spec assumes. Real baseline and
outcome:

| | before | after | archived before | archived after |
|---|---|---|---|---|
| conversation → agentsession | 10 | 10 | 9 | 9 |
| message → agentsessionturn | 7 | 7 | 6 | 6 |

- Run 1: `sessions: 10 copied, 0 already present, 0 failed; turns: 7 copied, 0 already present, 0 failed`
- Run 2: `sessions: 0 copied, 10 already present, 0 failed; turns: 0 copied, 7 already present, 0 failed`
- Verifier (`verify.sql`): 0 mismatches.
- Positive control: un-archived one migrated object and changed its title; the verifier
  reported exactly that uuid and nothing else.
- Sources: compared field for field against the pre-migration snapshot, **0 modified**.
- Rollback: exercised four times (delete the copies, sources intact, app still works).

### Four defects this found in the migration itself, all silent

1. `ObjectService::find()` returned the SOURCE conversation when asked for a session with
   the same uuid, so the one live conversation was reported "already present" and never
   migrated while nine archived ones were.
2. `saveObject()` ignores `@self.deleted`: all nine archived conversations arrived LIVE.
3. It also stamps `_owner` from the acting identity (`__system__`, not `admin`) and
   re-dates `_created`/`_updated` to now. Impersonating the owner around the call did not
   change that.
4. `ObjectService::find()` excludes soft-deleted rows, so on a re-run every archived
   session read as absent and was overwritten, breaking the idempotency guarantee.

None of them raised. The counts alone looked correct after (1) and (2), which is why the
field-by-field verifier and its positive control are the acceptance criteria that matter.
