# Test Plan: skill-evals

## Test Cases

### TC-1: skillRefs relation property + forced re-import
- **spec_ref**: `openspec/changes/skill-evals/specs/agent-evals/spec.md#requirement-an-evaldataset-links-skills-via-skillrefs-per-the-relation-dialect`
- **type**: regression
- **preconditions**: install with a pre-existing 0.16.0 register, one pre-existing EvalDataset and EvalRun
- **steps**: upgrade the app; inspect the imported `evaldataset`/`evalrun`/`agent` schemas; link and unlink a skill via a plain object write
- **expected result**: existing schemas actually gain the new optional properties (forced import), incl. `Agent.evalBaselineMode` with its `title` and consequence-explaining `description` riding the same re-import; pre-existing objects valid with `skillRefs`/`evalBaselineMode` absent (agents read as `joint`); link/unlink round-trips through the generic path
- **test command**: `/test-regression`

### TC-2: Paired run (joint default) — both halves, frozen everything, joint delta
- **spec_ref**: `openspec/changes/skill-evals/specs/agent-evals/spec.md#requirement-a-paired-baseline-run-executes-with-and-without-halves-per-evalbaselinemode`
- **type**: api (PHPUnit unit + `/test-api`)
- **preconditions**: agents with `evalBaselineMode` unset and `joint`; dataset with 4 cases linked to one skill that is NOT installed on the agent; a second dataset linked to two skills; a dataset with empty `skillRefs`
- **steps**: trigger `baseline: true` on each
- **expected result**: one EvalRun per trigger with `results` (with) + `baselineResults` (without) + `passRate`/`baselinePassRate` + `attributionMode: joint` (also for the unset-mode agent) + per-skill `skillResults`; the linked-but-not-installed skill is exposed in the WITH half and detached in the WITHOUT half exactly like an installed one (install state cannot skew — qualification before install works); two-skill dataset executes exactly two halves and both entries share joint numbers; empty `skillRefs` → 400 with zero cases executed; no delivery call in either half
- **test command**: `docker run --rm -v $PWD:/app -w /app <nc-image> php vendor/bin/phpunit tests/unit/Service/EvalRunServicePairedTest.php` + `/test-api`

### TC-3: The exposure seam is live (green-but-dead killer)
- **spec_ref**: `openspec/changes/skill-evals/specs/agent-evals/spec.md#requirement-the-engine-run-loop-exposes-the-effective-skill-set-to-a-run`
- **type**: functional
- **persona**: Priya (ZZP developer / integrator)
- **preconditions**: a marker-token skill (body: "always include the token PAIRED_EVAL_MARKER") linked to a dataset with a `contains: PAIRED_EVAL_MARKER` case; a quarantined skill referenced by another dataset
- **steps**: run the paired eval live; run the quarantined-skill dataset paired
- **expected result**: WITH half passes, WITHOUT half fails, `baselineDelta` > 0 — proving the engine actually consumes skill content; the quarantined skill's content never appears in the run context (its delta is 0 and the WITH output lacks its marker)
- **test command**: `/test-functional`

### TC-4: In-memory detachment is crash-safe
- **spec_ref**: `openspec/changes/skill-evals/specs/agent-evals/spec.md#requirement-baseline-detachment-is-per-run-and-in-memory-only`
- **type**: api (PHPUnit, failure injection)
- **preconditions**: agent with two installed skills, one dataset-linked; snapshot of `skillInstalls` + both skills' `installedOn`
- **steps**: complete a paired run; then force the WITHOUT half to throw after the WITH half completed
- **expected result**: stored `skillInstalls`/`installedOn` byte-identical to the snapshots in BOTH outcomes; the crashed run records `status=failed` and writes no `levelEvidence.l5`
- **test command**: phpunit (as TC-2)

### TC-5: Gates once, every half in one budget sum, mode-dependent cost surfaced
- **spec_ref**: `openspec/changes/skill-evals/specs/agent-evals/spec.md#requirement-every-half-of-a-paired-run-counts-toward-the-same-budgets-and-gates`
- **type**: api
- **preconditions**: org A with budget hard cap reached; org B healthy with known usage
- **steps**: trigger paired on org A; complete a paired run on org B; evaluate `BudgetService::isBlocked()` for B; inspect the trigger UI copy
- **expected result**: A records `blocked_budget` with zero cases in any half; B's sum includes both halves' tokens (with + without + judge calls) in the SAME aggregation as scheduled runs; both trigger surfaces state the mode-dependent cost (≈2× joint, (N+1)× per-skill)
- **test command**: `/test-api`

### TC-6: l5 write-back — only writer, only on completed, patch-only
- **spec_ref**: `openspec/changes/skill-evals/specs/agent-evals/spec.md#requirement-a-completed-paired-run-is-the-only-writer-of-levelevidencel5`
- **type**: api
- **preconditions**: linked skill with content, state, files, and a stored `maturityLevel`; runs engineered to end `completed`, `failed`, `blocked_killswitch`
- **steps**: run each; then qualify the skill via the skill-maturity endpoint
- **expected result**: only the completed run stamps `l5 = {evalDatasetId, passRate, baselineDelta, lastValidated=endedAt, mode=attributionMode}`; skill `body`/`frontmatter`/`files`/`state`/`installedOn`/`maturityLevel` untouched by the write; the next qualify folds the evidence to L5 (given L1–L4 pass) — proving the skill-maturity-model contract is satisfied end-to-end
- **test command**: `/test-api` + phpunit (as TC-2)

### TC-7: Widened IDOR guard
- **spec_ref**: `openspec/changes/skill-evals/specs/agent-evals/spec.md#requirement-the-paired-trigger-owner-guard-covers-dataset-agent-and-every-linked-skill`
- **type**: security
- **persona**: Noor (Municipal CISO / functional admin)
- **preconditions**: dataset + agent owned by user A, `skillRefs` referencing a skill owned by user B; plus user B attempting A's dataset
- **steps**: A triggers `baseline: true`; B triggers on A's dataset; repeat with a nil-UUID dataset id
- **expected result**: 404 (never 403) in every case — no existence confirmation for dataset, agent, or linked skill; zero cases executed
- **test command**: `/test-security`

### TC-8: Regression gate on the with-half
- **spec_ref**: `openspec/changes/skill-evals/specs/agent-evals/spec.md#requirement-the-regression-gate-applies-to-a-paired-runs-with-half-pass-rate`
- **type**: api
- **preconditions**: a previous completed PLAIN run at `passRate=0.90` for the same dataset+agent; threshold 10 percentage points
- **steps**: complete a paired run whose with-half scores 0.75
- **expected result**: `regressionGateResult=failed`, `previousPassRate=0.90` — existing machinery, fed by the with-half rate
- **test command**: `/test-api`

### TC-9: Dataset detail — link panel + paired rendering
- **spec_ref**: `openspec/changes/skill-evals/specs/agent-evals/spec.md#requirement-the-evaldataset-detail-surface-manages-skill-links-and-paired-runs`
- **type**: functional
- **persona**: Priya
- **preconditions**: seeded `woo-triage-paired-eval` dataset (linked to `woo-request-triage`) + one unlinked dataset
- **steps**: open both dataset detail pages; link/unlink a skill; run paired on the seeded dataset; open the run
- **expected result**: baseline toggle disabled on the unlinked dataset, enabled with cost note on the linked one; with/without columns + per-skill delta render with failing cases distinguishable in both halves
- **test command**: `/test-functional`

### TC-10: SkillDetail evidence card + Run paired eval
- **spec_ref**: `openspec/changes/skill-evals/specs/skill-maturity/spec.md#requirement-skilldetail-surfaces-eval-evidence-and-a-run-paired-eval-action`
- **type**: functional
- **preconditions**: `woo-request-triage` skill before any paired run; then after TC-9's run
- **steps**: open `/skills/:id` before (honest empty state, no fabricated metric); use Run paired eval (dataset + agent pickers, cost note); reopen after completion
- **expected result**: empty state first; after the run the card shows pass rate, baseline delta, last validated, and the trend includes the run
- **test command**: `/test-functional`

### TC-11: Accessibility of the new surfaces
- **spec_ref**: `openspec/changes/skill-evals/specs/agent-evals/spec.md#requirement-the-evaldataset-detail-surface-manages-skill-links-and-paired-runs`
- **type**: accessibility
- **persona**: Henk (elderly citizen)
- **steps**: audit the skill-link panel, baseline toggle, paired columns/delta badges, the evidence card, and the `evalBaselineMode` info affordance on the agent detail widget — delta not color-only, accessible names, keyboard-reachable tooltip, target size 2.5.8
- **expected result**: WCAG 2.2 AA on the touched surfaces
- **test command**: `/test-accessibility`

### TC-12: Plain runs unchanged (regression)
- **spec_ref**: `openspec/changes/skill-evals/specs/agent-evals/spec.md#requirement-the-engine-run-loop-exposes-the-effective-skill-set-to-a-run`
- **type**: regression
- **preconditions**: an agent with no installed skills; an existing non-baseline eval flow
- **steps**: trigger a plain (default `baseline: false`) run; compare behaviour/fields with pre-change expectations
- **expected result**: byte-identical behaviour for skill-less agents; no paired fields set; existing eval UI unaffected
- **test command**: `/test-regression`

### TC-13: Per-skill mode — N+1 halves, true marginals, (N+1)× in one budget sum
- **spec_ref**: `openspec/changes/skill-evals/specs/agent-evals/spec.md#requirement-a-paired-baseline-run-executes-with-and-without-halves-per-evalbaselinemode`
- **type**: api (PHPUnit unit + `/test-api`)
- **preconditions**: agent with `evalBaselineMode: per-skill`; dataset with 3 cases linked to two skills — one marker-token skill that flips a `contains` case, one inert skill
- **steps**: trigger `baseline: true`; complete the run; inspect the run object, the org budget sum, and each linked skill's `levelEvidence.l5`
- **expected result**: exactly three halves execute (one WITH + one WITHOUT per skill; each case runs 3 times); each `skillResults` entry carries its OWN `passRateWithout`/`baselineDelta` from its dedicated without-half (marker skill delta > 0, inert skill delta ≈ 0) with that half's case results on the entry's `baselineResults`; top-level `baselineResults`/`baselinePassRate` unset; `attributionMode: per-skill`; all three halves' tokens (incl. judge calls) land in ONE budget sum; each linked skill's l5 stamped with `mode: per-skill` and its own marginal delta
- **test command**: `docker run --rm -v $PWD:/app -w /app <nc-image> php vendor/bin/phpunit tests/unit/Service/EvalRunServicePairedTest.php` + `/test-api`

### TC-14: evalBaselineMode info affordance on the agent detail surface
- **spec_ref**: `openspec/changes/skill-evals/specs/agent-evals/spec.md#requirement-the-agent-schema-declares-evalbaselinemode-with-a-consequence-explaining-description`
- **type**: functional
- **persona**: Priya (ZZP developer / integrator)
- **preconditions**: register imported with the bumped `Agent` schema; an agent detail page whose data widget holds `evalBaselineMode`
- **steps**: open the agent detail page; engage the info affordance (info icon/tooltip) on the `evalBaselineMode` property; read the text; switch the value `joint` → `per-skill` and back
- **expected result**: the affordance renders the register property's description in place — joint-vs-per-skill semantics AND the 2× vs (N+1)× cost consequence, understandable without leaving the widget; the value round-trips through the generic object path; an agent with the property unset behaves as `joint`
- **test command**: `/test-functional`

## Coverage Summary

| Requirement | Covered by |
|---|---|
| skillRefs per relation dialect + forced import | TC-1 |
| Agent evalBaselineMode property + consequence description + info affordance | TC-1, TC-14, TC-11 |
| Paired baseline run per evalBaselineMode — joint default | TC-2, TC-9 |
| Paired baseline run per evalBaselineMode — per-skill marginals | TC-13 |
| Install state cannot skew the comparison (qualify before install) | TC-2 |
| Run-loop skill-exposure seam (active-only) | TC-3, TC-12 |
| Detachment in-memory only, crash-safe | TC-4 |
| Same budgets/gates for every half, mode-dependent cost surfaced | TC-5, TC-13 |
| Completed-run-only l5 writer, patch-only, mode marker | TC-6, TC-13 |
| Widened owner guard (404-never-403) | TC-7 |
| Regression gate on with-half | TC-8 |
| Dataset detail link/unlink + paired rendering | TC-9, TC-11 |
| SkillDetail evidence card + Run paired eval | TC-10, TC-11 |

Deliberately untested: learnings/self-improvement consumption of the
regression evidence (parallel changes); `SkillMaturityService` folding rules
(owned + tested by skill-maturity-model — TC-6 only proves the contract is fed).

After implementation: promote TC-3, TC-6, TC-9 to reusable scenarios via `/test-scenario-create`.
