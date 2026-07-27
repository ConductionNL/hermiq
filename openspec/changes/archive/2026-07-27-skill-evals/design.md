# Design: skill-evals

## Context

`agent-evals` is live: `EvalDataset` (embedded cases) + `EvalRun` execute through
`ScheduleService::runAgentAsOwner()` — the real impersonation + Engine/ChatService
dual path — non-delivering, scored by `EvalScoringService` (deterministic +
LLM-judge via `ProviderFactory`), gated by kill-switch/budget, regression-gated
against the previous run, owner-guard-triggered (404-never-403).
`skill-maturity-model` landed the `levelEvidence.l5` contract on `agentskill`
(`{evalDatasetId, passRate, baselineDelta, lastValidated}`) with a
service-written/silent-preserve write contract — and deliberately left it
writer-less. This change is the writer.

One hard fact from code reading: **the engine run loop does not consume skills
today.** `Agent.skillInstalls` is described in the register as "the allowlist a
future run loop uses" and `SkillService` calls it "a convenience forward-ref";
nothing under `lib/Service/Engine/` reads skills. The skills-catalog spec promises
"agent X's next run MUST have that skill available" — that seam is unbuilt. A
with/without-skill paired eval against an engine that ignores skills would
complete green with `baselineDelta` structurally 0 — the exact green-but-dead
class this repo keeps re-learning. So this change lands the minimal exposure seam
as an explicit, tested requirement.

```
POST /api/evals/{datasetId}/run  { agentId, baseline: true }   (owner-guarded)
        │  gates once: kill-switch + budget hard cap
        ▼
EvalRunService (paired orchestration)
        │  WITH half:       effective skills = installed ∪ skillRefs → all cases
        │  WITHOUT half(s): per Agent.evalBaselineMode —
        │      joint (default): ONE half, installed ∖ skillRefs (all detached)
        │      per-skill:       one half PER linked skill, with-set ∖ {skill}
        │       (override is a per-run in-memory parameter threaded through
        │        runAgentAsOwner() → engine context assembly; stored
        │        skillInstalls / installedOn are NEVER written)
        ▼
one EvalRun object: results (with) + baselineResults (joint without) +
skillResults[] + attributionMode snapshot
        │  passRate = with-half rate → existing regression gate unchanged
        ▼  only when status = completed:
levelEvidence.l5 = {evalDatasetId, passRate, baselineDelta, lastValidated, mode}
written on each linked skill (the ONLY l5 writer; carry-all-fields-forward)
```

## Goals / Non-Goals

**Goals:** skill↔dataset linkage (`skillRefs`); a paired baseline run mode that
measures a skill's contribution through the real engine path, with the
attribution strategy as agent-level configuration (`evalBaselineMode`: `joint`
default | `per-skill` marginals); the
minimal run-loop skill-exposure seam; l5 evidence write-back per the maturity
write contract; dataset link/unlink UI + SkillDetail eval-evidence card and
"Run paired eval" action + the `evalBaselineMode` info affordance on the agent
detail data widget. Wholesale reuse of agent-evals machinery.

**Non-Goals:** learnings/self-improvement (parallel changes — self-improvement
consumes this regression evidence later); L6/L7 evidence; scheduled/automatic
evals; changing scoring, budget math, or the regression formula; a skill
tool-calling framework (the seam is context exposure only); writing
`maturityLevel` (only `SkillMaturityService::qualify()` folds evidence).

## Decisions

### Decision 1: Declarative-vs-imperative (ADR-031)

Split exactly along the agent-evals precedent:

- **Declarative:** `skillRefs` is a plain relation property per the relation
  dialect — array of `type: string`, `format: uuid`, `$ref: Skill` items,
  written through the generic object path (link/unlink is ordinary object CRUD,
  no bespoke endpoint). Likewise `baselineMode`, `skillResults`, and
  `baselineResults` are plain schema fields on `EvalRun`.
- **Imperative:** paired-run orchestration is engine work —
  sequential impersonated execution of two halves, in-memory skill-set
  overrides, usage aggregation, gate checks, regression comparison, and a
  cross-object evidence write. None of that is expressible as
  `x-openregister-calculations`; it extends the already-justified imperative
  `EvalRunService` rather than creating a second system.

### Decision 2: Baseline attribution mode is agent-level configuration (`evalBaselineMode`)

How the without-half is constructed is an AGENT-level configuration property:
`Agent.evalBaselineMode`, enum `joint` (default) | `per-skill`, declared in the
register with a human-friendly `title` and a thorough `description` — the
operator MUST be able to understand the consequences of changing it from the
property description alone, so the description spells out both semantics AND
the cost:

- **`joint` (default, including when absent):** one WITH half + ONE WITHOUT
  half that detaches ALL linked skills together. Each case runs exactly twice
  (~2× cost); `baselineDelta` is the **joint** contribution of the linked set
  and every `skillResults` entry carries the same numbers. The honest cheap
  default — link ONE skill per dataset for clean attribution (the seed does
  exactly that, and the UI copy says so).
- **`per-skill`:** one WITH half + one WITHOUT half PER linked skill (with-set
  minus only that skill) — N+1 halves for N linked skills, each case running
  N+1 times at (N+1)× token cost per paired run. Each `skillResults` entry
  carries its OWN without-half results and a TRUE per-skill marginal
  `baselineDelta`.

The run snapshots the mode actually used in `EvalRun.attributionMode` (agent
config may change later; run history stays honest), and l5 evidence carries
the matching `mode` marker so joint evidence never over-claims per-skill
attribution. The description is surfaced as an info affordance (info
icon/tooltip) in the agent detail data widget holding the property — the
explanation lives exactly where the value is changed (spec scenario).

### Decision 3: Cost implication — joint 2×, per-skill (N+1)×, same budgets

A `joint` paired run costs ~2× an agent-scoped run of the same dataset: every
case executes twice (plus rubric judge calls twice). A `per-skill` paired run
costs (N+1)× for N linked skills: every case executes once per half, judge
calls included. **Every half counts toward the
SAME per-org/per-agent budgets** — usage from all halves aggregates into the
run's single AuditTrail usage entry, which `BudgetService::isBlocked()` already
sums (agent-evals precedent: no separate spend meter, no eval-only budget). The
kill-switch + hard-cap gates are checked once before any case runs, identical to
agent-evals; like any eval run it can overshoot mid-run, and the overshoot is
bounded at roughly one paired run — which in `per-skill` mode means (N+1)
halves, one more reason the mode's cost consequence is spelled out in the
`evalBaselineMode` property description at the place the mode is changed. The
trigger UI labels the action with the mode-dependent cost (≈2× `joint`,
(N+1)× `per-skill`) so an operator is never surprised.

### Decision 4: Detachment is per-run, in-memory only — never a stored write

The without-half MUST NOT be implemented by calling
`SkillService::uninstallFromAgent()`/`installOnAgent()` around the run.
Rationale: a crash between "detach" and "re-attach" would leave the agent
permanently stripped of its skills — a stored-state corruption triggered by an
eval (green-but-dead risk flagged in the proposal). Instead the effective skill
set is a nullable parameter threaded `EvalRunService` →
`ScheduleService::runAgentAsOwner(..., skillSetOverride)` → engine context
assembly; when null (every non-eval caller), the engine reads the agent's stored
`skillInstalls` as before. There is no code path in a paired run that writes
`Agent.skillInstalls` or `Skill.installedOn` — asserted by a failure-injection
test (kill the run between halves; stored objects byte-identical).

### Decision 5: The minimal run-loop skill-exposure seam lands here

The seam the skills-catalog spec reserved: engine context assembly resolves the
effective skill set (override, else stored `skillInstalls`), loads each skill in
`active` state, and injects its content (frontmatter `name`/`description` +
`body`) into the run's system context. Non-`active` skills (quarantined/stale/
archived) are never exposed — preserving the marketplace gate "an agent MUST NOT
use an unapproved skill". Scope is deliberately minimal: context exposure only,
no skill tool-calling, no dynamic routing. Alternative — depending on a separate
engine change to land the seam first — rejected: no such change exists in the
wave, and without the seam this entire change measures nothing. (Flagged as a
deferred question for the wave owner; see Open Questions.)

### Decision 6: One EvalRun object carries all halves; regression gate unchanged

The existing `results` array stays the with-half (back-compat: every existing
consumer reads it as "the run's results"); `baselineResults` (same item shape)
holds the joint-mode without-half; `passRate` stays the with-half aggregate;
`attributionMode` snapshots the mode the run used. Consequence:
`evaluateRegressionGate()` and `findPreviousCompletedRun()` work unchanged, and
a paired run regression-compares like-for-like against a previous run's
with-skill (or plain) pass rate. `skillResults[]` records per linked skill
`{skillId, passRateWith, passRateWithout, baselineDelta}`; in `per-skill` mode
each entry additionally carries its dedicated without-half's case results in
an entry-level `baselineResults` array, and the top-level
`baselineResults`/`baselinePassRate` stay unset (there is no single "the"
without-half to promote). Alternative — one
EvalRun object per half — rejected: it would multiply regression-gate
history entries, split the budget/audit trail, and force the UI to re-join
halves.

### Decision 7: l5 write-back only on `completed`, through the maturity write contract

Evidence is written only when the paired run reaches `status=completed` (every
case in ALL halves executed), in BOTH attribution modes. `failed`,
`blocked_killswitch`, and
`blocked_budget` runs write nothing — partial evidence is worse than none. The
write reads the current skill object and patches ONLY `levelEvidence.l5`,
carrying every other field forward (OR saveObject PUT semantics — nulls omitted
props), and never touches `body`/`frontmatter`/`files`/`state`/`maturityLevel`.
This is the single l5 writer in the codebase; the client-facing skill write
paths keep silently preserving stored l5 per skill-maturity-model. The written
`passRate`/`baselineDelta` are that skill's `skillResults` entry;
`lastValidated` is the run's `endedAt`; `evalDatasetId` the dataset uuid;
`mode` is the run's `attributionMode` — `joint` evidence honestly marks its
delta as the joint contribution of the linked set (an additive extension of
the skill-maturity-model l5 shape), `per-skill` evidence carries the skill's
true marginal.
`maturityLevel` itself is NOT recomputed here — the level changes on the next
qualify (keeps this change out of the maturity-folding business).

### Decision 8: Trigger stays on the existing endpoint, owner guard widened

`POST /api/evals/{datasetId}/run` gains an optional boolean `baseline` request
parameter (default false — existing behaviour byte-identical). When true: the
dataset MUST have a non-empty `skillRefs`, and the caller MUST own the dataset,
the agent, AND every linked skill (the run writes evidence onto the skills, so
skill ownership is part of the guard). Any missing/invisible/non-owned object →
404, never 403 (agent-evals IDOR pattern). Alternative — a separate
`/run-paired` route — rejected: same resource, same guard family, one more
route-auth surface for no gain.

### Decision 9: UI split follows page ownership

- **EvalDatasetDetail** (agent-evals surface): a `skill-link-panel` widget —
  list linked skills, link (skill picker over the caller's active skills),
  unlink; plain object writes to `skillRefs`. The existing `eval-run-panel`
  gains a "Paired baseline" toggle (enabled only when `skillRefs` non-empty,
  with the mode-dependent cost note: ≈2× `joint`, (N+1)× `per-skill`, per the
  selected agent's `evalBaselineMode`) and renders paired runs with
  with/without columns + per-skill delta.
- **SkillDetail** (skill-maturity surface, created by skill-maturity-model): an
  `skill-eval-evidence` card — current l5 evidence (pass rate, baseline delta
  with its `mode` label, last validated), pass-rate trend across this skill's
  paired runs, and a "Run paired eval" action (dataset picker over datasets
  whose `skillRefs` contain this skill + agent picker; owner-guarded;
  mode-dependent cost note).
- **AgentDetail** (agent surface): the data widget that holds
  `evalBaselineMode` surfaces the register property's `description` via an
  info affordance (info icon/tooltip), so the joint-vs-per-skill semantics and
  the (N+1)× cost consequence are visible exactly where the value is changed.

## API Design

### `POST /api/evals/{datasetId}/run` (extended)

**Auth:** NC session, `#[NoAdminRequired]`, CSRF on; owner-guarded 404-never-403.

**Request:**
```json
{ "agentId": "00000000-0000-0000-0000-000000000000", "baseline": true }
```

**Response (200)** — existing shape plus, when `baseline: true`:
```json
{
  "id": "00000000-0000-0000-0000-000000000000",
  "status": "completed",
  "baselineMode": true,
  "attributionMode": "joint",
  "passRate": 0.9,
  "baselinePassRate": 0.6,
  "skillResults": [
    { "skillId": "00000000-0000-0000-0000-000000000000",
      "passRateWith": 0.9, "passRateWithout": 0.6, "baselineDelta": 0.3 }
  ],
  "regressionGateResult": "not_applicable"
}
```
**Errors:** 404 (dataset/agent/any linked skill missing or not owned —
indistinguishable), 400 (`baseline: true` on a dataset with empty `skillRefs`),
401.

## Database Changes

None — hermiq owns no tables. `lib/Settings/hermiq_register.json` (register
`info.version` 0.16.0 → 0.17.0, FORCED re-import per openregister#2075; nothing
added to any `required`; no `if`/`then`/`allOf`):

- `EvalDataset.skillRefs` (NEW, optional): array, default `[]`, items
  `{type: "string", format: "uuid", "$ref": "Skill"}` — the relation dialect,
  same shape as `Agent.skillInstalls`.
- `EvalRun.baselineMode` (NEW, optional): boolean, default false.
- `EvalRun.attributionMode` (NEW, optional): string, enum `joint` | `per-skill`
  — snapshot of the attribution mode the run actually used.
- `EvalRun.baselineResults` (NEW, optional): array, same item shape as
  `results` — the joint-mode without-half, in dataset case order (unset in
  `per-skill` mode).
- `EvalRun.baselinePassRate` (NEW, optional): number 0–1, nullable (unset in
  `per-skill` mode).
- `EvalRun.skillResults` (NEW, optional): array of
  `{skillId (uuid, $ref Skill), passRateWith, passRateWithout, baselineDelta,
  baselineResults (per-skill mode only: that skill's without-half case
  results)}`.
- `Agent.evalBaselineMode` (NEW, optional): string, enum `joint` | `per-skill`,
  default `joint`, with a human-friendly `title` and a thorough `description`
  spelling out exactly what each mode does — joint: one without-half detaching
  all linked skills together, one joint delta, ~2× cost; per-skill: one
  without-half per linked skill, true marginals, (N+1)× token cost per paired
  run — understandable from the description alone (the UI surfaces it as the
  info affordance). Rides the SAME version bump + forced re-import.

`Skill.levelEvidence.l5` gains only the additive `mode` marker written by this
change's l5 writer; the field contract otherwise exists per skill-maturity-model.

## Nextcloud Integration

- Controllers: `EvalRunController::run()` extended (baseline param + widened
  guard). No new routes.
- Services: `EvalRunService` (paired orchestration + l5 write-back via
  `ObjectService`); `ScheduleService::runAgentAsOwner()` gains the optional
  `skillSetOverride` parameter (null = unchanged behaviour); engine context
  assembly (`lib/Service/Engine/ContextAssembler.php`) gains skill exposure.
  `EvalScoringService`, `BudgetService`, `DeliveryService` untouched.
- Events/Hooks/Mappers: none. Repair step: register version bump + seed (below).

## Security Considerations

- **IDOR:** the widened trigger guard covers dataset + agent + every linked
  skill, 404-never-403 (no existence confirmation for any of the three).
- **Quarantine gate:** the exposure seam only injects `state=active` skills — a
  quarantined skill cannot reach a run context via `skillRefs` any more than via
  install.
- **Evidence integrity:** l5 is written only by this service path on `completed`
  runs; client writes remain silently preserved-over (skill-maturity-model
  contract) — a forged l5 payload via the skill edit path never persists.
- **Stored-state safety:** no paired-run code path writes
  `skillInstalls`/`installedOn` (Decision 4); crash mid-run leaves agent + skills
  byte-identical.
- **Prompt-injection surface:** the seam injects skill bodies into context — the
  same trust already granted to installed skills by the catalog design; the
  marketplace scan/quarantine path remains the gate for untrusted content
  (ADR-068 threat model). Judge calls stay behind `ProviderFactory` (model
  policy, guardrails, budgets).
- **CSRF:** standard NC session + CSRF token; no `#[NoCSRFRequired]`.

## NL Design System

CSS variables only; with/without columns and delta badges not color-only
(sign/arrow + text, WCAG 2.2 AA); all new strings EN + NL (ADR-007); Cn*
components; the 2× cost note is a translated string.

## File Structure

```
lib/
  Service/EvalRunService.php            (paired orchestration + l5 write-back)
  Service/ScheduleService.php           (skillSetOverride parameter)
  Service/Engine/ContextAssembler.php   (skill-exposure seam)
  Controller/EvalRunController.php      (baseline param + widened guard)
  Settings/hermiq_register.json         (schema deltas + 0.17.0 bump)
appinfo/info.xml                        (version bump; forced import repair)
src/
  manifest.json                         (skill-link-panel on EvalDatasetDetail;
                                         skill-eval-evidence on SkillDetail;
                                         evalBaselineMode info affordance in the
                                         AgentDetail data widget)
  widgets/SkillLinkPanel.vue            (new)
  widgets/SkillEvalEvidence.vue         (new)
  widgets/EvalRunPanel.vue              (baseline toggle + paired rendering)
  api/evals.js                          (baseline param)
tests/
  unit/Service/EvalRunServicePairedTest.php (new)
  e2e (Playwright): link → paired run → evidence card
```

## Seed Data

Extends the seed repair path (idempotent by name, system context
`_rbac: false, _multitenancy: false`, never overwriting admin edits) with one
EvalDataset so the paired flow is demonstrable on a fresh install, municipality
context, linked to the `woo-request-triage` skill seeded by skill-maturity-model:

| Object | Content |
|---|---|
| EvalDataset `woo-triage-paired-eval` | description: "Paired baseline eval for the woo-request-triage skill — measures the skill's marginal contribution on realistic WOO intake prompts."; `skillRefs: [<uuid of the seeded woo-request-triage skill, resolved at seed time>]`; 3 cases: (1) `contains` — prompt "Er is een Woo-verzoek binnengekomen over de kapvergunningen in het Vondelpark; wat is de eerste triagestap?" expecting "termijn"; (2) `notContains` — a request that must NOT be routed as a complaint, expecting absence of "klacht"; (3) `rubric` — "Score 1 when the answer names routing, deadline (4 weken), and an exemption pre-check per the woo-request-triage procedure; 0 otherwise", threshold 0.7. |

The seed resolves the skill uuid by name at repair time (never a hard-coded
uuid; docs/examples use nil UUIDs `00000000-0000-0000-0000-000000000000` only).
It links exactly ONE skill (Decision 2's clean-attribution recommendation). No
EvalRun and no l5 evidence are seeded — evidence must only ever come from real
executed runs (ADR-060); the seeded `tender-summary` skill's scorecard keeps
showing "no eval evidence" until an operator actually runs a paired eval.

## Risks / Trade-offs

- [Engine ignores skills → delta structurally 0] → the seam is a spec
  requirement with a deterministic differentiation scenario; e2e proves a
  content-bearing skill changes output.
- [Crash between halves strips the agent] → in-memory override only (Decision
  4) + failure-injection test.
- [2×/(N+1)× token cost surprises operators] → mode-dependent cost note on
  both trigger surfaces + the `evalBaselineMode` property description explains
  the consequence exactly where the mode is changed (info affordance);
  same budget gates bound the spend (Decision 3).
- [Joint delta over-claims per-skill contribution] → l5 evidence carries the
  `mode` marker; `per-skill` mode gives true marginals as an explicit opt-in;
  UI copy still recommends one skill per dataset as the cheap default; seed
  models it.
- [l5 write-back clobbers concurrent skill edits] → read-latest + patch-only-l5
  carry-forward write; the window is one object write and losing it costs one
  re-run, never content.
- [Forced re-import missed on upgrade] → repair step forces (openregister#2075);
  upgrade check in test plan.

## Migration Plan

1. Land schema deltas (incl. `Agent.evalBaselineMode` with its title +
   consequence-explaining description) + version bumps (register 0.17.0, app
   `info.xml`); ONE forced re-import repair step covers all three schemas.
2. Seed dataset (idempotent; skipped with a log line if the
   `woo-request-triage` skill is absent).
3. Code + UI ship together; `baseline` defaults false so existing eval flows are
   byte-identical; the seam changes non-eval runs only for agents that actually
   have installed active skills — which the catalog spec always promised.
4. Rollback: revert code; optional schema fields stay inert; existing l5
   evidence remains valid data (proposal Rollback Strategy).

## Open Questions

None blocking. Deferred (recorded for the wave owner):

- The run-loop skill-exposure seam lands HERE (Decision 5) because no other
  change in the wave owns it and the paired eval is dead without it. If
  `skill-learnings` (parallel agent) also assumes engine-side skill activity
  tracking, both changes touch `ContextAssembler` — coordinate at apply time;
  this change's seam is the smaller, load-bearing half.
- Whether a with-half should also expose linked-but-not-installed skills —
  RESOLVED as normative: yes, `installed ∪ linked`, with a spec scenario
  asserting install state cannot skew the comparison (a skill can be qualified
  BEFORE it is ever installed on the agent).
- Per-skill marginal attribution — RESOLVED: available as the agent-level
  `evalBaselineMode: per-skill` opt-in ((N+1)× cost, Decision 2); `joint`
  remains the default.
