# Test Plan: skill-maturity-model

## Test Cases

### TC-1: L1–L3 mechanical computation and contiguous fold
- **spec_ref**: `openspec/changes/skill-maturity-model/specs/skill-maturity/spec.md#requirement-skillmaturityservice-computes-l1l3-mechanically-from-skill-content`
- **type**: api (PHPUnit unit)
- **preconditions**: fixture skills — valid-but-untriggered (L1), compact-triggered no-references (L2), triggered + `references/` (L3), 500-line monolith, L2-failing skill WITH `references/`
- **steps**: run `SkillMaturityService::qualify()` on each fixture
- **expected result**: levels 1/2/3/1/1 respectively; scorecard reasons name the first failing check; monolith fails L2 on progressive disclosure; contiguity never skips a failed lower level
- **test command**: `docker run --rm -v $PWD:/app -w /app <nc-image> php vendor/bin/phpunit tests/unit/Service/SkillMaturityServiceTest.php`

### TC-2: L4 attestation is action-gated and never auto-detected
- **spec_ref**: `openspec/changes/skill-maturity-model/specs/skill-maturity/spec.md#requirement-l4-is-human-attested-only-behind-action-authorization`
- **type**: security
- **persona**: Noor (Municipal CISO / functional admin)
- **preconditions**: an L3 skill; user without `skill.attest-maturity`; curator with the action mapped
- **steps**: unauthorized POST `/api/skills/{id}/attest-l4`; then authorized POST; then qualify a skill with perfect content but no attestation
- **expected result**: 403 with skill unchanged; authorized call stamps `l4.attestedBy`/`attestedAt` and level becomes 4; unattested skill never exceeds 3
- **test command**: `/test-security`

### TC-3: L5–L7 read-only evidence folding
- **spec_ref**: `openspec/changes/skill-maturity-model/specs/skill-maturity/spec.md#requirement-l5l7-are-read-from-evidence-written-by-other-subsystems`
- **type**: api (PHPUnit unit)
- **preconditions**: an L4-attested skill; one variant with complete `levelEvidence.l5` (nil-UUID dataset id, passRate 0.9, baselineDelta 0.15, lastValidated), one without
- **steps**: qualify both
- **expected result**: 5 vs 4; the capped skill's scorecard says "no eval evidence"; service performed no write to `l5`–`l7`
- **test command**: phpunit (as TC-1)

### TC-4: Qualify endpoint IDOR guard
- **spec_ref**: `openspec/changes/skill-maturity-model/specs/skill-maturity/spec.md#requirement-the-qualify-endpoint-is-owner-guarded-and-returns-a-scorecard`
- **type**: security
- **preconditions**: skill owned by user A; authenticated user B
- **steps**: user B POSTs `/api/skills/{id}/qualify`; also POST a random nil-UUID id
- **expected result**: 404 (never 403) in both cases — existence not confirmable; skill unchanged
- **test command**: `/test-security`

### TC-5: Qualify persists and returns the scorecard
- **spec_ref**: `openspec/changes/skill-maturity-model/specs/skill-maturity/spec.md#requirement-the-qualify-endpoint-is-owner-guarded-and-returns-a-scorecard`
- **type**: api
- **preconditions**: owner session; the `woo-request-triage` seed skill
- **steps**: POST `/api/skills/{id}/qualify`; GET the object back
- **expected result**: 200 with `maturityLevel: 2`, seven scorecard entries with reasons; persisted object matches the response; works on a `quarantined` skill too, `state` untouched
- **test command**: `/test-api`

### TC-6: Computed fields survive hostile writes
- **spec_ref**: `openspec/changes/skill-maturity-model/specs/skill-maturity/spec.md#requirement-maturitylevel-and-computed-evidence-are-never-client-writable`
- **type**: api
- **preconditions**: stored skill at level 2
- **steps**: edit via the skill update path claiming `maturityLevel: 7` and a forged `levelEvidence.l4`; then edit `targetLevel: 4`
- **expected result**: level stays 2 and `l4` stays empty; `targetLevel` update persists
- **test command**: `/test-api`

### TC-7: Serializer round-trip regression
- **spec_ref**: `openspec/changes/skill-maturity-model/specs/skill-maturity/spec.md#requirement-the-agentskillsio-export-is-byte-identical-regardless-of-maturity`
- **type**: regression
- **preconditions**: a skill exported to a package string before any maturity action
- **steps**: qualify + attest the skill; export again; byte-compare
- **expected result**: identical packages; no maturity field appears in the package
- **test command**: phpunit (as TC-1) + `/test-regression`

### TC-8: Maturity ⊥ lifecycle
- **spec_ref**: `openspec/changes/skill-maturity-model/specs/skill-maturity/spec.md#requirement-maturity-is-orthogonal-to-the-curation-lifecycle`
- **type**: regression
- **preconditions**: an `active` L4 skill past the staleness threshold; a `quarantined` L2 skill
- **steps**: run the Curator job; qualify the quarantined skill
- **expected result**: Curator transition leaves all maturity fields unchanged; qualify succeeds without touching `state`
- **test command**: `/test-regression`

### TC-9: Catalog dots, Qualify action, detail scorecard
- **spec_ref**: `openspec/changes/skill-maturity-model/specs/skill-maturity/spec.md#requirement-the-catalog-ui-surfaces-maturity-dots-a-detail-scorecard-and-a-qualify-action`
- **type**: functional
- **persona**: Priya (ZZP developer / integrator)
- **preconditions**: fresh install with the three seed skills
- **steps**: open `/skills`; trigger Qualify on `woo-request-triage`; open `/skills/:id` for `tender-summary`
- **expected result**: rows show 1/2/4 filled dots with textual level; scorecard modal shows L3 failing with a references reason; detail page shows the durable scorecard incl. the seeded L4 attestation
- **test command**: `/test-functional`

### TC-10: Accessibility of dots + scorecard
- **spec_ref**: `openspec/changes/skill-maturity-model/specs/skill-maturity/spec.md#non-functional-requirements`
- **type**: accessibility
- **persona**: Henk (elderly citizen)
- **steps**: audit `/skills` and `/skills/:id` — dots not color-only, accessible names on badge + Qualify action, target size 2.5.8, scorecard rows readable by screen reader
- **expected result**: WCAG 2.2 AA on the touched surfaces
- **test command**: `/test-accessibility`

### TC-11: Seeds + forced upgrade import
- **spec_ref**: `openspec/changes/skill-maturity-model/specs/skill-maturity/spec.md#requirement-seeded-example-skills-demonstrate-distinct-maturity-levels`
- **type**: regression
- **preconditions**: install with a pre-existing 0.15.1 register and one pre-existing skill
- **steps**: upgrade the app; re-run repair steps twice
- **expected result**: existing `agentskill` schema actually gains the three fields (forced import); pre-existing skill valid with level absent/0; exactly one of each seed skill at 1/2/4; second run duplicates nothing and preserves an admin edit
- **test command**: `/test-regression`

## Coverage Summary

| Requirement | Covered by |
|---|---|
| Skill schema carries maturity metadata | TC-11 (+ TC-6) |
| SkillMaturityService computes L1–L3 | TC-1 |
| L4 human-attested, action-gated | TC-2 |
| L5–L7 read from external evidence | TC-3 |
| Qualify endpoint owner-guarded + scorecard | TC-4, TC-5 |
| Computed fields never client-writable | TC-6 |
| Export byte-identical | TC-7 |
| Maturity orthogonal to lifecycle | TC-8 |
| Catalog UI (dots/scorecard/Qualify) | TC-9, TC-10 |
| Seeded example skills | TC-9, TC-11 |

Deliberately untested: L5–L7 evidence WRITING (owned by the future `skill-evals` /
`skill-learnings` / `skill-orchestration` changes); tuning of the service-owned
progressive-disclosure threshold (design.md Open Questions).

After implementation: promote TC-5, TC-7, TC-9 to reusable scenarios via `/test-scenario-create`.
