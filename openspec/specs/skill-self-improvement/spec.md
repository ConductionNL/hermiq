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

### Requirement: The SkillDraft schema and Skill.lastAcceptedVersionAt are optional inert register fields

`lib/Settings/hermiq_register.json` MUST declare a new `SkillDraft` schema (slug
`agentskilldraft`; required only `skillId`; no `if`/`then`/`allOf` blocks) carrying:
`skillId`, `baseVersionId`, `trigger` (enum `threshold`/`regression`/`manual`), `status`
(enum `proposed`/`awaiting-approval`/`accepted`/`rejected`/`discarded`, default
`proposed`), proposed content (`proposedFrontmatter`, `proposedBody`, `proposedFiles` as
`{name, content}` entries), `provenance` (driving `learningRefs`, `runIds`,
`triggerEvalRunId`), `scanVerdict` + `scanReport`, `evalEvidence` (`datasetId`,
`draftPassRate`, `activePassRate`, `delta`, `draftEvalRunId`, `activeEvalRunId`),
`noEvalEvidence`, `approvalId`, `editedBeforeAccept` + `editedBy`,
`rejectedLearningRefs`, `auditNote`, `decidedBy`, `decidedAt`. The `Skill` schema MUST
gain an optional `lastAcceptedVersionAt` (string, `format: date-time`) that is
service-written only. The register `info.version` MUST be bumped and applied as a
FORCED import so existing installs gain both.

#### Scenario: Existing skills stay valid when the field is added

- GIVEN a `Skill` object created before this change
- WHEN the bumped register is force-imported on upgrade
- THEN the existing `Skill` MUST remain valid without modification
- AND its `lastAcceptedVersionAt` MUST read as absent with no backfill

#### Scenario: The upgraded register exposes the SkillDraft schema

- GIVEN the register `info.version` has been bumped and the app upgraded
- WHEN the repair step runs the forced configuration import
- THEN the `agentskilldraft` schema MUST exist with the properties and enums above
- AND only `skillId` MUST be listed as required

### Requirement: Consolidation proposes a draft version and never edits the active skill

The system MUST provide a `SkillConsolidationTask` (TimedJob, sibling of
`SkillCuratorTask`) that proposes a DRAFT new skill version when (a) the skill's
`files['learnings.md']` entry count reaches a configurable threshold (default 20), or
(b) an eval run linked to the skill (its dataset's `skillRefs` contains the skill)
completes with `regressionGateResult` `failed`; and a manual owner-guarded
`POST /api/skills/{id}/propose-improvement` endpoint as trigger (c). Consolidation MUST
be a single LLM pass through `ProviderFactory::generateText()`. The proposal MUST be
persisted as a `SkillDraft` object recording provenance (the driving learnings entries,
run IDs, and the trigger), the pinned `baseVersionId` of the active version it diffs
against, and the proposed content. No code path in this capability MUST write
`frontmatter`, `body`, or `files` of the active `Skill` except draft acceptance. At most
ONE draft per skill MAY be open (`proposed` or `awaiting-approval`) at a time — all
three triggers MUST no-op (manual trigger returning the existing open draft) while one
is open.

#### Scenario: Threshold trigger creates a draft with provenance

- GIVEN a skill whose `files['learnings.md']` holds 20 or more entries and no open draft
- WHEN the consolidation job runs
- THEN a `SkillDraft` MUST be created in `proposed` state via one
  `ProviderFactory::generateText()` call
- AND its provenance MUST list the driving learnings entries and the pinned
  `baseVersionId`
- AND the active skill's `frontmatter`, `body`, and `files` MUST be byte-identical to
  before the job ran

#### Scenario: An open draft suppresses new proposals

- GIVEN a skill with a draft in `awaiting-approval`
- WHEN the threshold trigger fires and the owner also calls
  `POST /api/skills/{id}/propose-improvement`
- THEN no second draft MUST be created
- AND the manual call MUST return the existing open draft

#### Scenario: A linked eval regression triggers a proposal

- GIVEN a skill linked to an EvalDataset via `skillRefs` and no open draft
- WHEN an eval run for that dataset completes with `regressionGateResult` `failed`
- THEN the next consolidation pass MUST create a draft with `trigger` `regression`
- AND the provenance MUST record the triggering eval run id

### Requirement: Consolidation and its evals respect the kill-switch and budget hard-caps

The system MUST check the skill organisation's kill-switch
(`ScheduleService::isOrganisationEngaged()`) and budget hard cap
(`BudgetService::isBlocked()`) before the consolidation LLM call AND before the paired
pre-qualification eval, exactly as a schedule tick and an eval run are gated
(agent-evals precedent), and consolidation token usage MUST roll into the same budget
aggregation. When either gate blocks, no LLM or eval call MUST be made and the blocked
attempt MUST be recorded.

#### Scenario: Engaged kill-switch blocks consolidation

- GIVEN a skill whose organisation has an engaged kill-switch
- AND the skill's learnings count is past the threshold
- WHEN the consolidation job runs
- THEN no `ProviderFactory` call MUST be made and no draft MUST be created
- AND the blocked attempt MUST be auditable

#### Scenario: Budget hard cap blocks the paired eval

- GIVEN a draft in `proposed` whose organisation has reached its budget hard cap
- WHEN the pre-qualification eval would run
- THEN no eval case MUST execute
- AND the draft MUST remain in `proposed` (gates are evidence, never bypassed)

### Requirement: Every draft is content-scanned with learnings treated as instruction content

Before any human review, the system MUST run the marketplace content scan
(OpenRegister `ContentScanService`) over the draft's proposed `frontmatter`, `body`, and
ALL proposed files — explicitly INCLUDING `files['learnings.md']`, which MUST be scanned
as instruction content (it is injected into agent context) — recording `scanVerdict` and
`scanReport` on the draft. A `dangerous` verdict MUST transition the draft to
`discarded` with an audit note; no override action MUST exist for a dangerous
self-modified draft (stricter than the install quarantine path). A draft MUST NOT reach
`awaiting-approval` unscanned; if the scan is unavailable the draft MUST remain in
`proposed`.

#### Scenario: A dangerous verdict discards the draft with no override

- GIVEN a draft whose proposed `learnings.md` contains injected instruction content the
  scan rates `dangerous`
- WHEN pre-qualification runs
- THEN the draft MUST transition to `discarded` with the verdict in its audit note
- AND no endpoint MUST exist that forces this draft onward (unlike
  `skill.override-scan-verdict` on the install path)

#### Scenario: Scan unavailability fails closed

- GIVEN the content scan service is unavailable
- WHEN pre-qualification runs for a `proposed` draft
- THEN the draft MUST remain in `proposed` and MUST NOT reach `awaiting-approval`

### Requirement: A paired draft-vs-active eval gates the draft, and a worse draft is auto-discarded

When the skill is linked to an EvalDataset, pre-qualification MUST run a paired A/B
eval (reusing the `skill-evals` paired mode) — the draft version versus the active
version with everything else frozen (same agent, dataset, cases) — and record both pass
rates and the delta in `evalEvidence`. A draft whose pass rate is strictly LOWER than
the active version's MUST be auto-discarded with an audit note carrying both pass rates
(the driving learnings entries are retained); a draft whose pass rate EQUALS the
active version's MUST survive to human review. A skill with NO linked EvalDataset MUST
still produce a reviewable draft flagged `noEvalEvidence: true`; accepting such a draft
MUST NOT grant or write any L5 evidence (`levelEvidence.l5` remains owned by
`skill-evals`).

#### Scenario: A regressing draft is auto-discarded and learnings survive

- GIVEN a draft whose paired eval scores `draftPassRate` 0.6 against `activePassRate` 0.8
- WHEN pre-qualification completes
- THEN the draft MUST transition to `discarded` with both rates in the audit note
- AND the skill's `files['learnings.md']` MUST be unchanged (entries retained)
- AND no `Approval` object MUST be created

#### Scenario: No linked evals yields an honestly-flagged draft

- GIVEN a skill with no EvalDataset whose `skillRefs` contains it
- WHEN a draft passes the content scan
- THEN the draft MUST reach `awaiting-approval` with `noEvalEvidence: true`
- AND the review surface MUST state there is no eval evidence
- AND accepting it MUST NOT write `levelEvidence.l5`

### Requirement: Draft acceptance runs through the Approval state machine behind action authorization

When a draft reaches `awaiting-approval`, the system MUST create a linked `Approval`
object (human-approval-gate state machine, `sourceType: "skill-draft"`) whose pending
state notifies the reviewer via Talk/Notifications. The Approval's request payload
MUST carry everything an informed out-of-band decision needs: a deep link to the
SkillDetail review surface, the scan verdict, the eval delta (or the explicit
`noEvalEvidence` flag), and a one-line summary of the driving learnings entries — an
`Approval` missing any of these MUST be rejected as invalid at creation and MUST NOT
reach any approval surface (the draft stays awaiting a valid Approval). Approval of
the draft's `Approval` object IS acceptance: the apply step MUST fire on the
pending-to-`approved` state transition regardless of which surface approved it —
including the generic approval inbox — writing the draft's content onto the `Skill`
through the normal versioned write path (a NEW version recorded in AuditTrail,
subsequently pinned by runs), stamping `Skill.lastAcceptedVersionAt`, and
transitioning the draft to `accepted`. Denial from any surface MUST reconcile the
draft to `rejected`. The decision endpoints (`POST /api/skill-drafts/{id}/accept`,
`/reject`, and the edit-then-accept content update) MUST require the
`skill.review-draft` action via `ActionAuthService::requireAction()` (ADR-023) and
MUST decide by transitioning that SAME Approval object. Edit-then-accept MUST remain
available ONLY on the SkillDetail review surface (editing needs the surface): it lets
the reviewer modify the draft's proposed content first, recording `editedBeforeAccept`
and the editor, and any edit to the draft's proposed content MUST invalidate the prior
scan and eval results and re-run pre-qualification — the Approval MUST NOT be
approvable from ANY surface until re-qualification passes (otherwise an inbox approval
could apply an edited-but-unscanned body). Reject MAY record curator-marked
`rejectedLearningRefs` — entries so marked in any prior rejected draft of the skill
MUST be excluded from driving the next proposal. A caller without the action MUST
receive `403 Forbidden` with draft and skill unchanged; an invisible draft/skill MUST
yield 404 before any action check.

#### Scenario: Accepting a draft creates a new active version

- GIVEN a draft in `awaiting-approval` with a pending linked `Approval`
- AND a caller granted `skill.review-draft`
- WHEN the caller posts to `/api/skill-drafts/{id}/accept`
- THEN the `Approval` MUST reach `approved` and the draft MUST become `accepted`
- AND the skill's `frontmatter`/`body`/`files` MUST equal the draft's proposed content,
  written as a NEW version (prior versions unchanged)
- AND `Skill.lastAcceptedVersionAt` MUST be stamped

#### Scenario: Edit-then-accept records human curation

- GIVEN a reviewer who first updates the draft's proposed body via the draft content
  endpoint and then accepts
- WHEN the accepted version is inspected
- THEN it MUST carry the reviewer's edited content, not the original LLM output
- AND the draft MUST record `editedBeforeAccept: true` and the editor

#### Scenario: Rejecting can mark learnings as bad for future proposals

- GIVEN a reviewer rejecting a draft while marking one driving learnings entry as bad
- WHEN the skill's next consolidation runs after the threshold is reached again
- THEN the new draft's provenance MUST NOT include the marked entry as a driving entry

#### Scenario: An unauthorized caller cannot decide a draft

- GIVEN a caller whose groups are not mapped to `skill.review-draft`
- WHEN the caller posts to `/api/skill-drafts/{id}/accept`
- THEN the system MUST respond `403 Forbidden`
- AND the draft, its Approval, and the skill MUST be unchanged

#### Scenario: Approving from the generic approval inbox applies the draft

- GIVEN a draft's linked `Approval` pending in the generic approval inbox
- WHEN an authorized approver approves it there, without opening SkillDetail
- THEN the draft's content MUST be applied onto the skill as a NEW active version
  through the versioned write path
- AND `Skill.lastAcceptedVersionAt` MUST be stamped and the draft MUST become
  `accepted`

#### Scenario: Denying from the generic approval inbox rejects the draft

- GIVEN a draft's linked `Approval` pending in the generic approval inbox
- WHEN an approver denies it there
- THEN the draft MUST be reconciled to `rejected`
- AND the skill's content MUST be unchanged

#### Scenario: An Approval without its decision-evidence payload is invalid

- GIVEN a draft that has passed pre-qualification and reaches `awaiting-approval`
- WHEN its `Approval` would be created without the SkillDetail deep link, the scan
  verdict, the eval delta (or `noEvalEvidence` flag), or the driving-learnings summary
- THEN the `Approval` MUST be rejected as invalid and MUST NOT reach any approval
  surface
- AND the draft MUST remain awaiting a valid `Approval`

#### Scenario: Editing the draft invalidates prior gate evidence

- GIVEN a draft in `awaiting-approval` whose reviewer updates the proposed body on the
  SkillDetail review surface
- WHEN the content update lands
- THEN the prior `scanVerdict` and `evalEvidence` MUST be invalidated and
  pre-qualification MUST re-run over the edited content
- AND the linked `Approval` MUST NOT be approvable from any surface until the re-run
  scan (and paired eval, when a dataset is linked) passes

### Requirement: Skills have version history, diff, and rollback mirroring agent-versioning

The system MUST expose a skill's version history as its OpenRegister AuditTrail
`create`/`update` entries (newest first, each with the entry UUID as version id,
timestamp, acting user, action); MUST compute a field-level diff between any two
versions limited to the versioned field set `frontmatter`, `body`, `files`; and MUST
let an authorized owner roll back to a previous version by writing that version's
versioned-field values as a NEW version — existing AuditTrail entries MUST NOT be
altered or deleted, and non-versioned fields (identity, lifecycle `state`, provenance
`githubOwner`/`githubRepo`/`publishedAt`, maturity and evidence fields, `installedOn`)
MUST retain their current values. Version endpoints MUST be owner-guarded, returning
404 (never 403) on any mismatch.

#### Scenario: Rolling back restores content as a new version

- GIVEN a skill whose current body differs from version V's body
- WHEN the owner posts `/api/skills/{id}/rollback` with version V
- THEN the skill's `frontmatter`/`body`/`files` MUST match version V's values
- AND a brand-new version MUST be recorded, with version V's history entry unchanged

#### Scenario: Rollback leaves non-versioned fields alone

- GIVEN the skill's `state`, `maturityLevel`, and `publishedAt` changed since version V
- WHEN the owner rolls back to version V
- THEN `state`, `maturityLevel`, `levelEvidence`, and the GitHub provenance fields MUST
  retain their CURRENT values

#### Scenario: Diff covers only the versioned field set

- GIVEN two versions differing in `body` and in `state`
- WHEN the diff between them is requested
- THEN the diff MUST contain `body` with old and new values
- AND MUST NOT contain `state`

### Requirement: Runs pin the exact skill versions that executed

The system MUST record, on every run audit entry hermiq writes for a run in which
installed skills were available, the version identifier of each installed skill as it
was at run start, so any run can be traced to the exact skill content that shaped it.
A failed version lookup MUST NOT fail the run — the audit entry is written without the
pin.

#### Scenario: A run records the executing skill version

- GIVEN an agent with skill S installed, S currently at version V
- WHEN a scheduled run completes and S is then accepted to a new version W
- THEN the completed run's audit entry MUST pin S at version V, not W

#### Scenario: A pin failure is never fatal

- GIVEN the version lookup fails during a run's audit write
- WHEN the run completes
- THEN the run's audit entry MUST still be written without the skill version pin
- AND the run's own status MUST be unaffected

### Requirement: Post-acceptance regression surfaces a rollback suggestion

The system MUST, after a draft is accepted, compare the next completed eval run for
the skill's linked dataset against the previous baseline via the EXISTING regression gate; when
that gate reports `failed`, the system MUST surface a "roll back to previous version?"
suggestion on the SkillDetail page referencing the pre-acceptance version, and MUST
notify the accepting reviewer. The suggestion MUST be advisory — rollback remains an
explicit human action.

#### Scenario: A live regression after acceptance suggests rollback

- GIVEN skill S's draft was accepted, creating version W over version V
- WHEN the next eval run completes with `regressionGateResult` `failed`
- THEN SkillDetail MUST show a rollback suggestion targeting version V
- AND the accepting reviewer MUST receive a notification
- AND no rollback MUST occur without an explicit rollback request

### Requirement: An accepted version behind the published copy raises an explicit republish signal

The system MUST, when a skill has `githubOwner`/`githubRepo` set and its
`lastAcceptedVersionAt` postdates `publishedAt`, show a "published copy is behind" state as a
badge on both the skills catalog list and SkillDetail, MUST notify the publisher once
per newly-behind transition, and MUST offer a one-click Republish action guarded by the
SAME publish action authorization the skills-marketplace publish requirement defines.
The system MUST NEVER republish automatically. A successful republish restamps
`publishedAt`, clearing the badge.

#### Scenario: Acceptance flips a published skill to behind

- GIVEN a skill published to GitHub (`publishedAt` stamped) with a draft awaiting
  approval
- WHEN the draft is accepted
- THEN the catalog row and SkillDetail MUST show the "published copy is behind" badge
- AND the publisher MUST receive a notification
- AND no GitHub call MUST be made without an explicit Republish

#### Scenario: Republish clears the badge through the authorized path

- GIVEN a behind skill and a caller holding the publish action authorization
- WHEN the caller triggers Republish
- THEN the current active version MUST be pushed to the skill's own provenance repo
- AND `publishedAt` MUST be restamped, clearing the badge

### Requirement: Every draft state transition is audited

The system MUST write an AuditTrail entry (run-audit-log seam, organisation-scoped) for
every `SkillDraft` transition — proposed, discarded (with gate reason and evidence),
awaiting-approval, accepted (with the new version id), rejected (with any
`rejectedLearningRefs`) — and for every rollback and republish, each recording the
acting principal (background job or user), timestamp, and gate evidence, so the full
draft lineage is reconstructable.

#### Scenario: A discard is reconstructable from the audit trail

- GIVEN a draft auto-discarded by the eval gate
- WHEN an auditor reads the skill's audit entries
- THEN an entry MUST show the transition to `discarded`, the acting principal (the
  consolidation job), both pass rates, and the timestamp

### Requirement: The SkillDetail review surface presents diff, provenance, and verdicts with three actions

The SkillDetail page MUST render, for a draft in `awaiting-approval`: a side-by-side
diff of the proposed versus active content, the driving learnings entries, the scan
verdict, and the eval delta (or the explicit "no eval evidence" flag) — plus the three
action-gated decisions (Accept, Edit-then-accept, Reject with bad-learnings marking).
The page MUST also render the version history with per-version diff and rollback, and
the behind-badge with its Republish action. Pass/fail and added/removed indications
MUST NOT be color-only.

#### Scenario: The review card shows everything the decision needs

- GIVEN a skill with a draft in `awaiting-approval`
- WHEN the reviewer opens `/skills/:id`
- THEN the page MUST show the side-by-side diff, the driving learnings entries, the
  scan verdict, and the eval delta or no-evidence flag
- AND Accept, Edit-then-accept, and Reject MUST be available to an authorized reviewer

### Requirement: A seeded pending draft demonstrates the review surface

The system MUST seed, via an idempotent repair step (matched by skill+draft name,
never overwriting admin edits, system context), ONE `SkillDraft` in `awaiting-approval`
on the seeded `tender-summary` skill, with threshold trigger provenance (dated
learnings refs, nil-UUID run ids), a clean scan verdict, `noEvalEvidence: true`, and a
linked pending `Approval` — carrying the required decision-evidence payload (deep
link, scan verdict, `noEvalEvidence` flag, learnings summary) — seeded WITHOUT
dispatching a notification. Placeholders MUST be nil UUIDs / `YOUR_TOKEN_HERE` style
only.

#### Scenario: A fresh install renders a decidable review surface

- GIVEN a fresh Hermiq install has run its repair steps
- WHEN an authorized reviewer opens the `tender-summary` SkillDetail page
- THEN the pending draft's review card MUST render with diff, provenance, and the
  no-eval-evidence flag
- AND deciding it MUST exercise the real accept/reject path

#### Scenario: Re-running the seed never duplicates

- GIVEN the seeded draft exists (in any state, including already decided)
- WHEN the repair step runs again on upgrade
- THEN no second draft or Approval MUST be created

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
