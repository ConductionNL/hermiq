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

@e2e exclude upgrade-path/repair-step concern with no user-visible browser surface; the
forced-import decision logic is unit-covered by InitializeSettingsTest
(testForcesImportWhenRegisterVersionDiffersFromApplied,
testSkipsForceWhenRegisterVersionEqualsApplied,
testUpdatesStoredAppliedVersionOnlyAfterSuccessfulApply) and the live upgrade
check is TC-11 in test-plan.md (deferred to the deployment phase of this pipeline).

#### Scenario: The upgraded schema exposes the three properties

- GIVEN the register `info.version` has been bumped and the app upgraded
- WHEN the repair step runs the forced configuration import
- THEN the imported `Skill` schema MUST expose `maturityLevel`, `targetLevel`, and
  `levelEvidence` as optional properties with the bounds above

@e2e exclude repair-step/schema-import concern with no browser surface; the register
JSON is statically validated (`npm run check:register`) and the live forced-import is
TC-11 in test-plan.md (deployment phase).

#### Scenario: The l6 activity fields survive an OpenRegister write

- GIVEN the upgraded schema declaring `candidateCount` and `lastCaptureAt` inside
  `levelEvidence.l6`
- WHEN the learnings subsystem persists a skill with both fields set
- THEN both values MUST be present on the stored object after the write (not silently
  dropped as undeclared keys)

@e2e exclude schema/write-path concern with no browser surface; the l6 activity
write (candidateCount + lastCaptureAt) is deterministically covered by
SkillLearningsCaptureServiceTest::testCaptureStampsL6ActivityAndPreservesOtherEvidence
and the register JSON declaring the fields is statically validated
(`npm run check:register`).

### Requirement: SkillMaturityService computes L1–L3 mechanically from skill content

`SkillMaturityService` MUST compute levels 1–3 solely from the skill's `frontmatter`,
`body`, and `files`: **L1** passes when the frontmatter parses and yields a non-empty
`name` and `description` and the `body` is non-empty; **L2** passes when the
`description` has trigger quality (starts with verb-ish trigger phrasing AND contains
when-to-use phrasing), the `body` is under 500 lines, and the skill shows progressive
disclosure (a large body is accompanied by `references/` entries in `files` rather than
being one monolith — compact/procedural bodies score better than comprehensive-docs
style); **L3** passes when the `files` map contains at least one `references/*` or
`examples/*` entry. The service MUST record `{passed, checkedAt}` per computed level in
`levelEvidence.l1`–`l3` and MUST set `maturityLevel` to the highest CONTIGUOUS level
passed (level n counts only when levels 1..n−1 also pass).

#### Scenario: A structurally valid but poorly-triggering skill is L1

- GIVEN a skill with valid frontmatter (name + description) and a non-empty body
- AND a description that is a bare noun phrase with no when-to-use phrasing
- WHEN the service qualifies the skill
- THEN `maturityLevel` MUST be 1
- AND the scorecard MUST report L2 failed with a triggering reason

@e2e exclude pure computation rule, deterministically covered by
SkillMaturityServiceTest::testStructurallyValidButPoorlyTriggeringSkillIsL1; the browser
surface of the same rule is exercised via the seeded L1 skill's dot badge in
tests/e2e/skill-maturity.spec.ts.

#### Scenario: A compact well-triggering skill without reference files is L2

- GIVEN a skill whose description starts with a verb trigger and states when to use it
- AND a body under 500 lines with no `references/*` or `examples/*` entries in `files`
- WHEN the service qualifies the skill
- THEN `maturityLevel` MUST be 2
- AND the scorecard MUST report L3 failed for missing references/examples

@e2e tests/e2e/skill-maturity.spec.ts

#### Scenario: A monolithic body fails L2 even with good description

- GIVEN a skill whose description has trigger quality
- AND a body of 500 or more lines with no `references/` entries
- WHEN the service qualifies the skill
- THEN L2 MUST be reported failed with a structure/progressive-disclosure reason
- AND `maturityLevel` MUST be 1

@e2e exclude pure computation rule, deterministically covered by
SkillMaturityServiceTest::testMonolithicBodyFailsL2EvenWithGoodDescription; no distinct
browser behaviour beyond the scorecard rendering already covered in
tests/e2e/skill-maturity.spec.ts.

#### Scenario: Levels are contiguous

- GIVEN a skill that fails L2 but has `references/*` files (L3's check alone would pass)
- WHEN the service qualifies the skill
- THEN `maturityLevel` MUST be 1 (a passed higher check never skips a failed lower level)

@e2e exclude pure computation rule, deterministically covered by
SkillMaturityServiceTest::testLevelsAreContiguous; no distinct browser behaviour.

### Requirement: L4 is human-attested only, behind action authorization

L4 MUST NOT be auto-detected under any circumstance. The system MUST provide
`POST /api/skills/{id}/attest-l4` which requires the caller to hold the
`skill.attest-maturity` action via `ActionAuthService::requireAction()` (ADR-023). On
success it MUST set `levelEvidence.l4` to `{attestedBy: <caller>, attestedAt: <now>,
note}` and recompute the maturity level. A caller without the action MUST receive
`403 Forbidden` with the skill unchanged; a skill outside the caller's visibility MUST
yield `404` (never a 403 that confirms existence).

#### Scenario: An authorized curator attests L4

- GIVEN a skill at `maturityLevel` 3 and a caller granted `skill.attest-maturity`
- WHEN the caller posts to `/api/skills/{id}/attest-l4`
- THEN `levelEvidence.l4.attestedBy` MUST be the caller and `attestedAt` MUST be stamped
- AND the recomputed `maturityLevel` MUST be 4

@e2e exclude the attest mutation is covered by SkillMaturityControllerTest (success
and 403-unchanged paths); the committed Playwright suite asserts the action-gated
Attest surface renders on SkillDetail (tests/e2e/skill-maturity.spec.ts) but does
not perform the mutation, which would alter the seeded tender-summary fixture for
every later run.

#### Scenario: An unauthorized caller cannot attest

- GIVEN a caller whose groups are not mapped to `skill.attest-maturity`
- WHEN the caller posts to `/api/skills/{id}/attest-l4`
- THEN the system MUST respond `403 Forbidden`
- AND the skill's `levelEvidence.l4` and `maturityLevel` MUST remain unchanged

@e2e exclude the e2e fixture authenticates as admin only (admin always passes the
ADR-023 matrix, so a browser 403 cannot be produced without a second, group-mapped
fixture user, which no committed hermiq Playwright suite provisions); the 403-unchanged
posture is covered by
SkillMaturityControllerTest::testAttestWithoutActionIs403AndSkillUnchanged.

### Requirement: L5–L7 are read from evidence written by other subsystems

The service MUST grant L5 only when `levelEvidence.l5` carries `evalDatasetId`,
`passRate`, `baselineDelta`, and `lastValidated`; L6 only when `levelEvidence.l6` shows
learnings activity (`learningsCount` > 0 with `lastConsolidatedAt`); and L7 only when
`levelEvidence.l7` carries executed-chain evidence (`lastExecutedChainRunId` +
`lastExecutedAt` — a declared-but-never-executed chain is structurally L7, not mature
L7). This change MUST NOT write any of these fields — they are populated by the future
`skill-evals`, `skill-learnings`/`skill-self-improvement`, and `skill-orchestration`
changes; absent evidence MUST simply cap the level with an honest scorecard reason.

#### Scenario: Missing eval evidence caps at L4 with a reason

- GIVEN an L4-attested skill whose `levelEvidence.l5` is absent
- WHEN the skill is qualified
- THEN `maturityLevel` MUST be 4
- AND the scorecard MUST report L5 failed with an eval-evidence reason

@e2e exclude evidence-folding rule deterministically covered by
SkillMaturityServiceTest::testMissingEvalEvidenceCapsAtL4; the browser rendering of the
same cap (the seeded tender-summary at L4 with target L5) is asserted in
tests/e2e/skill-maturity.spec.ts.

#### Scenario: Externally-written eval evidence is honoured on the next qualify

- GIVEN an L4-attested skill whose `levelEvidence.l5` was written by another subsystem
  with `evalDatasetId: "00000000-0000-0000-0000-000000000000"`, `passRate: 0.9`,
  `baselineDelta: 0.15`, and a `lastValidated` timestamp
- WHEN the skill is qualified
- THEN `maturityLevel` MUST be 5

@e2e exclude the L5 evidence WRITER does not exist yet (the future skill-evals change),
so no browser flow can produce this state; the read-side folding is deterministically
covered by SkillMaturityServiceTest::testCompleteL5EvidenceGivesLevel5.

### Requirement: The qualify endpoint is owner-guarded and returns a scorecard

The system MUST provide `POST /api/skills/{id}/qualify` which recomputes the skill's
maturity via `SkillMaturityService`, persists `maturityLevel` and the refreshed
`levelEvidence.l1`–`l3`, and returns a scorecard listing, for each of the seven levels,
`passed` and human-readable `reasons` covering structure, triggering, eval evidence,
learnings activity, and orchestration use. The endpoint MUST refuse to qualify a skill
the caller does not own, returning `404` (not `403`) on any mismatch so a non-owner
cannot confirm the skill's existence (the agent-evals IDOR pattern). System-seeded
skills (owner `__system__`) have no human owner: for those objects — and ONLY those —
an instance admin acts as custodian-owner (mirroring the tool-oversight custodianship
rule), otherwise the seeds would ship with a permanently dead Qualify surface.
Qualification MUST be allowed in every lifecycle `state`.

#### Scenario: A non-owner cannot qualify a skill

- GIVEN a `Skill` owned by user A
- WHEN user B posts to `/api/skills/{id}/qualify`
- THEN the response MUST be `404 Not Found`
- AND the skill MUST be unchanged

@e2e exclude no committed hermiq Playwright fixture provisions a second (non-owner)
user; the 404-never-403 IDOR posture is covered by
SkillMaturityControllerTest::testQualifyByNonOwnerIs404NotForbidden (and mirrors the
already-unit-covered agent-evals guard).

#### Scenario: Qualifying persists the level and returns the scorecard

- GIVEN a skill owned by the caller
- WHEN the caller posts to `/api/skills/{id}/qualify`
- THEN the response MUST contain `maturityLevel`, `targetLevel`, and a seven-entry
  scorecard with per-level `passed` + `reasons`
- AND the persisted skill's `maturityLevel` MUST equal the returned value

@e2e exclude the persisted-equals-returned assertion is only observable at the
service/controller layer — deterministically covered by
SkillMaturityServiceTest::testQualifyPersistsLevelAndCarriesEverythingForward and
SkillMaturityControllerTest; the browser rendering of the returned scorecard is
covered by the Qualify row-action test in tests/e2e/skill-maturity.spec.ts.

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

@e2e exclude write-guard behaviour with no honest browser surface (the edit form never
surfaces the computed fields); deterministically covered by
SkillServiceTest::testUpdateSkillIgnoresClientSuppliedComputedMaturity and
SkillMaturityServiceTest::testPreserveComputedFieldsBlocksHandSetValues.

#### Scenario: targetLevel is curator-editable

- GIVEN a stored skill with `targetLevel` 2
- WHEN the owner edits the skill setting `targetLevel: 4`
- THEN the persisted skill's `targetLevel` MUST be 4

@e2e exclude covered by
SkillServiceTest::testUpdateSkillIgnoresClientSuppliedComputedMaturity (asserts the
targetLevel edit persists through the guarded merge path); no distinct browser
behaviour beyond the standard edit form.

#### Scenario: A forged l6 does not survive the write path

- GIVEN a stored skill whose `levelEvidence.l6` records `learningsCount` 0
- WHEN a client submits an edit payload claiming
  `levelEvidence.l6: {learningsCount: 99, lastConsolidatedAt: "2026-01-01T00:00:00Z"}`
- THEN the persisted skill's `levelEvidence.l6` MUST still record `learningsCount` 0
  with no `lastConsolidatedAt`

@e2e exclude write-guard behaviour with no honest browser surface (the edit form
never surfaces the computed fields); deterministically covered by
SkillLearningsPromotionServiceTest::testForgedL6DoesNotSurviveTheWritePathGuard.

### Requirement: The agentskills.io export is byte-identical regardless of maturity

The maturity fields are hermiq metadata only: `SkillSerializer::toPackage()` MUST NOT
emit `maturityLevel`, `targetLevel`, or `levelEvidence`, and the skills-catalog
byte-for-byte round-trip guarantee MUST hold unchanged for a qualified skill.

#### Scenario: Qualifying a skill does not change its exported package

- GIVEN a skill exported to an agentskills.io package before qualification
- WHEN the skill is qualified (and, separately, L4-attested) and exported again
- THEN the two exported packages MUST be byte-identical

@e2e exclude byte-identity is a serializer property no browser assertion can verify
more strictly than the unit regression
SkillSerializerTest::testMaturityFieldsNeverEnterTheExportedPackage.

### Requirement: Maturity is orthogonal to the curation lifecycle

The system MUST keep maturity and the curation lifecycle fully independent: neither
`maturityLevel` nor `state` derives from the other. Lifecycle transitions
(active/stale/archived/quarantined — including Curator runs and marketplace
approve/quarantine) MUST leave all maturity fields unchanged, and qualification MUST
leave `state` unchanged.

#### Scenario: A quarantined skill keeps and can recompute its maturity

- GIVEN an L2 skill that is placed in `quarantined` state
- WHEN the owner qualifies it
- THEN the qualification MUST succeed and `maturityLevel` MUST reflect its content
- AND its `state` MUST remain `quarantined`

@e2e exclude covered by
SkillMaturityServiceTest::testQualifyPersistsLevelAndCarriesEverythingForward (qualifies
a `quarantined` payload and asserts `state` is persisted unchanged); no committed
Playwright fixture seeds a quarantined skill.

#### Scenario: Curation does not touch maturity

- GIVEN an `active` L4 skill past the staleness threshold
- WHEN the Curator job transitions it to `stale`
- THEN its `maturityLevel`, `targetLevel`, and `levelEvidence` MUST be unchanged

@e2e exclude a background-job transition with no browser trigger; the Curator writes
through the same full-payload carry-forward save it always used (unchanged by this
change — SkillCuratorTask is untouched), and the maturity fields are ordinary optional
properties it never reads or writes. Live regression is TC-8 in test-plan.md
(deployment phase).

### Requirement: The catalog UI surfaces maturity dots, a detail scorecard, and a Qualify action

The SkillsCatalog list (`/skills`) MUST render each skill's `maturityLevel` as a
7-dot maturity badge (with a non-color-only accessible representation). A new manifest
`detail` page `SkillDetail` (`/skills/:id`) MUST show the skill's maturity scorecard
(per-level pass/fail + reasons + `targetLevel`). The skill row actions MUST offer a
**Qualify** action that calls the qualify endpoint and shows the returned scorecard.

#### Scenario: The list shows maturity dots

- GIVEN skills at maturity levels 1, 2, and 4 exist in the caller's tenant
- WHEN the user opens the Skills catalog
- THEN each row MUST show a maturity badge with the corresponding number of filled dots
  and an accessible textual level

@e2e tests/e2e/skill-maturity.spec.ts

#### Scenario: Qualifying from the row shows the scorecard

- GIVEN a skill owned by the user
- WHEN the user triggers the Qualify row action
- THEN the qualify endpoint MUST be called and the returned per-level scorecard MUST be
  shown, including failure reasons for the first failing level

@e2e tests/e2e/skill-maturity.spec.ts

#### Scenario: The detail page shows the durable scorecard

- GIVEN a previously-qualified skill
- WHEN the user opens `/skills/:id`
- THEN the page MUST show the stored maturity level, target level, and per-level
  evidence (including attestation and any eval evidence timestamps)

@e2e tests/e2e/skill-maturity.spec.ts

### Requirement: Seeded example skills demonstrate distinct maturity levels

The system MUST seed, via an idempotent repair step (matched by name, never overwriting
admin edits, written through OpenRegister `ObjectService` in system context), three
example skills demonstrating the maturity spread: `meeting-notes-cleanup` (L1 —
structurally valid, poorly triggering), `woo-request-triage` (L2 — municipality WOO
triage, compact procedural body, no reference files), and `tender-summary` (L4 —
consultancy tender summarisation with `references/` + `examples/` files and seeded
`levelEvidence.l4` attestation). Each seed's stored `maturityLevel` MUST equal what
`SkillMaturityService` computes for its content. Placeholders MUST be nil UUIDs /
`YOUR_API_KEY_HERE` style only.

Seed freshness: the creation payload MUST stamp `lastActivityAt` (the Curator's
staleness clock starts at seed time), and a repair re-run MUST refresh
`lastActivityAt` on a `__system__`-owned seed still in state `active` or `stale` —
flipping a `stale` seed back to `active` — so age-based curation never empties the
seed catalog (and with it the skill link-pickers) on a longer-lived instance.
`archived`/`quarantined` seeds and skills not owned by `__system__` MUST NEVER be
touched: curator and human decisions win. All other fields keep the
only-when-absent semantics.

#### Scenario: A fresh install shows the maturity spread

- GIVEN a fresh Hermiq install runs its repair steps
- WHEN the user opens the Skills catalog
- THEN `meeting-notes-cleanup`, `woo-request-triage`, and `tender-summary` MUST exist
  with maturity badges 1, 2, and 4 respectively

@e2e tests/e2e/skill-maturity.spec.ts

#### Scenario: Re-running the seed never duplicates or overwrites

- GIVEN the three seed skills exist (one edited by an admin)
- WHEN the repair step runs again on a later upgrade
- THEN no duplicate objects MUST be created
- AND the admin-edited skill's content MUST be left untouched
- AND a `__system__`-owned seed still `active` or `stale` MUST get a fresh
  `lastActivityAt` (a `stale` seed flips back to `active`), while `archived`/
  `quarantined` seeds and human-created skills MUST NOT be touched

@e2e exclude repair-step idempotency has no browser trigger (repair steps run on
install/upgrade only); the by-name match + never-overwrite behaviour mirrors the
already-shipped SeedSkillCreator pattern and its live regression is TC-11 in
test-plan.md (deployment phase).

### Requirement: SkillDetail surfaces eval evidence and a Run paired eval action

The SkillDetail page (`/skills/:id`) MUST show an eval-evidence card presenting
the skill's `levelEvidence.l5` (pass rate, baseline delta, last validated) —
labelling a `mode: joint` delta as the joint contribution of the linked skill
set, so joint evidence never reads as a per-skill marginal — plus
a pass-rate trend across the paired EvalRuns of datasets whose `skillRefs`
reference this skill, and MUST offer a **Run paired eval** action that lets the
owner pick a linked dataset and an agent and triggers the paired run endpoint
(owner-guarded, 404-never-403, per the agent-evals delta), stating the
mode-dependent cost (≈2× in `joint` mode, (N+1)× in `per-skill` mode, per the
selected agent's `evalBaselineMode`). When no l5 evidence exists the card MUST
show an honest empty state that
points at linking a dataset (never a fabricated or placeholder metric). The
card MUST NOT write any maturity field — evidence arrives only via the paired
run's completion, and the displayed delta MUST NOT be conveyed by color alone.

#### Scenario: The card shows evidence after a completed paired run

- GIVEN a skill whose `levelEvidence.l5` was written by a completed paired run
  with `passRate: 0.9` and `baselineDelta: 0.3`
- WHEN the owner opens `/skills/:id`
- THEN the eval-evidence card MUST show the pass rate, the baseline delta, and
  the last-validated timestamp, and the trend MUST include that run

#### Scenario: No evidence shows an honest empty state

- GIVEN a skill with no `levelEvidence.l5` and no linked dataset
- WHEN the owner opens `/skills/:id`
- THEN the card MUST state that no eval evidence exists and how to obtain it
- AND no metric value MUST be rendered

#### Scenario: Run paired eval triggers the owner-guarded endpoint

- GIVEN a skill linked from one dataset, both owned by the caller
- WHEN the owner uses **Run paired eval**, picks the dataset and an agent they
  own, and confirms past the cost note
- THEN the paired run endpoint MUST be called with `baseline: true`
- AND on completion the card MUST reflect the refreshed l5 evidence

## Notes

Depends on `skills-catalog` (V1) for the `agentskill` schema and `SkillSerializer`, and
on ADR-023 action authorization for the L4 attest action (`skill.attest-maturity`).
Related: ADR-068 (skill maturity + self-learning), ADR-031 (declarative-vs-imperative —
design.md Decision 1 of the change justifies the imperative qualifier service).
