# Tasks: skill-evals

## 1. Eval schema deltas (register JSON)

- [x] 1.1 In `lib/Settings/hermiq_register.json` add to `components.schemas.EvalDataset.properties`: `skillRefs` (array, default `[]`, items `{type: "string", format: "uuid", "$ref": "Skill"}` — relation dialect, same shape as `Agent.skillInstalls`); and to `components.schemas.EvalRun.properties`: `baselineMode` (boolean, default false), `attributionMode` (string, enum `joint`|`per-skill` — snapshot of the mode the run used), `baselineResults` (array, same item shape as `results` — joint-mode without-half, unset in per-skill mode), `baselinePassRate` (number 0–1, nullable, unset in per-skill mode), `skillResults` (array of `{skillId (uuid, $ref Skill), passRateWith, passRateWithout, baselineDelta, baselineResults (per-skill mode only)}`). Nothing added to any `required`; no `if`/`then`/`allOf`; Edit tool only, re-validate JSON after.
- [x] 1.2 In the same file add to `components.schemas.Agent.properties`: `evalBaselineMode` (string, enum `joint`|`per-skill`, default `joint`, NOT required) with a human-friendly `title` (e.g. "Eval baseline mode") and a thorough `description` that explains, on its own, exactly what changing it does — `joint` (default): ONE without-half detaching all linked skills together, one JOINT baseline delta, ~2× the token cost of an agent-scoped run; `per-skill`: one without-half PER linked skill, true per-skill marginal deltas, (N+1)× token cost per paired run for N linked skills — the user must understand the consequences from the description alone (the UI renders this description as the info affordance).
- [x] 1.3 Bump register `info.version` 0.16.0 → 0.17.0 (rebase on skill-maturity-model's bump) + app version in `appinfo/info.xml`; repair step applies the bump as a FORCED import (openregister#2075: `force:false` advances the stored version without applying) — the `Agent` schema edit (`evalBaselineMode`) rides the SAME forced re-import as the eval schema deltas.

## 2. Run-loop skill-exposure seam (spec: Requirement "The engine run loop exposes the effective skill set to a run")

- [x] 2.1 In `lib/Service/Engine/ContextAssembler.php` (SPDX + `@spec` tags): resolve the effective skill set — per-run override when supplied, else the agent's stored `skillInstalls` — load each referenced skill via `ObjectService`, and inject frontmatter name/description + `body` into the run's system context for `state: active` skills ONLY (quarantined/stale/archived never exposed). Context exposure only — no tool-calling semantics.
- [x] 2.2 Thread an optional `skillSetOverride` parameter through `ScheduleService::runAgentAsOwner()` into the engine path; `null` (every existing caller) preserves current behaviour byte-identically apart from the new stored-installs exposure.

## 3. Paired orchestration + evidence (spec: Requirements "A paired baseline run…", "Baseline detachment…", "Both halves…", "…only writer of levelEvidence.l5", "Regression gate…")

- [x] 3.1 Extend `lib/Service/EvalRunService.php` with paired mode: gates (kill-switch + budget) checked ONCE up front; WITH half (installed ∪ skillRefs) then WITHOUT half(s) per the agent's `evalBaselineMode` — `joint` (default/absent): ONE half (installed ∖ skillRefs), JOINT delta shared across `skillResults` entries; `per-skill`: one half PER linked skill (with-set ∖ {skill}), TRUE marginal `passRateWithout`/`baselineDelta` per entry with that half's case results on the entry's `baselineResults`, top-level `baselineResults`/`baselinePassRate` unset — all halves sequential, non-delivering, via `runAgentAsOwner(...)` with the in-memory override — NO code path writes `Agent.skillInstalls`/`Skill.installedOn`; persist ONE EvalRun (`results`=with, `passRate`=with-half, `baselineMode: true`, `attributionMode` snapshot, joint-mode `baselineResults`/`baselinePassRate`, per-skill `skillResults`); EVERY half's token usage aggregated into the single per-run AuditTrail usage entry (same budgets, no separate meter); regression gate evaluated on the with-half `passRate` via the existing machinery.
- [x] 3.2 l5 write-back: on `status=completed` ONLY (both attribution modes), write `levelEvidence.l5 = {evalDatasetId, passRate, baselineDelta, lastValidated: endedAt, mode: attributionMode}` on each linked skill — the `mode` marker keeps joint evidence honest about attribution; read current object, patch ONLY `l5`, carry ALL other fields forward (OR PUT semantics); never touch `body`/`frontmatter`/`files`/`state`/`maturityLevel`; `failed`/`blocked_*` runs write nothing; this is the codebase's only l5 writer.

## 4. Trigger endpoint

- [x] 4.1 Extend `lib/Controller/EvalRunController::run()` with the optional `baseline` boolean: `400` when true with empty `skillRefs`; widened owner guard — caller must own dataset + agent + EVERY linked skill, any mismatch/missing object → `404` never `403` (agent-evals IDOR pattern); no new route.

## 5. Frontend (manifest + widgets)

- [x] 5.1 `src/widgets/SkillLinkPanel.vue` (new) on the EvalDatasetDetail page (`src/manifest.json` + registration): list linked skills, link via picker over visible active skills, unlink — plain `skillRefs` object writes via the store.
- [x] 5.2 Extend `src/widgets/EvalRunPanel.vue` + `src/api/evals.js`: "Paired baseline" toggle (enabled only when `skillRefs` non-empty, mode-dependent cost note: ≈2× `joint`, (N+1)× `per-skill` per the selected agent's `evalBaselineMode`); paired run rendering with with/without columns + per-skill `baselineDelta` (not color-only).
- [x] 5.3 `src/widgets/SkillEvalEvidence.vue` (new) on the SkillDetail page from skill-maturity-model: l5 evidence card (pass rate, baseline delta with its `mode` label — a `joint` delta is labelled as the joint contribution of the linked set — last validated, pass-rate trend across paired runs of datasets referencing this skill), honest empty state, "Run paired eval" action (linked-dataset picker + agent picker + mode-dependent cost note → the trigger endpoint).
- [x] 5.4 Surface the `evalBaselineMode` register property `description` as an info affordance (info icon/tooltip) in the AgentDetail data widget that holds the property — the joint-vs-per-skill explanation incl. the (N+1)× cost consequence is shown exactly where the user changes the value (spec scenario).
- [x] 5.5 EN + NL translation strings for all new UI text incl. the mode-dependent cost notes and joint-delta copy (ADR-007).

## 6. Seed data (design.md Seed Data)

- [x] 6.1 Extend the seed repair path with the idempotent `woo-triage-paired-eval` EvalDataset (matched by name, never overwriting admin edits, system context `_rbac:false,_multitenancy:false`): 3 municipality-context cases (contains "termijn" / notContains "klacht" / rubric with threshold 0.7), `skillRefs` resolved AT SEED TIME to the `woo-request-triage` skill's uuid (skip with a log line if absent); no EvalRun and no l5 evidence seeded (ADR-060); nil-UUID placeholders only in docs/examples.

## 7. Tests + verification

- [x] 7.1 PHPUnit (nextcloud:34 container): paired halves + joint-delta semantics (unset mode → `joint`); per-skill mode — N+1 halves, per-entry marginal deltas + entry-level `baselineResults`, top-level baseline fields unset, `attributionMode` snapshot; linked-but-not-installed skill measured identically (install state cannot skew); in-memory detachment — stored `skillInstalls`/`installedOn` byte-identical after a completed run AND after an injected crash between halves (no l5 written on `failed`); l5 write-back patch-only carry-forward with the `mode` marker on `completed`, nothing on `blocked_*`; EVERY half's usage in one budget sum (joint 2 halves, per-skill N+1); 400 on empty `skillRefs`; 404 guard incl. non-owned linked skill; regression gate on with-half vs a previous plain run.
- [x] 7.2 Playwright e2e (`@e2e` refs on the spec scenarios): link skill on dataset detail → run paired eval → with/without columns + delta render; SkillDetail evidence card shows refreshed l5 (and the empty state beforehand); marker-token skill proves WITH passes while WITHOUT fails (the seam is live, delta > 0); AgentDetail info affordance shows the `evalBaselineMode` description (semantics + (N+1)× cost) where the value is changed.
- [ ] 7.3 Live-verify on NC 34 + OR 8080-matched instance: forced re-import applies the new properties to EXISTING `evaldataset`/`evalrun` schemas; pre-existing datasets/runs stay valid; a plain (non-baseline) run is behaviourally unchanged for an agent without installed skills; `composer check:strict` green.

## Acceptance criteria

- `EvalDataset.skillRefs` + the five `EvalRun` paired fields (incl. `attributionMode`) + `Agent.evalBaselineMode` (enum `joint` default | `per-skill`, human-friendly `title` + consequence-explaining `description` incl. the (N+1)× cost) exist as optional properties (relation dialect for refs); nothing added to `required`; ONE forced re-import on upgrade covers all three schemas.
- Paired mode runs through the real engine path with model/tools/prompt/owner frozen, per the agent's `evalBaselineMode`: `joint` (default/absent) = every case twice, one without-half, joint delta shared across `skillResults`; `per-skill` = N+1 halves, true per-entry marginals with entry-level `baselineResults`, top-level baseline fields unset; with-half always `installed ∪ linked` (install state cannot skew — a skill can be qualified before install); `400` on empty `skillRefs`.
- Engine context assembly exposes the effective skill set (override else stored installs), `active` skills only; a content-bearing skill demonstrably changes output between halves.
- Detachment is per-run in-memory only — no write to `skillInstalls`/`installedOn` on any path, crash-safe between halves.
- Kill-switch + budget gates checked once up front; EVERY half counts toward the SAME budgets; mode-dependent cost (≈2× joint, (N+1)× per-skill) stated on both trigger surfaces AND in the property description.
- Completed paired runs are the ONLY l5 writer in BOTH modes: `{evalDatasetId, passRate, baselineDelta, lastValidated, mode}` per linked skill (the `mode` marker keeps joint evidence honest), patch-only carry-forward; `failed`/`blocked_*` write nothing; `maturityLevel` untouched.
- Trigger owner-guarded across dataset + agent + every linked skill, 404-never-403; regression gate unchanged, fed by the with-half pass rate.
- Dataset detail link/unlink + paired rendering; SkillDetail evidence card + Run paired eval; `evalBaselineMode` description surfaced as an info affordance in the AgentDetail data widget; EN + NL; WCAG 2.2 AA (delta not color-only).

## Quality reminders

- `kind: code`, `depends_on: [skill-maturity-model]` — the l5 contract, its silent-preserve write protection, and the SkillDetail page come from the predecessor; do not redefine them.
- Edit `hermiq_register.json` with the Edit tool — no sed/awk/scripts; re-validate JSON after (dup keys).
- Reuse, never fork: `EvalScoringService`, `BudgetService`, gate checks, regression formula, and the 404-never-403 guard are the agent-evals machinery — no parallel eval system.
- PHP gate runs in the nextcloud:34 container (host PHP too old); placeholders nil UUIDs / `YOUR_API_KEY_HERE` style only (gitleaks).
- Coordinate with the parallel `skill-learnings` change if it also touches `ContextAssembler` (design.md Open Questions).
