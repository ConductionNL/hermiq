# Test Plan: skill-learnings

## Test Cases

### TC-1: Utilization gating — exercised vs unexercised skills
- **spec_ref**: `openspec/changes/skill-learnings/specs/skill-learnings/spec.md#requirement-the-engine-records-which-skills-were-exercised-in-a-run`
- **type**: api (PHPUnit unit + live)
- **preconditions**: agent with two installed `active` skills, one injected into the run context, one not (e.g. injection path bypassed for it)
- **steps**: execute a run; inspect the run's audit entry; let the capture job process it
- **expected result**: `skillsUsed` contains only the injected skill's uuid; the unexercised skill's `learning-candidates.md` and `levelEvidence.l6` are untouched
- **test command**: `docker run --rm -v $PWD:/app -w /app <nc-image> php vendor/bin/phpunit tests/unit/Service/SkillLearningsCaptureServiceTest.php`

### TC-2: Capture appends grammar-exact, run-attributed candidates
- **spec_ref**: `openspec/changes/skill-learnings/specs/skill-learnings/spec.md#requirement-a-post-run-capture-pass-appends-dated-atomic-candidates-per-exercised-skill`
- **type**: api (PHPUnit unit)
- **preconditions**: mocked `ProviderFactory` returning structured observations + one confirmation of an existing candidate
- **steps**: run `captureForRun()`; parse the resulting `learning-candidates.md`
- **expected result**: new lines match the pinned grammar (date, `{section}`, `runs:` marker); the confirmed candidate gains the run id and a refreshed date without a duplicate line; `body`/`frontmatter`/other files byte-unchanged
- **test command**: phpunit (as TC-1)

### TC-3: Failure isolation — capture can never hurt the run
- **spec_ref**: `openspec/changes/skill-learnings/specs/skill-learnings/spec.md#requirement-capture-is-failure-isolated-from-the-run`
- **type**: regression
- **preconditions**: provider mocked to throw for skill 1 of 2; separately, `IJobList::add()` forced to throw
- **steps**: complete a run; execute the capture job
- **expected result**: run record, outcome, and delivery identical to a control run; failures logged; skill 2 still captured; enqueue failure swallowed
- **test command**: `/test-regression`

### TC-4: Budget gate + budget accounting
- **spec_ref**: `openspec/changes/skill-learnings/specs/skill-learnings/spec.md#requirement-capture-is-budget-gated-and-budget-counted`
- **type**: api
- **preconditions**: org A at its hard cap; org B with headroom
- **steps**: run + capture in both orgs; read `BudgetService` period status for org B before/after
- **expected result**: org A — no LLM call, no write, skip logged; org B — capture runs and its tokens appear in the same period window as run tokens (`runType: 'skill-capture'` entry)
- **test command**: `/test-api`

### TC-5: Idempotency per run ID
- **spec_ref**: `openspec/changes/skill-learnings/specs/skill-learnings/spec.md#requirement-capture-is-idempotent-per-run-id`
- **type**: api (PHPUnit unit)
- **preconditions**: `learning-candidates.md` already contains run R's id
- **steps**: execute the capture job for run R again (simulated re-delivery)
- **expected result**: candidates file byte-identical; zero provider calls; no l6 stamp change
- **test command**: phpunit (as TC-1)

### TC-6: Redaction inheritance + no new write channel
- **spec_ref**: `openspec/changes/skill-learnings/specs/skill-learnings/spec.md#requirement-learnings-writes-inherit-the-agent-memory-redaction-path-and-tool-governance`
- **type**: security
- **persona**: Noor (Municipal CISO / functional admin)
- **preconditions**: run trace containing a recognised credential pattern; an agent with an unrestricted tool allowlist
- **steps**: capture the run; inspect the stored candidate line and the OR AuditTrail; enumerate the tools offered to the agent; force all observations to redact to empty in a second pass
- **expected result**: credential masked in the persisted line; write recorded in the hash-chained AuditTrail via `ObjectService`; no capture/promotion tool offered to any agent; redaction-empty pass writes nothing (no empty lines, no l6 stamp)
- **test command**: `/test-security`

### TC-7: Mechanical promotion — confirmations, eval-fail, expiry
- **spec_ref**: `openspec/changes/skill-learnings/specs/skill-learnings/spec.md#requirement-promotion-is-a-mechanical-two-stage-background-pass`
- **type**: api (PHPUnit unit)
- **preconditions**: candidates file with: a `{domain}` line holding 3 distinct nil-UUID run ids; a 1-run line with `eval-fail:`; a 40-day-old 1-run line; an unparseable line
- **steps**: run the promotion service
- **expected result**: line 1 → Domain Knowledge in `learnings.md` and removed from candidates; line 2 promoted despite 1 run; line 3 dropped; unparseable line left to age out; Consolidated Principles stays empty; zero provider calls; l6 `learningsCount`/`candidateCount`/`lastPromotedAt` match parsed reality
- **test command**: `docker run --rm -v $PWD:/app -w /app <nc-image> php vendor/bin/phpunit tests/unit/Service/SkillLearningsPromotionServiceTest.php`

### TC-8: l6 single-writer — hostile writes and honest L6
- **spec_ref**: `openspec/changes/skill-learnings/specs/skill-maturity/spec.md#requirement-maturitylevel-and-computed-evidence-are-never-client-writable`
- **type**: api
- **preconditions**: skill with promoted learnings (`learningsCount` > 0) and no `lastConsolidatedAt`
- **steps**: client edit claiming `levelEvidence.l6: {learningsCount: 99, lastConsolidatedAt: ...}`; then qualify the skill via the skill-maturity endpoint
- **expected result**: forged `l6` discarded (stored values carried forward); qualify reports L6 failed with a missing-consolidation reason — promotion alone never grants L6
- **test command**: `/test-api`

### TC-9: Export round-trip with learnings aboard
- **spec_ref**: `openspec/changes/skill-learnings/specs/skill-learnings/spec.md#requirement-learnings-files-live-in-the-files-map-and-travel-with-the-export`
- **type**: regression
- **preconditions**: a skill carrying populated `learnings.md` + `learning-candidates.md`
- **steps**: export to an agentskills.io package; re-import; byte-compare both files (and frontmatter/body)
- **expected result**: byte-identical round trip; BOTH files present in the export (no publish-split in this change)
- **test command**: phpunit (as TC-7) + `/test-regression`

### TC-10: Learnings tab UI + empty state
- **spec_ref**: `openspec/changes/skill-learnings/specs/skill-learnings/spec.md#requirement-skilldetail-shows-a-read-only-learnings-surface`
- **type**: functional
- **persona**: Priya (ZZP developer / integrator)
- **preconditions**: fresh install with the seeded `tender-summary` learnings; one skill without learnings files
- **steps**: open `/skills/:id` for both skills; inspect the Learnings surface
- **expected result**: `tender-summary` renders the five-section markdown + candidate count + last activity, with NO edit affordance; the bare skill shows an empty state without error; scorecard still shows L6 not passed
- **test command**: `/test-functional`

### TC-11: Accessibility of the Learnings surface
- **spec_ref**: `openspec/changes/skill-learnings/specs/skill-learnings/spec.md#non-functional-requirements`
- **type**: accessibility
- **persona**: Henk (elderly citizen)
- **steps**: audit the Learnings tab — heading hierarchy of rendered markdown (1.3.1), activity metadata not color-only (1.4.1), tab label accessible name, EN + NL strings
- **expected result**: WCAG 2.2 AA on the touched surface
- **test command**: `/test-accessibility`

### TC-12: Seed idempotency + forced schema import on upgrade
- **spec_ref**: `openspec/changes/skill-learnings/specs/skill-learnings/spec.md#requirement-one-seeded-skill-demonstrates-the-learnings-shape`
- **type**: regression
- **preconditions**: install with a pre-existing 0.16.0 register; `tender-summary` present, once admin-edited
- **steps**: upgrade the app; run repair steps twice; write a skill with `candidateCount`/`lastCaptureAt` set
- **expected result**: existing `agentskill` schema gains the two l6 fields (forced import — both values survive an OR write, not dropped as undeclared); demo learnings added only where absent; second run duplicates nothing and preserves the admin edit
- **test command**: `/test-regression`

## Coverage Summary

| Requirement | Covered by |
|---|---|
| Engine records exercised skills (`skillsUsed`) | TC-1 |
| Post-run capture appends dated atomic candidates | TC-2 |
| Capture failure-isolated from the run | TC-3 |
| Capture budget-gated and budget-counted | TC-4 |
| Capture idempotent per run ID | TC-5 |
| Redaction + governance inherited, no new channel | TC-6 |
| Mechanical two-stage promotion | TC-7 |
| l6 written by learnings subsystem only (delta) | TC-8 |
| Skill schema l6 activity fields (delta) | TC-12 |
| Learnings files travel with the export | TC-9 |
| Read-only Learnings surface | TC-10, TC-11 |
| Seeded demo learnings | TC-10, TC-12 |

Deliberately untested: consolidation, draft versions, approval routing, and the
publish-time export split (`skill-self-improvement`); `levelEvidence.l5` writing
(`skill-evals`); tuning of the service-owned thresholds (3 confirmations / 30 days /
observation length cap — design.md Decision 1).

After implementation: promote TC-5, TC-7, TC-9 to reusable scenarios via `/test-scenario-create`.
