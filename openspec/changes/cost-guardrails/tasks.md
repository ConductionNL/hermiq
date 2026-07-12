# Tasks: cost-guardrails

## Implementation Tasks

### Task 1: Add the `Budget` OpenRegister schema
- **spec_ref**: `openspec/changes/cost-guardrails/specs/multi-tenant-ops/spec.md#requirement-per-scope-budget-guardrails--soft-threshold-and-hard-cap`
- **files**: `lib/Settings/hermiq_register.json`
- **acceptance_criteria**:
  - GIVEN the app is installed/upgraded WHEN `InitializeSettings` runs THEN OpenRegister has a `budget` schema (slug `budget`) with `scope`, `agentId`, `period`, `tokenLimit`, `eurLimit`, `softThresholdPercent`, `enabled`, `warnedPeriodKey`, `lastHardBlockAt` per design.md
  - GIVEN `scope=agent` WHEN a `Budget` is created without `agentId` THEN OpenRegister's schema validation rejects it (required-when semantics enforced service-side, per design.md's cross-field note)
- [ ] Implement
- [ ] Test

### Task 2: `BudgetService` — status, hard-cap check, soft-threshold warn, estimate
- **spec_ref**: `openspec/changes/cost-guardrails/specs/multi-tenant-ops/spec.md#requirement-per-scope-budget-guardrails--soft-threshold-and-hard-cap`
- **files**: `lib/Service/BudgetService.php`, `tests/Unit/Service/BudgetServiceTest.php`
- **acceptance_criteria**:
  - GIVEN a `Budget` at or above its `tokenLimit` for the current period WHEN `isBlocked(organisation, agentId)` is called THEN it returns `true`, computed from `action='run'` AuditTrail entries windowed to the budget's period (never a stored counter)
  - GIVEN a `Budget` whose usage crosses `softThresholdPercent` for the first time in a period WHEN `recordWarningIfDue()` runs THEN it returns the recipient (the organisation owner) exactly once per period (`warnedPeriodKey` persisted) and not again until the next period
  - GIVEN an agent with N prior runs WHEN `estimateNextRun(agentId)` is called THEN it returns the trailing-average prompt/completion/total tokens from `AnalyticsService::computeAnalytics(agentId)`, with `available:false` (no fabricated zero) when N=0
  - GIVEN the OR read for engaged budgets fails WHEN `isBlocked()` is evaluated THEN it fails open (logs, returns `false`) exactly as `ScheduleService::loadEngagedOrganisations()` does
- [ ] Implement
- [ ] Test

### Task 3: Wire the budget gate into the dispatch path + soft-threshold delivery
- **spec_ref**: `openspec/changes/cost-guardrails/specs/multi-tenant-ops/spec.md#requirement-per-scope-budget-guardrails--soft-threshold-and-hard-cap`
- **files**: `lib/Service/ScheduleService.php`, `lib/Service/FlowAgentRunService.php`, `lib/Service/DeliveryService.php`, `tests/Unit/Service/ScheduleServiceTest.php`, `tests/Unit/Service/FlowAgentRunServiceTest.php`
- **acceptance_criteria**:
  - GIVEN a schedule whose organisation/agent `Budget` is at its hard cap WHEN `ScheduleService::dispatch()` runs THEN it records `skipped_budget` (mirroring `recordGateSkip()`'s `skipped_killswitch` shape) and does NOT invoke the agent, and does NOT create a pending Approval
  - GIVEN the SAME budget-exhausted agent WHEN a webhook/event fires a run via `FlowAgentRunService` THEN the identical hard-cap block applies before the kill-switch's sibling GATE 1 approval check would even run
  - GIVEN a run already executing when the hard cap is reached WHEN the run completes THEN it is NOT aborted — the cap only ever prevents a NEW dispatch
  - GIVEN a budget crosses its soft threshold WHEN the gate check runs THEN `DeliveryService::deliverBudgetWarning()` sends one Talk/Notification message to the organisation owner (same dialect as `deliverApprovalRequestForFlowRun()`)
- [ ] Implement
- [ ] Test

### Task 4: `BudgetController` + routes — CRUD, status, estimate
- **spec_ref**: `openspec/changes/cost-guardrails/specs/run-analytics/spec.md#requirement-pre-run-cost-estimate-derived-from-trailing-per-agent-run-history`
- **files**: `lib/Controller/BudgetController.php`, `appinfo/routes.php`, `tests/Unit/Controller/BudgetControllerTest.php`
- **acceptance_criteria**:
  - GIVEN a caller who is neither the instance admin nor the organisation owner WHEN they call `POST`/`PUT`/`DELETE /api/budgets*` THEN the request is refused (403), mirroring `TenantControlController::mayAdminister()`
  - GIVEN any authenticated user in organisation A WHEN they call `GET /api/budgets/status` THEN only organisation A's budget status is returned (tenant-scoped via `ObjectService`, same convention as `AnalyticsController`)
  - GIVEN an agent with prior runs WHEN `GET /api/agents/{agentId}/budget-estimate` is called THEN the response matches design.md's estimate payload shape
- [ ] Implement
- [ ] Test

### Task 5: Seed realistic `Budget` objects (idempotent repair step)
- **spec_ref**: `openspec/changes/cost-guardrails/specs/multi-tenant-ops/spec.md#requirement-per-scope-budget-guardrails--soft-threshold-and-hard-cap`
- **files**: `lib/Repair/SeedBudgets.php`, `lib/AppInfo/Application.php`
- **acceptance_criteria**:
  - GIVEN a fresh install WHEN repair steps run THEN the 3 seed `Budget` objects from design.md's Seed Data table are created via `ObjectService` (single write-path)
  - GIVEN a re-run (upgrade) WHEN the repair step runs again THEN existing seeded budgets (matched by slug) are NOT duplicated
- [ ] Implement
- [ ] Test

### Task 6: Frontend — `budgets` API module + `BudgetFormModal` (CRUD UI)
- **spec_ref**: `openspec/changes/cost-guardrails/specs/multi-tenant-ops/spec.md#requirement-per-scope-budget-guardrails--soft-threshold-and-hard-cap`
- **files**: `src/api/budgets.js`, `src/modals/BudgetFormModal.vue`
- **acceptance_criteria**:
  - GIVEN an org admin opens the budget form WHEN they submit scope/period/limits THEN `budgets.js` posts to `/api/budgets` and the modal closes on success, using `NcDialog` + `NcSelect` with `inputLabel` (ADR-004)
  - GIVEN a non-admin org member WHEN the TenantOps/AgentDetail budget card renders THEN no create/edit/delete control is shown (read-only view)
- [ ] Implement
- [ ] Test

### Task 7: Frontend — budget status cards on TenantOps + AgentDetail
- **spec_ref**: `openspec/changes/cost-guardrails/specs/multi-tenant-ops/spec.md#requirement-per-scope-budget-guardrails--soft-threshold-and-hard-cap`
- **files**: `src/views/TenantOps.vue`, `src/views/AgentDetail.vue`
- **acceptance_criteria**:
  - GIVEN an organisation with a configured budget WHEN `TenantOps.vue` loads THEN a budget card renders using the existing `tenant-ops__card`/`--warn` pattern, warn-styled once the soft threshold is crossed
  - GIVEN an agent with its own agent-scoped budget WHEN `AgentDetail.vue` loads THEN the agent's budget status (used/limit/percent) renders
- [ ] Implement
- [ ] Test

### Task 8: Frontend — pre-run cost estimate on Run now + schedule creation
- **spec_ref**: `openspec/changes/cost-guardrails/specs/run-analytics/spec.md#requirement-pre-run-cost-estimate-derived-from-trailing-per-agent-run-history`
- **files**: `src/views/AgentDetail.vue`, `src/modals/ScheduleFormModal.vue`
- **acceptance_criteria**:
  - GIVEN an agent with prior runs WHEN a user opens Run now or the schedule-creation form THEN the trailing-average estimate renders, clearly labelled "estimate"
  - GIVEN an agent with no prior runs WHEN the same forms open THEN "not enough run history yet" (or equivalent) renders instead of a zero/fabricated figure
- [ ] Implement
- [ ] Test

## Quality checklist

- All new/changed business logic covered by PHPUnit unit tests (`tests/Unit/`)
- New/changed API endpoints covered by Newman/Postman tests
- UI changes covered by Playwright browser tests
- All tests pass (`composer test`, `newman run`)
- Feature documentation updated in `docs/` if user-facing (ADR-010)
- Dutch (`nl_NL`) and English (`en_US`) translation strings added for any new user-facing strings (ADR-007)
- `openspec validate` passes
