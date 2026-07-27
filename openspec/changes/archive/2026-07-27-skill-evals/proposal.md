---
kind: code
depends_on: [skill-maturity-model]
---

# Proposal: skill-evals

## Summary

Make evals skill-scoped so they produce the L5 qualification evidence defined by
`skill-maturity-model` (ADR-068 §4): the `EvalDataset` schema gains `skillRefs` (a dataset
can be linked to one or more skills), `EvalRun` gains a **paired with-skill vs
without-skill baseline mode** in which the same cases run twice through the agent's real
engine path — once with the linked skill(s) exposed, once with them detached in-memory —
with everything else frozen (agent, model, tools, budget). The baseline strategy is
agent-level configuration — the `Agent` schema gains `evalBaselineMode`, enum `joint`
(default: one without-half detaching all linked skills together, ~2× cost) |
`per-skill` (one without-half PER linked skill: true per-skill marginal attribution at
(N+1)× token cost per paired run) — carried by a register property whose title and
description explain the consequences where the value is changed. Completing a paired run
computes, per linked skill, pass rate with, pass rate without, and `baselineDelta`, and
writes `levelEvidence.l5` (`{evalDatasetId, passRate, baselineDelta, lastValidated,
mode}` — the `mode` marker keeps joint evidence honest about attribution) on
each linked skill — the ONLY writer of l5 evidence. Everything reuses the agent-evals
machinery wholesale (non-delivering runs, deterministic + LLM-judge scoring via
`ProviderFactory`, kill-switch/budget gates, regression gate, owner-guarded trigger); no
parallel eval system is introduced. Because the engine's run loop does not yet consume
`skillInstalls`, this change also lands the minimal run-loop skill-exposure seam the
skills-catalog spec reserved — without it, a with/without comparison measures nothing.

## Motivation

ADR-068 makes skill-scoped evals the L5 evidence: "every skill change is a paired
experiment" (Arize). Today `agent-evals` is agent-scoped — it can tell an operator whether
an *agent* behaves, but not what a *skill* contributes at the margin. `skill-maturity-model`
landed the `levelEvidence.l5` contract (`evalDatasetId`, `passRate`, `baselineDelta`,
`lastValidated`) but deliberately only reads it; nothing writes it, so no hermiq skill can
reach L5, and the eval-gated self-improvement path (`skill-self-improvement`) has no
regression evidence to gate on. This change closes that gap with the smallest possible
surface: a relation property, a run mode, and an evidence write-back.

## Capabilities

### New Capabilities

- None. Skill-scoped evals are an extension of the existing eval engine and of the
  maturity evidence contract, not a new capability.

### Modified Capabilities

- `agent-evals`: `EvalDataset` gains `skillRefs`; `EvalRun` gains the paired baseline
  mode (`baselineMode`, `attributionMode` snapshot, per-skill paired results,
  mode-dependent cost semantics under the SAME budget gates); the `Agent` schema gains
  `evalBaselineMode` (`joint` default | `per-skill`) with a consequence-explaining
  title + description surfaced as an info affordance on the agent detail surface; the
  run-trigger endpoint accepts the paired mode owner-guarded as
  before; the run-loop skill-exposure seam and the in-memory-only detachment rule; the
  EvalDataset detail UI gains skill link/unlink.
- `skill-maturity`: the `levelEvidence.l5` contract gains its writer — a completed
  paired run stamps l5 evidence on each linked skill through the service-written,
  silent-preserve write contract defined by `skill-maturity-model`. The SkillDetail
  page gains an eval-evidence card and a "Run paired eval" action.

## Affected Projects

- [ ] Project: `hermiq` — `EvalDataset`/`EvalRun`/`Agent` schema extension in
  `lib/Settings/hermiq_register.json` (register version bump + forced re-import);
  `EvalRunService` paired-mode orchestration + l5 evidence write-back; run-loop
  skill-exposure seam (`ScheduleService::runAgentAsOwner()` → engine path);
  `EvalRunController` paired trigger; `src/manifest.json`/widgets (dataset skill
  link/unlink, SkillDetail eval-evidence card + Run paired eval); unit + e2e tests.

## Scope

### In Scope

- `EvalDataset.skillRefs`: optional array of skill UUIDs per the relation dialect
  (items `type: string`, `format: uuid`, `$ref: Skill`) — link/unlink via the normal
  object write path.
- Paired baseline mode on EvalRun: same cases, with-half plus without-half(s) through
  `ScheduleService::runAgentAsOwner()` — with-half exposes the linked skills, the
  without-half(s) detach them **per-run, in-memory only** (the agent's stored
  `skillInstalls` and the skills' `installedOn` are NEVER mutated); model, tools,
  prompt, and budget identical.
- `Agent.evalBaselineMode` (enum `joint` default | `per-skill`): agent-level
  configuration of the without-half strategy — `joint` runs ONE without-half detaching
  all linked skills together (joint delta, ~2× cost); `per-skill` runs one without-half
  PER linked skill (true per-skill marginals, (N+1)× token cost per paired run). The
  register property carries a human-friendly title and a thorough description
  explaining exactly these consequences, surfaced as an info affordance (info
  icon/tooltip) in the agent detail data widget that holds the property.
- The minimal run-loop skill-exposure seam: an engine run exposes the content of the
  agent's effective skill set, with a per-run override parameter threaded down the
  existing `runAgentAsOwner()` path (this is what "installed vs detached" varies).
- Per-skill paired result on the EvalRun: `passRateWith`, `passRateWithout`,
  `baselineDelta` per linked skill (shared joint numbers in `joint` mode; true
  marginals with per-entry without-half results in `per-skill` mode), plus the
  `attributionMode` snapshot.
- l5 evidence write-back: completing a paired run (in either mode) writes
  `levelEvidence.l5 = {evalDatasetId, passRate, baselineDelta, lastValidated, mode}` on
  each linked skill via the skill-maturity write contract (service-written; client
  writes silently preserved-over).
- Wholesale reuse of agent-evals machinery: non-delivering, deterministic + rubric
  scoring through `ProviderFactory`, kill-switch + budget gates (every half counts
  toward the SAME budgets), regression gate vs the previous run, owner-guarded trigger
  (404-never-403).
- UI: skill link/unlink on the EvalDataset detail surface; eval-evidence card (pass
  rate, baseline delta, trend, last validated) + owner-guarded "Run paired eval" action
  on the SkillDetail page created by `skill-maturity-model`.

### Out of Scope

- Learnings capture/consolidation and the gated self-improvement loop
  (`skill-learnings`, `skill-self-improvement` — parallel changes; self-improvement
  will CONSUME this change's regression evidence).
- L6/L7 evidence, orchestration chains (`skill-orchestration`).
- Any change to scoring semantics, budget math, or the regression-gate formula — they
  are reused, not redefined.
- Automatic/scheduled eval runs, eval-driven skill promotion, or writing
  `maturityLevel` directly (only `SkillMaturityService::qualify()` folds evidence into
  a level).
- A general skill-execution framework: the exposure seam injects skill content into the
  run context; tool-calling semantics for skills are deferred.

## Approach

Extend the two eval schemas declaratively (`skillRefs` on EvalDataset; paired-mode fields
on EvalRun) with a register version bump + forced re-import. Thread an optional per-run
effective-skill-set override through `ScheduleService::runAgentAsOwner()` into the engine
context assembly (the run-loop seam), defaulting to the agent's stored `skillInstalls`.
`EvalRunService` gains a paired orchestration wrapper: gate checks once up front, then the
existing case-execution path once for the with-half (with-override = installed ∪ linked)
plus without-half(s) per the agent's `evalBaselineMode` — `joint` (default): one half at
installed ∖ linked; `per-skill`: one half per linked skill at with-set ∖ {skill} —
aggregate per-skill, evaluate the regression gate on the with-half
pass rate, persist one EvalRun object carrying all halves, then write l5 evidence per
linked skill through OpenRegister `ObjectService` (silent-preserve contract). UI stays
manifest-driven plus two custom widgets. Full ADR-031 declarative-vs-imperative rationale
in design.md.

## New Dependencies

None.

## Impact

- `lib/Settings/hermiq_register.json` — `EvalDataset` + one optional property;
  `EvalRun` + paired-mode properties (incl. the `attributionMode` snapshot); `Agent` +
  `evalBaselineMode` (enum `joint` default | `per-skill`) with a human-friendly title
  and a consequence-explaining description; register `info.version` bump — the agent
  schema edit rides the same forced re-import; nothing added to
  `required`, no existing object becomes invalid.
- `lib/Service/EvalRunService.php` (paired orchestration + l5 write-back),
  `lib/Service/ScheduleService.php` (override parameter), engine context assembly
  (skill-exposure seam), `lib/Controller/EvalRunController.php` (paired trigger +
  linked-skill ownership guard).
- `src/manifest.json` (EvalDatasetDetail + SkillDetail widgets), new/extended widgets,
  `src/api/evals.js`.
- NOT impacted: `EvalScoringService` (scoring reused verbatim), `BudgetService`,
  `SkillSerializer`/export, marketplace paths, `SkillMaturityService` level-folding
  rules (it already reads complete l5 evidence — no code change there).

## Cross-Project Dependencies

- Depends on `skill-maturity-model` (same repo): the `levelEvidence.l5` field contract
  and its write-contract (computed fields are service-written; client writes are
  silently preserved-over), plus the SkillDetail page this change adds a card to.
- ADR-068 §4 (hydra `openspec/architecture/`) is the architectural decision implemented.
- OpenRegister: schema re-import via `ConfigurationService::importFromApp(force: true)`;
  no OR code change.

## Risks

### Risk 1: Green-but-dead baseline — engine ignores skills, delta is structurally 0
**Severity:** High — **Mitigation:** the run-loop skill-exposure seam is an explicit
requirement of this change with its own scenario (a skill whose content deterministically
changes output MUST produce different outputs with vs without). A paired run against an
engine path that does not expose skills would complete green while measuring nothing.

### Risk 2: Baseline detachment leaks into stored state
**Severity:** High — **Mitigation:** detachment is a per-run in-memory override parameter;
no code path in the paired run calls `SkillService::installOnAgent()`/uninstall or writes
`Agent.skillInstalls`/`Skill.installedOn`. A crash mid-run MUST NOT leave the agent
stripped — asserted by an explicit requirement + failure-injection test.

### Risk 3: Multiplied token cost per paired run — 2× joint, (N+1)× per-skill
**Severity:** Medium — **Mitigation:** stated up front in design.md, in the
`evalBaselineMode` property description (surfaced as an info affordance exactly where
the mode is changed), and in the trigger UI: a `joint` paired run costs ~2× an
agent-scoped run; `per-skill` costs (N+1)× for N linked skills. Every half counts
toward the SAME per-org/per-agent
budgets (agent-evals precedent), so the existing hard-cap gate bounds the spend; the gate
is checked before the run starts.

### Risk 4: l5 write-back races or clobbers skill content
**Severity:** Medium — **Mitigation:** the write-back reads the current skill object and
patches ONLY `levelEvidence.l5` (carry-all-fields-forward per the OR saveObject
PUT-semantics gotcha); it never touches `body`/`frontmatter`/`files`/`state`.

### Risk 5: Schema re-import silently not applied on existing installs
**Severity:** Low — **Mitigation:** known OR gotcha (openregister#2075); the repair step
forces the import; upgrade path covered in the test plan.

## Rollback Strategy

Revert the code (service orchestration, override parameter, controller, widgets, manifest
entries). The new schema properties are optional and inert — they stay in place without
invalidating any object. Already-written `levelEvidence.l5` evidence remains valid data
under the `skill-maturity-model` contract (its reader ships independently); stale evidence
simply ages via `lastValidated`. No data migration to undo.

## Open Questions

None blocking — deferred decisions are recorded in design.md.
