# Skill Maturity Specification

**Status**: in-progress

**Feature tier**: V2

**OpenSpec changes:** `skill-maturity-model` — in-progress: `agentskill` schema gains
`maturityLevel` (computed 0–7) / `targetLevel` (curator intent) / `levelEvidence`
(per-level evidence); `SkillMaturityService` computes L1–L3 mechanically; L4 is
human-attested via the action-gated attest endpoint (ADR-023); L5–L7 are read from
evidence written by the future `skill-evals` / `skill-learnings` /
`skill-self-improvement` / `skill-orchestration` changes; owner-guarded Qualify endpoint
+ scorecard; catalog maturity dots + SkillDetail scorecard; three seed skills at
L1/L2/L4.
`skill-evals` — in-progress: adds the read surface for the L5 evidence written by paired
eval runs — a SkillDetail eval-evidence card (pass rate, baseline delta, trend, last
validated) + owner-guarded "Run paired eval" action; the l5 contract itself and its
silent-preserve write protection are unchanged (the writer lives in the agent-evals
delta of that change). See `openspec/changes/skill-evals/specs/skill-maturity/spec.md`.
`skill-learnings` — in-progress (delta, depends on `skill-maturity-model`):
`levelEvidence.l6` gains optional capture-activity fields `candidateCount` +
`lastCaptureAt` (declared explicitly — OR drops undeclared keys); the computed-evidence
write guard extends to `l6` (written only by the learnings capture/promotion
subsystem). The L6 pass rule (`learningsCount` > 0 + `lastConsolidatedAt`) is
unchanged — capture/promotion alone never grant L6.

## Purpose

Ports the fleet's L1–L7 skill maturity model (ADR-068; canonical level definitions in
`.github/docs/claude/writing-skills.md` — referenced, never forked) onto hermiq
`agentskill` objects as a skill-qualification tool: computed maturity metadata, a
mechanical L1–L3 qualifier, action-gated L4 human attestation, a read-only L5–L7
evidence contract for future subsystems, and a qualify/scorecard surface in the catalog.
Maturity is orthogonal to the curation lifecycle (`state` — skills-catalog /
skills-marketplace); neither derives from the other, and the maturity fields are hermiq
metadata that never enter the byte-for-byte agentskills.io export.

## Requirements

Requirements land when `skill-maturity-model` archives — see
`openspec/changes/skill-maturity-model/specs/skill-maturity/spec.md` for the ADDED
requirements (schema metadata, mechanical L1–L3, attested L4, evidence-read L5–L7,
owner-guarded qualify + scorecard, computed-field write protection, export invariance,
lifecycle orthogonality, catalog UI, seed skills).

## Notes

Depends on `skills-catalog` (V1) for the `agentskill` schema and `SkillSerializer`, and
on ADR-023 action authorization for the L4 attest action (`skill.attest-maturity`).
Related: ADR-068 (skill maturity + self-learning), ADR-031 (declarative-vs-imperative —
design.md Decision 1 of the change justifies the imperative qualifier service).
