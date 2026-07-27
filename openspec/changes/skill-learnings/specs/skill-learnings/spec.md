# skill-learnings Specification

**Status**: in-progress
**Scope**: hermiq
**OpenSpec changes**:
- `skill-learnings`

## Purpose

The L6 substrate of ADR-068 §3: per-skill learnings CAPTURE. After each run in which an
installed skill's content was actually loaded into the run context, a post-run,
best-effort, budget-counted pass extracts dated atomic observations from the run trace
into the skill's `learning-candidates.md`; a mechanical daily job promotes confirmed
candidates into a five-section `learnings.md` and expires stale ones. Both files live in
the skill's agentskills.io `files` map so accumulated experience travels with the skill.
Consolidation into the skill body and the publish-time export split are the separate
`skill-self-improvement` change.

## ADDED Requirements

### Requirement: The engine records which skills were exercised in a run

The system MUST record, on each run's persisted run record, the list of skill uuids
whose content was loaded into the run context (`skillsUsed`), captured at the point of
injection in the context-assembly path. A skill that is installed on the agent but whose
content was NOT loaded into the run context MUST NOT appear in `skillsUsed`. Learnings
capture MUST be driven exclusively by `skillsUsed` — a skill absent from a run's
`skillsUsed` MUST receive no candidates from that run (no credit or blame without
utilization).

#### Scenario: An exercised skill is recorded

- GIVEN an agent with an installed `active` skill whose content is injected into the
  run context
- WHEN the run completes and its run record is persisted
- THEN the run record's `skillsUsed` MUST contain that skill's uuid

#### Scenario: An installed but unexercised skill gets nothing

- GIVEN a skill installed on the agent whose content was not loaded into the run
  context for a given run
- WHEN the post-run capture pass processes that run
- THEN no candidate line for that run MUST be appended to that skill's
  `learning-candidates.md`
- AND the skill's `levelEvidence.l6` MUST be unchanged by that run

### Requirement: A post-run capture pass appends dated atomic candidates per exercised skill

After a run whose `skillsUsed` is non-empty, the system MUST execute a post-run capture
pass that, for each exercised skill, extracts atomic observations from the persisted run
trace using ONE cheap LLM call through the existing `ProviderFactory`, and appends them
as structured candidate lines to the skill's `files` entry `learning-candidates.md`
(creating the entry if absent). Each candidate line MUST carry the capture date, a
target `learnings.md` section (`patterns|mistakes|domain|questions`), and the run id.
The LLM MUST NOT write the file: the capture service MUST parse the LLM's structured
output and serialize candidate lines itself in a fixed machine-parseable grammar. When
the LLM output confirms an EXISTING candidate, the service MUST append the run id to
that candidate's run-id list and refresh its date instead of adding a duplicate line.

#### Scenario: Observations from a run are captured as candidates

- GIVEN a completed run whose `skillsUsed` contains skill S and whose trace is persisted
- WHEN the capture pass processes the run
- THEN S's `learning-candidates.md` MUST gain at least zero candidate lines, each with
  the capture date, a valid section tag, and the run's id in its run-id marker
- AND S's `body`, `frontmatter`, and all other `files` entries MUST be byte-unchanged

#### Scenario: A repeated observation becomes a confirmation, not a duplicate

- GIVEN skill S has a candidate line whose run-id list contains run R1
- WHEN the capture pass for run R2 reports the same observation as a confirmation
- THEN the existing candidate line's run-id list MUST gain R2 and its date MUST be
  refreshed
- AND no new candidate line for that observation MUST be added

### Requirement: Capture is failure-isolated from the run

Capture MUST run strictly after the run's outcome is persisted (queued background
execution, never inline in the run), and a capture failure of ANY kind — enqueue
failure, provider error, redaction error, persistence error — MUST NOT fail, delay, or
alter the run, its recorded outcome, or its delivered result. Every capture failure
MUST be caught and logged, and a failure while processing one skill MUST NOT prevent
capture for the run's other exercised skills.

#### Scenario: A provider outage during capture leaves the run untouched

- GIVEN a completed, persisted run with two exercised skills
- WHEN the capture pass's LLM call fails for the first skill
- THEN the run's record, outcome, and delivery MUST be unaffected
- AND the failure MUST be logged
- AND capture MUST still be attempted for the second skill

### Requirement: Capture is budget-gated and budget-counted

Before any capture LLM call, the system MUST check the same per-org/per-agent budget
authority that gates runs (`BudgetService`): when the scope is budget-blocked, the
capture pass MUST be skipped (logged, no LLM call, no write). The tokens consumed by a
capture pass MUST be recorded through the same usage channel the budget authority
aggregates for runs, attributed to the originating run's schedule/agent scope, so
capture cost counts against the SAME budget windows as run cost.

#### Scenario: A run that exhausted the budget gets no capture pass

- GIVEN an organisation whose budget hard cap is reached by a run
- WHEN the post-run capture job for that run executes
- THEN no LLM call MUST be made and no candidate MUST be written
- AND the skip MUST be logged

#### Scenario: Capture tokens count toward the budget window

- GIVEN a capture pass that consumed tokens for skill S after run R
- WHEN the budget status for R's org/agent scope is computed for the current period
- THEN the reported token usage MUST include the capture pass's tokens

### Requirement: Capture is idempotent per run ID

Re-processing the same run id for the same skill MUST NOT duplicate candidates or
confirmations: before invoking the LLM, the capture service MUST check whether the
skill's `learning-candidates.md` already records that run id in any candidate's run-id
marker, and if so MUST skip the skill for that run entirely (no LLM call, no write).

#### Scenario: A re-delivered capture job is a no-op

- GIVEN skill S's `learning-candidates.md` already contains run R's id
- WHEN a capture job for run R executes again (re-delivery or double enqueue)
- THEN S's `learning-candidates.md` MUST be byte-unchanged
- AND no LLM call MUST be made for S

### Requirement: Learnings writes inherit the agent-memory redaction path and tool governance

Every observation text MUST pass the existing `RedactionService` secret/PII redaction
before persist — no secrets, no personal data, and no raw conversation content may
enter `learning-candidates.md` or `learnings.md` (observations are length-capped atomic
statements, extraction not quotation). An observation that redacts to empty MUST be
dropped; when ALL of a pass's observations are dropped, nothing MUST be written at all.
Learnings writes MUST persist exclusively through the unchanged OpenRegister
`ObjectService` write path (so the hash-chained AuditTrail records them) and MUST NOT
introduce a new write channel: no new HTTP endpoint, and no MCP tool — an agent MUST
NOT be able to invoke capture or promotion on any skill.

#### Scenario: A secret in a run trace never reaches the candidates file

- GIVEN a run trace containing a recognised credential pattern
- WHEN the capture pass extracts an observation containing that credential
- THEN the persisted candidate line MUST carry the masked form, never the raw credential

#### Scenario: Redaction-empty means no write

- GIVEN a capture pass whose every extracted observation is empty after redaction
- WHEN the pass completes
- THEN the skill's `files` and `levelEvidence.l6` MUST be unchanged (no empty lines, no
  activity stamp)

#### Scenario: No agent-invocable capture surface exists

- GIVEN an agent running inside the tool loop with an unrestricted tool allowlist
- WHEN the LLM's available functions are assembled
- THEN no tool that triggers learnings capture or promotion MUST be offered

### Requirement: Promotion is a mechanical two-stage background pass

The system MUST run a daily background job (TimedJob, parallel runs disallowed) that,
WITHOUT any LLM call, parses each skill's `learning-candidates.md` grammar and: promotes
to the skill's `files` entry `learnings.md` every candidate confirmed by 3 or more
DISTINCT run ids; promotes every candidate carrying a failed-eval marker regardless of
confirmation count; and drops every candidate untouched for 30 days. Promoted candidates
MUST be placed under their tagged section in `learnings.md` — which MUST hold exactly
five sections: Patterns That Work, Mistakes to Avoid, Domain Knowledge, Open Questions,
Consolidated Principles — and MUST be removed from `learning-candidates.md`. This change
MUST NOT write the Consolidated Principles section (reserved for
`skill-self-improvement` consolidation). The confirmation threshold and expiry window
are service-owned constants; the two-stage RULE is normative.

#### Scenario: A thrice-confirmed candidate promotes to its section

- GIVEN a candidate tagged `{domain}` whose run-id list holds 3 distinct run ids
- WHEN the promotion job runs
- THEN the observation MUST appear under Domain Knowledge in `learnings.md`
- AND the candidate line MUST be removed from `learning-candidates.md`

#### Scenario: A candidate explaining a failed eval case promotes immediately

- GIVEN a candidate with one run id but a failed-eval marker
- WHEN the promotion job runs
- THEN the candidate MUST be promoted to `learnings.md`

#### Scenario: A stale candidate is dropped

- GIVEN a candidate whose date is older than the expiry window with fewer than 3
  distinct run ids and no failed-eval marker
- WHEN the promotion job runs
- THEN the candidate line MUST be removed without entering `learnings.md`

#### Scenario: Promotion never calls an LLM

- GIVEN a promotion pass over any set of skills
- WHEN the job executes
- THEN no LLM provider call MUST be made

### Requirement: levelEvidence.l6 activity is written by the learnings subsystem only

The capture and promotion services MUST be the only writers of the skill's
`levelEvidence.l6` activity fields: capture stamps `candidateCount` and `lastCaptureAt`;
promotion stamps `candidateCount`, `learningsCount`, and `lastPromotedAt`. The counts
MUST be derived from the actual parsed file contents at write time. This change MUST NOT
write `lastConsolidatedAt` and MUST NOT alter the skill-maturity L6 pass rule — a skill
with captured and promoted learnings but no consolidation remains below L6 with an
honest scorecard reason.

#### Scenario: Capture stamps activity

- GIVEN a capture pass that appended its first candidate line to skill S
- WHEN the pass persists
- THEN S's `levelEvidence.l6.candidateCount` MUST equal the parsed candidate count
- AND `levelEvidence.l6.lastCaptureAt` MUST be stamped

#### Scenario: Promotion alone does not grant L6

- GIVEN a skill with `learningsCount` 4, `lastPromotedAt` set, and no
  `lastConsolidatedAt`
- WHEN the skill is qualified via the skill-maturity qualify endpoint
- THEN L6 MUST be reported failed with a learnings-activity reason referencing missing
  consolidation

### Requirement: Learnings files live in the files map and travel with the export

`learnings.md` and `learning-candidates.md` MUST exist as ordinary entries in the
skill's agentskills.io `files` map, and both MUST travel with the skill through the
existing skills-catalog export/round-trip unchanged in this change (the publish-time
policy split — shipping `learnings.md` while stripping `learning-candidates.md` —
belongs to `skill-self-improvement` and MUST NOT be implemented here). The
`SkillSerializer` byte-for-byte round-trip guarantee MUST hold for a skill carrying
both files.

#### Scenario: Learnings travel with the exported skill

- GIVEN a skill with populated `learnings.md` and `learning-candidates.md` entries
- WHEN the skill is exported to an agentskills.io package and re-imported
- THEN both files MUST be present and byte-identical after the round trip

### Requirement: SkillDetail shows a read-only Learnings surface

The SkillDetail page (`/skills/:id`) MUST show a Learnings tab or card rendering the
skill's `learnings.md` as formatted markdown together with the candidate count and last
activity (`lastCaptureAt`/`lastPromotedAt`) from `levelEvidence.l6`. The surface MUST be
read-only in this change: no editing, adding, or deleting of learnings content is
offered. A skill with no learnings files MUST show an empty state, not an error.

#### Scenario: The Learnings tab renders promoted learnings

- GIVEN a skill whose `learnings.md` has entries under Patterns That Work and a
  `levelEvidence.l6` with `candidateCount` 2
- WHEN the user opens the skill's detail page and its Learnings surface
- THEN the rendered sections and entries MUST be visible with the candidate count and
  last activity
- AND no edit affordance MUST be present

#### Scenario: A skill without learnings shows an empty state

- GIVEN a skill with no `learnings.md` or `learning-candidates.md` entries
- WHEN the user opens its Learnings surface
- THEN an empty state MUST be shown and no error MUST occur

### Requirement: One seeded skill demonstrates the learnings shape

The seed repair step MUST give exactly one seeded skill (`tender-summary`) a demo
`learnings.md` (five sections; realistic consultancy-context entries; Consolidated
Principles empty) and a demo `learning-candidates.md` (candidate lines in the exact
grammar, nil-UUID run ids) plus matching `levelEvidence.l6` activity WITHOUT
`lastConsolidatedAt`, idempotently: files are added only when absent, an admin-edited
skill is never overwritten, and re-running the seed never duplicates content.
Placeholders MUST be nil UUIDs / `YOUR_API_KEY_HERE` style only.

#### Scenario: A fresh install demonstrates learnings end-to-end

- GIVEN a fresh Hermiq install runs its repair steps
- WHEN the user opens the `tender-summary` skill's Learnings surface
- THEN populated learnings sections and the seeded activity counts MUST render
- AND the maturity scorecard MUST still report L6 not passed (no consolidation)

#### Scenario: Re-running the seed never duplicates learnings

- GIVEN `tender-summary` already carries the seeded learnings files
- WHEN the repair step runs again on upgrade
- THEN no duplicate files or duplicate lines MUST be created and admin edits MUST be
  preserved

## Non-Functional Requirements

- **Performance:** capture adds ZERO latency to runs (queued post-run); one LLM call
  per exercised skill per run, hard-capped by the budget gate; the promotion pass is
  linear line parsing and MUST complete for 100 skills with 200 candidates each without
  an LLM call or network I/O beyond OR persistence.
- **Accessibility:** target WCAG 2.2 AA. The Learnings surface is standard text/markdown
  content — headings preserve hierarchy (1.3.1); activity metadata is text, never
  color-only (1.4.1). Of the NEW-in-2.2 SCs, 2.4.11/2.5.7/2.5.8/3.2.6/3.3.7/3.3.8 are
  n/a — read-only content, no dragging, no new targets beyond standard components, no
  forms.
- **Internationalization:** Dutch and English MUST be supported (ADR-007) for all UI
  strings (tab label, activity labels, empty state). Captured observation text is
  stored as produced (run-language) and is not translated.

## Acceptance Criteria

- `skillsUsed` recorded at the injection seam; capture keyed exclusively off it
- Post-run queued capture appends dated, sectioned, run-id-marked candidates via one
  `ProviderFactory` call; service-serialized grammar; confirmations extend, never
  duplicate
- A capture failure never fails/delays/alters a run; per-skill isolation; logged
- Budget-blocked scope → no capture; capture tokens counted in the same budget windows
- Same run id re-processed → byte-identical candidates file, no LLM call
- Redaction before persist; redaction-empty → no write; `ObjectService`-only writes;
  no new endpoint/tool
- Daily mechanical promotion: 3+ distinct runs or eval-fail marker → `learnings.md`
  section; 30-day expiry; Consolidated Principles never written
- `levelEvidence.l6` `{candidateCount, learningsCount, lastCaptureAt, lastPromotedAt}`
  written by this subsystem only; L6 pass rule unchanged
- Both learnings files round-trip byte-for-byte through the export
- Read-only Learnings tab on SkillDetail (EN + NL); empty state
- Idempotent demo learnings seed on `tender-summary`

## Notes

- Schema.org: learnings files are content of the `schema:CreativeWork`-shaped
  `agentskill` object; no new schema.org mapping.
- OCP surfaces: `OCP\BackgroundJob\QueuedJob` (capture), `OCP\BackgroundJob\TimedJob`
  (promotion), `OCP\BackgroundJob\IJobList` (enqueue); no new controllers.
- ADR-068 §3 defines the pipeline and threat model; ADR-031 exception rationale and the
  service-constant thresholds (3 confirmations, 30 days, observation length cap) are in
  design.md Decision 1; the utilization-seam ownership decision is design.md Decision 2.
- The candidate line grammar is normative-by-test (pinned in unit tests), documented in
  design.md; `files` is physically an array of `{name, content}` entries — "files map"
  language follows the agentskills.io convention of unique names.
