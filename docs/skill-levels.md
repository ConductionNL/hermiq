---
sidebar_position: 9
description: The L1–L7 skill maturity ladder — what each level means, the Qualify scorecard, human attestation, and how evidence moves the badge.
keywords:
  - Hermiq
  - Skills
  - Maturity
  - Qualification
  - Scorecard
---

# Skill maturity levels

Every skill in the catalog carries a **maturity level** from L1 to L7,
rendered as a 7-dot badge on the catalog list and as a full scorecard on the
skill's detail page. Maturity answers one question: *how much evidence exists
that this skill is well-built and actually works?* It is computed — never
hand-set — and each level demands strictly more evidence than the last.

Be realistic about the ladder: **most skills plateau at L4**. L1–L3 are
structural checks any careful author passes; L4 is a human vouching for the
skill; L5–L7 require measured evidence that only accumulates when you run
evals, let learnings consolidate, and orchestrate skills together. A skill at
L4 is a perfectly good skill — the upper levels are proof, not polish.

## The ladder

| Level | Name | How it's earned |
|---|---|---|
| **L1** | Structurally valid | Computed. Frontmatter parses with a non-empty `name` and `description`, and the body is non-empty. |
| **L2** | Triggers well | Computed. The description has trigger quality (verb-led, says *when* to use the skill), the body stays under 500 lines, and a large skill uses progressive disclosure (`references/` files) instead of one monolith. |
| **L3** | Progressive disclosure | Computed. At least one `references/*` or `examples/*` entry in the skill's files. |
| **L4** | Human-attested | A curator with the `skill.attest-maturity` action explicitly attests the skill works. Never auto-detected. |
| **L5** | Eval-proven | A completed [paired eval run](./skill-evals.md) wrote evidence: dataset, pass rate, baseline delta, last-validated timestamp. |
| **L6** | Self-learning | The [learnings loop](./skill-learnings-and-self-improvement.md) shows real consolidated activity: promoted learnings exist *and* a consolidation has landed. Captured candidates alone do not count. |
| **L7** | Orchestrated | The skill has been **executed** as part of a declared skill chain — a declared-but-never-run chain is not mature L7. |

Levels are **contiguous**: a skill that fails L2 is L1 even if it would pass
L3's check. Passing a higher check never skips a failed lower level.

## The Qualify scorecard

The **Qualify** action (on the catalog row and the detail page) recomputes the
skill's maturity and returns a seven-entry scorecard: per level, a pass/fail
and human-readable reasons — structure problems, triggering problems, missing
eval evidence, missing learnings activity, missing orchestration use. The
first failing level tells you exactly what to work on next.

Qualify is **owner-guarded**: only the skill's owner can qualify it (for the
system-seeded example skills, an instance admin acts as custodian-owner).
Qualification is allowed in every lifecycle state — a quarantined skill can
still be qualified.

Two things to know about how the badge moves:

- **Evidence lands on the card; the badge moves on the next Qualify.** When a
  paired eval completes or learnings consolidate, the evidence is written to
  the skill immediately, but `maturityLevel` is only recomputed when Qualify
  next runs. If the badge looks behind the evidence, qualify the skill.
- **You cannot hand-set a level.** The computed fields (`maturityLevel`,
  L1–L4 and L6 evidence) are ignored on every create/edit/import — only the
  qualifier, the attest endpoint, and the evidence-writing subsystems write
  them. The one field you *do* set by hand is **target level**: a freely
  editable statement of curator intent ("this skill should reach L5") shown
  alongside the computed level.

## Attesting L4

L4 is deliberately a human call. A reviewer who has actually used the skill
and holds the `skill.attest-maturity` action attests it from the skill detail
page; the attestation records who, when, and an optional note. There is no
automated path to L4 — no amount of structural polish gets a skill past L3 on
its own.

## How L5–L7 evidence arrives

The qualifier never fabricates upper-level evidence; it only *reads* what
other subsystems wrote:

- **L5** — a completed [paired eval run](./skill-evals.md) (with/without
  comparison) stamps the skill's eval evidence. Absent evidence caps the skill
  at L4 with an honest "no eval evidence" reason.
- **L6** — the [learnings pipeline](./skill-learnings-and-self-improvement.md)
  stamps capture and promotion activity, but L6 passes only when promoted
  learnings exist **and** a consolidation has happened. A skill with a full
  candidates file and no consolidation stays below L6, with the scorecard
  saying why.
- **L7** — reserved for executed skill-chain evidence (orchestration). Until a
  chain run has actually executed with the skill aboard, L7 reads failed.

## Maturity vs lifecycle state

Maturity (L1–L7) and lifecycle state (`active` / `stale` / `archived` /
`quarantined`) are **fully independent**; neither derives from the other:

- The Curator moving a skill `active → stale` changes nothing about its
  maturity level, target level, or evidence.
- Quarantining (or approving) a skill leaves its maturity untouched, and a
  quarantined skill can still be qualified.
- Qualifying never changes lifecycle state.

Read the two together: lifecycle tells you whether the skill is currently
usable and trusted for agents; maturity tells you how much evidence backs it.

## The seeded examples

A fresh install seeds three example skills that make the spread visible:

| Skill | Level | Why |
|---|---|---|
| `meeting-notes-cleanup` | L1 | Structurally valid, but the description doesn't say when to use it. |
| `woo-request-triage` | L2 | Compact, well-triggering procedural body — no reference files yet. |
| `tender-summary` | L4 | References + examples aboard, human-attested, and carries demo learnings. |

The seeds are idempotent (re-running install/upgrade never duplicates them)
and never overwrite your edits.

## Related pages

- [Skills](./skills.md) — the catalog, authoring, and marketplace.
- [Skill evals](./skill-evals.md) — earning L5 evidence.
- [Learnings and self-improvement](./skill-learnings-and-self-improvement.md)
  — earning L6 evidence.
