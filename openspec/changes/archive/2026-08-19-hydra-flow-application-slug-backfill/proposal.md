---
kind: code
depends_on: []
---

# Proposal: hydra-flow-application-slug-backfill

## Summary

OpenRegister's `Flow` entity now carries an optional `applicationSlug` field (STRING(255),
nullable, `or_flow_app_slug_idx` on `(applicationSlug, id)`, AND-composable with the
existing `?app=` filter) — landed by a companion openregister change. This change tags
hermiq's own seeded "Hydra Triage" agentflow with hydra-console's real OpenBuild
application slug (`hydra-console`) on both new installs and already-seeded rows, so an
external consumer can select exactly that flow via
`GET /apps/openregister/api/flows?app=hermiq&applicationSlug=hydra-console` instead of
`?app=hermiq` alone, which also matches hermiq's unrelated demo/test flows.

## Motivation

`app=hermiq` is too coarse a filter to mean "hydra's pipeline". Live census of
`/apps/openregister/api/flows?app=hermiq` returns 16 flows, only 12 of which are hydra
pipeline flows — the other 4 are hermiq's own "Demo — ..." fixtures (paginated sync,
sync-one-page, fetch-one-page, sync-contacts). `applicationSlug` exists specifically to
let a consumer narrow past that. Nothing currently sets it, because the field did not
exist until the companion openregister change.

## Affected Projects

- [x] Project: `hermiq` — `SeedHydraTriageFlow.php` sets `applicationSlug` on the flow it
  creates, and backfills it onto the already-seeded row on instances where the repair step
  ran before this change existed.

## Scope

### In Scope
- Set `applicationSlug` to `hydra-console` on the "Hydra Triage" flow this repair step
  creates on a clean install.
- Backfill `applicationSlug` onto an already-seeded "Hydra Triage" row, ONLY when the field
  is currently empty — never overwriting a value already present (an operator's own retag,
  or a slug a later run already backfilled).
- Update the PHPStan/Psalm declaration-only stubs (`tests/Stubs/Db/Flow.php`,
  `tests/Stubs/Db/FlowMapper.php`) to declare the new accessor/`update()` method, mirroring
  the real OpenRegister contract.
- Unit test coverage for both the create-path and the backfill-path, following the existing
  `SeedHydraTriageFlowTest.php` pattern.

### Out of Scope
- The other 11 hydra-pipeline flows visible at `?app=hermiq` ("Hydra dispatch", "Hydra
  sequencer", "Hydra applier", "Hydra lock reaper", "Hydra label transition", "Hydra retry
  and escalate", `hydra-record-stage`, `hydra-file-findings`, "Hydra commit by API", "Hydra
  analyze verdicts", plus a live-proof variant of "Hydra applier"). **These are not seeded
  by any hermiq repair step.** They are hydra's own control-plane data
  (`hydra/flows/*.flow.json` in the separate `hydra` repo), deployed independently of
  hermiq's install/upgrade lifecycle by design — `hydra/flows/README.md` states this
  explicitly: they live outside any app's seed code "because... the flow decides what runs
  next, and that decision is hydra's, not a leaf app's" (design decision D3,
  `hydra-flows-first-port`). Tagging them with `applicationSlug` is a `hydra`-repo change,
  not a hermiq one, and is not covered here.
- `FlowEngine`/`FlowStepDispatcher` and any flow's `nodes`/`edges`/execution logic.
- Hermiq's 2 non-Hydra "Demo — ..." flows also visible at `?app=hermiq`.
- Any change to OpenRegister's `applicationSlug` contract itself (already landed).

## Approach

`SeedHydraTriageFlow::run()` is skip-entirely-if-found today: `flowExists()` returns a
bare `true`/`false` and the found branch neither reads nor rewrites any field. This change
replaces that boolean check with one that returns the found `Flow` entity, and adds a
narrow backfill: if found and `getApplicationSlug()` is empty, set it to the constant
`hydra-console` and call `$mapper->update($flow)` (inherited from `QBMapper`, same pattern
already used for `insert()`); if already set, the row is left completely untouched, exactly
as today. The create path additionally calls `$flow->setApplicationSlug(...)` before
`insert()`. See `design.md` for the full rationale.

## New Dependencies

None.

## Impact

- `lib/Repair/SeedHydraTriageFlow.php` — the only hermiq repair step that seeds a Flow
  object into OpenRegister's native flow store (confirmed by repo-wide grep for
  `FlowMapper` usage under `lib/`; no other `lib/Repair/Seed*Flow*.php` files exist).
- `tests/Unit/Repair/SeedHydraTriageFlowTest.php` — new/updated coverage.
- `tests/Stubs/Db/Flow.php`, `tests/Stubs/Db/FlowMapper.php` — declaration-only stubs kept
  in sync with the real contract.

## Cross-Project Dependencies

Depends on the openregister change that added `Flow::applicationSlug` (STRING(255),
nullable, filterable) — implemented and merging to openregister's `development` now.
This hermiq change assumes that contract as given and does not re-verify it; the live
shared dev instance had not yet picked it up at the time of writing (`openregister
0.2.17-unstable.38`, field absent from `GET /apps/openregister/api/flows` responses),
which is expected for a change still landing.

## Risks

### Risk 1: The `hydra-console` slug is wrong or changes later
**Severity:** Medium — **Mitigation:** Verified from two independent, live sources rather
than assumed: (1) `openbuild`'s own `FlowAndAgentExportBundlerTest.php` uses the literal
`'hydra-console'` as the exact value passed through `applicationSlug` filters when looking
up objects "by the application they point at"; (2) a live query of
`/apps/openregister/api/objects/openbuild/application` returns an Application object named
"Hydra Console" with `slug: "hydra-console"` (uuid `93941bf0-18ac-4d50-97fd-1daef540911d`).
This is distinct from the register slug `openbuild-hydra-console-production`, which names a
per-version *schema-namespace register*, not the Application object itself. The value is a
single named class constant, so a future rename is a one-line fix.

### Risk 2: `FlowMapper::update()` behaves unexpectedly on a row with hand-edited nodes/edges
**Severity:** Low — **Mitigation:** `update()` is inherited from Nextcloud's `QBMapper`
and persists only the entity's tracked dirty fields (the standard `setXxx()`-marks-dirty /
`getUpdatedFields()` pattern already relied on implicitly by this class's use of
`insert()`). Only `applicationSlug` is set before the call, so only that column changes;
`nodes`, `edges`, `enabled`, `owner` and everything else an operator may have edited are
untouched.

## Rollback Strategy

Revert the `SeedHydraTriageFlow.php` change. The backfilled `applicationSlug` value on any
already-updated row is inert (OpenRegister ignores an unrecognised/unused column value) and
requires no data cleanup; if a clean rollback of the data itself is ever wanted, it is a
single `UPDATE` clearing `applicationSlug` on rows where `app = 'hermiq' AND name = 'Hydra
Triage'`.

## Open Questions

None — the create-vs-backfill mechanism and the real slug were both resolved by reading the
existing repair step's actual `run()`/`flowExists()` logic and by querying live application
data (see `design.md` for the full trace).
