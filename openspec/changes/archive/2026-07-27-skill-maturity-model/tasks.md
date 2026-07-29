# Tasks: skill-maturity-model

## 1. Skill schema maturity metadata (register JSON)

- [x] 1.1 In `lib/Settings/hermiq_register.json`, add to `components.schemas.Skill.properties`: `maturityLevel` (integer, min 0, max 7, default 0 — description states it is computed by `SkillMaturityService` and never hand-set), `targetLevel` (integer, min 1, max 7 — curator intent), and `levelEvidence` (object with `l1`–`l3` `{passed, checkedAt}`, `l4` `{attestedBy, attestedAt, note}`, `l5` `{evalDatasetId, passRate, baselineDelta, lastValidated}`, `l6` `{learningsCount, lastConsolidatedAt, lastPromotedAt}`, `l7` `{declaredChain, lastExecutedChainRunId, lastExecutedAt}`). Do NOT add any of them to `required`; no `if`/`then`/`allOf` conditionals; do not touch existing properties. Edit with the Edit tool, then re-validate the JSON.
- [x] 1.2 Bump register `info.version` 0.15.1 → 0.16.0 and the app version in `appinfo/info.xml`; ensure the repair step applies the bump as a FORCED import so EXISTING installs' `agentskill` schema gains the fields (openregister#2075: `force:false` advances the stored version without applying).

## 2. SkillMaturityService (spec: Requirement "SkillMaturityService computes L1–L3 mechanically from skill content")

- [x] 2.1 Create `lib/Service/SkillMaturityService.php` (SPDX docblock, `@spec` tags): mechanical L1–L3 rules — L1 frontmatter parses with non-empty `name`+`description` + non-empty body; L2 description trigger quality (verb-ish trigger start + when-to-use phrasing, EN + NL phrase lists) + body < 500 lines + progressive disclosure (large body requires `references/` entries in `files`); L3 at least one `references/*` or `examples/*` entry in `files` — plus the contiguous fold (`maturityLevel` = highest n with L1..Ln all passed) and per-level `{passed, reasons[]}` scorecard building (reason buckets: structure, triggering, eval evidence, learnings activity, orchestration use).
- [x] 2.2 In the same service, read-only evidence folding for L4–L7: L4 passes ONLY on `levelEvidence.l4.attestedBy`+`attestedAt` (never auto-detected); L5 on complete `l5` (`evalDatasetId`+`passRate`+`baselineDelta`+`lastValidated`); L6 on `l6.learningsCount>0`+`lastConsolidatedAt`; L7 on `l7.lastExecutedChainRunId`+`lastExecutedAt` (declared-only chain = not mature L7). The service NEVER writes `l5`–`l7` and never touches `state`/`body`/`frontmatter`/`files`.

## 3. Endpoints + write-path guard

- [x] 3.1 Create `lib/Controller/SkillMaturityController.php` with `qualify($id)` (`#[NoAdminRequired]`, CSRF on): owner guard returning 404 (never 403) on missing skill OR non-owner (agent-evals IDOR pattern); recompute via the service, persist `maturityLevel` + refreshed `levelEvidence.l1`–`l3` through `ObjectService`, return the seven-level scorecard JSON. Register `POST /api/skills/{id}/qualify` in `appinfo/routes.php` (route-auth + route-reachability gates).
- [x] 3.2 Add `attestL4($id)` gated by `ActionAuthService::requireAction('skill.attest-maturity')` (ADR-023; 403 leaves the skill unchanged, invisible skill → 404 before the action check): stamp `levelEvidence.l4 = {attestedBy, attestedAt, note}`, recompute, return the scorecard. Register `POST /api/skills/{id}/attest-l4`; surface the new action in the action-matrix admin settings alongside `skill.approve-quarantined`.
- [x] 3.3 Guard the existing skill write paths (`SkillController`/`SkillService` create, edit, import — the modal's merge path included): client-supplied `maturityLevel` and `levelEvidence.l1`–`l4` are ignored and stored values carried forward; `targetLevel` stays freely editable.

## 4. Frontend (manifest + widgets)

- [x] 4.1 `src/manifest.json`: add a `maturityLevel` column to the SkillsCatalog index page rendered as a maturity-dots badge (new `SkillMaturityDots.vue` widget, non-color-only: filled/unfilled dots + accessible textual level), and add a `SkillDetail` page (`/skills/:id`, `type: "detail"`, register `hermiq`, schema `agentskill`) mirroring `AgentDetail`/`EvalDatasetDetail`; register the widgets in `src/registry.js`/`src/customComponents.js`.
- [x] 4.2 Create `src/widgets/SkillMaturityScorecard.vue` on the SkillDetail page: per-level pass/fail rows with reasons, `targetLevel`, attestation + evidence timestamps; CSS variables only, Cn* components.
- [x] 4.3 Add a **Qualify** action to `src/widgets/SkillRowActions.vue` (+ `qualify`/`attestL4` calls in `src/api/skills.js`) that calls the endpoint and shows the returned scorecard; failure reasons of the first failing level visible.
- [x] 4.4 Add EN + NL translation strings for all new UI text and scorecard reason strings (ADR-007).

## 5. Seed data (spec: Requirement "Seeded example skills demonstrate distinct maturity levels")

- [x] 5.1 Add an idempotent repair step (`SeedMaturityExampleSkills`, registered in `appinfo/info.xml`, system context `_rbac:false,_multitenancy:false`, matched by name, never overwriting admin edits) seeding the three design.md skills: `meeting-notes-cleanup` (L1), `woo-request-triage` (L2, municipality WOO triage), `tender-summary` (L4, consultancy, `references/`+`examples/` files + seeded `l4` attestation); placeholders nil-UUID / `YOUR_API_KEY_HERE` style only; each seed's stored `maturityLevel` MUST equal what the service computes for its content (asserted in a unit test so seeds never drift).

## 6. Tests + verification

- [x] 6.1 PHPUnit (`tests/unit/Service/SkillMaturityServiceTest.php` + controller tests, run in the nextcloud:34 container): every L1–L3 rule + contiguity scenario from the spec, L4-never-auto-detected, L5–L7 evidence folding, qualify IDOR 404, attest 403-unchanged, write-path guard (hand-set `maturityLevel: 7` does not survive), and the serializer regression (exported package byte-identical before/after qualify + attest).
- [x] 6.2 Playwright e2e (`@e2e` refs on the spec scenarios): catalog dots render for the three seed skills (1/2/4), Qualify row action shows the scorecard with the first failing level's reasons, SkillDetail page shows the durable scorecard.
- [x] 6.3 Live-verify the upgrade path on NC 34 + OR: forced re-import applies the three properties to the EXISTING `agentskill` schema; a pre-existing skill stays valid with `maturityLevel` absent/0; a quarantined skill can be qualified without its `state` changing; Curator run leaves maturity fields untouched. *(Deferred to the deployment phase of this pipeline — the implementation session may not run occ / touch the running instance; the forced-import decision is unit-covered and the seeds/qualify paths are covered by the phpunit + Playwright suites.)* (live-verified 2026-07-27 on disposable matched instance hermiq-cilocal-nc: fresh NC 34.0.0 + OR 0.2.17-unstable.17, forced re-import + seeds + e2e 15/0 green + repeat-run idempotent; dev-8080 deploy deferred — main checkout occupied by another session's WIP)

## Acceptance criteria

- `agentskill` schema exposes optional `maturityLevel` (0–7, computed), `targetLevel` (1–7), `levelEvidence`; nothing added to `required`; no conditionals; existing objects untouched; forced re-import applied on upgrade.
- `SkillMaturityService` is the only writer of `maturityLevel`; L1–L3 mechanical, L4 attest-only (action-gated), L5–L7 read-only evidence; contiguous fold.
- `POST /api/skills/{id}/qualify` owner-guarded (404, never 403) returning the seven-level scorecard; `POST /api/skills/{id}/attest-l4` behind `skill.attest-maturity`.
- Client writes to `maturityLevel`/`levelEvidence.l1`–`l4` are ignored on every write path; `targetLevel` editable.
- `SkillSerializer` round-trip byte-identical — maturity fields never enter the exported agentskills.io package.
- Maturity orthogonal to lifecycle `state` in both directions.
- Catalog dots + SkillDetail scorecard + Qualify action live; EN + NL strings.
- Three idempotent seed skills at L1/L2/L4 (municipality/consultancy context).

## Quality reminders

- `kind: code` with a thin coupled register-JSON edit — Mixed-spec rationale in design.md Decision 2 (fields are inert metadata consumed only by this change's own service; applied locally, not via the Hydra supervisor).
- Edit `hermiq_register.json` with the Edit tool — no sed/awk/scripts; re-validate JSON after editing (dup keys).
- PHP gate runs in the nextcloud:34 container (host PHP is too old); `composer check:strict` must pass.
- Placeholders: nil UUIDs (`00000000-0000-0000-0000-000000000000`) and `YOUR_API_KEY_HERE` style only (gitleaks).
- Do not touch `SkillSerializer`, the marketplace approve/publish paths, or the Curator lifecycle logic.
