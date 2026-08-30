# Design: skill-self-improvement

## Architecture Overview

The self-improvement loop is a strictly gated pipeline around a new `SkillDraft`
OpenRegister object. The active `Skill` is NEVER edited in place — acceptance is the
only path that writes skill content, and it writes a new VERSION through the normal
skill write path.

```
Triggers ──────────────────────────────────────────────────────────────
 (a) files['learnings.md'] entry count ≥ threshold (config, default 20)
 (b) event: linked eval run regressed vs previous (skill-evals dataset with
     skillRefs containing this skill, regressionGateResult = failed)
 (c) manual: "Propose improvement" on SkillDetail (owner/curator-guarded)
        │
        ▼
SkillConsolidationTask (TimedJob, sibling of SkillCuratorTask)
        │  gate: org kill-switch (ScheduleService::isOrganisationEngaged)
        │  gate: budget hard-cap (BudgetService::isBlocked)
        │  gate: at most ONE open draft per skill
        ▼
SkillConsolidationService::propose()
        │  ONE LLM pass via ProviderFactory::generateText()
        │  (tenant model policy + budget accounting, agent-evals precedent)
        ▼
SkillDraft {status: proposed}  — provenance: driving learnings entries,
        │                        run IDs, baseVersionId, body diff vs active
        ▼
Pre-qualification (before ANY human sees it)
        │  1. ContentScanService over frontmatter+body+ALL files —
        │     learnings.md scanned AS INSTRUCTION CONTENT
        │     verdict dangerous → status: discarded (audit note; no override)
        │  2. Paired A/B eval (skill-evals paired mode): draft vs active,
        │     frozen agent/dataset/cases
        │     draft WORSE than active → status: discarded (audit note;
        │     learnings retained)
        │     no linked EvalDataset → survives, flagged noEvalEvidence
        ▼
SkillDraft {status: awaiting-approval}
        │  Approval object created (human-approval-gate state machine,
        │  sourceType "skill-draft") → Talk/Notification ping (existing
        │  pending-approval notification requirement). Payload REQUIRED at
        │  creation: SkillDetail deep link, scan verdict, eval delta (or
        │  noEvalEvidence flag), one-line driving-learnings summary — an
        │  Approval without them is invalid and never reaches an inbox.
        ▼
The Approval object IS the decision — approving it from ANY surface
(SkillDetail review card OR the generic approval inbox) applies the draft:
   Approve (any surface) ► Approval→approved fires the apply step: draft
                          content written onto the Skill via the normal
                          versioned write path (new AuditTrail version,
                          pinned by subsequent runs); stamp
                          lastAcceptedVersionAt; draft {status: accepted}
   Edit-then-accept ────► SkillDetail ONLY (editing needs the surface):
                          human modifies draft content first (Arize: human
                          curation beats self-generated) — the edit
                          INVALIDATES prior scan+eval and re-runs
                          pre-qualification; the Approval is not approvable
                          from any surface until it passes; then as Approve
                          with editedBeforeAccept recorded
   Deny / Reject ───────► Approval→denied (any surface); draft {status:
                          rejected}; on SkillDetail the curator MAY mark
                          driving learnings entries bad
                          (rejectedLearningRefs) — excluded from the next
                          proposal
        ▼
Post-acceptance: next scheduled eval run's EXISTING regression gate compares
new version vs previous baseline → live regression surfaces a "roll back to
previous version?" suggestion on SkillDetail + notification.

Republish signal: lastAcceptedVersionAt > publishedAt on a skill with
githubOwner/githubRepo → "published copy is behind" badge (catalog +
SkillDetail) + notification to the publisher + one-click Republish through
the SAME publish action authorization skills-marketplace defines. NEVER
auto-republish. Republish ships files['learnings.md'] but STRIPS
files['learning-candidates.md'].
```

Skill versioning mirrors `agent-versioning` exactly: versions ARE the Skill object's OR
AuditTrail `create`/`update` entries; diff is limited to the versioned field set
(`frontmatter`, `body`, `files`); rollback creates a NEW version carrying a previous
version's values (history never mutated); run audit entries pin the exact skill
versions that executed.

### Draft pipeline states

`proposed → awaiting-approval → accepted | rejected`, with `discarded` reachable only
from `proposed` (scan/eval auto-discard). Every transition writes an AuditTrail entry
(run-audit-log seam) recording actor (system job or user), gate evidence, and note.
Terminal states are terminal — a decided draft is never reopened; a new proposal is a
new draft.

## Goals / Non-Goals

**Goals:** close ADR-068 §5 with zero shortcuts — draft → scan → eval gate → human
approval → versioned apply → regression watch → explicit republish; make every
transition auditable; keep learnings consumption read-only (capture/promotion belongs
to `skill-learnings`).

**Non-Goals:** auto-apply (later ADR, only after gates have a track record);
auto-republish; learnings capture/promotion/redaction mechanics; paired-eval internals
(`skill-evals`); L7/orchestration evidence; any `SkillSerializer` fidelity change.

## Decisions

### Decision 1: ADR-031 — imperative services, and why the draft state machine is not x-openregister-lifecycle

Consolidation is LLM work (prompt assembly over learnings + body, `ProviderFactory`
call, response shaping) — legitimately imperative, exactly like the eval judge path.
The draft lifecycle was the real ADR-031 question; two declarative options were
considered and rejected:

- **`x-openregister-lifecycle` on `SkillDraft`**: every meaningful transition is gated
  on imperative external evidence — an LLM response, a `ContentScanService` verdict, a
  paired-eval delta, an ADR-023 action check. Each guard would be a PHP handler
  reference anyway (gate 51), making the declarative block a thin index over imperative
  code — declarative theatre, not declarative logic. The enum + service-owned
  transitions are more honest and fully audited.
- **Reusing the `Approval` object's state machine AS the draft state machine**: rejected
  as a replacement but ADOPTED as a component — the HUMAN decision is modeled ONLY on
  the linked `Approval` object (pending/approved/denied), so there is exactly one
  human-decision surface in hermiq (inbox, notification ping, audit semantics come for
  free, EU AI Act Art. 14 posture unchanged). The draft's own `status` tracks the
  PIPELINE (proposed/awaiting-approval/…), which the Approval machine cannot express
  (it has no scanning/eval/discard states and its objects are decision records, not
  content carriers).

What stays declarative: the whole `SkillDraft` schema, `Skill.lastAcceptedVersionAt`,
and all provenance/evidence fields are plain register JSON; no computed accessors.

### Decision 2: Draft is a separate `SkillDraft` object, not fields on `Skill`

A draft embedded on the Skill (e.g. `Skill.draft`) would (a) make every draft write a
write to the active skill object — the exact thing ADR-068 forbids the pipeline from
normalising, (b) leak draft content into skill reads used by the run loop, and (c) cap
history at one draft. A separate object keeps the active skill byte-stable until
acceptance, gives drafts their own audit trail, and preserves rejected/discarded drafts
as evidence (marketplace no-hard-delete ethos).

### Decision 3: Skill versions are AuditTrail entries (mirror agent-versioning), no SkillVersion schema

`agent-versioning` proved the pattern: version ids = AuditTrail entry UUIDs, diff over
a fixed field set, rollback-as-new-version, run pinning that is never fatal. Reusing it
means no parallel version store to drift and the versioned field set is exactly the
agentskills.io content plane: `frontmatter`, `body`, `files`. Identity, lifecycle,
provenance, maturity, and evidence fields (`name`*, `state`, `source`, `githubOwner`/
`githubRepo`/`publishedAt`, `maturityLevel`/`levelEvidence`, `installedOn`…) are NOT
versioned-config: rollback leaves them at their CURRENT values (mirroring
agent-versioning's identity/visibility/quota rule). Alternative considered — a
`SkillVersion` schema snapshotting content per version — rejected: duplicates what
AuditTrail already records, doubles storage for `files`, and diverges from the fleet
pattern. (*`name` is identity: renames are not rolled back.)

### Decision 4: Auto-discard rule is "strictly worse"

The draft is auto-discarded when its paired-eval pass rate is strictly LOWER than the
active version's on the same frozen dataset; equal survives (a tie plus consolidated
learnings is still an improvement in maintainability, and the human gate remains).
Discard writes an audit note carrying both pass rates and the eval run ids; the
learnings that drove the draft are RETAINED (they may drive a better proposal later).
No linked evals → the draft survives but carries `noEvalEvidence: true`, the review
surface says so verbatim, and acceptance of such a draft can never grant L5 (the
`levelEvidence.l5` contract stays owned by `skill-evals` — nothing here writes it).

### Decision 5: A `dangerous` scan verdict discards — no override path for self-modified content

The marketplace install path lets an admin with `skill.override-scan-verdict` force a
dangerous skill active. Drafts are stricter: `dangerous` → auto-discard, no force. A
self-modified skill is untrusted by definition (ADR-068), the instance produced the
draft itself (nothing is lost by discarding — learnings are retained), and an override
affordance on machine-authored content is exactly the hole an injected instruction
would aim for. `suspicious`/lesser verdicts surface verbatim on the review card.

### Decision 6: The Approval transition applies, from any surface, through the normal skill write path

The apply step hangs on the Approval state machine, not on a dedicated endpoint: the
pending→`approved` transition of the draft's linked Approval — triggered from ANY
surface, the SkillDetail review card or the generic approval inbox alike — writes
`frontmatter`/`body`/`files` onto the Skill via the existing `SkillService` update
path, so every standing invariant holds automatically: computed maturity fields are
carried forward (skill-maturity-model write protection), unsurfaced fields survive the
merge, and the write lands as an ordinary AuditTrail `update` — the new version,
pinned by subsequent runs. `lastAcceptedVersionAt` is stamped in the same transition;
denial from any surface reconciles the draft to `rejected`.

An earlier draft of this decision made the SkillDetail accept endpoint the only
applier (inbox approvals were inert). The user reversed it deliberately: approvers
live in the generic approval inbox, and a second mandatory hop to SkillDetail slowed
the loop without adding evidence. The compensating control is the Approval payload
itself — the deep link to the review surface, scan verdict, eval delta (or
noEvalEvidence flag), and one-line driving-learnings summary are REQUIRED at Approval
creation (an Approval without them is invalid and never reaches an inbox), so an
informed decision is possible without opening SkillDetail; and every pre-qualification
gate (scan, paired eval, auto-discard) has already run BEFORE the Approval exists —
nothing unscanned can be approved from anywhere. Edit-then-accept stays
SkillDetail-only (editing needs the surface); a content edit invalidates prior
scan+eval evidence and re-runs pre-qualification, and the Approval is not approvable
until it passes — closing the edited-but-unscanned-body hole an inbox approval would
otherwise open. `editedBeforeAccept` + editor are recorded because human curation is
evidence (Arize finding), not noise. The SkillDetail accept/reject endpoints remain
(ADR-023 action-gated) but decide by transitioning that SAME Approval object — one
human-decision object, one applier: the approval transition.

### Decision 7: One open draft per skill

All three triggers no-op while a draft for that skill is in `proposed`/
`awaiting-approval`. This caps LLM + eval spend per skill per review cycle, prevents
approval-inbox flooding, and makes the review diff unambiguous (always draft vs current
active). The manual endpoint returns the existing open draft (200 with a pointer)
rather than erroring.

### Decision 8: Bad-learnings marking lives on the draft, not in learnings.md

On Reject, the curator may mark specific driving entries (`rejectedLearningRefs`, keyed
by the dated entry hashes recorded in provenance). Consolidation excludes any entry
marked bad in ANY prior rejected draft of that skill. The alternative — annotating or
deleting entries in `files['learnings.md']` — was rejected: that file is
`skill-learnings`' write territory, and editing skill content from the rejection path
would itself be an ungated skill-content write.

### Decision 9: Behind-badge derives from `Skill.lastAcceptedVersionAt`

Acceptance stamps `lastAcceptedVersionAt` (service-written, optional, inert). The badge
condition is a pure client-side comparison (`githubRepo` set AND `publishedAt <
lastAcceptedVersionAt`) — cheap enough for the catalog list with no extra query.
Republish restamps `publishedAt`, clearing the badge. Alternative — computing from
AuditTrail per row — rejected (N+1 on the catalog).

### Decision 10: Republish is an update-mode push to the skill's OWN provenance repo only

`GitHubTemplatePushService.push()` gains/uses an update mode reachable ONLY when the
target owner/repo equals the skill's stamped `githubOwner`/`githubRepo`. Publishing to
any OTHER existing repo still refuses (the marketplace rule stands). Same fail-closed
broker path, same action authorization as the publish requirement defines, token never
held or logged. Export file selection drops `learning-candidates.md`; `learnings.md`
ships (a skill's vetted experience travels with it — ADR-068 §3), and the serializer's
byte-for-byte fidelity is untouched because the strip is file SELECTION, not
serialization.

## Prompt-injection amplification — threat model (ADR-068)

Why every gate exists; removing any one is a spec violation, not tuning:

| Gate | Threat it closes |
|---|---|
| Draft-only writes (Decisions 2, 6) | An injected "improve yourself" instruction can never mutate the skill agents actually run; the blast radius of a poisoned consolidation is one inert draft object. |
| Scan treats `learnings.md` as instruction content | Learnings originate from run output influenced by untrusted inputs (web, Talk, documents) and ARE injected into future context — the laundering channel ADR-068 names. The scan sees exactly what an agent would obey. |
| `dangerous` verdict discards with no override (Decision 5) | An attacker who steers run output cannot then socially-engineer the force button — it does not exist for machine-authored drafts. |
| Paired eval regression gate (ADR-060: real executed runs) | Behavioural sabotage that reads innocuously still has to beat the active version on frozen cases; scoring worse is auto-discard, silently. |
| Human approval with evidence in the payload + side-by-side diff on the surface | The final trust decision is a human's, on every surface: the Approval payload carries the scan verdict, eval delta, and driving-learnings summary plus a deep link to the full diff, so even an inbox decision is evidence-backed — laundered instructions are surfaced, not summarized away. Edit-then-accept keeps the human the author of record, and an edited draft cannot be approved anywhere until re-scanned and re-evaled. |
| Versioned rollback + regression watch | Post-acceptance detection has a one-click undo; the poisoned version remains in history as evidence, never overwritten. |
| `learning-candidates.md` never exported | Unvetted observations cannot propagate to other instances through publish/republish — containment at the instance boundary. |
| Kill-switch + budget hard-caps on consolidation and its evals | An injected consolidation storm cannot burn tenant spend or outlive an org-level halt; same gates as schedule ticks (agent-evals precedent). |
| Every transition audited (run-audit-log seam) | The whole path is reconstructable for an EU AI Act Art. 12/19 review — who/what moved a draft, on which evidence, when. |

## API Design

All routes in `appinfo/routes.php` (house pattern), NC session + CSRF, `#[NoAdminRequired]`,
owner/visibility guard returning **404 never 403** (agent-evals IDOR pattern) before any
action check.

### `POST /api/skills/{id}/propose-improvement`
Manual trigger (c). Owner-guarded. Kill-switch/budget/open-draft gates apply as in the
job. **Response 200:** the created (or already-open) draft. **Errors:** 404, 429-style
`blocked_killswitch`/`blocked_budget` structured error, 401.

### `GET /api/skills/{id}/drafts`
Owner-guarded list (newest first) for the SkillDetail surface.

### `POST /api/skill-drafts/{id}/content`
Edit-then-accept step (SkillDetail-only surface): replaces the DRAFT's proposed
`frontmatter`/`body`/`files`. Requires the same review action as accept; only valid in
`awaiting-approval`. Invalidates the stored `scanVerdict`/`evalEvidence` and re-runs
pre-qualification; the linked Approval is not approvable (from any surface) until it
passes.

### `POST /api/skill-drafts/{id}/accept` · `POST /api/skill-drafts/{id}/reject`
`ActionAuthService::requireAction('skill.review-draft')` (ADR-023; one action for the
review verb set, mirroring `skill.approve-quarantined`). Both decide by transitioning
the draft's linked Approval — the apply logic lives on the Approval transition, so an
approval from the generic inbox runs the identical path. Accept: Approval→approved →
versioned apply, stamp `lastAcceptedVersionAt`, draft→accepted; response carries the
new version id. Reject body: `{note, rejectedLearningRefs: ["2026-07-20-a1b2…"]}`;
Approval→denied, draft→rejected. 403 leaves draft + skill unchanged.

### `GET /api/skills/{id}/versions` · `GET /api/skills/{id}/versions/diff?from=&to=`
Version history (AuditTrail-backed) and field-level diff over
`frontmatter`/`body`/`files`.

### `POST /api/skills/{id}/rollback`
Body `{versionId}`. Owner-guarded; writes the target version's versioned-config values
as a NEW version through the normal write path; non-versioned fields keep current
values.

### `POST /api/skills/{id}/republish`
Same action authorization as the skills-marketplace publish requirement. Only valid
when `githubOwner`/`githubRepo` are stamped; pushes the current active version
(update mode, own repo only), strips `learning-candidates.md`, restamps `publishedAt`.

## Database Changes

None — thin client. `lib/Settings/hermiq_register.json` changes (register
`info.version` bump — next free minor after the preceding chain changes
(`skill-maturity-model` 0.16.0, then `skill-evals`/`skill-learnings`) — applied as a
FORCED import, openregister#2075):

- **New `SkillDraft` schema** (slug `agentskilldraft`), required: `["skillId"]`, no
  `if`/`then`/`allOf`: `skillId` (string uuid), `baseVersionId` (string — AuditTrail
  version pinned at proposal), `trigger` (enum `threshold`/`regression`/`manual`),
  `status` (enum `proposed`/`awaiting-approval`/`accepted`/`rejected`/`discarded`,
  default `proposed`), `proposedFrontmatter` (string), `proposedBody` (string),
  `proposedFiles` (array of `{name, content}`), `provenance` (object:
  `learningRefs[]` dated-entry keys, `runIds[]`, `triggerEvalRunId`), `scanVerdict`
  (string), `scanReport` (object), `evalEvidence` (object: `datasetId`,
  `draftPassRate`, `activePassRate`, `delta`, `draftEvalRunId`, `activeEvalRunId`),
  `noEvalEvidence` (boolean), `approvalId` (string), `editedBeforeAccept` (boolean),
  `editedBy` (string), `rejectedLearningRefs` (array of strings), `auditNote`
  (string), `decidedBy` (string), `decidedAt` (date-time).
- **`Skill` gains** optional `lastAcceptedVersionAt` (string, `format: date-time`,
  description: stamped by the draft-acceptance service, never hand-set).

## Nextcloud Integration

- Controllers: `SkillDraftController` (propose/list/content/accept/reject),
  `SkillVersionController` (versions/diff/rollback/republish) — new.
- Services: `SkillConsolidationService` (pipeline owner), `SkillVersionService`
  (history/diff/rollback/pin helpers) — new; existing `ProviderFactory`,
  `BudgetService`, `ScheduleService` (kill-switch), `ActionAuthService`,
  `SkillService` (write path), `SkillMarketplaceService`/GitHub push service
  (republish), OR `ContentScanService`, `ObjectService`, AuditTrail.
- Background jobs: `SkillConsolidationTask` (TimedJob, daily, registered in
  `appinfo/info.xml`) — fail-safe construction (one poison job must not brick the
  fleet cron): constructor does no I/O; all gates inside `run()` try/catch per skill.
- Events/Hooks: Approval creation reuses the human-approval-gate pending-notification
  path (`sourceType: "skill-draft"`), with the decision-evidence payload required at
  creation; an Approval state-transition listener owns the apply (approved → versioned
  apply) and reject (denied → draft `rejected`) paths so any-surface decisions run the
  same code; new notification types for behind-badge and rollback suggestion via the
  existing notifier registration.
- Repair step: `SeedSkillDraftExample` (idempotent, system context, matched by
  skill+draft name pair).

## Security Considerations

- **IDOR:** every skill/draft endpoint resolves visibility FIRST and returns 404 (never
  403) on missing/invisible objects; action checks run after (mirroring attest-l4).
- **Authorization (ADR-023):** `skill.review-draft` (accept/edit-content/reject) added
  to the action matrix beside `skill.approve-quarantined`; republish reuses the
  publish action authorization skills-marketplace defines. Manual propose is
  owner-guarded (proposing is cheap and gated downstream anyway).
- **State-machine integrity:** the Approval pending→approved transition is the ONLY
  applier (the endpoints decide by transitioning that same Approval); a client
  hand-setting `status: accepted` through the generic OR object path changes a label,
  not the skill — no content applies outside the approval transition; a draft content
  edit invalidates scan+eval evidence and blocks approval on every surface until
  re-qualification passes; the TimedJob reconciliation applies/rejects any decided
  Approval whose transition event was missed (idempotent) and flags status/Approval
  divergence with an audit note (maturity Decision-3 layering).
- **Fail-closed gates:** scan unavailable → draft stays `proposed` (never advances
  unscanned); broker unavailable → republish 503, no token fallback; eval engine
  unavailable with a linked dataset → draft stays `proposed` (evidence, not bypass).
- **No new content channel:** consolidation reads learnings/body and writes ONLY draft
  objects; redaction of learnings content happened at capture (skill-learnings,
  agent-memory path) — this pipeline adds no unredacted sink.
- **CSRF:** standard NC session + CSRF on all POST routes; no `#[NoCSRFRequired]`.
- **Audit:** one AuditTrail entry per transition (proposed, discarded(+reason),
  awaiting-approval, accepted(+versionId), rejected(+refs), rollback, republish).

## NL Design System

CSS variables only; Cn* components. Side-by-side diff must not be color-only
(added/removed markers + aria labels, WCAG 2.2 AA); badge "published copy is behind"
carries text, not just an icon; all new strings EN + NL (ADR-007). Diff rendering uses
the same widget approach as agent-versioning's diff surface.

## File Structure

```
lib/
  BackgroundJob/SkillConsolidationTask.php       (new)
  Service/SkillConsolidationService.php          (new)
  Service/SkillVersionService.php                (new)
  Controller/SkillDraftController.php            (new)
  Controller/SkillVersionController.php          (new)
  Settings/hermiq_register.json                  (SkillDraft schema; Skill.lastAcceptedVersionAt; version bump)
  Migration/SeedSkillDraftExample.php            (new repair step)
appinfo/
  routes.php  info.xml                           (routes; job + repair step; version bump)
src/
  manifest.json                                  (SkillDetail: draft review + versions widgets; catalog behind-badge column hint)
  widgets/SkillDraftReview.vue                   (new — diff, provenance, verdicts, 3 actions)
  widgets/SkillVersionHistory.vue                (new — history, diff, rollback, republish, behind-badge)
  api/skills.js                                  (draft + version + republish calls)
tests/
  unit/Service/SkillConsolidationServiceTest.php (new)
  unit/Service/SkillVersionServiceTest.php       (new)
  e2e (Playwright): review surface + accept/reject + behind-badge
```

## Seed Data

`SeedSkillDraftExample` (idempotent; system context `_rbac:false,_multitenancy:false`;
never overwrites admin edits) seeds ONE pending draft on the `tender-summary` seed skill
(from skill-maturity-model, a declared dependency): `status: awaiting-approval`,
`trigger: threshold`, provenance listing three plausible dated learnings refs and two
nil-UUID run ids, a small body improvement (one sharpened step + one added exemption
note), `scanVerdict: "clean"`, `noEvalEvidence: true` (so the review card demonstrates
the honest "no eval evidence — cannot grant L5" flag), plus its linked `Approval` in
`pending` — carrying the required decision-evidence payload (SkillDetail deep link,
clean scan verdict, `noEvalEvidence` flag, one-line learnings summary), since an
Approval without it is invalid and approving the seed from the generic inbox must
exercise the real apply path — seeded directly in system context WITHOUT dispatching
a notification (the ping requirement applies to runtime creation, not repair-step
seeding). Placeholders:
nil UUIDs / `YOUR_TOKEN_HERE` style only. Result: a fresh install renders the full
review surface with live Accept/Edit/Reject actions for demo + e2e.

## Risks / Trade-offs

- [LLM consolidation quality varies] → the human gate + edit-then-accept absorb it;
  provenance shows exactly which entries drove the draft; a bad draft costs one
  rejection, not a regression.
- [Paired eval doubles eval spend per draft] → bounded by one-open-draft-per-skill +
  budget hard-caps; no evals linked = no eval spend (and honest flagging).
- [AuditTrail-as-versions couples version fidelity to OR audit retention] → same
  trade-off agent-versioning accepted; run pinning is never fatal by spec.
- [Register version race with parallel chain changes] → version number chosen at
  implementation time (next free minor); forced import is idempotent over the union.
- [Seeded pending Approval could confuse a real inbox] → seeded reviewer is the admin,
  note text marks it as an example, and deciding it exercises the real path.

## Migration Plan

1. Land register patch (SkillDraft + `lastAcceptedVersionAt`) + version bumps; forced
   re-import repair step.
2. Ship job + services + controllers + UI in the same release; the TimedJob no-ops on
   skills with no learnings/evals; nothing changes for existing skills until a trigger
   fires.
3. Seed draft repair step (idempotent).
4. Rollback: revert code; drafts and the two schema additions stay inert (proposal
   Rollback Strategy); no data undo required.

## Open Questions

None blocking. Deferred: the learnings-entry threshold (default ~20) and the TimedJob
interval are service-owned config constants (admin-settings exposure can follow);
the exact register version number is chosen at implementation time after the parallel
chain changes land. If implementation reveals the review surface + versioning to
exceed one comfortable PR, the ADR-032 split option is: `skill-versioning` (versions/
diff/rollback/pinning) as a standalone predecessor change — tasks.md currently fits
the 20-checkbox cap, so the split is recorded here only as the contingency.
