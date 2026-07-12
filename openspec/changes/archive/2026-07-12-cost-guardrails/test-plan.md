# Test Plan: cost-guardrails

## Test Cases

### TC-1: Hard cap blocks a new scheduled run, records skipped_budget
- **spec_ref**: `openspec/changes/cost-guardrails/specs/multi-tenant-ops/spec.md#requirement-per-scope-budget-guardrails--soft-threshold-and-hard-cap` (Scenario: A hard cap blocks a new run but never an in-flight one)
- **type**: functional
- **persona**: N/A (org-admin operational flow)
- **preconditions**: An agent-scoped `Budget` (tokenLimit=100000) exists at/above its limit for the current period; the agent has an enabled, due schedule
- **steps**: Wait for/trigger the scheduler tick (or call Run now) for the budgeted agent's schedule
- **expected result**: The agent is NOT invoked; the schedule's `lastStatus` reflects a budget-exhausted skip; the run audit entry records `skipped_budget`
- **test command**: `/test-functional`

### TC-2: In-flight run is never aborted when the cap is reached mid-run
- **spec_ref**: `openspec/changes/cost-guardrails/specs/multi-tenant-ops/spec.md#requirement-per-scope-budget-guardrails--soft-threshold-and-hard-cap` (Scenario: A hard cap blocks a new run but never an in-flight one)
- **type**: regression
- **preconditions**: A run is already dispatched (commit-before-run has already persisted `lastStatus=running`) when another run pushes the budget over its cap
- **steps**: Simulate a budget crossing its cap while a prior dispatch is mid-flight; observe the in-flight run to completion
- **expected result**: The in-flight run completes normally and its outcome is recorded; only the NEXT due occurrence is blocked
- **test command**: `/test-regression`

### TC-3: Organisation-scoped budget blocks all of that org's schedules; other orgs unaffected
- **spec_ref**: `openspec/changes/cost-guardrails/specs/multi-tenant-ops/spec.md#requirement-per-scope-budget-guardrails--soft-threshold-and-hard-cap` (Scenario: An organisation-scoped budget blocks all of that organisation's schedules)
- **type**: functional
- **preconditions**: Organisation A has an org-scoped `Budget` at its hard cap and 2+ due schedules; organisation B has its own due schedule and no exhausted budget
- **steps**: Trigger a dispatch tick
- **expected result**: All of organisation A's due schedules are skipped; organisation B's schedule runs normally
- **test command**: `/test-functional`

### TC-4: Budget enforcement applies to a webhook-triggered run identically
- **spec_ref**: `openspec/changes/cost-guardrails/specs/multi-tenant-ops/spec.md#requirement-per-scope-budget-guardrails--soft-threshold-and-hard-cap` (Scenario: Budget enforcement applies identically to a webhook/event-triggered run)
- **type**: api
- **preconditions**: An agent whose organisation-scoped budget is at its hard cap; the `flow-agent-listener` trigger path is reachable
- **steps**: Fire the event/webhook that triggers a run for that agent
- **expected result**: `FlowAgentRunService` blocks the run with the same gate-skip convention as a scheduled tick
- **test command**: `/test-api`

### TC-5: Soft threshold sends exactly one notification per period
- **spec_ref**: `openspec/changes/cost-guardrails/specs/multi-tenant-ops/spec.md#requirement-per-scope-budget-guardrails--soft-threshold-and-hard-cap` (Scenario: A soft threshold is crossed)
- **type**: functional
- **preconditions**: A `Budget` with an 80% soft threshold; current usage below 80%
- **steps**: Drive usage to cross 80% via successive runs, then continue past it with further runs within the same period
- **expected result**: Exactly one Talk/Notification message is delivered to the organisation owner for that period; no duplicate notifications on subsequent runs in the same period; a NEW period re-arms the warning
- **test command**: `/test-functional`

### TC-6: Tenant isolation — a budget-status read never leaks another organisation's budget
- **spec_ref**: `openspec/changes/cost-guardrails/specs/multi-tenant-ops/spec.md#requirement-strict-per-tenant-isolation-across-all-object-types`
- **type**: security
- **preconditions**: Organisations A and B each have a `Budget`
- **steps**: As a user in organisation A, call `GET /api/budgets/status` and `GET /api/budgets`
- **expected result**: Only organisation A's budgets/status are returned; organisation B's data never appears
- **test command**: `/test-security`

### TC-7: Write endpoints are admin/owner-gated
- **spec_ref**: `openspec/changes/cost-guardrails/specs/multi-tenant-ops/spec.md#requirement-per-scope-budget-guardrails--soft-threshold-and-hard-cap`
- **type**: security
- **preconditions**: A plain (non-admin, non-owner) authenticated user in organisation A
- **steps**: Call `POST /api/budgets`, `PUT /api/budgets/{id}`, `DELETE /api/budgets/{id}` for organisation A
- **expected result**: All three requests are refused (403); no `Budget` object is created/changed/removed
- **test command**: `/test-security`

### TC-8: Pre-run estimate renders for an agent with history, and degrades gracefully without it
- **spec_ref**: `openspec/changes/cost-guardrails/specs/run-analytics/spec.md#requirement-pre-run-cost-estimate-derived-from-trailing-per-agent-run-history`
- **type**: functional
- **persona**: Priya (ZZP Developer / Integrator) — evaluates cost before running an agent
- **preconditions**: Agent X has 5+ completed runs with recorded usage; Agent Y has none
- **steps**: Open Run now and the schedule-creation form for Agent X, then for Agent Y
- **expected result**: Agent X shows a labelled trailing-average estimate; Agent Y shows "not enough history" (no zero/fabricated figure); Run now remains available in both cases
- **test command**: `/test-persona-priya`

### TC-9: Estimate is never read by the enforcement gate
- **spec_ref**: `openspec/changes/cost-guardrails/specs/run-analytics/spec.md#requirement-pre-run-cost-estimate-derived-from-trailing-per-agent-run-history` (Scenario: The estimate never influences the hard-cap gate)
- **type**: regression
- **preconditions**: An agent with a very high pre-run estimate but current-period actual usage well under its budget limit
- **steps**: Trigger a due run for that agent
- **expected result**: The run executes (the high estimate does not itself trigger a block); only actual recorded usage vs. limit decides the gate
- **test command**: `/test-regression`

### TC-10: Budget status cards render on TenantOps and AgentDetail, WCAG AA
- **spec_ref**: `openspec/changes/cost-guardrails/specs/multi-tenant-ops/spec.md#requirement-per-scope-budget-guardrails--soft-threshold-and-hard-cap`
- **type**: accessibility
- **preconditions**: An organisation with both an org-scoped and an agent-scoped budget, one of them past its soft threshold
- **steps**: Load TenantOps and AgentDetail as an org admin; run an accessibility scan
- **expected result**: Budget cards render with the existing warn-card contrast/keyboard/ARIA conventions already verified for the quota cards; no new WCAG AA violations
- **test command**: `/test-accessibility`

## Coverage Summary

- REQ (multi-tenant-ops, ADDED) "Per-scope budget guardrails — soft threshold and hard cap": covered by TC-1, TC-2, TC-3, TC-4, TC-5, TC-7, TC-10
- REQ (multi-tenant-ops, MODIFIED) "Strict per-tenant isolation across all object types": covered by TC-6
- REQ (run-analytics, ADDED) "Pre-run cost estimate derived from trailing per-agent run history": covered by TC-8, TC-9

## Out of Scope

- Per-tool/per-step cost breakdown (belongs to `run-trace-observability`) — not tested here.
- EUR-denominated budgets/estimates beyond confirming they report `available:false` when no
  conversion rate is configured — the conversion-rate feature itself is a thin config read
  (`IAppConfig`), covered by TC-8's estimate payload shape check, not a dedicated test case.
- Billing/invoicing accuracy — budgets are an operational guardrail, not a billing system.
