---
kind: code
---

# Proposal: skills-marketplace

# Why

The `skills-marketplace` capability spec (V2, status: idea) extends the skills catalog to
share skills across tenants and publish to/consume from external hubs (ClawHub, skills.sh)
in agentskills.io format, with **quarantine + security scan** before an externally-sourced
skill can run, and a **Curator** that manages the lifecycle without ever hard-deleting a
skill. This change builds the Hermiq-ownable surface of that spec on top of skills-catalog.

# What Changes

- Extend the `Skill` schema: add `quarantined` to the `state` enum; add `source`
  (`local|org|hub`, default `local`), `staleSince` and `archivedAt` (datetime) for
  curation, and `quarantineReason`.
- Add `lib/Service/SkillMarketplaceService.php`:
  - `installFromSource(package|skillId, source)` — a skill from **another org or a hub**
    is created/copied in the **`quarantined`** state and MUST NOT be usable until it passes
    review. (OpenRegister has no content-scan service — `SecurityService` is auth
    rate-limiting — so the scan gate is an explicit review/approval step, a documented
    seam; the quarantine invariant is enforced regardless.)
  - `approveQuarantined(skillId)` — the review gate: a quarantined skill transitions to
    `active` only via this explicit approval.
  - `curate()` — age-based lifecycle: `active` → `stale` after a staleness threshold, then
    `stale` → `archived` after an archival threshold; **never hard-deletes** the object or
    its files. (Usage-based staleness needs the OR run loop to stamp last-used — a seam.)
  - `publishToHub(skillId, hubId)` — serialise via the skills-catalog `SkillSerializer`
    and route the outbound submission through **OpenConnector `CallService`** (no direct
    HTTP); a structured error when no hub connector is configured.
- Add `lib/BackgroundJob/SkillCuratorTask.php` (`TimedJob` → `SkillMarketplaceService::curate()`),
  registered in `appinfo/info.xml` alongside the existing dispatcher job (single
  `<background-jobs>` block — a second block breaks the NC upgrade).
- Add `lib/Controller/SkillMarketplaceController.php` + routes (install-from-source,
  approve, publish); surface quarantine state + an Approve action in the Skills UI.

# Impact

- Affected specs: `skills-marketplace` (idea → active, with documented scan/hub seams).
- Affected code: `lib/Settings/hermiq_register.json`, `lib/Service/SkillMarketplaceService.php`,
  `lib/BackgroundJob/SkillCuratorTask.php`, `lib/Controller/SkillMarketplaceController.php`,
  `appinfo/routes.php`, `appinfo/info.xml`, `src/views/SkillsCatalog.vue`,
  `src/api/skills.js`, `tests/Unit/Service/SkillMarketplaceServiceTest.php`.
- Seams (documented, not implemented): the content **security scan** (no OR scanner
  exists); usage-based staleness (OR run-loop stamps last-used); a live external **hub**
  (needs an OpenConnector connector + a reachable hub).
