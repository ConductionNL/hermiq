# Tasks: hermiq-seed-hydra-flows

- [ ] Decide where the definitions live: (c) OpenRegister configuration imported
      via `ConfigurationService::importFromApp()` — cost this first — falling back
      to (a) vendored `lib/Settings/flows/*.flow.json`.
      ⚠️ A non-forced `importFromApp` advances the version WITHOUT applying.
- [ ] Repair step iterating the ten definitions, modelled on
      `lib/Repair/SeedHydraTriageFlow.php` (`saveObject(..., _rbac: false,
      _multitenancy: false)` — the session "blocker" is already solved there).
- [ ] Seed DISABLED with no owner, per SeedHydraTriageFlow's documented rule: a
      trigger fires with no acting user, so enabling is the human act that
      supplies the owner. Every one of these flows writes to the forge.
- [ ] Idempotent — check by name before writing.
- [ ] Preflight each definition (or#2254) and report per flow, so an instance
      that legitimately lacks openconnector gets a clear warning.
- [ ] Do NOT swallow a real failure into `$output->warning()`. SeedHydraTriageFlow
      catches every Throwable, so a failed seed still reports a clean install —
      the same green-but-dead shape this batch removes.
- [ ] Fix the four flows whose config dialect is wrong BEFORE seeding them
      (hydra-analyze-verdicts, hydra-record-stage, hydra-retry-escalate,
      hydra-lock-reaper) — or#2254 will now refuse them at save.
