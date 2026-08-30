---
kind: code
depends_on: [skill-maturity-model]
---

# Proposal: skill-learnings

## Summary

Build the L6 substrate of ADR-068 §3: per-skill learnings **capture**. After each run in
which an installed skill was actually exercised (its content was loaded into the run
context), a post-run, best-effort capture pass extracts dated atomic observations from
the run trace (one cheap LLM pass through `ProviderFactory`, budget-counted) and appends
them to the skill's `learning-candidates.md` entry in its agentskills.io `files` map. A
mechanical daily promotion job moves candidates confirmed in 3+ distinct runs (or ones
that explain a failed eval case) into a five-section `learnings.md`, drops candidates
untouched for 30 days, and stamps the skill's `levelEvidence.l6` activity per the
skill-maturity-model computed-field contract. All learnings writes reuse the
agent-memory redaction path. A read-only Learnings tab on the SkillDetail page surfaces
the result. Consolidation and self-modification drafts are explicitly NOT here — they
are the separate `skill-self-improvement` change.

## Motivation

`skill-maturity-model` defined `levelEvidence.l6` but nothing writes it: no skill in
hermiq can ever reach L6 because no learnings exist and no subsystem observes skill
performance during runs. ADR-068 §3 mandates the learnings-to-rules pipeline as the L6
mechanism, stored inside the skill's `files` map so accumulated experience travels with
the skill through the byte-for-byte SkillSerializer round-trip and external publishing.
Capture is the substrate every later stage (consolidation, gated self-improvement
drafts) builds on — without dated, run-attributed, redacted observations there is
nothing to consolidate. Landing capture separately keeps the prompt-injection surface
reviewable: this change only ever appends redacted observations to two well-known files;
it never edits a skill's `body`.

## Capabilities

### New Capabilities

- `skill-learnings`: post-run capture of dated atomic observations into
  `learning-candidates.md` (utilization-gated, budget-counted, failure-isolated,
  idempotent per run ID), the mechanical two-stage promotion job into the five-section
  `learnings.md`, the `levelEvidence.l6` activity stamp, redaction-inherited writes,
  and the read-only Learnings surface on SkillDetail.

### Modified Capabilities

- `skill-maturity`: the `levelEvidence.l6` sub-object gains two optional activity
  fields (`candidateCount`, `lastCaptureAt`) so the L6 evidence records capture
  activity, not only promotion/consolidation. The L6 pass rule itself is unchanged
  (`learningsCount > 0` + `lastConsolidatedAt` — consolidation stays in
  `skill-self-improvement`).

## Affected Projects

- [ ] Project: `hermiq` — new `SkillLearningsCaptureService` +
  `SkillLearningsPromotionService`; a queued post-run capture job + a daily
  `SkillLearningsPromotionTask` (TimedJob, sibling of `SkillCuratorTask`); a
  `skillsUsed` utilization record on the run trace; `levelEvidence.l6` activity
  fields in `lib/Settings/hermiq_register.json` (register version bump);
  SkillDetail Learnings tab widget; seed extension; unit + e2e tests.

## Scope

### In Scope

- **Utilization signal**: the engine records which installed skills' content was
  loaded into the run context (`skillsUsed` on the run trace/run record). Skills not
  exercised in a run get nothing — no credit, no blame.
- **Post-run capture**: a best-effort, failure-isolated pass that extracts dated,
  atomic observations from the run trace via one cheap LLM call (`ProviderFactory`),
  budget-counted against the same per-org/per-agent budgets as runs, and appends them
  (with date + run ID) to the skill's `files` entry `learning-candidates.md`.
- **Idempotency**: re-processing the same run ID never duplicates candidates.
- **Two-stage promotion** (mechanical, no LLM): candidates confirmed in 3+ DISTINCT
  runs, or annotated as explaining a failed eval case, promote into `learnings.md`
  (sections: Patterns That Work, Mistakes to Avoid, Domain Knowledge, Open Questions,
  Consolidated Principles); candidates untouched for 30 days are dropped.
- **Redaction inherited**: every learnings write passes the agent-memory
  `RedactionService` path before persist; redaction-empty means nothing is written.
- **`levelEvidence.l6` activity** ({candidateCount, learningsCount, lastCaptureAt,
  lastPromotedAt}) written by this subsystem only.
- **UI**: read-only Learnings tab/card on SkillDetail (rendered `learnings.md`,
  candidate count, last activity).
- **Seed**: one seeded skill gains a demo `learnings.md` + `learning-candidates.md`.

### Out of Scope

- Consolidation of learnings into a skill body, draft versions, human-approval-gate
  routing, quarantine re-entry, and the eval regression gate — all
  `skill-self-improvement` (ADR-068 §5).
- The export policy split (publish ships `learnings.md` but strips
  `learning-candidates.md`) — belongs to `skill-self-improvement`'s publish work;
  here both files simply exist in the `files` map and travel with the normal export.
- Skill-scoped evals and `levelEvidence.l5` (parallel `skill-evals` change); this
  change only READS an eval-failure annotation contract at capture time.
- Any manual editing surface for learnings; any change to `SkillSerializer`; any new
  write channel outside the redaction + `ObjectService` path.

## Approach

Hook the existing run persistence seam: the engine records `skillsUsed` when it injects
installed skill content into the run context; after the run's audit entry is written, a
queued capture job (never inline in the run) runs one `ProviderFactory` extraction pass
per exercised skill over the persisted run trace, redacts, and appends structured
candidate lines to `learning-candidates.md` in the skill's `files` array. Candidate
lines carry a machine-parseable marker (date, run IDs, target section, optional
eval-failure ref) so the daily `SkillLearningsPromotionTask` can promote/expire purely
mechanically. `levelEvidence.l6` is stamped by these two services only, honouring
skill-maturity-model's computed-field guard (client writes to `l6` are ignored on the
skill write paths). UI reuses the SkillDetail page from skill-maturity-model.

## New Dependencies

None.

## Impact

- `lib/Settings/hermiq_register.json` — `levelEvidence.l6` gains `candidateCount` +
  `lastCaptureAt` (optional; nothing added to `required`); register version bump +
  forced re-import (openregister#2075).
- `lib/Service/Engine/` — minimal utilization recording where skill content enters the
  run context; `ScheduleService` run record gains `skillsUsed`.
- New: `lib/Service/SkillLearningsCaptureService.php`,
  `lib/Service/SkillLearningsPromotionService.php`, `lib/Cron/SkillLearningsCaptureJob.php`
  (QueuedJob), `lib/Cron/SkillLearningsPromotionTask.php` (TimedJob), registered in
  `appinfo/info.xml`.
- `src/manifest.json` + a `SkillLearnings` widget on the SkillDetail page; seed repair
  step extension.
- NOT impacted: `SkillSerializer`, skill `body`/`frontmatter`, marketplace
  quarantine/approve/publish, the qualify/attest endpoints, run latency (capture is
  post-run and queued).

## Cross-Project Dependencies

- `skill-maturity-model` (predecessor, complete): defines `levelEvidence.l6` and the
  computed-field write guard this change extends and fills.
- ADR-068 §3 (hydra `openspec/architecture/`): the learnings-to-rules pipeline and its
  trust boundary; agent-memory spec: the redaction + governance path reused verbatim.
- Parallel changes `skill-evals` (writes `l5`; defines eval runs whose failed cases
  capture may reference) and `skill-self-improvement` (consumes `learnings.md`);
  boundary: this change never writes `l5`, never consolidates, never publishes.

## Risks

### Risk 1: Captured observations become a prompt-injection laundering channel
**Severity:** High — **Mitigation:** capture only APPENDS to the two learnings files —
never to `body`/`frontmatter`; nothing in this change feeds learnings back into any
prompt or skill content (that is the gated `skill-self-improvement` path, ADR-068 §5).
Writes are redacted (no secrets, no personal data, no raw conversation content) and go
through the unchanged `ObjectService` write path, so they are hash-chain audited.

### Risk 2: Capture cost creep across many skills/runs
**Severity:** Medium — **Mitigation:** one cheap extraction call per exercised skill per
run, hard-gated by the same `BudgetService` check as runs (an org over budget gets no
capture pass), and utilization-gated (unexercised skills cost nothing).

### Risk 3: Capture failures disturbing runs
**Severity:** Medium — **Mitigation:** explicit requirement — capture is post-run,
queued, best-effort; every failure is caught and logged; a capture error can never fail,
delay, or alter the run or its recorded outcome.

### Risk 4: Candidate file grows without bound
**Severity:** Low — **Mitigation:** the 30-day expiry drops stale candidates on every
promotion pass; promotion removes promoted lines; idempotency prevents duplicate lines
per run.

## Rollback Strategy

Revert the code (services, jobs, widget, manifest entry). The two `l6` activity fields
are optional and inert; they can stay on rollback without invalidating any object.
Learnings files already written remain ordinary `files` entries on skills — harmless,
removable by editing the skill. Unregistering the jobs stops all capture/promotion
immediately; no data migration to undo.

## Open Questions

None blocking — deferred decisions (utilization seam ownership, l6 field naming
alignment) are recorded in design.md.
