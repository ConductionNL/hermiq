---
sidebar_position: 11
description: How skills learn from real runs — capture, promotion, and the fully gated self-improvement flow that proposes new skill versions for human approval.
keywords:
  - Hermiq
  - Skills
  - Learnings
  - Self-improvement
  - Drafts
  - Versioning
---

# Skill learnings and self-improvement

Hermiq's skills can **learn from their own runs**. Observations from real
executions accumulate as *candidates*, confirmed candidates promote to
*learnings*, and accumulated learnings can drive a *proposed new skill
version* — which only ever lands after a content scan, an eval gate, and an
explicit human approval. Nothing in this loop edits a live skill in place,
and nothing publishes itself.

## The learnings loop: capture → candidates → learnings

**Utilization first.** Every run records which skills' content was actually
loaded into its context. A skill that was merely installed but not loaded
gets no credit and no blame — capture is driven exclusively by real use.

**Capture (per run, background).** After a run completes, a queued capture
pass extracts short, dated, atomic observations from the run trace (one cheap
LLM call per skill) and appends them to the skill's
`learning-candidates.md` file. Each candidate line carries its capture date, a
target section (patterns / mistakes / domain / questions), and the run id. A
repeated observation becomes a *confirmation* — the existing line gains the
new run id instead of duplicating. Capture is deliberately boring and safe:

- **Failure-isolated** — a capture failure never fails, delays, or alters the
  run itself, and one skill's failure never blocks another's capture.
- **Budget-gated and budget-counted** — capture is skipped when the org/agent
  budget is blocked, and its tokens count against the same budget windows as
  runs.
- **Idempotent** — the same run id is never captured twice.
- **Redacted** — every observation passes the same secret/PII redaction as
  agent memory before persist; an observation that redacts to empty is
  dropped.
- **Not agent-invocable** — no endpoint and no tool lets an agent trigger
  capture or promotion on any skill.

**Promotion (daily, mechanical).** A daily job — with no LLM involved —
promotes every candidate confirmed by **3 or more distinct runs** (or any
candidate explaining a failed eval case, immediately) into the skill's
`learnings.md`, filed under one of its five sections: *Patterns That Work,
Mistakes to Avoid, Domain Knowledge, Open Questions, Consolidated Principles*.
Candidates untouched for 30 days expire. Promotion alone does **not** grant
L6 — that needs consolidation (below); the scorecard says so honestly.

Both files live in the skill's ordinary agentskills.io `files` map, so
learnings travel with an exported skill. On publish, `learnings.md` ships and
`learning-candidates.md` is always stripped (see
[the strip rule](#what-ships-and-what-never-does)).

**Where you see it.** The skill detail page has a read-only **Learnings**
surface: the rendered `learnings.md`, the candidate count, and the last
capture/promotion activity. The seeded `tender-summary` skill ships with demo
learnings so you can see the shape on a fresh install.

## Self-improvement: the gated draft flow

A skill that rewrites itself is a prompt-injection amplifier, so Hermiq never
lets one. Instead, every self-improvement is a **draft version** that must
survive three gates before a human even sees it — and a human decision before
it applies.

### When does Hermiq propose a draft?

A background consolidation job creates a draft (one LLM pass) when:

1. **Threshold** — the skill's `learnings.md` accumulates enough entries
   (default 20);
2. **Regression** — an eval run linked to the skill fails the regression
   gate;
3. **Manual** — the owner explicitly asks for a proposal from the skill page.

At most **one draft per skill** is open at a time. Every draft records its
provenance — the driving learnings entries, run ids, the trigger, and the
pinned base version it diffs against — and the consolidation pass respects the
organisation kill-switch and budget hard caps just like a run does. The
active skill's content is never touched by proposing.

### Gate 1 — content scan

Before any human review, the draft's entire proposed content is
content-scanned — **including `learnings.md`, scanned as instruction
content**, because it is injected into agent context. A `dangerous` verdict
discards the draft outright, with **no override path** (stricter than the
install quarantine). If the scanner is unavailable, the draft fails closed and
stays un-reviewable.

### Gate 2 — paired draft-vs-active eval

When the skill is linked to an eval dataset, the draft is A/B-tested against
the active version using the [paired eval machinery](./skill-evals.md):
same agent, same dataset, same cases — only the skill version differs.

- A draft that scores **strictly worse** is auto-discarded, with both pass
  rates in the audit note (the learnings that drove it are retained).
- A draft that scores equal or better proceeds to review.
- A skill with **no linked dataset** still produces a reviewable draft, but it
  is honestly flagged **"no eval evidence"** — and accepting it grants no L5
  evidence.

### Gate 3 — human approval, with evidence in hand

A surviving draft enters `awaiting-approval` and creates a pending
**Approval** (the same [approval inbox](./approvals.md) used everywhere in
Hermiq). The approval request always carries everything an informed decision
needs — a deep link to the review surface, the scan verdict, the eval delta
(or the explicit no-evidence flag), and a summary of the driving learnings; an
approval missing any of that is invalid and never reaches a reviewer.

Reviewers hold the `skill.review-draft` action and get three decisions on the
skill's review surface (side-by-side diff, provenance, verdicts):

- **Accept** — the draft's content is applied to the skill as a **new
  version** through the normal versioned write path.
- **Edit, then accept** — modify the proposed content first; you become the
  author of record. Any edit **invalidates the prior scan and eval results**
  and re-runs the gates — an edited draft can't slip through on stale
  evidence.
- **Reject** — optionally marking specific learnings entries as *bad*, which
  excludes them from driving future proposals.

Approving from the generic approval inbox works too — approval *is*
acceptance, wherever it happens. Every draft transition is written to the
audit trail, so the full lineage of any skill version is reconstructable.

## Versions, rollback, and the regression watch

Every accepted draft (and every ordinary edit) is a new **version** in the
skill's history, viewable on the detail page with per-version field diffs
(frontmatter, body, files). Rolling back writes the old content as a *new*
version — history is never rewritten — and leaves non-versioned fields
(lifecycle state, maturity, provenance) alone. Runs pin the exact skill
versions that executed, so any run can be traced to the content that shaped
it.

After an acceptance, Hermiq keeps watching: if the next eval run for the
skill's linked dataset fails the regression gate, the detail page shows an
advisory **"roll back to previous version?"** suggestion and notifies the
accepting reviewer. Rollback stays a human action — it never happens
automatically.

## Republish: the "published copy is behind" signal

When a GitHub-published skill accepts a new version, its published copy is now
older than the local one. Hermiq shows a **"published copy is behind"** badge
on the catalog row and detail page and notifies the publisher once — and then
waits. **Republishing is never automatic**: a one-click Republish action
(behind the same publish authorization as first publish) pushes the current
version to the skill's own provenance repo and clears the badge.

## What ships and what never does

Publish and republish both apply the same file selection:

- `learnings.md` — vetted, promoted, redacted experience — **ships** with the
  package, so a shared skill carries what it has learned.
- `learning-candidates.md` — raw, unvetted observations — is **always
  stripped**. Unreviewed content never leaves your instance, on any publish
  route.

## Related pages

- [Skill maturity levels](./skill-levels.md) — consolidated learnings are the
  L6 evidence.
- [Skill evals](./skill-evals.md) — the paired machinery the draft gate
  reuses.
- [Approvals](./approvals.md) — the approval state machine draft acceptance
  runs through.
- [Skills](./skills.md) — the catalog, publishing, and the marketplace.
