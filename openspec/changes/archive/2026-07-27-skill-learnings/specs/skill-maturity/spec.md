# skill-maturity Specification (delta)

**Status**: in-progress
**Scope**: hermiq
**OpenSpec changes**:
- `skill-maturity-model`
- `skill-learnings`

## Purpose

Delta from `skill-learnings`: the `levelEvidence.l6` sub-object gains two OPTIONAL
capture-activity fields (`candidateCount`, `lastCaptureAt`) now that a real L6 writer
exists, and the computed-evidence write guard is extended to cover `l6`. The L6 pass
rule is unchanged. Applies on top of the requirements added by `skill-maturity-model`
(this change `depends_on` it, so its requirements land first).

## MODIFIED Requirements

### Requirement: The Skill schema carries maturity metadata as optional inert fields

The `Skill` schema (slug `agentskill`) in `lib/Settings/hermiq_register.json` MUST
declare three OPTIONAL properties: `maturityLevel` (integer, minimum 0, maximum 7,
default 0 — computed, never hand-set), `targetLevel` (integer, minimum 1, maximum 7 —
curator intent, freely editable), and `levelEvidence` (object with per-level sub-objects:
`l1`–`l3` `{passed, checkedAt}`; `l4` `{attestedBy, attestedAt, note}`;
`l5` `{evalDatasetId, passRate, baselineDelta, lastValidated}`;
`l6` `{candidateCount, learningsCount, lastCaptureAt, lastConsolidatedAt,
lastPromotedAt}` — the `candidateCount` (integer, minimum 0) and `lastCaptureAt`
(string, `format: date-time`) activity fields are declared explicitly because
OpenRegister silently drops undeclared object keys on write, and their descriptions MUST
state they are written only by the learnings subsystem;
`l7` `{declaredChain, lastExecutedChainRunId, lastExecutedAt}`). None of the three MUST
be added to the schema's `required` array, and no conditional (`if`/`then`/`allOf`)
blocks MUST be used (the OpenRegister importer rejects them). The register
`info.version` MUST be bumped and the repair step MUST apply the schema change as a
forced import so existing installs actually gain the fields.

#### Scenario: Existing skills stay valid and unchanged when the fields are added

- GIVEN a `Skill` object created before this change, with no maturity fields
- WHEN the bumped register is force-imported on upgrade
- THEN the existing `Skill` object MUST remain valid without modification
- AND its `maturityLevel` MUST read as absent/0 with no backfill or data transformation

#### Scenario: The upgraded schema exposes the three properties

- GIVEN the register `info.version` has been bumped and the app upgraded
- WHEN the repair step runs the forced configuration import
- THEN the imported `Skill` schema MUST expose `maturityLevel`, `targetLevel`, and
  `levelEvidence` as optional properties with the bounds above

#### Scenario: The l6 activity fields survive an OpenRegister write

- GIVEN the upgraded schema declaring `candidateCount` and `lastCaptureAt` inside
  `levelEvidence.l6`
- WHEN the learnings subsystem persists a skill with both fields set
- THEN both values MUST be present on the stored object after the write (not silently
  dropped as undeclared keys)

### Requirement: maturityLevel and computed evidence are never client-writable

The skill write paths (create, edit, import) MUST ignore client-supplied
`maturityLevel`, `levelEvidence.l1`–`l4`, and `levelEvidence.l6`, carrying the stored
values forward unchanged — only `SkillMaturityService` (via qualify) writes
`maturityLevel` and `l1`–`l3`, only the attest endpoint writes `l4`, and only the
learnings capture/promotion subsystem writes the `l6` activity fields. `targetLevel`
MUST remain freely editable through the normal write paths.

#### Scenario: A hand-set maturityLevel does not survive the write path

- GIVEN a stored skill with `maturityLevel` 2
- WHEN a client submits an edit payload claiming `maturityLevel: 7`
- THEN the persisted skill's `maturityLevel` MUST still be 2

#### Scenario: targetLevel is curator-editable

- GIVEN a stored skill with `targetLevel` 2
- WHEN the owner edits the skill setting `targetLevel: 4`
- THEN the persisted skill's `targetLevel` MUST be 4

#### Scenario: A forged l6 does not survive the write path

- GIVEN a stored skill whose `levelEvidence.l6` records `learningsCount` 0
- WHEN a client submits an edit payload claiming
  `levelEvidence.l6: {learningsCount: 99, lastConsolidatedAt: "2026-01-01T00:00:00Z"}`
- THEN the persisted skill's `levelEvidence.l6` MUST still record `learningsCount` 0
  with no `lastConsolidatedAt`

## Notes

- The L6 PASS rule from `skill-maturity-model` (`learningsCount > 0` +
  `lastConsolidatedAt`) is deliberately NOT modified: capture + promotion activity alone
  never grants L6 — consolidation (`skill-self-improvement`) completes the level.
- Register `info.version` for this delta bumps 0.16.0 → 0.17.0 (the predecessor's bump
  was 0.15.1 → 0.16.0); forced import per openregister#2075.
