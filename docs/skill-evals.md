---
sidebar_position: 10
description: Prove a skill actually helps — link eval datasets to skills and run paired with/without evals that measure the skill's real contribution.
keywords:
  - Hermiq
  - Skills
  - Evals
  - Baseline
  - Paired runs
---

# Skill evals

Agent evals answer "does my agent pass its test cases?". **Skill evals**
answer a sharper question: *does this skill actually make the agent better?*
Hermiq measures that with **paired runs** — the same dataset executed twice
(or more), once with the skill's content in the agent's context and once
without, with everything else frozen. The difference between the two pass
rates is the skill's **baseline delta**, and a completed paired run is what
earns a skill its [L5 evidence](./skill-levels.md#how-l5l7-evidence-arrives).

## Linking a dataset to skills

An eval dataset gains a **skill links** panel on its detail page: pick from
your visible active skills to link them (this writes the dataset's
`skillRefs`; unlinking is the same edit in reverse). Linking is what makes a
paired run possible — triggering a baseline run on a dataset with no linked
skills is rejected.

A linked skill does **not** need to be installed on the agent: the paired
run's WITH half uses the agent's installed skills *plus* the dataset's linked
skills, so you can qualify a skill before ever installing it.

## How a paired run works

Trigger a run from the dataset's run panel with the **baseline** toggle on
(enabled only when skills are linked). Every case executes through the agent's
real engine path — real impersonation, real model, real tools — and, like all
eval runs, nothing is delivered to Talk, notes, or notifications.

- The **WITH half** runs the cases with the linked skills in context. Its pass
  rate is the run's headline `passRate`.
- The **WITHOUT half(s)** run the same cases with skills detached. Detachment
  is per-run and in-memory only — your agent's stored installs are never
  touched, even if the run crashes mid-way.

The run detail page shows both halves side by side, with failing cases
distinguishable in each, plus every linked skill's baseline delta.

## Joint vs per-skill baseline mode

*How many* without-halves execute is the agent's choice, via the Agent's
**`evalBaselineMode`** property (an info affordance next to the field explains
this in place):

| Mode | Without-halves | What the delta means | Cost per paired run |
|---|---|---|---|
| **`joint`** (default) | One, with *all* linked skills detached together | The **joint contribution of the linked set** — every linked skill's entry carries the same numbers | ≈ **2×** an agent-scoped run |
| **`per-skill`** | One *per linked skill*, detaching only that skill | Each skill's **true marginal contribution** | **(N+1)×** for N linked skills |

Pick `joint` when you care whether the skill set as a whole earns its keep;
pick `per-skill` when you need honest attribution per skill and accept the
cost. The trigger surface states the mode-dependent cost before you confirm,
and the run records which mode it actually used — joint evidence is always
labelled as joint, so it never masquerades as a per-skill marginal.

Every half counts toward the **same budgets and gates** as ordinary runs: the
organisation kill-switch and budget hard cap are checked before any case
executes (a blocked run executes neither half), and all halves' tokens —
including judge calls — aggregate into the same per-org/per-agent budget a
scheduled run uses. There is no separate spend meter.

## Reading the evidence card

When a paired run completes, it writes the skill's L5 evidence — dataset,
pass rate, baseline delta, last-validated timestamp, and attribution mode.
This paired-run completion is the *only* writer of L5 evidence in the system;
nothing else (including a hand-crafted edit) can fabricate it, and failed or
gate-blocked runs write nothing.

The skill's detail page (`/skills/:id`) shows an **eval evidence card**:

- the recorded pass rate, baseline delta, and last-validated timestamp
  (a joint-mode delta is explicitly labelled as the joint contribution of the
  linked set);
- a **pass-rate trend** across the paired runs of every dataset that links
  this skill;
- a **Run paired eval** action — pick a linked dataset and an agent you own,
  read the mode-dependent cost note, confirm, and the paired run fires.

A skill with no evidence shows an honest empty state pointing you at linking a
dataset — never a placeholder metric. And note the badge rule: fresh evidence
lands on the card immediately, but the maturity badge only moves on the next
[Qualify](./skill-levels.md#the-qualify-scorecard).

## Ownership and the regression gate

Triggering a paired run requires you to own the dataset, the agent, **and**
every linked skill (the run writes evidence onto those skills); anything you
don't own reads as not-found. The WITH half's pass rate also feeds the normal
eval **regression gate**, compared against the previous completed run for the
same dataset + agent — a regression here is one of the triggers for the
[self-improvement draft flow](./skill-learnings-and-self-improvement.md#when-does-hermiq-propose-a-draft).

## Related pages

- [Skill maturity levels](./skill-levels.md) — where L5 fits on the ladder.
- [Learnings and self-improvement](./skill-learnings-and-self-improvement.md)
  — evals as the gate for self-proposed skill versions.
- [Skills](./skills.md) — the catalog itself.
