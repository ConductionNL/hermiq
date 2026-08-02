# Test Plan: skill-install-idempotency

## Unit

| area | assertion |
|---|---|
| Identity | exact `sourceUrl` match wins; name fallback applies ONLY to skills with no `sourceUrl`; a name collision against a different `sourceUrl` does NOT match |
| Mirror | a mirrored host normalises to the canonical host and matches |
| Merge | content keys are written; `maturityLevel`, `targetLevel`, `levelEvidence`, `installedOn`, `createdBy`, `publishedAt`, `archivedAt`, `lastAcceptedVersionAt` are not |
| Quarantine | any content difference → `quarantined` with a reason; identical content → state unchanged and reported `unchanged` |
| Learnings | ahead + differing `learnings.md` → local kept, body still updated, `learningsKept: true`; not ahead → incoming taken |
| Clock | `sourceUpdatedAt` advances on update; `lastAcceptedVersionAt` does not |

## Mutation checks (a test that cannot fail is not evidence)

1. Remove the identity match → the duplication tests MUST go red.
2. Compare learnings against `publishedAt` instead of `sourceUpdatedAt` → the
   learnings test MUST go red (this is the specific wrong implementation that would
   otherwise look correct and never fire).
3. Preserve `state` across a content change → the re-quarantine test MUST go red.

## Live verification

Re-install `buildiq-hydra` (94 skills) on the dev instance:

- skill count **unchanged** — read from the DB before and after, not from the response
- every bundle skill carries a `sourceUrl`
- no name appears twice

Counts are compared against the **published artifact** (94 skill directories in the
repo tree), never against the installer's own report.

## E2E (Playwright)

Through the UI on `browser-1`, against the dev instance:

1. Open the hermiq skills list and record the total.
2. Install the `buildiq-hydra` bundle from the marketplace UI.
3. Assert the list total is **unchanged**, and that no skill name is listed twice.
4. Open a skill that was `active` and whose content changed — assert it now shows as
   quarantined with a visible reason.
5. For a skill with local learnings ahead of its last sync, assert the UI shows that
   local learnings were kept.

Waits are on explicit UI state, never `networkidle` (gate-53). The run must target
the dev instance explicitly — `docker ps -qf name=` is a substring match and has
silently hit the shared container before.
