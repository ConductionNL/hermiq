# Tasks: skills-marketplace

## 1. Schema patch

- [x] 1.1 Extend the `Skill` (`agentskill`) schema: add `quarantined` to the `state` enum; add `source` (enum `local|org|hub`, default `local`), `staleSince` (datetime), `archivedAt` (datetime), `quarantineReason` (string). Bump the register `info.version`; import via the repair step; confirm the enum + fields exist (no regression).

## 2. SkillMarketplaceService

- [x] 2.1 Create `lib/Service/SkillMarketplaceService.php` (SPDX): `installFromSource(package, source, createdBy)` — parse via `SkillSerializer`, save a `Skill` with `state='quarantined'`, `source`, `quarantineReason`; an externally-sourced skill MUST NOT be `active`.
- [x] 2.2 `approveQuarantined(skillId)` — the review gate: transition a `quarantined` skill to `active` (records the decision); a non-quarantined skill is unchanged.
- [x] 2.3 `curate()` — age-based lifecycle: `active`→`stale` when older than the staleness threshold, `stale`→`archived` when older than the archival threshold; set `staleSince`/`archivedAt`; NEVER delete the object. Thresholds from app config (`skillStaleDays` default 90, `skillArchiveDays` default 180).
- [x] 2.4 `publishToHub(skillId, hubId)` — serialise via `SkillSerializer` and submit through OpenConnector `CallService` (resolved lazily); a structured error when OpenConnector/the hub connector is unavailable (no direct HTTP).

## 3. Curator background job

- [x] 3.1 Add `lib/Cron/SkillCuratorTask.php` (`TimedJob`, daily) delegating to `SkillMarketplaceService::curate()` (thin wrapper, ADR-002). Register it in `appinfo/info.xml` inside the SINGLE existing `<background-jobs>` block (a second block breaks the NC upgrade).

## 4. Controller + routes

- [x] 4.1 Create `lib/Controller/SkillMarketplaceController.php` (`@NoAdminRequired`, `@NoCSRFRequired`): `installFromSource()`, `approve(id)`, `publish(id)` — RBAC-scoped, cross-tenant denied.
- [x] 4.2 Register the routes in `appinfo/routes.php`.

## 5. UI

- [x] 5.1 Extend `src/api/skills.js` with install-from-source, approve, publish.
- [x] 5.2 Extend `src/views/SkillsCatalog.vue`: show the `quarantined` state distinctly with an **Approve** action (only for quarantined skills), and a **Publish** action; an "Install from hub/source" affordance that lands the skill in quarantine.

## 6. Verify

- [x] 6.1 Unit-test `SkillMarketplaceService` the CI way: `installFromSource` yields `state='quarantined'` (never active); `approveQuarantined` → `active`; `curate()` transitions `active`→`stale`→`archived` by age and NEVER deletes; `publishToHub` returns a structured error with no hub connector.
- [x] 6.2 Verify live on NC + OR: install-from-source → a `quarantined` skill (not usable); approve → `active`; run the curator with low thresholds → a stale/archived transition with the object still present; publish with no connector → structured error. Playwright-test the quarantine badge + Approve action with 0 console errors.

## Acceptance criteria

- Skills installed from another org or an external hub start `quarantined` and MUST NOT become `active` until the review gate passes (the content security scan is a documented OR seam).
- The Curator transitions `active`→`stale`→`archived` by threshold and NEVER hard-deletes a skill or its files.
- Publishing serialises via `SkillSerializer` and routes outbound through OpenConnector `CallService` — no direct third-party HTTP; a structured error when unavailable.

## Quality reminders

- SPDX in each PHP docblock; pass `composer phpcs` (lib scope) + PHPStan; run PHPUnit the CI way.
- Single write-path via OR `ObjectService`; flat declarative schema (no `if`/`then`).
- SINGLE `<background-jobs>` block in info.xml; the Curator is a thin TimedJob wrapper (ADR-002).
- No sed/awk/scripts on code — Edit tool only; `@spec` tags; i18n keys in English.
