# Tasks: hermiq-seed-hydra-flows

> **Decision (2026-11-24, build round): (a), vendored definitions.** Costed (c) first: OpenRegister's
> configuration import (`ImportHandler`) carries no flow-table rows, so (c) needs an OpenRegister change
> before hermiq could use it. (a) ships today: `lib/Settings/flows/*.flow.json`, copied from hydra
> `development` (402b5ce) by `scripts/sync-bundled-flows.sh`, with hydra kept as the source of truth
> (`lib/Settings/flows/README.md`). Ten definitions: hydra's twelve minus `hydra-triage` (hermiq's
> `SeedHydraTriageFlow` owns "Hydra Triage", and hydra's README says two seeders on one name is worse
> than drift) and `hydra-applier-live-4-1-proof` (a provenance duplicate of `hydra-applier`).

- [x] Decide where the definitions live: (c) OpenRegister configuration imported
      via `ConfigurationService::importFromApp()` — cost this first — falling back
      to (a) vendored `lib/Settings/flows/*.flow.json`.
      ⚠️ A non-forced `importFromApp` advances the version WITHOUT applying.
      - (a), see the decision above.
- [x] Repair step iterating the ten definitions, modelled on
      `lib/Repair/SeedHydraTriageFlow.php` (`saveObject(..., _rbac: false,
      _multitenancy: false)` — the session "blocker" is already solved there).
      - `SeedBundledFlows` (post-migration and install), writing into OpenRegister's native flow store
        through `FlowMapper::insert()` as `SeedHydraTriageFlow` now does, on the default organisation
        (`DefaultOrganisationLookup`; refuses to write an org-less row, hermiq#140).
- [x] Seed DISABLED with no owner, per SeedHydraTriageFlow's documented rule: a
      trigger fires with no acting user, so enabling is the human act that
      supplies the owner. Every one of these flows writes to the forge.
      - Also for the four definitions that say `enabled: true`. `SeedBundledFlowsTest`.
- [x] Idempotent — check by name before writing.
      - By name across every app and organisation, so the hand-imported `hydra-file-findings` and
        `hydra-record-stage` rows are left alone. `SeedBundledFlowsTest`.
- [x] Preflight each definition (or#2254) and report per flow, so an instance
      that legitimately lacks openconnector gets a clear warning.
      - `FlowNodePreflight::inspect()`; a blocking finding is a warning naming the reason and the flow is
        not seeded. Seeds without it on an OpenRegister that has no preflight.
- [x] Do NOT swallow a real failure into `$output->warning()`. SeedHydraTriageFlow
      catches every Throwable, so a failed seed still reports a clean install —
      the same green-but-dead shape this batch removes.
      - Every flow is attempted, then the step throws naming the failed ones (a write error or an
        unreadable definition file). Per-flow outcome in `occ config:app:get hermiq bundled_flow_seed`.
- [x] Fix the four flows whose config dialect is wrong BEFORE seeding them
      (hydra-analyze-verdicts, hydra-record-stage, hydra-retry-escalate,
      hydra-lock-reaper) — or#2254 will now refuse them at save.
      - Fixed in hydra since (node-form regeneration hydra#529-#533 and later): on 402b5ce every
        openregister node's config keys are inside that node's `configKeys()` in OpenRegister main,
        `config.onError` is only `stop` on openregister nodes (a warning, not a refusal) and on
        openconnector nodes (which read it themselves), and `scripts/test-flow-definitions.sh`, which
        checks the same dialect rules, exits 0. A definition preflight still refuses is reported, not seeded.
