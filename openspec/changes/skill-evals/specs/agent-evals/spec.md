# agent-evals Specification (delta)

**Status**: in-progress
**Scope**: hermiq
**OpenSpec changes**:
- `skill-evals`

## Purpose

Extends the agent-scoped eval engine with skill scoping (ADR-068 §4): datasets
link skills (`skillRefs`), runs support a paired with-skill vs without-skill
baseline mode through the same real engine path, and a completed paired run
writes the `levelEvidence.l5` qualification evidence defined by
`skill-maturity-model`. No parallel eval system — every gate, scoring path, and
guard is the existing agent-evals machinery.

## ADDED Requirements

### Requirement: An EvalDataset links skills via skillRefs per the relation dialect

The `EvalDataset` schema in `lib/Settings/hermiq_register.json` MUST declare an
OPTIONAL `skillRefs` property: array, default `[]`, items
`{type: "string", format: "uuid", "$ref": "Skill"}` (the relation dialect, the
same shape as `Agent.skillInstalls`). Linking and unlinking MUST work through
the generic object write path — no bespoke link endpoint. `skillRefs` MUST NOT
be added to the schema's `required` array and no `if`/`then`/`allOf` blocks may
be used. The register `info.version` MUST be bumped (0.16.0 → 0.17.0) and the
repair step MUST apply the change as a FORCED import (openregister#2075).

#### Scenario: Linking a skill is a plain object write

- GIVEN an EvalDataset owned by the caller and an active Skill in their tenant
- WHEN the caller updates the dataset setting `skillRefs` to `[<skill uuid>]`
- THEN the dataset MUST persist with that reference through the generic object
  path
- AND unsetting it back to `[]` MUST equally succeed

#### Scenario: Existing datasets stay valid after the forced re-import

- GIVEN an EvalDataset created before this change
- WHEN the bumped register is force-imported on upgrade
- THEN the existing dataset MUST remain valid unchanged, with `skillRefs`
  reading as absent/empty

### Requirement: The Agent schema declares evalBaselineMode with a consequence-explaining description

The `Agent` schema in `lib/Settings/hermiq_register.json` MUST declare an
OPTIONAL `evalBaselineMode` property: string, enum `joint` | `per-skill`,
default `joint`. The property MUST carry a human-friendly `title` and a
thorough `description` that explains, on its own, exactly what changing the
value does: `joint` (the default) runs ONE without-skill half detaching all
linked skills together, producing one joint baseline delta at roughly 2× the
token cost of an agent-scoped run; `per-skill` runs one without-skill half PER
linked skill, producing a true per-skill marginal delta at (N+1)× the token
cost per paired run for N linked skills. The user MUST be able to understand
the attribution and cost consequences from the description alone.
`evalBaselineMode` MUST NOT be added to the schema's `required` array, and the
edit rides the SAME register version bump and forced re-import as the eval
schema deltas.

#### Scenario: An agent without the property runs joint baselines

- GIVEN an agent created before this change, with `evalBaselineMode` absent
- WHEN a paired run is triggered on a dataset linked to two skills
- THEN the run MUST behave as `joint` mode (exactly one without-half)

#### Scenario: The property description surfaces as an info affordance where the value is changed

- GIVEN the agent detail surface's data widget that holds `evalBaselineMode`
- WHEN the user engages the info affordance (info icon/tooltip) on the property
- THEN the register property's description — including the joint-vs-per-skill
  semantics and the (N+1)× cost consequence — MUST be shown to the user in
  place, where they change the value

### Requirement: A paired baseline run executes with and without halves per evalBaselineMode

When triggered with `baseline: true`, the system MUST execute the dataset's
cases through `ScheduleService::runAgentAsOwner()` — the real impersonation +
Engine/ChatService path — with the agent, model, tools, prompts, and owner
identical between halves and ONLY the effective skill set varying. The WITH
half MUST use the agent's installed skills UNION the dataset's linked skills.
The without-half strategy MUST follow the agent's `evalBaselineMode`:

- `joint` (the default, including when the property is absent): ONE WITHOUT
  half using the installed skills MINUS the linked skills — every case
  executes exactly twice; the recorded delta is the JOINT contribution of the
  linked set and every `skillResults` entry carries the same numbers.
- `per-skill`: ONE WITHOUT half PER linked skill, each using the WITH set
  MINUS only that skill — with N linked skills every case executes N+1 times,
  and each `skillResults` entry carries its OWN `passRateWithout` and
  `baselineDelta` (that skill's true marginal contribution) computed from its
  dedicated without-half, whose case results are stored on the entry's
  `baselineResults`; the top-level `baselineResults`/`baselinePassRate` remain
  unset.

All halves MUST be non-delivering and sequential (impersonation is not
concurrency-safe). The run MUST record the WITH half in the existing `results`
array (so `passRate` stays the with-half aggregate), `baselineMode: true`, and
`attributionMode` (`joint` | `per-skill`) as a snapshot of the mode the run
actually used; in `joint` mode the WITHOUT half lands in `baselineResults`
with `baselinePassRate`, and a `skillResults` entry per linked skill records
`{skillId, passRateWith, passRateWithout, baselineDelta}`. Triggering
`baseline: true` on a dataset whose `skillRefs` is empty MUST be rejected with
`400`.

#### Scenario: A paired run records both halves and the per-skill delta

- GIVEN an agent whose `evalBaselineMode` is unset and a dataset with 4 cases
  linked to one skill, run with `baseline: true`
- WHEN all cases execute in both halves and 4/4 pass with the skill while 2/4
  pass without it
- THEN the EvalRun MUST record `passRate: 1.0`, `baselinePassRate: 0.5`,
  `baselineMode: true`, `attributionMode: joint`, and a `skillResults` entry
  with `passRateWith: 1.0`, `passRateWithout: 0.5`, `baselineDelta: 0.5`
- AND no case output is delivered to Talk, Note-to-self, or a notification

#### Scenario: Joint mode yields a joint delta for multiple linked skills

- GIVEN an agent whose `evalBaselineMode` is `joint` and a dataset linked to
  two skills, run with `baseline: true`
- WHEN the paired run completes
- THEN exactly two halves MUST have executed (never one per skill)
- AND both `skillResults` entries MUST carry the same
  `passRateWith`/`passRateWithout`/`baselineDelta` values

#### Scenario: Per-skill mode runs one without-half per linked skill

- GIVEN an agent whose `evalBaselineMode` is `per-skill` and a dataset with 3
  cases linked to two skills, run with `baseline: true`
- WHEN the paired run completes
- THEN exactly three halves MUST have executed (one WITH plus one WITHOUT per
  skill), each case running 3 times
- AND each `skillResults` entry MUST carry its OWN
  `passRateWithout`/`baselineDelta` computed from its dedicated without-half,
  with that half's case results on the entry's `baselineResults`
- AND the run MUST record `attributionMode: per-skill` with the top-level
  `baselineResults`/`baselinePassRate` unset

#### Scenario: Install state cannot skew the comparison

- GIVEN a linked skill that is NOT installed on the agent
- WHEN a paired run executes
- THEN the WITH half MUST expose that skill (installed ∪ linked) and the
  without-half computed for it MUST detach it, so the measured delta is
  identical to that of an installed linked skill — a skill can be qualified
  BEFORE it is ever installed on the agent

#### Scenario: Baseline mode requires linked skills

- GIVEN a dataset whose `skillRefs` is empty
- WHEN the owner triggers a run with `baseline: true`
- THEN the response MUST be `400` and no case executes

### Requirement: The engine run loop exposes the effective skill set to a run

Engine context assembly MUST resolve a run's effective skill set — a per-run
override when supplied, otherwise the agent's stored `skillInstalls` — and MUST
inject each resolved skill's content (frontmatter name/description + `body`)
into the run context, exposing ONLY skills in `active` state (quarantined,
stale, and archived skills are never exposed, preserving the marketplace
approval gate). Every non-eval caller passes no override, so a plain run reads
the stored installs — the run-loop consumption the skills-catalog spec reserved.

#### Scenario: A content-bearing skill deterministically changes output

- GIVEN a skill whose body instructs the agent to always include a fixed marker
  token in its answer, linked to a dataset with a `contains` case expecting
  that marker
- WHEN a paired run executes
- THEN the WITH-half case MUST pass and the WITHOUT-half case MUST fail
- AND `baselineDelta` MUST be positive (the seam demonstrably feeds the engine)

#### Scenario: A quarantined skill is never exposed

- GIVEN a `quarantined` skill referenced by a dataset's `skillRefs`
- WHEN a paired run's WITH half executes
- THEN that skill's content MUST NOT be injected into the run context

### Requirement: Baseline detachment is per-run and in-memory only

The WITHOUT half MUST be produced by a per-run, in-memory effective-skill-set
override threaded down the engine path. No paired-run code path may write
`Agent.skillInstalls` or `Skill.installedOn` — the system MUST NOT implement
detachment by uninstalling and re-installing skills. A paired run that crashes
at any point (including between halves) MUST leave the agent's stored
`skillInstalls` and every skill's `installedOn` byte-identical to their pre-run
values.

#### Scenario: Stored installs survive a completed paired run

- GIVEN an agent with two installed skills, one of which is dataset-linked
- WHEN a paired run completes
- THEN the agent's `skillInstalls` and both skills' `installedOn` MUST be
  unchanged

#### Scenario: A crash between halves never strips the agent

- GIVEN a paired run whose WITHOUT half is forced to fail with an
  infrastructure error after the WITH half completed
- WHEN the run terminates with `status=failed`
- THEN the agent's stored `skillInstalls` MUST be unchanged
- AND no `levelEvidence.l5` is written

### Requirement: Every half of a paired run counts toward the same budgets and gates

A paired run MUST check the organisation kill-switch
(`ScheduleService::isOrganisationEngaged()`) and budget hard cap
(`BudgetService::isBlocked()`) ONCE before any case of any half executes,
and MUST NOT execute any case when either gate blocks (`blocked_killswitch` /
`blocked_budget`, as agent-evals). Token usage from EVERY half — two in
`joint` mode, N+1 in `per-skill` mode, including judge calls — MUST aggregate
into the run's single per-run AuditTrail usage entry so
`BudgetService::isBlocked()` sums it into the SAME per-org/per-agent budget a
scheduled run uses — no separate spend meter. The trigger surfaces MUST state
the mode-dependent cost: approximately 2× an agent-scoped run in `joint` mode
and (N+1)× in `per-skill` mode.

#### Scenario: A blocked paired run executes neither half

- GIVEN the target organisation's budget has reached its hard cap
- WHEN a paired run is triggered
- THEN no case of either half executes and the run records `blocked_budget`

#### Scenario: Both halves' tokens land in one budget sum

- GIVEN a completed paired run whose WITH half used 1000 tokens and WITHOUT
  half used 900
- WHEN `BudgetService::isBlocked()` next evaluates that organisation
- THEN the full 1900 tokens MUST be included in the usage sum

#### Scenario: Per-skill halves all land in one budget sum

- GIVEN a completed `per-skill` paired run over two linked skills whose three
  halves used 1000, 900, and 800 tokens
- WHEN `BudgetService::isBlocked()` next evaluates that organisation
- THEN the full 2700 tokens MUST be included in the usage sum

### Requirement: A completed paired run is the only writer of l5 evidence

The system MUST, on a paired run reaching `status=completed` (every case of
ALL halves executed), write on EACH linked skill — in BOTH attribution modes —
`levelEvidence.l5 = {evalDatasetId, passRate, baselineDelta, lastValidated,
mode}` — `passRate`/`baselineDelta` from that skill's `skillResults` entry,
`lastValidated` the run's `endedAt`, `mode` the run's `attributionMode` so the
evidence is honest about attribution: `mode: joint` marks the delta as the
joint contribution of the linked set, `mode: per-skill` marks it as that
skill's true marginal — using the write contract defined by
`skill-maturity-model`: the write reads the current skill object, patches ONLY
`levelEvidence.l5`, and carries every other field forward (never touching
`body`, `frontmatter`, `files`, `state`, `installedOn`, or `maturityLevel`).
This path MUST be the only l5 writer in the codebase; client-supplied l5 on the
skill write paths remains silently preserved-over. Runs ending `failed`,
`blocked_killswitch`, or `blocked_budget` MUST write no evidence.
`maturityLevel` is NOT recomputed by this write — the level updates on the next
qualify.

#### Scenario: Completing a paired run stamps l5 on the linked skill

- GIVEN a `joint`-mode paired run for a dataset linked to one skill completes
  with `passRate: 0.9` and `baselineDelta: 0.3`
- WHEN the run persists
- THEN the linked skill's `levelEvidence.l5` MUST read
  `{evalDatasetId: <dataset uuid>, passRate: 0.9, baselineDelta: 0.3,
  lastValidated: <run endedAt>, mode: joint}`
- AND the skill's `body`, `frontmatter`, `files`, `state`, and `maturityLevel`
  MUST be unchanged

#### Scenario: A gate-blocked run writes no evidence

- GIVEN a paired run recorded `blocked_killswitch`
- WHEN the run persists
- THEN every linked skill's `levelEvidence.l5` MUST be unchanged

### Requirement: The paired trigger owner guard covers dataset, agent, and every linked skill

When `baseline: true`, the run trigger MUST require the caller to own the
dataset, the agent, AND every skill referenced by `skillRefs` (the run writes
evidence onto those skills). Any missing, invisible, or non-owned object MUST
yield `404` (never `403`) so a non-owner cannot confirm any of the three
objects' existence — the agent-evals IDOR pattern unchanged.

#### Scenario: A non-owner of a linked skill cannot trigger a paired run

- GIVEN a dataset and agent owned by user A whose `skillRefs` references a
  skill owned by user B
- WHEN user A triggers a run with `baseline: true`
- THEN the response MUST be `404 Not Found` and no case executes

### Requirement: The regression gate applies to a paired run's with-half pass rate

A paired run's `passRate` (the WITH half) MUST feed the existing regression
gate unchanged: on completion it is compared against the immediately preceding
completed EvalRun for the same `datasetId`+`agentId` (paired or not), recording
`regressionGateResult` and `previousPassRate` exactly as agent-evals defines.

#### Scenario: A paired run regression-compares against a previous plain run

- GIVEN the previous completed run for this dataset+agent had `passRate: 0.90`
  and the effective threshold is 10 percentage points
- WHEN a paired run completes with a WITH-half `passRate: 0.75`
- THEN `regressionGateResult` MUST be `failed` with `previousPassRate: 0.90`

### Requirement: The EvalDataset detail surface manages skill links and paired runs

The EvalDatasetDetail page MUST offer link/unlink of skills (a picker over the
caller's visible active skills, writing `skillRefs` through the generic object
path) and the run panel MUST offer a baseline-mode toggle that is enabled only
when `skillRefs` is non-empty and carries the mode-dependent cost note (≈2× in
`joint` mode, (N+1)× in `per-skill` mode, per the selected agent's
`evalBaselineMode`). A paired run's
detail rendering MUST show with vs without results side by side and each linked
skill's `baselineDelta`, with the delta not conveyed by color alone.

#### Scenario: Linking a skill from the dataset detail page

- GIVEN an EvalDataset with no linked skills
- WHEN the user links a skill via the skill panel and then unlinks it
- THEN `skillRefs` MUST gain and then lose that skill's uuid through the
  generic object write path

#### Scenario: A paired run renders both halves

- GIVEN a completed paired run
- WHEN the operator opens it on the dataset detail page
- THEN with and without pass rates and the per-skill delta MUST be visible,
  with failing cases distinguishable in both halves

## Notes

- ADR-068 §4 is the architectural decision; ADR-060: l5 evidence only from real
  executed runs — no seeded or statically-graded evidence.
- ADR-031: paired orchestration is imperative engine work (agent-evals
  precedent); `skillRefs` and the new EvalRun fields are plain declarative
  schema properties (design.md Decision 1).
- The baseline attribution strategy is agent-level configuration
  (`Agent.evalBaselineMode`, design.md Decision 2): `joint` (default, ~2×) or
  `per-skill` ((N+1)×, true marginals); UI copy still recommends one skill per
  dataset as the cheapest clean attribution.
- The l5 evidence shape gains the `mode` marker (`joint` | `per-skill`) — an
  additive extension of the skill-maturity-model l5 contract so evidence never
  over-claims attribution.
- EN + NL strings for all new UI text (ADR-007); WCAG 2.2 AA on the touched
  surfaces.
