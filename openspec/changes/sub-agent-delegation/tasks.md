# Tasks: sub-agent-delegation

## Implementation Tasks

### Task 1: Add `Agent.delegationAllowlist` schema field + delegation IAppConfig defaults
- **spec_ref**: `openspec/changes/sub-agent-delegation/specs/sub-agent-delegation/spec.md#requirement-delegation-is-refused-by-default-until-explicitly-allowlisted`
- **files**: `lib/Settings/hermiq_register.json`, `appinfo/info.xml`
- **acceptance_criteria**:
  - GIVEN the app is installed/upgraded WHEN `InitializeSettings`/Repair runs THEN OpenRegister's
    `Agent` schema (bumped `0.2.0` → `0.3.0`) has a `delegationAllowlist` array field (`$ref Agent`,
    default `[]`)
  - GIVEN a newly created agent with no `delegationAllowlist` supplied WHEN it is saved THEN
    `delegationAllowlist` defaults to `[]`
- [ ] Implement
- [ ] Test

### Task 2: `DelegationContext` — request-scoped delegation call-stack
- **spec_ref**: `openspec/changes/sub-agent-delegation/specs/sub-agent-delegation/spec.md#requirement-delegation-depth-and-fan-out-are-bounded`
- **files**: `lib/Service/Engine/DelegationContext.php`, `tests/Unit/Service/Engine/DelegationContextTest.php`
- **acceptance_criteria**:
  - GIVEN no frame has been pushed WHEN `depth()` is called THEN it returns 0 and `ancestorAgentIds()`
    returns an empty array
  - GIVEN a frame is pushed for agent A (depth 1) and then a nested frame for agent B WHEN `depth()`
    is read inside B's frame THEN it returns 2, and `ancestorAgentIds()` includes A
  - GIVEN a frame is popped WHEN `current()` is read afterward THEN it returns the PREVIOUS frame
    (or null if none), never the popped one
  - GIVEN `incrementFanOut()` is called 3 times on the current frame WHEN `fanOutCount()` is read
    THEN it returns 3
- [ ] Implement
- [ ] Test

### Task 3: `ScheduleService` — `forceOwner`/`anchor` params, call-stack push/pop, `runId` in audit
- **spec_ref**: `openspec/changes/sub-agent-delegation/specs/sub-agent-delegation/spec.md#requirement-delegated-runs-inherit-the-parents-acting-user-attribution`
- **files**: `lib/Service/ScheduleService.php`, `tests/Unit/Service/ScheduleServiceTest.php`
- **acceptance_criteria**:
  - GIVEN `runAgentAsOwner(owner: U, agentId: B, prompt: ..., forceOwner: true)` is called and agent
    B has its own `actingUser` V set WHEN the run executes THEN it impersonates U, not V, and
    `lastRunAsUser` records U
  - GIVEN `runAgentAsOwner()` is called WITHOUT `forceOwner` (existing callers unchanged) WHEN agent
    B has its own valid `actingUser` V set THEN it impersonates V exactly as it does today (no
    behavior change for existing callers)
  - GIVEN `runAgentViaEngine()` runs WHEN it starts THEN it pushes a `DelegationContext` frame
    (agentId, organisation from the resolved Agent entity, the passed `anchor`, a fresh `runId`,
    depth = previous depth + 1) before `Engine::processMessage()` and pops it in a `finally`
  - GIVEN a run completes (top-level or delegated) WHEN `writeRunAudit()`/its delegated counterpart
    writes the `AuditTrail` entry THEN the context includes the run's own `runId`, and a delegated
    run's context additionally includes `parentRunId` referencing the calling run's `runId`
  - GIVEN `runDue()` fires a schedule WHEN it calls `runAgentAsOwner()` THEN it passes `anchor: $schedule`
- [ ] Implement
- [ ] Test

### Task 4: `FlowAgentRunService` — pass the triggering object as the delegation anchor
- **spec_ref**: `openspec/changes/sub-agent-delegation/specs/multi-tenant-ops/spec.md#requirement-per-scope-budget-guardrails--soft-threshold-and-hard-cap`
- **files**: `lib/Service/FlowAgentRunService.php`, `tests/Unit/Service/FlowAgentRunServiceTest.php`
- **acceptance_criteria**:
  - GIVEN `runAgentAndWriteBack()` calls `runAgentAsOwner()` WHEN it fires THEN it passes
    `anchor: $object` (the triggering object) so a delegation nested inside a flow-triggered run
    rolls up to the same anchor a scheduled run's `$schedule` would
- [ ] Implement
- [ ] Test

### Task 5: `DelegationService` — the governed delegation dispatcher
- **spec_ref**: `openspec/changes/sub-agent-delegation/specs/sub-agent-delegation/spec.md#requirement-self-delegation-and-delegation-cycles-are-refused`
- **files**: `lib/Service/DelegationService.php`, `tests/Unit/Service/DelegationServiceTest.php`
- **acceptance_criteria**:
  - GIVEN a calling agent's `delegationAllowlist` does not name the requested target WHEN
    `delegate()` is called THEN it returns a `delegation_not_allowed` error and never invokes
    `ScheduleService::runAgentAsOwner()`
  - GIVEN the requested target is the calling agent itself, OR already appears in the current
    `DelegationContext` ancestor chain WHEN `delegate()` is called THEN it returns
    `delegation_self`/`delegation_cycle` respectively, checked BEFORE the allowlist check
  - GIVEN the current depth/fan-out would exceed `delegation.maxDepth`/`delegation.maxFanOut`
    (`IAppConfig`, defaults 2/3) WHEN `delegate()` is called THEN it returns
    `delegation_depth_exceeded`/`delegation_fanout_exceeded` and never invokes the target
  - GIVEN the target agent belongs to a different organisation than the calling agent WHEN
    `delegate()` is called THEN it returns `delegation_cross_organisation` unconditionally
  - GIVEN the target agent's resolved provider/model falls outside the organisation's effective
    `ModelPolicy`, OR the organisation's kill-switch is engaged, OR the relevant `Budget` is at its
    hard cap, OR the target has `requiresApproval` true WHEN `delegate()` is called THEN it returns
    the matching error code and never invokes the target
  - GIVEN every gate passes WHEN `delegate()` is called THEN it calls
    `ScheduleService::runAgentAsOwner(owner: <caller's current IUserSession uid>, agentId: target,
    prompt: task, forceOwner: true, anchor: DelegationContext::current()->anchor)`, writes its own
    `AuditTrail` entry (anchored the same way, `runId`/`parentRunId` populated), and returns
    `{targetAgentId, result}`
  - GIVEN any gate refuses WHEN `delegate()` returns THEN no exception is thrown (mirrors
    `HermiqToolProvider`'s never-throws contract)
- [ ] Implement
- [ ] Test

### Task 6: `HermiqToolProvider` — register the `hermiq.delegateAgent` tool
- **spec_ref**: `openspec/changes/sub-agent-delegation/specs/sub-agent-delegation/spec.md#requirement-a-delegated-sub-agent-runs-in-an-isolated-conversation`
- **files**: `lib/Mcp/HermiqToolProvider.php`, `tests/Unit/Mcp/HermiqToolProviderTest.php`
- **acceptance_criteria**:
  - GIVEN `getTools()` is called WHEN the catalogue is enumerated THEN it includes
    `hermiq.delegateAgent` with `targetAgentId` (uuid, required) and `task` (string, required)
  - GIVEN `invokeTool('hermiq.delegateAgent', {targetAgentId, task})` is called WHEN it runs THEN
    it delegates to `DelegationService::delegate()` and returns that result verbatim
    (success or structured error), never throwing
- [ ] Implement
- [ ] Test

### Task 7: Frontend — delegation allowlist editor + translations
- **spec_ref**: `openspec/changes/sub-agent-delegation/specs/agent-management-ui/spec.md#requirement-agent-detail-manages-the-delegation-allowlist-in-place-mvp`
- **files**: `src/modals/AgentFormModal.vue`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN the agent create/edit form WHEN it renders THEN a "Delegation allowlist" `NcSelect`
    (multi-select, `inputLabel`, mirrors the existing "Enabled tools" field) lists the caller's
    visible agent catalog EXCLUDING the agent currently being edited
  - GIVEN the user selects agents and saves WHEN the form submits THEN `delegationAllowlist` is
    persisted as the selected agent UUIDs via the existing OpenRegister save path
  - GIVEN a brand-new agent WHEN the create form is submitted with the field left empty THEN
    `delegationAllowlist` saves as `[]`
- [ ] Implement
- [ ] Test

## Quality checklist

- All new/changed business logic covered by PHPUnit unit tests (`tests/Unit/`)
- UI changes covered by Playwright browser tests
- All tests pass (`composer test`)
- Dutch (`nl_NL`) and English (`en_US`) translation strings added for any new user-facing strings (ADR-007)
- `openspec validate` passes

## Verification
- [ ] All tasks checked off
- [ ] `openspec validate sub-agent-delegation --type change --strict` passes
- [ ] `composer check:strict` passes (PHPCS, PHPMD, Psalm, PHPStan)
