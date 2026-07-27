# Skill Learnings Specification

**Status**: in-progress

**Feature tier**: V2

**OpenSpec changes:** `skill-learnings` — in-progress (depends on
`skill-maturity-model`): the L6 capture substrate — the engine records which installed
skills' content was loaded into the run context (`skillsUsed`); a queued post-run,
budget-gated, idempotent, failure-isolated capture pass extracts dated atomic
observations from the run trace (one cheap `ProviderFactory` call) into the skill's
`files['learning-candidates.md']`; a daily mechanical promotion job moves candidates
confirmed in 3+ distinct runs (or explaining a failed eval case) into the five-section
`files['learnings.md']` and expires 30-day-stale candidates; all writes pass the
agent-memory redaction path and the unchanged `ObjectService` channel;
`levelEvidence.l6` activity is stamped by this subsystem only; read-only Learnings tab
on SkillDetail; demo learnings seeded on `tender-summary`.

## Purpose

Implements the capture half of ADR-068 §3 (the L6 learnings-to-rules pipeline) for
hermiq product skills: per-skill learnings live inside the skill's agentskills.io
`files` map so accumulated experience travels with the skill through the byte-for-byte
SkillSerializer round-trip and external publishing. Capture is append-only and
utilization-gated (no credit or blame without the skill's content having been in the
run context); promotion is two-stage and purely mechanical. Consolidation into the
skill body, draft versions, approval-gate routing, and the publish-time export split
(`learnings.md` ships, `learning-candidates.md` stripped) belong to the separate
`skill-self-improvement` change; skill-scoped eval evidence (`levelEvidence.l5`)
belongs to `skill-evals`.

## Requirements

Requirements land when `skill-learnings` archives — see
`openspec/changes/skill-learnings/specs/skill-learnings/spec.md` for the ADDED
requirements (utilization recording, post-run capture, failure isolation, budget
gating + accounting, per-run-ID idempotency, redaction + governance inheritance,
mechanical promotion, l6 single-writer activity, export round-trip with learnings
aboard, read-only Learnings UI, seeded demo learnings) and
`openspec/changes/skill-learnings/specs/skill-maturity/spec.md` for the `skill-maturity`
delta (l6 activity fields + write-guard extension).

## Notes

Depends on `skill-maturity-model` (the `levelEvidence.l6` contract and the SkillDetail
page), `skills-catalog` (V1 — `agentskill` schema, `files` map, SkillSerializer
round-trip), `agent-memory` (the `RedactionService` redact-before-persist path, reused
verbatim), and the run/budget substrate (`BudgetService`, `ProviderFactory`, run audit
entries). Related: ADR-068 §3 (pipeline + prompt-injection threat model: capture only
appends to two files and never touches `body`/`frontmatter`; the loop back into skill
content is the human-gated `skill-self-improvement` path), ADR-031 (imperative capture
extraction is a justified exception; promotion counters deliberately imperative —
design.md Decision 1), ADR-023 (no new actions — no new endpoints at all).
