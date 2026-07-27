# skill-maturity Specification

**Status**: in-progress
**Scope**: hermiq
**OpenSpec changes**:
- `skill-maturity-model`

## Purpose

Ports the fleet's L1–L7 skill maturity model (ADR-068; canonical level definitions in
`.github/docs/claude/writing-skills.md` — referenced, never forked) onto hermiq
`agentskill` objects as a skill-qualification tool: computed maturity metadata, a
mechanical L1–L3 qualifier, action-gated L4 human attestation, a read-only L5–L7
evidence contract for future subsystems, and a qualify/scorecard surface in the catalog.
Maturity is orthogonal to the curation lifecycle (`state`).

## ADDED Requirements

### Requirement: The Skill schema carries maturity metadata as optional inert fields

The `Skill` schema (slug `agentskill`) in `lib/Settings/hermiq_register.json` MUST
declare three OPTIONAL properties: `maturityLevel` (integer, minimum 0, maximum 7,
default 0 — computed, never hand-set), `targetLevel` (integer, minimum 1, maximum 7 —
curator intent, freely editable), and `levelEvidence` (object with per-level sub-objects:
`l1`–`l3` `{passed, checkedAt}`; `l4` `{attestedBy, attestedAt, note}`;
`l5` `{evalDatasetId, passRate, baselineDelta, lastValidated}`;
`l6` `{learningsCount, lastConsolidatedAt, lastPromotedAt}`;
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
forced-import decision logic is unit-covered (InitializeSettings) and the live upgrade
check is TC-11 in test-plan.md (deferred to the deployment phase of this pipeline).

#### Scenario: The upgraded schema exposes the three properties

- GIVEN the register `info.version` has been bumped and the app upgraded
- WHEN the repair step runs the forced configuration import
- THEN the imported `Skill` schema MUST expose `maturityLevel`, `targetLevel`, and
  `levelEvidence` as optional properties with the bounds above

@e2e exclude repair-step/schema-import concern with no browser surface; the register
JSON is statically validated (`npm run check:register`) and the live forced-import is
TC-11 in test-plan.md (deployment phase).

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

@e2e tests/e2e/skill-maturity.spec.ts

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
cannot confirm the skill's existence (the agent-evals IDOR pattern). Qualification MUST
be allowed in every lifecycle `state`.

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

@e2e tests/e2e/skill-maturity.spec.ts

### Requirement: maturityLevel and computed evidence are never client-writable

The skill write paths (create, edit, import) MUST ignore client-supplied
`maturityLevel` and `levelEvidence.l1`–`l4`, carrying the stored values forward
unchanged — only `SkillMaturityService` (via qualify) and the attest endpoint (for
`l4`) write them. `targetLevel` MUST remain freely editable through the normal write
paths.

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
- AND the admin-edited skill MUST be left untouched

@e2e exclude repair-step idempotency has no browser trigger (repair steps run on
install/upgrade only); the by-name match + never-overwrite behaviour mirrors the
already-shipped SeedSkillCreator pattern and its live regression is TC-11 in
test-plan.md (deployment phase).

## Non-Functional Requirements

- **Performance:** qualification is synchronous single-object analysis; a qualify call
  MUST complete in under 2 seconds for a skill with a 500-line body and 20 files (no
  LLM calls, no network I/O).
- **Accessibility:** target WCAG 2.2 AA. Maturity dots MUST NOT be color-only
  (1.4.1) — filled/unfilled shape + textual level via accessible label (1.3.1, 4.1.2);
  scorecard pass/fail rows carry text, not just color. Of the 6 NEW-in-2.2 SCs: 2.5.8
  Target Size applies to the Qualify row action and dots (met via standard NC
  components); 2.4.11, 2.5.7, 3.2.6, 3.3.7, 3.3.8 n/a — no dragging, no auth flows, no
  repeated-entry forms, no new help surface.
- **Internationalization:** Dutch and English MUST be supported (ADR-007); scorecard
  reasons are translated strings; L2 trigger-phrase heuristics include Dutch phrasings.

## Acceptance Criteria

- [ ] `agentskill` schema exposes optional `maturityLevel`/`targetLevel`/`levelEvidence`; existing objects unaffected; forced re-import on upgrade
- [ ] `SkillMaturityService` computes L1–L3 per the mechanical rules with contiguous folding
- [ ] L4 only via action-gated attest endpoint; 403 without the action
- [ ] Qualify endpoint owner-guarded (404, never 403) and returns the seven-level scorecard
- [ ] Client-supplied `maturityLevel`/computed evidence ignored on every write path
- [ ] Exported agentskills.io package byte-identical before/after qualification
- [ ] Catalog dots + SkillDetail scorecard + Qualify row action render (Playwright)
- [ ] Three seed skills at L1/L2/L4, idempotent

## Notes

- Schema.org: an `agentskill` remains a `schema:CreativeWork`/`schema:HowTo`-shaped
  object; maturity fields are hermiq governance metadata with no schema.org mapping.
- OCP surfaces: standard `OCP\AppFramework` controller + `#[NoAdminRequired]` attributes;
  no new OCP interfaces (thin-client, OR `ObjectService` for persistence).
- The 500-line L2 cap is normative; the progressive-disclosure size trigger below it is
  a service-owned constant (design.md Open Questions) and may be tuned without a spec
  change.
- ADR-068 is the architectural decision; level definitions are referenced from
  `.github/docs/claude/writing-skills.md`, never forked. ADR-023 governs the attest
  action; ADR-031 governs the imperative-service justification (design.md Decision 1).
