# agent-evals Specification

**Status**: in-progress

**OpenSpec changes:** `agent-evals` — DONE (archived): the agent-scoped eval engine below.
`skill-evals` — in-progress: `EvalDataset` gains `skillRefs` (relation dialect: uuid
items, `$ref: Skill`); `EvalRun` gains a paired with-skill vs without-skill baseline mode
(same cases twice through the real engine path, everything else frozen; detachment is
per-run in-memory only — stored `skillInstalls`/`installedOn` never mutated; both halves
count toward the SAME budgets, ~2× token cost); the minimal run-loop skill-exposure seam
(active skills only); completed paired runs write `levelEvidence.l5` on each linked skill
— the ONLY l5 writer (skill-maturity contract); widened 404-never-403 trigger guard;
dataset skill link/unlink UI + SkillDetail eval-evidence card. See
`openspec/changes/skill-evals/specs/agent-evals/spec.md`.

## Purpose
TBD - created by archiving change agent-evals. Update Purpose after archive.
## Requirements
### Requirement: EvalDataset CRUD as a plain OpenRegister object
The system MUST store an EvalDataset as a plain OpenRegister object (register `hermiq`,
schema `evaldataset`) manageable through the generic object CRUD path, with no bespoke
create/update/delete controller.

@e2e exclude legacy agent-evals scenarios predating this wave (swept into diff scope
by the skill-evals canonical sync only); dataset CRUD is the generic OpenRegister
object write path and the case-shape rules are engine-level, unit-covered by
EvalRunServiceTest / EvalScoringServiceTest — no committed Playwright coverage exists.

#### Scenario: Owner creates a dataset with cases
- GIVEN an authenticated Hermiq user
- WHEN they submit a new EvalDataset with a name and one or more EvalCase entries via
  `EvalDatasetFormModal`
- THEN the object is persisted via `createObjectStore('evaldataset', ...)` against
  `register=hermiq, schema=evaldataset`
- AND it inherits OpenRegister's owner/organisation scoping like every other Hermiq object

#### Scenario: Case expectation types
- GIVEN an EvalDataset being authored
- WHEN a case's `expectationType` is set to `contains`, `notContains`, or `jsonPathEquals`
- THEN the case MUST also carry the matching deterministic field
  (`expectedSubstring` for contains/notContains; `jsonPath` + `expectedValue` for
  jsonPathEquals)
- AND WHEN `expectationType` is `rubric`
- THEN the case MUST carry a non-empty `rubric` string and MAY carry a
  `rubricPassThreshold` (default `0.7`)

### Requirement: An EvalRun executes each case through the agent's real engine path
The system MUST execute every EvalCase in a triggered EvalRun by calling
`ScheduleService::runAgentAsOwner()` — the same impersonation and Engine/ChatService
dual-path a scheduled run or "Run now" action uses — so a case's behaviour reflects a real
run, not a simulated one.

@e2e exclude legacy engine-level scenario predating this wave: the impersonation
seam (`runAgentAsOwner`) is not browser-observable; asserted by EvalRunServiceTest
and ScheduleServiceTest's acting-user tests. No UI surface.

#### Scenario: Case runs as the agent's owner
- GIVEN an EvalRun triggered for `datasetId` against `agentId`
- WHEN a case's input prompt is executed
- THEN `EvalRunService` calls `ScheduleService::runAgentAsOwner(owner, agentId, prompt)`
- AND the identity impersonated is the SAME owner-resolution `runAgentAsOwner()` already
  performs (including any `Agent.actingUser` override on the engine-enabled path)

### Requirement: Eval runs are non-delivering
The system MUST NOT deliver any eval case's output to Talk, Note-to-self, or a Nextcloud
notification.

@e2e exclude legacy engine-level scenario predating this wave: a negative
"DeliveryService is never called" assertion is only observable at the service
layer — unit-covered by EvalRunServiceTest; no browser surface.

#### Scenario: No delivery call for an eval case
- GIVEN an EvalRun executing its cases
- WHEN a case completes (success or failure)
- THEN `EvalRunService` never calls `DeliveryService`
- AND the case's output is recorded only on the EvalRun object's `results` array

### Requirement: Deterministic scoring
The system MUST score a `contains`/`notContains` case by a plain substring check against the
case's actual output, and a `jsonPathEquals` case by parsing the actual output as JSON and
comparing the value at the given path (as a string) to `expectedValue`.

@e2e exclude legacy pure-computation scoring rules predating this wave,
deterministically covered by EvalScoringServiceTest (testContainsSubstring,
testJsonPathEqualsMalformedOutputFailsNotThrows and siblings); no distinct browser
behaviour.

#### Scenario: contains case passes
- GIVEN a case with `expectationType=contains` and `expectedSubstring="approved"`
- WHEN the actual output contains `"approved"` anywhere
- THEN the case's `passed` is `true`

#### Scenario: jsonPathEquals case with malformed output fails, not errors
- GIVEN a case with `expectationType=jsonPathEquals`
- WHEN the actual output is not valid JSON, or the given path does not resolve
- THEN the case's `passed` is `false` and `errorMessage` records why
- AND the EvalRun as a whole still completes (one bad case does not abort the run)

### Requirement: LLM-as-judge scoring goes through the existing ProviderFactory chokepoint
The system MUST score a `rubric` case by calling `ProviderFactory::generateText()` with a
judge prompt built from the case's `rubric`, its input, and its actual output, so tenant
model policy, budgets, and guardrails already enforced at that chokepoint apply to judge
calls exactly as they apply to every other LLM call Hermiq makes.

@e2e exclude legacy engine-level scenarios predating this wave: judge scoring and its
policy enforcement run inside the ProviderFactory chokepoint with no browser surface —
covered by EvalScoringServiceTest (testRubricPassesWhenJudgeScoreMeetsThreshold,
testRubricJudgePolicyViolationFailsNotThrows).

#### Scenario: Judge call is model-policy-enforced
- GIVEN an organisation whose effective ModelPolicy excludes the currently configured
  provider/model
- WHEN a rubric case's judge call is made with that organisation supplied
- THEN `ProviderFactory::createChatDriver()`'s existing enforcement rejects it with
  `ModelPolicyViolationException`, identically to an agent-under-test call being rejected
- AND the case is recorded with `passed=false` and the violation as `errorMessage`, not a
  fatal EvalRun failure

#### Scenario: Judge score determines pass/fail against the case's threshold
- GIVEN a rubric case with `rubricPassThreshold=0.7`
- WHEN the judge returns a numeric score of `0.8`
- THEN the case's `passed` is `true` and `score`/`judgeRationale` are recorded

### Requirement: Kill-switch and budget hard-cap gate an eval run exactly as they gate a schedule tick
The system MUST check the target organisation's kill-switch
(`ScheduleService::isOrganisationEngaged()`) and budget hard cap
(`BudgetService::isBlocked()`) before running ANY case in a triggered EvalRun, and MUST NOT
run any case when either gate blocks.

@e2e exclude legacy engine-level gating scenarios predating this wave: engaging a
kill-switch/budget cap and asserting zero case execution is a service-level concern —
covered by EvalRunServiceTest::testKillSwitchBlocksTheRun and
testBudgetHardCapBlocksTheRun; no committed Playwright fixture can force these states.

#### Scenario: Engaged kill-switch blocks the whole eval run
- GIVEN the target agent's organisation has an engaged TenantControl kill-switch
- WHEN an EvalRun is triggered for that agent
- THEN no case executes and the EvalRun is recorded with `status=blocked_killswitch`

#### Scenario: Budget hard cap blocks the whole eval run
- GIVEN the target organisation/agent's budget has reached its hard cap for the current period
- WHEN an EvalRun is triggered
- THEN no case executes and the EvalRun is recorded with `status=blocked_budget`

### Requirement: Eval-run token usage counts toward the same per-org/per-agent budget a scheduled run does
The system MUST include an EvalRun's recorded token usage in the SAME budget usage
aggregation `BudgetService::isBlocked()`/`checkAndDeliverWarnings()` already compute for
Schedule-driven runs — no separate spend meter.

@e2e exclude legacy engine-level scenario predating this wave: budget aggregation is
a service-layer sum with no browser surface — covered by BudgetServiceTest's
isBlocked/usage tests and the EvalRunService usage-write path.

#### Scenario: Eval spend rolls into the organisation's budget total
- GIVEN a completed EvalRun that recorded prompt/completion token usage in its per-run
  AuditTrail entry
- WHEN `BudgetService::isBlocked()` is next evaluated for that organisation
- THEN the EvalRun's usage is included in `currentUsageTokens()`'s sum, exactly as a
  Schedule's `action='run'` AuditTrail entries already are

### Requirement: Regression gate compares aggregate pass-rate against the previous run
The system MUST, on completing an EvalRun, compare its aggregate pass-rate against the
immediately preceding completed EvalRun for the same `datasetId`+`agentId`, and record a
`regressionGateResult` of `passed`, `failed`, or `not_applicable` (no prior run exists).

@e2e exclude legacy engine-level scenarios predating this wave: gate comparison and
its not_applicable first-run case are service-layer rules — covered by
EvalRunServicePairedTest::testRegressionGateComparesWithHalfAgainstPreviousPlainRun
and EvalRunServiceTest; no committed Playwright fixture stages consecutive runs.

#### Scenario: Pass-rate drop beyond threshold fails the gate
- GIVEN the previous completed EvalRun for this dataset+agent had `passRate=0.90`
- AND the effective regression threshold is `10` (percentage points)
- WHEN the new EvalRun completes with `passRate=0.75`
- THEN `regressionGateResult` is `failed` and `previousPassRate=0.90` is recorded on the run

#### Scenario: First run for a dataset+agent has no comparison
- GIVEN no prior EvalRun exists for this `datasetId`+`agentId` combination
- WHEN the EvalRun completes
- THEN `regressionGateResult` is `not_applicable`

### Requirement: agentVersionId is an inert, forward-compatible field
The system MUST accept an optional `agentVersionId` on an EvalRun trigger request without
validating it against any agent-version registry, since no such registry exists yet
(`agent-versioning` is a separate, not-yet-built change).

@e2e exclude legacy scenario predating this wave: an inert stored-verbatim field with
deliberately NO behavioural branch — nothing browser-observable; covered at the
trigger layer by EvalRunControllerTest.

#### Scenario: agentVersionId is stored but not resolved
- GIVEN an EvalRun triggered with `agentVersionId="v-123"`
- WHEN the run executes
- THEN `v-123` is stored verbatim on the EvalRun object
- AND no lookup, validation, or behavioural branch occurs based on its value

### Requirement: Run-trigger endpoint is owner-guarded (IDOR)
The system MUST refuse to run an EvalDataset against an agent unless the requesting user
owns both the dataset and the agent, returning 404 (not 403) on any mismatch so a non-owner
cannot confirm either object's existence.

@e2e exclude legacy scenario predating this wave: no committed hermiq Playwright
fixture provisions a second (non-owner) user, so the 404-never-403 posture cannot be
produced in a browser — covered by EvalRunControllerTest::testNonOwnedDatasetReturns404
and testNonOwnedAgentReturns404.

#### Scenario: Non-owner cannot trigger a run
- GIVEN an EvalDataset owned by user A
- WHEN user B calls `POST /api/evals/{datasetId}/run`
- THEN the response is `404 Not Found`

### Requirement: UI surfaces per-case results and pass-rate trend
The system MUST show, for a selected EvalRun, each case's input, expected summary, actual
output, pass/fail, and (for rubric cases) score/rationale, with failing cases visually
distinguished, and MUST show a pass-rate trend across a dataset+agent's run history.

@e2e exclude legacy agent-evals UI scenario predating this wave with no committed
Playwright coverage of the run-detail case table; the paired-run rendering this wave
added to the same surface IS browser-covered (`a-paired-run-renders-both-halves` in
tests/e2e/skill-evals.spec.ts).

#### Scenario: Failing case shows its actual output
- GIVEN an EvalRun with at least one failing case
- WHEN the operator opens that run's detail view
- THEN the failing case's row shows its actual output and `errorMessage`/judge rationale,
  visually distinguished from passing rows

### Requirement: An EvalDataset links skills via skillRefs per the relation dialect

The `EvalDataset` schema in `lib/Settings/hermiq_register.json` MUST declare an
OPTIONAL `skillRefs` property: array, default `[]`, items
`{type: "string", format: "uuid", "$ref": "Skill"}` (the relation dialect, the
same shape as `Agent.skillInstalls`). Linking and unlinking MUST work through
the generic object write path — no bespoke link endpoint. `skillRefs` MUST NOT
be added to the schema's `required` array and no `if`/`then`/`allOf` blocks may
be used. The register `info.version` MUST be bumped (0.16.0 → 0.17.0) and the
repair step MUST apply the change as a FORCED import (openregister#2075).

#### Scenario: Linking a skill is a plain object write

- GIVEN an EvalDataset owned by the caller and an active Skill in their tenant
- WHEN the caller updates the dataset setting `skillRefs` to `[<skill uuid>]`
- THEN the dataset MUST persist with that reference through the generic object
  path
- AND unsetting it back to `[]` MUST equally succeed

#### Scenario: Existing datasets stay valid after the forced re-import

- GIVEN an EvalDataset created before this change
- WHEN the bumped register is force-imported on upgrade
- THEN the existing dataset MUST remain valid unchanged, with `skillRefs`
  reading as absent/empty

@e2e exclude upgrade-path/repair-step concern with no browser surface; the forced
register import is unit-covered (InitializeSettings) and the register JSON is
statically validated (`npm run check:register`).

### Requirement: The Agent schema declares evalBaselineMode with a consequence-explaining description

The `Agent` schema in `lib/Settings/hermiq_register.json` MUST declare an
OPTIONAL `evalBaselineMode` property: string, enum `joint` | `per-skill`,
default `joint`. The property MUST carry a human-friendly `title` and a
thorough `description` that explains, on its own, exactly what changing the
value does: `joint` (the default) runs ONE without-skill half detaching all
linked skills together, producing one joint baseline delta at roughly 2× the
token cost of an agent-scoped run; `per-skill` runs one without-skill half PER
linked skill, producing a true per-skill marginal delta at (N+1)× the token
cost per paired run for N linked skills. The user MUST be able to understand
the attribution and cost consequences from the description alone.
`evalBaselineMode` MUST NOT be added to the schema's `required` array, and the
edit rides the SAME register version bump and forced re-import as the eval
schema deltas.

#### Scenario: An agent without the property runs joint baselines

- GIVEN an agent created before this change, with `evalBaselineMode` absent
- WHEN a paired run is triggered on a dataset linked to two skills
- THEN the run MUST behave as `joint` mode (exactly one without-half)

@e2e exclude default-folding rule for an absent property — engine-level, not
browser-observable; deterministically covered by
EvalRunServicePairedTest::testJointPairedRunRecordsBothHalvesAndDelta (agent
without the property).

#### Scenario: The property description surfaces as an info affordance where the value is changed

- GIVEN the agent detail surface's data widget that holds `evalBaselineMode`
- WHEN the user engages the info affordance (info icon/tooltip) on the property
- THEN the register property's description — including the joint-vs-per-skill
  semantics and the (N+1)× cost consequence — MUST be shown to the user in
  place, where they change the value

### Requirement: A paired baseline run executes with and without halves per evalBaselineMode

When triggered with `baseline: true`, the system MUST execute the dataset's
cases through `ScheduleService::runAgentAsOwner()` — the real impersonation +
Engine/ChatService path — with the agent, model, tools, prompts, and owner
identical between halves and ONLY the effective skill set varying. The WITH
half MUST use the agent's installed skills UNION the dataset's linked skills.
The without-half strategy MUST follow the agent's `evalBaselineMode`:

- `joint` (the default, including when the property is absent): ONE WITHOUT
  half using the installed skills MINUS the linked skills — every case
  executes exactly twice; the recorded delta is the JOINT contribution of the
  linked set and every `skillResults` entry carries the same numbers.
- `per-skill`: ONE WITHOUT half PER linked skill, each using the WITH set
  MINUS only that skill — with N linked skills every case executes N+1 times,
  and each `skillResults` entry carries its OWN `passRateWithout` and
  `baselineDelta` (that skill's true marginal contribution) computed from its
  dedicated without-half, whose case results are stored on the entry's
  `baselineResults`; the top-level `baselineResults`/`baselinePassRate` remain
  unset.

All halves MUST be non-delivering and sequential (impersonation is not
concurrency-safe). The run MUST record the WITH half in the existing `results`
array (so `passRate` stays the with-half aggregate), `baselineMode: true`, and
`attributionMode` (`joint` | `per-skill`) as a snapshot of the mode the run
actually used; in `joint` mode the WITHOUT half lands in `baselineResults`
with `baselinePassRate`, and a `skillResults` entry per linked skill records
`{skillId, passRateWith, passRateWithout, baselineDelta}`. Triggering
`baseline: true` on a dataset whose `skillRefs` is empty MUST be rejected with
`400`.

#### Scenario: A paired run records both halves and the per-skill delta

- GIVEN an agent whose `evalBaselineMode` is unset and a dataset with 4 cases
  linked to one skill, run with `baseline: true`
- WHEN all cases execute in both halves and 4/4 pass with the skill while 2/4
  pass without it
- THEN the EvalRun MUST record `passRate: 1.0`, `baselinePassRate: 0.5`,
  `baselineMode: true`, `attributionMode: joint`, and a `skillResults` entry
  with `passRateWith: 1.0`, `passRateWithout: 0.5`, `baselineDelta: 0.5`
- AND no case output is delivered to Talk, Note-to-self, or a notification

#### Scenario: Joint mode yields a joint delta for multiple linked skills

- GIVEN an agent whose `evalBaselineMode` is `joint` and a dataset linked to
  two skills, run with `baseline: true`
- WHEN the paired run completes
- THEN exactly two halves MUST have executed (never one per skill)
- AND both `skillResults` entries MUST carry the same
  `passRateWith`/`passRateWithout`/`baselineDelta` values

@e2e exclude half-counting is engine-level and not observable from the browser —
deterministically covered by
EvalRunServicePairedTest::testJointModeTwoSkillsShareJointNumbers; the browser
rendering of a paired run is separately covered
(`a-paired-run-renders-both-halves` in tests/e2e/skill-evals.spec.ts).

#### Scenario: Per-skill mode runs one without-half per linked skill

- GIVEN an agent whose `evalBaselineMode` is `per-skill` and a dataset with 3
  cases linked to two skills, run with `baseline: true`
- WHEN the paired run completes
- THEN exactly three halves MUST have executed (one WITH plus one WITHOUT per
  skill), each case running 3 times
- AND each `skillResults` entry MUST carry its OWN
  `passRateWithout`/`baselineDelta` computed from its dedicated without-half,
  with that half's case results on the entry's `baselineResults`
- AND the run MUST record `attributionMode: per-skill` with the top-level
  `baselineResults`/`baselinePassRate` unset

@e2e exclude per-skill half-counting and marginal attribution are engine-level —
deterministically covered by
EvalRunServicePairedTest::testPerSkillModeRunsOneWithoutHalfPerSkill; no distinct
browser behaviour beyond the paired-run rendering already covered in
tests/e2e/skill-evals.spec.ts.

#### Scenario: Install state cannot skew the comparison

- GIVEN a linked skill that is NOT installed on the agent
- WHEN a paired run executes
- THEN the WITH half MUST expose that skill (installed ∪ linked) and the
  without-half computed for it MUST detach it, so the measured delta is
  identical to that of an installed linked skill — a skill can be qualified
  BEFORE it is ever installed on the agent

@e2e exclude the installed-vs-linked union/detach arithmetic is engine-level with
no browser surface — covered by EvalRunServicePairedTest's paired-half set
construction assertions (with-half = installed ∪ linked, per-skill detach).

#### Scenario: Baseline mode requires linked skills

- GIVEN a dataset whose `skillRefs` is empty
- WHEN the owner triggers a run with `baseline: true`
- THEN the response MUST be `400` and no case executes

### Requirement: The engine run loop exposes the effective skill set to a run

Engine context assembly MUST resolve a run's effective skill set — a per-run
override when supplied, otherwise the agent's stored `skillInstalls` — and MUST
inject each resolved skill's content (frontmatter name/description + `body`)
into the run context, exposing ONLY skills in `active` state (quarantined,
stale, and archived skills are never exposed, preserving the marketplace
approval gate). Every non-eval caller passes no override, so a plain run reads
the stored installs — the run-loop consumption the skills-catalog spec reserved.

#### Scenario: A content-bearing skill deterministically changes output

- GIVEN a skill whose body instructs the agent to always include a fixed marker
  token in its answer, linked to a dataset with a `contains` case expecting
  that marker
- WHEN a paired run executes
- THEN the WITH-half case MUST pass and the WITHOUT-half case MUST fail
- AND `baselineDelta` MUST be positive (the seam demonstrably feeds the engine)

#### Scenario: A quarantined skill is never exposed

- GIVEN a `quarantined` skill referenced by a dataset's `skillRefs`
- WHEN a paired run's WITH half executes
- THEN that skill's content MUST NOT be injected into the run context

@e2e exclude context-assembly exposure rule with no browser surface —
deterministically covered by
ContextAssemblerTest::testNonActiveAndMissingSkillsAreNeverExposed; no committed
Playwright fixture seeds a quarantined skill.

### Requirement: Baseline detachment is per-run and in-memory only

The WITHOUT half MUST be produced by a per-run, in-memory effective-skill-set
override threaded down the engine path. No paired-run code path may write
`Agent.skillInstalls` or `Skill.installedOn` — the system MUST NOT implement
detachment by uninstalling and re-installing skills. A paired run that crashes
at any point (including between halves) MUST leave the agent's stored
`skillInstalls` and every skill's `installedOn` byte-identical to their pre-run
values.

@e2e exclude stored-state invariants across paired halves (including a forced
mid-run crash) cannot be produced or asserted from a browser — deterministically
covered by EvalRunServicePairedTest::testCrashBetweenHalvesNeverStripsTheAgentAndWritesNoEvidence
and the paired-run tests asserting `skillInstalls`/`installedOn` are untouched.

#### Scenario: Stored installs survive a completed paired run

- GIVEN an agent with two installed skills, one of which is dataset-linked
- WHEN a paired run completes
- THEN the agent's `skillInstalls` and both skills' `installedOn` MUST be
  unchanged

#### Scenario: A crash between halves never strips the agent

- GIVEN a paired run whose WITHOUT half is forced to fail with an
  infrastructure error after the WITH half completed
- WHEN the run terminates with `status=failed`
- THEN the agent's stored `skillInstalls` MUST be unchanged
- AND no `levelEvidence.l5` is written

### Requirement: Every half of a paired run counts toward the same budgets and gates

A paired run MUST check the organisation kill-switch
(`ScheduleService::isOrganisationEngaged()`) and budget hard cap
(`BudgetService::isBlocked()`) ONCE before any case of any half executes,
and MUST NOT execute any case when either gate blocks (`blocked_killswitch` /
`blocked_budget`, as agent-evals). Token usage from EVERY half — two in
`joint` mode, N+1 in `per-skill` mode, including judge calls — MUST aggregate
into the run's single per-run AuditTrail usage entry so
`BudgetService::isBlocked()` sums it into the SAME per-org/per-agent budget a
scheduled run uses — no separate spend meter. The trigger surfaces MUST state
the mode-dependent cost: approximately 2× an agent-scoped run in `joint` mode
and (N+1)× in `per-skill` mode.

@e2e exclude gate-blocking and multi-half token aggregation are engine/budget-layer
behaviour with no browser surface — covered by
EvalRunServicePairedTest::testGateBlockedPairedRunExecutesNeitherHalf and its
usage-aggregation assertions; the cost note ON the trigger surface is browser-covered
via the baseline-toggle coverage in tests/e2e/skill-evals.spec.ts.

#### Scenario: A blocked paired run executes neither half

- GIVEN the target organisation's budget has reached its hard cap
- WHEN a paired run is triggered
- THEN no case of either half executes and the run records `blocked_budget`

#### Scenario: Both halves' tokens land in one budget sum

- GIVEN a completed paired run whose WITH half used 1000 tokens and WITHOUT
  half used 900
- WHEN `BudgetService::isBlocked()` next evaluates that organisation
- THEN the full 1900 tokens MUST be included in the usage sum

#### Scenario: Per-skill halves all land in one budget sum

- GIVEN a completed `per-skill` paired run over two linked skills whose three
  halves used 1000, 900, and 800 tokens
- WHEN `BudgetService::isBlocked()` next evaluates that organisation
- THEN the full 2700 tokens MUST be included in the usage sum

### Requirement: A completed paired run is the only writer of l5 evidence

The system MUST, on a paired run reaching `status=completed` (every case of
ALL halves executed), write on EACH linked skill — in BOTH attribution modes —
`levelEvidence.l5 = {evalDatasetId, passRate, baselineDelta, lastValidated,
mode}` — `passRate`/`baselineDelta` from that skill's `skillResults` entry,
`lastValidated` the run's `endedAt`, `mode` the run's `attributionMode` so the
evidence is honest about attribution: `mode: joint` marks the delta as the
joint contribution of the linked set, `mode: per-skill` marks it as that
skill's true marginal — using the write contract defined by
`skill-maturity-model`: the write reads the current skill object, patches ONLY
`levelEvidence.l5`, and carries every other field forward (never touching
`body`, `frontmatter`, `files`, `state`, `installedOn`, or `maturityLevel`).
This path MUST be the only l5 writer in the codebase; client-supplied l5 on the
skill write paths remains silently preserved-over. Runs ending `failed`,
`blocked_killswitch`, or `blocked_budget` MUST write no evidence.
`maturityLevel` is NOT recomputed by this write — the level updates on the next
qualify.

@e2e exclude the l5 write-back contract (patch-only-l5, carry-everything-forward,
no write on failed/blocked runs) is a service-layer invariant — deterministically
covered by EvalRunServicePairedTest::testL5WriteBackPatchesOnlyL5AndCarriesEverythingForward
and testCrashBetweenHalvesNeverStripsTheAgentAndWritesNoEvidence; the resulting
evidence RENDERING is browser-covered in tests/e2e/skill-evals.spec.ts.

#### Scenario: Completing a paired run stamps l5 on the linked skill

- GIVEN a `joint`-mode paired run for a dataset linked to one skill completes
  with `passRate: 0.9` and `baselineDelta: 0.3`
- WHEN the run persists
- THEN the linked skill's `levelEvidence.l5` MUST read
  `{evalDatasetId: <dataset uuid>, passRate: 0.9, baselineDelta: 0.3,
  lastValidated: <run endedAt>, mode: joint}`
- AND the skill's `body`, `frontmatter`, `files`, `state`, and `maturityLevel`
  MUST be unchanged

#### Scenario: A gate-blocked run writes no evidence

- GIVEN a paired run recorded `blocked_killswitch`
- WHEN the run persists
- THEN every linked skill's `levelEvidence.l5` MUST be unchanged

### Requirement: The paired trigger owner guard covers dataset, agent, and every linked skill

When `baseline: true`, the run trigger MUST require the caller to own the
dataset, the agent, AND every skill referenced by `skillRefs` (the run writes
evidence onto those skills). Any missing, invisible, or non-owned object MUST
yield `404` (never `403`) so a non-owner cannot confirm any of the three
objects' existence — the agent-evals IDOR pattern unchanged.

#### Scenario: A non-owner of a linked skill cannot trigger a paired run

- GIVEN a dataset and agent owned by user A whose `skillRefs` references a
  skill owned by user B
- WHEN user A triggers a run with `baseline: true`
- THEN the response MUST be `404 Not Found` and no case executes

@e2e exclude no committed hermiq Playwright fixture provisions a second user, so
the cross-owner 404 cannot be produced in a browser — covered by
EvalRunControllerTest::testBaselineWithNonOwnedLinkedSkillReturns404 and
testBaselineWithMissingLinkedSkillReturns404.

### Requirement: The regression gate applies to a paired run's with-half pass rate

A paired run's `passRate` (the WITH half) MUST feed the existing regression
gate unchanged: on completion it is compared against the immediately preceding
completed EvalRun for the same `datasetId`+`agentId` (paired or not), recording
`regressionGateResult` and `previousPassRate` exactly as agent-evals defines.

#### Scenario: A paired run regression-compares against a previous plain run

- GIVEN the previous completed run for this dataset+agent had `passRate: 0.90`
  and the effective threshold is 10 percentage points
- WHEN a paired run completes with a WITH-half `passRate: 0.75`
- THEN `regressionGateResult` MUST be `failed` with `previousPassRate: 0.90`

@e2e exclude staging two consecutive completed runs with controlled pass rates is
not feasible in the committed Playwright suite — deterministically covered by
EvalRunServicePairedTest::testRegressionGateComparesWithHalfAgainstPreviousPlainRun.

### Requirement: The EvalDataset detail surface manages skill links and paired runs

The EvalDatasetDetail page MUST offer link/unlink of skills (a picker over the
caller's visible active skills, writing `skillRefs` through the generic object
path) and the run panel MUST offer a baseline-mode toggle that is enabled only
when `skillRefs` is non-empty and carries the mode-dependent cost note (≈2× in
`joint` mode, (N+1)× in `per-skill` mode, per the selected agent's
`evalBaselineMode`). A paired run's
detail rendering MUST show with vs without results side by side and each linked
skill's `baselineDelta`, with the delta not conveyed by color alone.

#### Scenario: Linking a skill from the dataset detail page

- GIVEN an EvalDataset with no linked skills
- WHEN the user links a skill via the skill panel and then unlinks it
- THEN `skillRefs` MUST gain and then lose that skill's uuid through the
  generic object write path

#### Scenario: A paired run renders both halves

- GIVEN a completed paired run
- WHEN the operator opens it on the dataset detail page
- THEN with and without pass rates and the per-skill delta MUST be visible,
  with failing cases distinguishable in both halves
