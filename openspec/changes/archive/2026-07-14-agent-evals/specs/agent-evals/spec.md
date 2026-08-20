# agent-evals Specification

**Status**: planned
**Scope**: hermiq
**OpenSpec changes**:
- agent-evals

## Purpose

Gives a Hermiq operator a repeatable, governed way to test an agent against a named set of
prompts before trusting it with real, delivered runs — a dataset of cases (deterministic
assertion or LLM-judge rubric), executed through the agent's real engine path in a
non-delivering mode, scored automatically, and compared against the previous run for the
same dataset+agent as a regression gate. Closes a gap against Langfuse (evaluations +
dataset management), AgentOps (evaluations & benchmarking), OpenAI AgentKit (evals + trace
grading), and IBM watsonx.governance/Monitaur (pre-deployment simulation). See ADR-001
(OpenRegister single write-path) and ADR-004 (audit trail) for the platform invariants this
capability inherits rather than reimplements.

## ADDED Requirements

### Requirement: EvalDataset CRUD as a plain OpenRegister object
The system MUST store an EvalDataset as a plain OpenRegister object (register `hermiq`,
schema `evaldataset`) manageable through the generic object CRUD path, with no bespoke
create/update/delete controller.

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

#### Scenario: Case runs as the agent's owner
- GIVEN an EvalRun triggered for `datasetId` against `agentId`
- WHEN a case's input prompt is executed
- THEN `EvalRunService` calls `ScheduleService::runAgentAsOwner(owner, agentId, prompt)`
- AND the identity impersonated is the SAME owner-resolution `runAgentAsOwner()` already
  performs (including any `Agent.actingUser` override on the engine-enabled path)

### Requirement: Eval runs are non-delivering
The system MUST NOT deliver any eval case's output to Talk, Note-to-self, or a Nextcloud
notification.

#### Scenario: No delivery call for an eval case
- GIVEN an EvalRun executing its cases
- WHEN a case completes (success or failure)
- THEN `EvalRunService` never calls `DeliveryService`
- AND the case's output is recorded only on the EvalRun object's `results` array

### Requirement: Deterministic scoring
The system MUST score a `contains`/`notContains` case by a plain substring check against the
case's actual output, and a `jsonPathEquals` case by parsing the actual output as JSON and
comparing the value at the given path (as a string) to `expectedValue`.

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

#### Scenario: agentVersionId is stored but not resolved
- GIVEN an EvalRun triggered with `agentVersionId="v-123"`
- WHEN the run executes
- THEN `v-123` is stored verbatim on the EvalRun object
- AND no lookup, validation, or behavioural branch occurs based on its value

### Requirement: Run-trigger endpoint is owner-guarded (IDOR)
The system MUST refuse to run an EvalDataset against an agent unless the requesting user
owns both the dataset and the agent, returning 404 (not 403) on any mismatch so a non-owner
cannot confirm either object's existence.

#### Scenario: Non-owner cannot trigger a run
- GIVEN an EvalDataset owned by user A
- WHEN user B calls `POST /api/evals/{datasetId}/run`
- THEN the response is `404 Not Found`

### Requirement: UI surfaces per-case results and pass-rate trend
The system MUST show, for a selected EvalRun, each case's input, expected summary, actual
output, pass/fail, and (for rubric cases) score/rationale, with failing cases visually
distinguished, and MUST show a pass-rate trend across a dataset+agent's run history.

#### Scenario: Failing case shows its actual output
- GIVEN an EvalRun with at least one failing case
- WHEN the operator opens that run's detail view
- THEN the failing case's row shows its actual output and `errorMessage`/judge rationale,
  visually distinguished from passing rows

## Non-Functional Requirements

- **Performance:** an EvalRun executes its cases sequentially (not in parallel) to avoid
  concurrent impersonation/session-swap races in `ScheduleService::runAgentAsOwner()`
  (`IUserSession::setUser()` is not concurrency-safe); a dataset is expected to hold a
  human-reviewable number of cases (tens, not thousands).
- **Accessibility:** the per-case result table and pass-rate trend follow the same WCAG AA
  conventions (NL Design System tokens, no hardcoded colour-only pass/fail signalling — an
  icon or text label accompanies any colour) as every other Hermiq data table.
- **Internationalization:** Dutch and English MUST be supported (ADR-005) — all new UI
  strings ship in both `l10n/en.json` and `l10n/nl.json`.

## Acceptance Criteria

- [ ] An EvalDataset with mixed case types (contains/notContains/jsonPathEquals/rubric) can
  be created, edited, and deleted through the UI.
- [ ] Triggering a run against a real agent executes every case through
  `ScheduleService::runAgentAsOwner()` and never calls `DeliveryService`.
- [ ] A rubric case's judge call is rejected with `ModelPolicyViolationException` when the
  organisation's ModelPolicy excludes the configured provider/model.
- [ ] An engaged kill-switch or exhausted budget hard cap blocks an entire EvalRun before any
  case executes.
- [ ] EvalRun token usage is visible in `BudgetService::statusForScope()`'s current-period
  usage for the same organisation/agent a Schedule's usage already appears under.
- [ ] A second EvalRun for the same dataset+agent with a lower pass-rate than the first is
  recorded with `regressionGateResult=failed` when the drop exceeds the configured threshold.
- [ ] A non-owner's trigger request receives 404.

## Notes

- Depends conceptually on `agent-versioning` (not yet built) for resolving `agentVersionId`
  and for wiring a regression-gate failure to actually block a version "promotion" action —
  both are explicitly out of scope here (see proposal.md).
- Human annotation queues and automated prompt optimisation (both observed in rival tooling)
  are explicitly out of scope — see proposal.md.
