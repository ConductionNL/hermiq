# Test Plan: agent-evals

## Test Cases

### TC-1: EvalDataset CRUD with mixed case types
- **spec_ref**: `openspec/changes/agent-evals/specs/agent-evals/spec.md#requirement-evaldataset-crud-as-a-plain-openregister-object`
- **type**: functional
- **persona**: Priya (ZZP developer/integrator) — builds and iterates on eval datasets
- **preconditions**: authenticated user, at least one Agent exists
- **steps**: open the Eval datasets page; create a dataset with one case of each type (contains, notContains, jsonPathEquals, rubric); edit one case; delete another; save
- **expected result**: the dataset persists via `createObjectStore('evaldataset', ...)` with all case fields intact; edits/deletes reflect on reload
- **test command**: /test-functional

### TC-2: Eval run executes through the real engine path, non-delivering
- **spec_ref**: `openspec/changes/agent-evals/specs/agent-evals/spec.md#requirement-an-evalrun-executes-each-case-through-the-agents-real-engine-path`
- **type**: functional
- **preconditions**: an EvalDataset with 2+ cases, a real Agent, no kill-switch/budget block
- **steps**: trigger `POST /api/evals/{datasetId}/run` with the agent id
- **expected result**: each case's actual output is produced by `ScheduleService::runAgentAsOwner()` (verifiable via the impersonated `runAsUser` matching the owner); no Talk message or notification is sent for any case
- **test command**: /test-functional

### TC-3: Deterministic scoring correctness
- **spec_ref**: `openspec/changes/agent-evals/specs/agent-evals/spec.md#requirement-deterministic-scoring`
- **type**: functional
- **preconditions**: a dataset with a `contains` case and a `jsonPathEquals` case, one of which is set up to fail (malformed JSON output)
- **steps**: run the dataset
- **expected result**: the `contains` case scores correctly against the substring; the malformed-JSON case is `passed=false` with a populated `errorMessage`, and the run still completes
- **test command**: /test-functional

### TC-4: LLM-as-judge respects tenant model policy
- **spec_ref**: `openspec/changes/agent-evals/specs/agent-evals/spec.md#requirement-llm-as-judge-scoring-goes-through-the-existing-providerfactory-chokepoint`
- **type**: functional
- **preconditions**: an organisation with a ModelPolicy excluding the currently configured chat provider/model
- **steps**: run a dataset containing a rubric case against an agent in that organisation
- **expected result**: the rubric case is recorded `passed=false` with the model-policy violation as `errorMessage`; the run does not hard-fail
- **test command**: /test-functional

### TC-5: Kill-switch and budget hard cap block the entire eval run
- **spec_ref**: `openspec/changes/agent-evals/specs/agent-evals/spec.md#requirement-kill-switch-and-budget-hard-cap-gate-an-eval-run-exactly-as-they-gate-a-schedule-tick`
- **type**: functional
- **preconditions**: (a) an organisation with an engaged TenantControl kill-switch; (b) separately, an organisation/agent budget at its hard cap
- **steps**: trigger an eval run in each precondition
- **expected result**: no case executes in either case; the run is recorded `blocked_killswitch` / `blocked_budget` respectively
- **test command**: /test-functional

### TC-6: Eval spend rolls into the shared budget total
- **spec_ref**: `openspec/changes/agent-evals/specs/agent-evals/spec.md#requirement-eval-run-token-usage-counts-toward-the-same-per-orgper-agent-budget-a-scheduled-run-does`
- **type**: regression
- **preconditions**: an organisation/agent budget with a known current-period usage from a prior Schedule run
- **steps**: run an eval dataset against the same agent; re-check `GET /api/budgets/status`
- **expected result**: the reported current-period usage increases by the eval run's token usage, on top of the pre-existing Schedule usage
- **test command**: /test-api

### TC-7: Regression gate flags a pass-rate drop
- **spec_ref**: `openspec/changes/agent-evals/specs/agent-evals/spec.md#requirement-regression-gate-compares-aggregate-pass-rate-against-the-previous-run`
- **type**: regression
- **preconditions**: one completed EvalRun for a dataset+agent with a recorded `passRate`
- **steps**: run the same dataset+agent again, with the agent's prompt/config changed such that fewer cases pass
- **expected result**: the new run's `regressionGateResult=failed`, `previousPassRate` matches the prior run
- **test command**: /test-functional

### TC-8: First run has no regression comparison
- **spec_ref**: `openspec/changes/agent-evals/specs/agent-evals/spec.md#requirement-regression-gate-compares-aggregate-pass-rate-against-the-previous-run`
- **type**: functional
- **preconditions**: a freshly created EvalDataset never run against this agent before
- **steps**: trigger the first run
- **expected result**: `regressionGateResult=not_applicable`
- **test command**: /test-functional

### TC-9: Non-owner cannot trigger or discover a run
- **spec_ref**: `openspec/changes/agent-evals/specs/agent-evals/spec.md#requirement-run-trigger-endpoint-is-owner-guarded-idor`
- **type**: security
- **preconditions**: an EvalDataset owned by user A
- **steps**: as user B, call `POST /api/evals/{datasetId}/run`
- **expected result**: `404 Not Found` (not `403`)
- **test command**: /test-security

### TC-10: Per-case results and pass-rate trend render correctly
- **spec_ref**: `openspec/changes/agent-evals/specs/agent-evals/spec.md#requirement-ui-surfaces-per-case-results-and-pass-rate-trend`
- **type**: accessibility
- **preconditions**: a dataset with at least one failing case and 2+ completed runs
- **steps**: open the Eval runs page for that dataset+agent
- **expected result**: the failing case is distinguished by an icon/text label (not colour alone, WCAG AA); the pass-rate trend across runs is visible; keyboard navigation reaches all interactive elements
- **test command**: /test-accessibility

## Coverage Summary

- REQ "EvalDataset CRUD as a plain OpenRegister object" — covered (TC-1)
- REQ "An EvalRun executes each case through the agent's real engine path" — covered (TC-2)
- REQ "Eval runs are non-delivering" — covered (TC-2)
- REQ "Deterministic scoring" — covered (TC-3)
- REQ "LLM-as-judge scoring goes through the existing ProviderFactory chokepoint" — covered (TC-4)
- REQ "Kill-switch and budget hard-cap gate an eval run exactly as they gate a schedule tick" — covered (TC-5)
- REQ "Eval-run token usage counts toward the same per-org/per-agent budget a scheduled run does" — covered (TC-6)
- REQ "Regression gate compares aggregate pass-rate against the previous run" — covered (TC-7, TC-8)
- REQ "agentVersionId is an inert, forward-compatible field" — not covered (no observable UI/API behaviour differs by value at HEAD; deferred to `agent-versioning`)
- REQ "Run-trigger endpoint is owner-guarded (IDOR)" — covered (TC-9)
- REQ "UI surfaces per-case results and pass-rate trend" — covered (TC-10)

## Out of Scope

- Load/perf testing of large datasets (hundreds+ of cases) — the non-functional requirement
  caps expected dataset size to a human-reviewable count; no perf test case is written for a
  size the feature is not designed to support.
- Automated prompt optimisation and human annotation queues — out of scope for this change
  (see proposal.md), so no test cases target them.
