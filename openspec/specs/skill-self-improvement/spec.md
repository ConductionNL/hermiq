# Skill Self-Improvement Specification

**Status**: in-progress

**Feature tier**: V2

**OpenSpec changes:** `skill-self-improvement` — in-progress: closes the ADR-068 §5
loop — `SkillConsolidationTask` (TimedJob) proposes DRAFT skill versions from
`files['learnings.md']` (threshold / eval-regression / manual triggers; one LLM pass
via `ProviderFactory`, kill-switch + budget gated); new `SkillDraft` OR object with
provenance + pinned base version; pre-qualification (marketplace content scan treating
learnings.md as instruction content — dangerous discards with no override — plus
paired draft-vs-active eval reusing `skill-evals`' paired mode, strictly-worse
auto-discarded, no-evals flagged `noEvalEvidence`); human acceptance through the
human-approval-gate `Approval` state machine behind `skill.review-draft` (ADR-023) with
Accept / Edit-then-accept / Reject (+ bad-learnings marking); skill version
history/diff/rollback/run-pinning mirroring `agent-versioning`;
`Skill.lastAcceptedVersionAt`; post-acceptance regression watch with an advisory
rollback suggestion; GitHub "published copy is behind" badge + notification + one-click
Republish (never automatic; strips `files['learning-candidates.md']` — see the
skills-marketplace delta); every transition audited via OR AuditTrail. Depends on
`skill-maturity-model`, `skill-evals`, `skill-learnings`.

## Purpose

Makes hermiq's "self-improving skills" claim real under the ADR-068 threat model: a
self-modified skill is a prompt-injection amplifier, so every self-improvement is a
gated draft version — draft → content scan → eval regression gate → human approval →
versioned apply with rollback → explicit republish — never in-place, never
auto-applied, never auto-published. Consolidation turns accumulated learnings (captured
and promoted by `skill-learnings`) into a proposed new version; measurement
(`skill-evals`) is the evidence bottleneck by design; the human remains the author of
record (edit-then-accept).

## Requirements

Requirements are defined in the in-progress change
`openspec/changes/skill-self-improvement/specs/skill-self-improvement/spec.md` and will
be folded in here at archive time.

## Notes

- ADR-068 §5 (gated draft path — non-negotiable), ADR-023 (action authorization),
  ADR-031 (imperative justification recorded in the change's design.md), ADR-060
  (regression evidence from real executed runs).
- Related capabilities: `skill-maturity` (evidence contract + SkillDetail),
  `skill-evals` (paired mode, `levelEvidence.l5`), `skill-learnings` (capture +
  promotion, `levelEvidence.l6`), `skills-marketplace` (publish/republish + export
  strip delta), `agent-versioning` (the mirrored versioning pattern),
  `human-approval-gate` (Approval state machine + notification), `run-audit-log`
  (audit seam).
