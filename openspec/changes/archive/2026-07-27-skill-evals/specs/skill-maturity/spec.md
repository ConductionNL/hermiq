# skill-maturity Specification (delta)

**Status**: in-progress
**Scope**: hermiq
**OpenSpec changes**:
- `skill-evals` (this delta; base capability defined by `skill-maturity-model`)

## Purpose

Adds the eval-evidence surface to the SkillDetail page created by
`skill-maturity-model`: the L5 evidence written by paired eval runs
(agent-evals delta of this change) becomes visible and actionable on the skill
itself. The `levelEvidence.l5` field contract and its silent-preserve write
protection are defined by `skill-maturity-model` and are NOT modified here —
this delta only adds the read surface and the trigger affordance.

## ADDED Requirements

### Requirement: SkillDetail surfaces eval evidence and a Run paired eval action

The SkillDetail page (`/skills/:id`) MUST show an eval-evidence card presenting
the skill's `levelEvidence.l5` (pass rate, baseline delta, last validated) —
labelling a `mode: joint` delta as the joint contribution of the linked skill
set, so joint evidence never reads as a per-skill marginal — plus
a pass-rate trend across the paired EvalRuns of datasets whose `skillRefs`
reference this skill, and MUST offer a **Run paired eval** action that lets the
owner pick a linked dataset and an agent and triggers the paired run endpoint
(owner-guarded, 404-never-403, per the agent-evals delta), stating the
mode-dependent cost (≈2× in `joint` mode, (N+1)× in `per-skill` mode, per the
selected agent's `evalBaselineMode`). When no l5 evidence exists the card MUST
show an honest empty state that
points at linking a dataset (never a fabricated or placeholder metric). The
card MUST NOT write any maturity field — evidence arrives only via the paired
run's completion, and the displayed delta MUST NOT be conveyed by color alone.

#### Scenario: The card shows evidence after a completed paired run

- GIVEN a skill whose `levelEvidence.l5` was written by a completed paired run
  with `passRate: 0.9` and `baselineDelta: 0.3`
- WHEN the owner opens `/skills/:id`
- THEN the eval-evidence card MUST show the pass rate, the baseline delta, and
  the last-validated timestamp, and the trend MUST include that run

#### Scenario: No evidence shows an honest empty state

- GIVEN a skill with no `levelEvidence.l5` and no linked dataset
- WHEN the owner opens `/skills/:id`
- THEN the card MUST state that no eval evidence exists and how to obtain it
- AND no metric value MUST be rendered

#### Scenario: Run paired eval triggers the owner-guarded endpoint

- GIVEN a skill linked from one dataset, both owned by the caller
- WHEN the owner uses **Run paired eval**, picks the dataset and an agent they
  own, and confirms past the cost note
- THEN the paired run endpoint MUST be called with `baseline: true`
- AND on completion the card MUST reflect the refreshed l5 evidence

## Notes

- The maturity scorecard's L5 row (skill-maturity-model) starts passing once
  this evidence exists and the skill is next qualified — no change to the
  folding rules or to `SkillMaturityService` is part of this delta.
- ADR-060: the trend renders only real executed runs.
